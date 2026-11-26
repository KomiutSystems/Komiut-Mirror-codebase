<?php

declare(strict_types=1);

namespace App\Services\Payments\PassengerPayments;

use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\QrcodePayment;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every payment a passenger made in the app, newest first: their bookings and
 * their QR fares, as one list (passenger/payments).
 *
 * TWO TABLES, ONE ORDER. A booking and a QR fare share nothing but an owner and
 * a time, so the page is built the way PassengerActivityController builds its
 * stream: a UNION ALL of (kind, id, created_at) keys -- each half a keyset walk
 * of ONE user's rows down the (user_id, created_at, id) index, each stopping at
 * a page -- then the page's rows loaded through their models, a fixed number
 * of queries whatever the page holds. Nothing loads a passenger's history to
 * sort it.
 *
 * THE ORDER IS TOTAL: created_at, then id, then kind. created_at is to the
 * second and a booking and its prompt routinely share one, and a booking and
 * a fare can share an id. The cursor names the last row by all three, so a
 * payment landing between two page reads appears on page one next time, not
 * twice on page two.
 *
 * SCOPING is user_id, and nothing else. Every query below drops the global
 * scopes on purpose: SaccoScope fails closed for a passenger (no SACCO), and a
 * passenger pays any brand's bus (BusBrand). Both would hide the passenger's
 * own payment from them; the user_id predicate is the boundary.
 *
 * Phone numbers never leave here, not even masked: no column carrying one is
 * put in a row.
 */
final class PassengerPaymentFeed
{
    public const PER_PAGE = 20;

    /**
     * One page of the caller's payments.
     *
     * @param  array{status?: ?string, from?: ?string, to?: ?string}  $filters  status is a PaymentStatus value; from/to are Nairobi days (Y-m-d), inclusive
     * @return array{payments: array<int, array<string, mixed>>, next_cursor: ?string}
     */
    public function page(int $userId, array $filters, ?string $cursor, int $perPage = self::PER_PAGE): array
    {
        $now = Carbon::now();
        $after = self::decodeCursor($cursor);

        $keys = $this->keys($userId, $filters, $after, $now, $perPage + 1);
        $more = $keys->count() > $perPage;
        $keys = $keys->take($perPage)->values();

        $payments = $this->hydrate($keys, $userId, $now);

        // From the KEYS, not the rows: a row deleted between the two reads is
        // skipped from this page, and the next one must still start after it.
        $last = $keys->last();

        return [
            'payments' => array_map(fn (PassengerPayment $p) => $p->toArray(), $payments),
            'next_cursor' => $more && $last !== null ? self::encodeCursor((string) $last->created_at, (int) $last->id, (string) $last->kind) : null,
        ];
    }

    /**
     * One payment by its key, or null. With a $userId it is the caller's or
     * nothing -- the controller turns nothing into a 404 whether the row is
     * missing or someone else's. Without one it is the announcer's read,
     * which skips the route: nothing it sends carries one.
     */
    public function find(string $kind, int $id, ?int $userId, ?CarbonInterface $now = null, bool $withRoute = true): ?PassengerPayment
    {
        $now ??= Carbon::now();

        $found = $kind === PassengerPayment::KIND_BOOKING
            ? $this->bookings([$id], $userId)
            : $this->qrFares([$id], $userId);

        $model = $found->get($id);
        if ($model === null) {
            return null;
        }

        return $kind === PassengerPayment::KIND_BOOKING
            ? $this->presentBooking($model, $now)
            : $this->presentQrFare($model, $withRoute ? $this->qrRoutes(collect([$id => $model])) : [], $now);
    }

    // ------------------------------------------------------------------
    // Keys
    // ------------------------------------------------------------------

    /**
     * @param  array{0: string, 1: int, 2: string}|null  $after
     * @return Collection<int, object{kind: string, id: int, created_at: string}>
     */
    private function keys(int $userId, array $filters, ?array $after, CarbonInterface $now, int $limit): Collection
    {
        $bookings = $this->branch(PassengerPayment::KIND_BOOKING, $userId, $filters, $after, $now, $limit);
        $fares = $this->branch(PassengerPayment::KIND_QR_FARE, $userId, $filters, $after, $now, $limit);

        return DB::query()
            ->fromSub($bookings->unionAll($fares), 'k')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->orderByDesc('kind')
            ->limit($limit)
            ->get();
    }

    /**
     * One kind's half of the page: at most $limit of the caller's rows after
     * the cursor, newest first. Its own ORDER BY and LIMIT stay inside its own
     * parentheses in the union, so each half stops after one page.
     *
     * @param  array{0: string, 1: int, 2: string}|null  $after
     */
    private function branch(string $kind, int $userId, array $filters, ?array $after, CarbonInterface $now, int $limit): QueryBuilder
    {
        $table = $kind === PassengerPayment::KIND_BOOKING ? 'bookings' : 'qrcode_payments';

        // The kind literal comes from a constant, never the request.
        $query = DB::table($table.' as o')
            ->selectRaw("'{$kind}' as kind, o.id, o.created_at")
            ->where('o.user_id', $userId)
            ->whereNotNull('o.created_at');

        if (filled($filters['from'] ?? null)) {
            $query->where('o.created_at', '>=', self::nairobiDay((string) $filters['from'])->utc()->format('Y-m-d H:i:s'));
        }
        if (filled($filters['to'] ?? null)) {
            // Half-open at the start of the next Nairobi day, so the whole of
            // `to` is in and a 23:59:59.5 row is not lost to a cutoff.
            $query->where('o.created_at', '<', self::nairobiDay((string) $filters['to'])->addDay()->utc()->format('Y-m-d H:i:s'));
        }

        $status = $filters['status'] ?? null;
        if ($status !== null) {
            $query->leftJoinLateral(
                PaymentStatus::latestPushJoin($kind === PassengerPayment::KIND_BOOKING ? 'booking_id' : 'qrcode_payment_id'),
                'lp',
            );

            $kind === PassengerPayment::KIND_BOOKING
                ? PaymentStatus::whereBookingIs($query, $status, $now)
                : PaymentStatus::whereQrFareIs($query, $status, $now);
        }

        if ($after !== null) {
            [$at, $id, $afterKind] = $after;
            // At the cursor's exact (created_at, id), the kinds run qr_fare
            // then booking (kind DESC). After a qr_fare, the booking with that
            // same pair is still to come; after a booking, nothing at it is.
            $op = strcmp($kind, $afterKind) < 0 ? '<=' : '<';
            $query->whereRaw("(o.created_at, o.id) {$op} (CAST(? AS timestamp), ?)", [$at, $id]);
        }

        return $query->orderByDesc('o.created_at')->orderByDesc('o.id')->limit($limit);
    }

    // ------------------------------------------------------------------
    // Rows
    // ------------------------------------------------------------------

    /**
     * @param  Collection<int, object>  $keys
     * @return array<int, PassengerPayment>
     */
    private function hydrate(Collection $keys, int $userId, CarbonInterface $now): array
    {
        $bookingIds = $keys->where('kind', PassengerPayment::KIND_BOOKING)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $fareIds = $keys->where('kind', PassengerPayment::KIND_QR_FARE)->pluck('id')->map(fn ($id) => (int) $id)->all();

        // user_id again, though the keys came from a query that had it: no
        // later edit to the key query can turn this into a read by bare id.
        $bookings = $bookingIds === [] ? collect() : $this->bookings($bookingIds, $userId);
        $fares = $fareIds === [] ? collect() : $this->qrFares($fareIds, $userId);
        $routes = $this->qrRoutes($fares);

        $items = [];
        foreach ($keys as $key) {
            $model = $key->kind === PassengerPayment::KIND_BOOKING ? $bookings->get((int) $key->id) : $fares->get((int) $key->id);
            if ($model === null) {
                continue;
            }

            $items[] = $key->kind === PassengerPayment::KIND_BOOKING
                ? $this->presentBooking($model, $now)
                : $this->presentQrFare($model, $routes, $now);
        }

        return $items;
    }

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int, Booking>
     */
    private function bookings(array $ids, ?int $userId): Collection
    {
        $unscoped = static fn ($q) => $q->withoutGlobalScopes();

        return Booking::withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->with([
                'from' => $unscoped,
                'to' => $unscoped,
                'queue' => $unscoped,
                'queue.vehicle' => $unscoped,
                'queue.vehicle.sacco' => $unscoped,
                'queue.route' => $unscoped,
                'queue.route.from' => $unscoped,
                'queue.route.to' => $unscoped,
                'mpesa_booking_callbacks',
                'latestStkPush',
                'loyaltyEntries' => fn ($q) => $q->withoutGlobalScopes()->whereIn('type', ['redeemed', 'refunded']),
            ])
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int, QrcodePayment>
     */
    private function qrFares(array $ids, ?int $userId): Collection
    {
        $unscoped = static fn ($q) => $q->withoutGlobalScopes();

        return QrcodePayment::withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->with([
                'vehicle' => $unscoped,
                'vehicle.sacco' => $unscoped,
                'mpesa_qrcode_payment',
                'latestStkPush',
                'pointsRedemption' => $unscoped,
            ])
            ->get()
            ->keyBy('id');
    }

    /**
     * The route each QR fare was paid on, in one query for the whole page.
     *
     * A scan names a bus, not a journey, so the fare row has no route. The
     * bus's most recent LIVE run started in the day before the payment is the
     * route it was plying -- what the passenger rode, as near as we can know.
     * No such run (a bus that never went live in the app) and it is null,
     * never a guess from further back. Walks the (vehicle_id, kind, id) index
     * backwards, one short seek per fare.
     *
     * @param  Collection<int, QrcodePayment>  $fares
     * @return array<int, string> fare id => route name
     */
    private function qrRoutes(Collection $fares): array
    {
        $ids = $fares->keys()->map(fn ($id) => (int) $id)->all();
        if ($ids === []) {
            return [];
        }

        $rows = DB::table('qrcode_payments as f')
            ->joinLateral(
                DB::table('queues as q')
                    ->select('q.route_id')
                    ->whereColumn('q.vehicle_id', 'f.vehicle_id')
                    ->where('q.kind', 'live')
                    ->whereNotNull('q.route_id')
                    ->whereColumn('q.created_at', '<=', 'f.created_at')
                    ->whereRaw("q.created_at >= f.created_at - interval '1 day'")
                    ->orderByDesc('q.id')
                    ->limit(1),
                'lq',
            )
            ->join('routes as r', 'r.id', '=', 'lq.route_id')
            ->leftJoin('places as rf', 'rf.id', '=', 'r.from_id')
            ->leftJoin('places as rt', 'rt.id', '=', 'r.to_id')
            ->whereIn('f.id', $ids)
            ->get(['f.id', 'r.name', 'rf.name as from_name', 'rt.name as to_name']);

        $routes = [];
        foreach ($rows as $row) {
            $name = self::routeName($row->name, $row->from_name, $row->to_name);
            if ($name !== null) {
                $routes[(int) $row->id] = $name;
            }
        }

        return $routes;
    }

    private function presentBooking(Booking $booking, CarbonInterface $now): PassengerPayment
    {
        $state = PaymentStatus::ofBooking($booking, $now);
        $method = $this->bookingMethod($booking);
        $push = $booking->latestStkPush;
        $receipts = $booking->mpesa_booking_callbacks->sortBy('id')->values();
        $vehicle = $booking->queue?->vehicle;
        $route = $booking->queue?->route;
        $settled = in_array($state->status, [PaymentStatus::PAID, PaymentStatus::REFUNDED], true);

        return new PassengerPayment(
            PassengerPayment::KIND_BOOKING,
            (int) $booking->id,
            $booking->user_id === null ? null : (int) $booking->user_id,
            $state,
            [
                'id' => PassengerPayment::key(PassengerPayment::KIND_BOOKING, (int) $booking->id),
                'kind' => PassengerPayment::KIND_BOOKING,
                'status' => $state->status,
                'method' => $method,
                'amount' => (float) $booking->amount,
                'currency' => 'KES',
                'points_spent' => $method === PassengerPayment::METHOD_POINTS ? PaymentStatus::pointsSpentOn($booking) : null,
                'mpesa_receipt' => $settled && $method === PassengerPayment::METHOD_MPESA
                    ? self::receipt($receipts->first()?->transid, $push?->checkout_request_id)
                    : null,
                'status_message' => $state->message,
                'plate' => self::text($vehicle?->plate),
                'sacco' => self::text($vehicle?->sacco?->name),
                'route' => $route === null ? null : self::routeName($route->name, $route->from?->name, $route->to?->name),
                'pickup' => self::text($booking->from?->name),
                'dropoff' => self::text($booking->to?->name),
                'passengers' => (int) $booking->passengers,
                'booking_id' => (int) $booking->id,
                'checkout_request_id' => $state->status === PaymentStatus::PENDING ? self::checkoutId($push) : null,
                'created_at' => self::iso($booking->created_at),
                'settled_at' => self::iso($state->settledAt),
                'updated_at' => self::iso(self::latest([
                    $booking->updated_at, $push?->updated_at,
                    PaymentStatus::refundOf($booking)?->created_at,
                ])),
                '_settling' => $state->status === PaymentStatus::PAID && $method === PassengerPayment::METHOD_MPESA
                    && $receipts->isEmpty() && $push !== null && $push->processed_at === null,
            ],
        );
    }

    /** @param  array<int, string>  $routes */
    private function presentQrFare(QrcodePayment $fare, array $routes, CarbonInterface $now): PassengerPayment
    {
        $state = PaymentStatus::ofQrFare($fare, $now);
        $method = $this->fareMethod($fare);
        $push = $fare->latestStkPush;
        $receipt = $fare->mpesa_qrcode_payment;

        // A points or wallet fare puts no shillings in the till, so its
        // `amount` is 0 and `fare` is what the ride was worth. The passenger
        // paid for a ride worth `fare`; that is the amount they recognise.
        $amount = $method === PassengerPayment::METHOD_MPESA
            ? (float) $fare->amount
            : (float) ($fare->fare ?? $fare->amount);

        return new PassengerPayment(
            PassengerPayment::KIND_QR_FARE,
            (int) $fare->id,
            $fare->user_id === null ? null : (int) $fare->user_id,
            $state,
            [
                'id' => PassengerPayment::key(PassengerPayment::KIND_QR_FARE, (int) $fare->id),
                'kind' => PassengerPayment::KIND_QR_FARE,
                'status' => $state->status,
                'method' => $method,
                'amount' => $amount,
                'currency' => 'KES',
                'points_spent' => $method === PassengerPayment::METHOD_POINTS && $fare->pointsRedemption !== null
                    ? round(abs((float) $fare->pointsRedemption->value), 2)
                    : null,
                'mpesa_receipt' => $state->status === PaymentStatus::PAID && $method === PassengerPayment::METHOD_MPESA
                    ? self::receipt($receipt?->transid, $push?->checkout_request_id)
                    : null,
                'status_message' => $state->message,
                'plate' => self::text($fare->vehicle?->plate),
                'sacco' => self::text($fare->vehicle?->sacco?->name),
                'route' => $routes[(int) $fare->id] ?? null,
                'pickup' => null,
                'dropoff' => null,
                'passengers' => null,
                'booking_id' => null,
                'checkout_request_id' => $state->status === PaymentStatus::PENDING ? self::checkoutId($push) : null,
                'created_at' => self::iso($fare->created_at),
                'settled_at' => self::iso($state->settledAt),
                'updated_at' => self::iso(self::latest([$fare->updated_at, $push?->updated_at])),
                '_settling' => $state->status === PaymentStatus::PAID && $method === PassengerPayment::METHOD_MPESA
                    && $receipt === null && $push !== null && $push->processed_at === null,
            ],
        );
    }

    /**
     * The rail that settled it (MpesaPaymentsController stamps Mpesa on an STK
     * settlement, the redeem LoyaltyPoints, the wallet Wallet). The bank
     * rails are M-Pesa to the passenger -- they entered an M-Pesa PIN. An
     * unstamped row is M-Pesa: that is the only rail older bookings had.
     */
    private function bookingMethod(Booking $booking): string
    {
        return match ($booking->payment_method) {
            PaymentMethod::LoyaltyPoints => PassengerPayment::METHOD_POINTS,
            PaymentMethod::Wallet => PassengerPayment::METHOD_WALLET,
            PaymentMethod::Cash => 'cash',
            default => PassengerPayment::METHOD_MPESA,
        };
    }

    /**
     * qrcode_payments.payment_method is stamped for wallet fares only; null is
     * a points redemption or an M-Pesa fare, told apart by `amount` -- a points
     * receipt is written at 0 shillings (LoyaltyService::redeemForVehicle).
     */
    private function fareMethod(QrcodePayment $fare): string
    {
        if ($fare->payment_method === PaymentMethod::Wallet->value) {
            return PassengerPayment::METHOD_WALLET;
        }
        if ($fare->payment_method === PaymentMethod::LoyaltyPoints->value || (float) $fare->amount <= 0) {
            return PassengerPayment::METHOD_POINTS;
        }

        return PassengerPayment::METHOD_MPESA;
    }

    /**
     * A real M-Pesa receipt, or null. A settlement that could not learn one
     * (STK Push Query returns no receipt; ReconcileMpesaPayments) stores the
     * CheckoutRequestID or a "recon-" placeholder in its place, to satisfy the
     * unique transid -- neither is a code the passenger can quote to anyone.
     */
    private static function receipt(?string $transid, ?string $checkoutId): ?string
    {
        $transid = trim((string) $transid);
        if ($transid === '' || $transid === $checkoutId || str_starts_with($transid, 'ws_CO_') || str_starts_with($transid, 'recon-')) {
            return null;
        }

        return $transid;
    }

    private static function checkoutId(?object $push): ?string
    {
        if ($push === null) {
            return null;
        }
        if ($push->checkout_request_id !== null) {
            return (string) $push->checkout_request_id;
        }

        $said = json_decode((string) $push->callback, true);

        return is_array($said) && filled($said['CheckoutRequestID'] ?? null) ? (string) $said['CheckoutRequestID'] : null;
    }

    private static function routeName(?string $name, ?string $from, ?string $to): ?string
    {
        if (filled($name)) {
            return trim((string) $name);
        }

        return filled($from) && filled($to) ? trim((string) $from).' - '.trim((string) $to) : null;
    }

    /** Null when unknown -- never an empty string. */
    private static function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** ISO-8601 in Nairobi time (+03:00), the passenger's clock. */
    private static function iso(?CarbonInterface $at): ?string
    {
        return $at?->copy()->setTimezone('Africa/Nairobi')->toIso8601String();
    }

    /** @param  array<int, CarbonInterface|null>  $times */
    private static function latest(array $times): ?CarbonInterface
    {
        $times = array_values(array_filter($times));
        if ($times === []) {
            return null;
        }

        usort($times, fn (CarbonInterface $a, CarbonInterface $b) => $b <=> $a);

        return $times[0];
    }

    private static function nairobiDay(string $day): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $day, 'Africa/Nairobi')->startOfDay();
    }

    // ------------------------------------------------------------------
    // Cursor: the last row's (created_at, id, kind), opaque to the client.
    // ------------------------------------------------------------------

    public static function encodeCursor(string $createdAt, int $id, string $kind): string
    {
        return rtrim(strtr(base64_encode($createdAt.'|'.$id.'|'.$kind), '+/', '-_'), '=');
    }

    /**
     * A malformed cursor is page one, not an error -- as SeeksByCursor treats
     * it: usually a stale client, and refusing loses the screen for nothing.
     *
     * @return array{0: string, 1: int, 2: string}|null
     */
    public static function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        $parts = $raw === false ? [] : explode('|', $raw);

        if (count($parts) !== 3
            || ! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?$/', $parts[0])
            || ! ctype_digit($parts[1])
            || ! in_array($parts[2], PassengerPayment::KINDS, true)) {
            return null;
        }

        return [$parts[0], (int) $parts[1], $parts[2]];
    }
}
