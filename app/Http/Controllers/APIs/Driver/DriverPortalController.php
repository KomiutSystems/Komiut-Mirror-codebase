<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Driver;

use App\Http\Controllers\Concerns\ResolvesDriverVehicle;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\ExpenseFee;
use App\Models\Queue;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VehicleExpenseAndFee;
use App\Models\VehicleUser;
use App\Services\Booking\SegmentSeatAvailability;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

/**
 * @group Driver — the app's home, earnings and expenses
 *
 * Everything here is keyed on the vehicle the caller is CURRENTLY assigned to,
 * never on the driver's own history. Drivers rotate between matatus, so the
 * plate is what identifies a day's takings: money is paid to the vehicle's till,
 * not to the person. A driver who moved buses this morning must see this bus.
 *
 * Access is by IDENTITY, not permission. These read only the caller's own
 * assigned vehicle, so a permission adds nothing that the assignment does not
 * already enforce — and gating them would 403 the 206 migrated crew, who hold
 * `Conductor` and therefore lack `Edit Queues`.
 */
class DriverPortalController extends Controller
{
    use ResolvesDriverVehicle;

    /** A page of transactions. Fixed so a phone on a matatu route cannot ask for 5,000. */
    private const PER_PAGE = 20;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * The home screen
     *
     * Today's running total, the vehicle's capacity, and the most recent
     * payments — one call, because three round trips from Nairobi to Frankfurt
     * costs about three seconds of a driver staring at spinners.
     */
    public function home(Request $request): JsonResponse
    {
        // An owner is attached to every bus they own through the same
        // assignments table the crew use, so they may name one. The id is
        // matched against their OWN assignments — it narrows, never widens, and
        // an id that is not theirs resolves to null and 403s like any other.
        $vehicle = $this->vehicle($request->filled('vehicle_id') ? (int) $request->input('vehicle_id') : null);
        if ($vehicle === null) {
            return $this->noAssignment();
        }

        return response()->json([
            'vehicle' => [
                'id' => (int) $vehicle->id,
                'plate' => $vehicle->plate,
                'sacco' => optional($vehicle->sacco)->name,
            ],
            'today' => $this->todaysTakings((int) $vehicle->id),
            'capacity' => $this->capacity($vehicle),
            'recent_transactions' => $this->recentTransactions((int) $vehicle->id, 1)['data'],
        ]);
    }

    /**
     * The earnings screen — today, this week, this month, all time
     *
     * The running total, from the day's first payment to its most recent, PLUS
     * the same money rolled up over four business-day-aligned windows. A driver
     * checks "today" through the shift and again when they knock off; the wider
     * windows are what the earnings tab shows when they zoom out.
     *
     * This stays a SUPERSET of the old response: `vehicle`, `date`, `takings`
     * and `expenses` mean exactly what they did, so the current app keeps working
     * while the new one reads `today`/`week`/`month`/`all_time`.
     */
    public function earnings(Request $request): JsonResponse
    {
        // An owner is attached to every bus they own through the same
        // assignments table the crew use, so they may name one. The id is
        // matched against their OWN assignments — it narrows, never widens, and
        // an id that is not theirs resolves to null and 403s like any other.
        $vehicle = $this->vehicle($request->filled('vehicle_id') ? (int) $request->input('vehicle_id') : null);
        if ($vehicle === null) {
            return $this->noAssignment();
        }

        $vehicleId = (int) $vehicle->id;
        $date = $request->filled('date') ? Carbon::parse($request->input('date')) : Carbon::today();

        // Business-day-aligned bounds (03:00 EAT boundary, via BusinessDay). The
        // wider windows share today's upper bound so they run right up to "now"
        // rather than to a future midnight:
        //   today = the current business day;
        //   week  = the last 7 business days, ending today (today + previous 6);
        //   month = the 1st of the current month's business day, to now;
        //   all   = no lower bound.
        [$todayFrom, $todayTo] = BusinessDay::windowFor(Carbon::now());
        $weekFrom = $todayFrom->copy()->subDays(6);
        [$monthFrom] = BusinessDay::windowFor(Carbon::now()->startOfMonth());

        return response()->json([
            'vehicle' => ['id' => $vehicleId, 'plate' => $vehicle->plate],
            // The driver on this vehicle today: the caller, always, plus anyone
            // else whose assignment overlapped today's business day (crews rotate).
            'driver' => $this->assignedDriver(),
            'date' => $date->toDateString(),
            'takings' => $this->takingsFor($vehicleId, $date),
            'expenses' => $this->expensesFor($vehicleId, $date),
            'today' => $this->windowSummary($vehicleId, $todayFrom, $todayTo)
                + ['drivers' => $this->driversOn($vehicleId, $todayFrom, $todayTo)],
            'week' => $this->windowSummary($vehicleId, $weekFrom, $todayTo),
            'month' => $this->windowSummary($vehicleId, $monthFrom, $todayTo),
            'all_time' => $this->windowSummary($vehicleId, null, null),
        ]);
    }

    /**
     * Recent payments, newest first
     *
     * Paginated at 20. The dashboard's `transactions` endpoint is SACCO-wide —
     * a driver reading it sees every other bus in the SACCO — so this one is
     * confined to the assigned vehicle.
     */
    public function transactions(Request $request): JsonResponse
    {
        // An owner is attached to every bus they own through the same
        // assignments table the crew use, so they may name one. The id is
        // matched against their OWN assignments — it narrows, never widens, and
        // an id that is not theirs resolves to null and 403s like any other.
        $vehicle = $this->vehicle($request->filled('vehicle_id') ? (int) $request->input('vehicle_id') : null);
        if ($vehicle === null) {
            return $this->noAssignment();
        }

        $page = max((int) $request->input('page', 1), 1);

        return response()->json($this->recentTransactions((int) $vehicle->id, $page));
    }

    /**
     * The bookings screen
     *
     * Passengers on the current queue, filterable by status. Unlike
     * trips/bookings this does not require `Edit Queues`, so a conductor can
     * read the manifest they are actually working.
     */
    public function bookings(Request $request): JsonResponse
    {
        // An owner is attached to every bus they own through the same
        // assignments table the crew use, so they may name one. The id is
        // matched against their OWN assignments — it narrows, never widens, and
        // an id that is not theirs resolves to null and 403s like any other.
        $vehicle = $this->vehicle($request->filled('vehicle_id') ? (int) $request->input('vehicle_id') : null);
        if ($vehicle === null) {
            return $this->noAssignment();
        }

        $queue = Queue::where('vehicle_id', $vehicle->id)
            ->whereHas('queue_status', fn ($q) => $q->whereIn('status', ['Active', 'Pending']))
            ->latest('id')->first();

        if ($queue === null) {
            return response()->json(['bookings' => [], 'total' => 0, 'per_page' => self::PER_PAGE, 'current_page' => 1, 'last_page' => 1]);
        }

        $query = Booking::with(['from', 'to', 'seats'])
            ->where('queue_id', $queue->id)
            // Full vocabulary, shared with the dashboard via the model scope:
            // failed | boarded | confirmed | reserved. `failed` is the one that
            // had no name before -- CheckPassengerPayments cancels unpaid
            // bookings and releases the seat, and those were silently mixed in
            // with live ones on every screen.
            ->statusIs($request->input('status'))
            ->orderBy('created_at');

        $total = (clone $query)->count();
        $page = max((int) $request->input('page', 1), 1);

        return response()->json([
            'bookings' => $query->skip(($page - 1) * self::PER_PAGE)->take(self::PER_PAGE)->get()
                ->map(fn ($b) => array_merge($b->toArray(), ['status_label' => $b->status_label])),
            // So the app can render tabs without knowing the rules.
            'statuses' => ['all', 'reserved', 'confirmed', 'boarded', 'failed'],
            'total' => $total,
            'per_page' => self::PER_PAGE,
            'current_page' => $page,
            'last_page' => (int) max(ceil($total / self::PER_PAGE), 1),
        ]);
    }

    /**
     * What the driver spent today
     *
     * Fuel, parking, the stage fee. Takings alone do not tell a driver what they
     * are going home with, which is the number they actually care about.
     */
    public function expenses(Request $request): JsonResponse
    {
        // An owner is attached to every bus they own through the same
        // assignments table the crew use, so they may name one. The id is
        // matched against their OWN assignments — it narrows, never widens, and
        // an id that is not theirs resolves to null and 403s like any other.
        $vehicle = $this->vehicle($request->filled('vehicle_id') ? (int) $request->input('vehicle_id') : null);
        if ($vehicle === null) {
            return $this->noAssignment();
        }

        $date = $request->filled('date') ? Carbon::parse($request->input('date')) : Carbon::today();

        $rows = VehicleExpenseAndFee::with('expense_fee')
            ->where('vehicle_id', $vehicle->id)
            ->whereBetween('trans_date', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->orderByDesc('trans_date')->get();

        return response()->json([
            'date' => $date->toDateString(),
            'total' => (float) $rows->sum('amount'),
            'expenses' => $rows->map(fn (VehicleExpenseAndFee $e) => [
                'id' => (int) $e->id,
                'type' => optional($e->expense_fee)->name,
                'amount' => (float) $e->amount,
                'recorded_at' => optional($e->trans_date)->toIso8601String(),
            ]),
            // Platform defaults PLUS this SACCO's own categories.
            //
            // ExpenseFee is SaccoScoped, and the shared types are stored with
            // sacco_id NULL — so the scope filtered every one of them out and
            // the picker came back empty for everybody. Scopes are dropped and
            // the boundary re-stated explicitly: null (shared) or mine, never
            // another SACCO's.
            'types' => ExpenseFee::withoutGlobalScopes()
                ->where('status', true)
                ->where(function ($q) use ($vehicle) {
                    $q->whereNull('sacco_id')->orWhere('sacco_id', $vehicle->sacco_id);
                })
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Record an expense
     *
     * The driver's own entry, against the vehicle they are assigned to. Nothing
     * here takes a vehicle from the request — a driver can only ever spend
     * against the bus they are on.
     */
    public function storeExpense(Request $request): JsonResponse
    {
        $vehicle = $this->vehicle();
        if ($vehicle === null) {
            return $this->noAssignment();
        }

        $validator = Validator::make($request->all(), [
            'expense_fee_id' => 'required|integer|exists:expense_fees,id',
            'amount' => 'required|numeric|min:1',
            'trans_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $expense = VehicleExpenseAndFee::create([
            'vehicle_id' => $vehicle->id,
            'expense_fee_id' => $request->input('expense_fee_id'),
            'amount' => $request->input('amount'),
            // Defaults to now, not to midnight: this is a running log through
            // the day, and the summaries recompute matches on the date part.
            'trans_date' => $request->filled('trans_date') ? Carbon::parse($request->input('trans_date')) : Carbon::now(),
            'status' => true,
        ]);

        $this->forgetTakings((int) $vehicle->id);

        return response()->json([
            'success' => 'Expense recorded.',
            'expense' => [
                'id' => (int) $expense->id,
                'amount' => (float) $expense->amount,
                'recorded_at' => $expense->trans_date->toIso8601String(),
            ],
        ], 201);
    }

    // ---------------------------------------------------------------------

    /**
     * Today's money, cached for 30 seconds.
     *
     * The home screen is polled while the matatu fills, so the same three
     * aggregates would otherwise be recomputed every few seconds per driver.
     * 30s is short enough that a fare shows up while the passenger is still
     * boarding, and long enough to collapse a burst of polling into one query.
     */
    private function todaysTakings(int $vehicleId): array
    {
        return Cache::remember($this->takingsKey($vehicleId), 30,
            fn () => $this->takingsFor($vehicleId, Carbon::today()));
    }

    private function takingsKey(int $vehicleId): string
    {
        // Keyed on the BUSINESS date (03:00 EAT boundary), not the calendar day,
        // so a bus loading at 02:00 still shares the cache slot of the day it
        // belongs to.
        return 'driver:takings:'.$vehicleId.':'.BusinessDay::current()->toDateString();
    }

    private function forgetTakings(int $vehicleId): void
    {
        Cache::forget($this->takingsKey($vehicleId));
    }

    /**
     * One business day's takings.
     *
     * The window (03:00 EAT boundary, via BusinessDay) is computed explicitly
     * rather than off calendar midnight, then handed to takingsBetween — the same
     * primitive the multi-window earnings screen uses, so there is one home for
     * the cash/mpesa/trips query logic.
     *
     * @return array<string,mixed>
     */
    private function takingsFor(int $vehicleId, Carbon $date): array
    {
        [$from, $to] = BusinessDay::windowFor($date);

        return $this->takingsBetween($vehicleId, $from, $to);
    }

    /**
     * Takings for an arbitrary half-open [from, to) window.
     *
     * The one place the cash/mpesa/trips/expenses aggregation lives. Either bound
     * may be null — all-time has no lower bound — and the comparison is half-open
     * so a row on the boundary is counted into one window only. Three aggregate
     * queries, all keyed on vehicle_id: the transaction totals, the expense sum,
     * and the trip count.
     *
     * @return array<string,mixed>
     */
    private function takingsBetween(int $vehicleId, ?Carbon $from, ?Carbon $to): array
    {
        // trans_date and the expense date store NAIROBI wall-clock; queues.created_at
        // is a Laravel UTC timestamp. One window, two conventions -- bind each
        // against the representation its column actually holds.
        $localFrom = $from !== null ? BusinessDay::forLocalColumn($from) : null;
        $localTo = $to !== null ? BusinessDay::forLocalColumn($to) : null;

        $r = $this->withinWindow(Transaction::where('vehicle_id', $vehicleId), 'trans_date', $localFrom, $localTo)
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->selectRaw('COALESCE(SUM(CASE WHEN mpesa_id > 0 THEN amount ELSE 0 END), 0) as mpesa')
            ->selectRaw('COALESCE(SUM(CASE WHEN cash_id > 0 THEN amount ELSE 0 END), 0) as cash')
            ->selectRaw('COUNT(*) as payments')
            ->selectRaw('MIN(trans_date) as first_at')
            ->selectRaw('MAX(trans_date) as last_at')
            ->first();

        $expenses = (float) $this->withinWindow(
            VehicleExpenseAndFee::where('vehicle_id', $vehicleId), 'trans_date', $localFrom, $localTo
        )->sum('amount');

        // A trip is a queue the bus actually ran, not one it abandoned. Cancelled
        // queues (a driver who joined the stage then exited) were inflating the
        // count; only Completed and still-running Active queues are real trips.
        $trips = $this->withinWindow(Queue::where('vehicle_id', $vehicleId), 'created_at', $from, $to)
            ->whereHas('queue_status', fn ($q) => $q->whereIn('status', ['Completed', 'Active']))
            ->count();

        return [
            'earnings' => (float) $r->total,
            'mpesa' => (float) $r->mpesa,
            'cash' => (float) $r->cash,
            'payments' => (int) $r->payments,
            'trips' => $trips,
            'expenses' => $expenses,
            // What they actually go home with.
            'net' => (float) $r->total - $expenses,
            'first_at' => $r->first_at ? Carbon::parse($r->first_at)->toIso8601String() : null,
            'last_at' => $r->last_at ? Carbon::parse($r->last_at)->toIso8601String() : null,
        ];
    }

    /**
     * Constrain a builder to the half-open [from, to) window on $column.
     *
     * Either bound may be null (all-time drops the lower bound). The builder is
     * mutated in place and returned for chaining.
     *
     * @template TBuilder of \Illuminate\Database\Eloquent\Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    private function withinWindow($query, string $column, ?Carbon $from, ?Carbon $to)
    {
        if ($from !== null) {
            $query->where($column, '>=', $from);
        }
        if ($to !== null) {
            $query->where($column, '<', $to);
        }

        return $query;
    }

    /**
     * The four numbers the earnings screen shows for one window.
     *
     * A projection of takingsBetween down to what the tab renders: cash, mpesa,
     * net (takings less expenses) and trips.
     *
     * @return array{cash: float, mpesa: float, net: float, trips: int}
     */
    private function windowSummary(int $vehicleId, ?Carbon $from, ?Carbon $to): array
    {
        $t = $this->takingsBetween($vehicleId, $from, $to);

        return [
            'cash' => $t['cash'],
            'mpesa' => $t['mpesa'],
            'net' => $t['net'],
            'trips' => $t['trips'],
        ];
    }

    /**
     * The caller — the driver on this vehicle right now.
     *
     * @return array{id: int, name: ?string}
     */
    private function assignedDriver(): array
    {
        $user = auth()->user();

        return [
            'id' => (int) optional($user)->id,
            'name' => $this->driverName($user),
        ];
    }

    /**
     * Everyone assigned to this vehicle whose shift overlapped today's business
     * day — started before the window closed and had not ended before it opened.
     * Crews rotate mid-day, so more than one driver can own a single day's till.
     *
     * @return array<int, array{id: int, name: ?string}>
     */
    private function driversOn(int $vehicleId, Carbon $from, Carbon $to): array
    {
        return VehicleUser::with('user:id,firstname,lastname')
            ->where('vehicle_id', $vehicleId)
            ->where('status', true)
            ->where('start_date', '<', $to)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $from))
            ->get()
            ->map(fn (VehicleUser $vu) => [
                'id' => (int) optional($vu->user)->id,
                'name' => $this->driverName($vu->user),
            ])
            ->filter(fn (array $d) => $d['id'] > 0)
            ->values()
            ->all();
    }

    private function driverName(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $name = trim(($user->firstname ?? '').' '.($user->lastname ?? ''));

        return $name !== '' ? $name : null;
    }

    /** @return array<string,mixed> */
    private function expensesFor(int $vehicleId, Carbon $date): array
    {
        // Same 03:00-EAT business-day window as the takings. whereBetween is
        // inclusive on both ends, so the upper bound is the last instant strictly
        // before the next day's boundary.
        [$from, $to] = BusinessDay::windowFor($date);

        return [
            'total' => (float) VehicleExpenseAndFee::where('vehicle_id', $vehicleId)
                ->whereBetween('trans_date', [$from, $to->copy()->subSecond()])->sum('amount'),
        ];
    }

    /**
     * Seats, and how many are taken on the queue being loaded.
     *
     * Occupancy comes from the ACTIVE queue rather than the vehicle, because a
     * matatu's seats do not change but who is sitting in them does.
     */
    private function capacity($vehicle): array
    {
        // A street-onboarded vehicle has no seat row yet, so its real capacity is
        // unknown. Fall back to a configured default (a standard 14-seater) so the
        // driver still sees a number, and flag it so the app can prompt the SACCO
        // to enter the real layout.
        $seatRow = $vehicle->seat;
        $seatsConfigured = $seatRow !== null;
        $seats = $seatsConfigured
            ? (int) ($seatRow->seats ?? 0)
            : (int) config('booking.default_seats', 14);

        $queue = Queue::where('vehicle_id', $vehicle->id)
            ->whereHas('queue_status', fn ($q) => $q->whereIn('status', ['Active', 'Pending']))
            ->latest('id')->first();

        // Occupied seat ids from the SAME segment-aware source the passenger seat
        // map uses, so what a driver sees free here can't be rejected at booking.
        $occupiedIds = $queue === null
            ? []
            : app(SegmentSeatAvailability::class)->occupiedSeatIds($queue, null, null, null);
        $occupied = count($occupiedIds);

        // Per-seat map (id/name/occupied) from the vehicle's arrangement. Empty for
        // a vehicle with no layout yet — capacity then rides on the default above.
        $seatMap = [];
        if ($seatsConfigured) {
            $vehicle->loadMissing('seat.seat_arrangements');
            $occupiedSet = array_flip($occupiedIds);
            $seatMap = collect(optional($vehicle->seat)->seat_arrangements ?? [])
                ->map(fn ($arrangement) => [
                    'id' => (int) $arrangement->id,
                    'name' => $arrangement->name,
                    'occupied' => isset($occupiedSet[$arrangement->id]),
                ])
                ->values()
                ->all();
        }

        return [
            'seats' => $seats,
            'occupied' => $occupied,
            'available' => max($seats - $occupied, 0),
            'seats_configured' => $seatsConfigured,
            'seat_map' => $seatMap,
            'queue_id' => $queue?->id,
        ];
    }

    /** @return array<string,mixed> */
    private function recentTransactions(int $vehicleId, int $page): array
    {
        $query = Transaction::with('mpesa:id,TransID,FirstName,LastName,MSISDN,TransTime')
            ->where('vehicle_id', $vehicleId)
            ->orderByDesc('trans_date');

        $total = (clone $query)->count();

        $rows = $query->skip(($page - 1) * self::PER_PAGE)->take(self::PER_PAGE)->get()
            ->map(fn (Transaction $t) => [
                'id' => (int) $t->id,
                'amount' => (float) $t->amount,
                'method' => $t->mpesa_id > 0 ? 'mpesa' : 'cash',
                'reference' => optional($t->mpesa)->TransID,
                // First name only: the manifest does not need a full identity,
                // and this payload leaves the building to a phone.
                'payer' => optional($t->mpesa)->FirstName,
                'at' => optional($t->trans_date)->toIso8601String(),
            ]);

        return [
            'data' => $rows,
            'total' => $total,
            'per_page' => self::PER_PAGE,
            'current_page' => $page,
            'last_page' => (int) max(ceil($total / self::PER_PAGE), 1),
        ];
    }
}
