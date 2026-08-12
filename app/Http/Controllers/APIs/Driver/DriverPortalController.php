<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Driver;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\ExpenseFee;
use App\Models\Queue;
use App\Models\Transaction;
use App\Models\VehicleExpenseAndFee;
use App\Models\VehicleUser;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        $vehicle = $this->vehicle();
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
     * Today's takings
     *
     * The running total, from the day's first payment to its most recent. A
     * driver checks this through the day and again when they knock off; the
     * `first_at`/`last_at` pair is what makes "so far" meaningful rather than
     * just a number.
     */
    public function earnings(Request $request): JsonResponse
    {
        $vehicle = $this->vehicle();
        if ($vehicle === null) {
            return $this->noAssignment();
        }

        $date = $request->filled('date') ? Carbon::parse($request->input('date')) : Carbon::today();

        return response()->json([
            'vehicle' => ['id' => (int) $vehicle->id, 'plate' => $vehicle->plate],
            'date' => $date->toDateString(),
            'takings' => $this->takingsFor((int) $vehicle->id, $date),
            'expenses' => $this->expensesFor((int) $vehicle->id, $date),
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
        $vehicle = $this->vehicle();
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
        $vehicle = $this->vehicle();
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
        $vehicle = $this->vehicle();
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

    /** The vehicle the caller is on RIGHT NOW, from the open assignment. */
    private function vehicle()
    {
        $assignment = VehicleUser::with('vehicle.sacco', 'vehicle.seat')
            ->where('user_id', auth()->id())
            ->where('status', true)
            ->whereNull('end_date')
            ->latest('id')
            ->first();

        return $assignment?->vehicle;
    }

    private function noAssignment(): JsonResponse
    {
        return response()->json(['error' => 'You have no active vehicle assignment.'], 403);
    }

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
        return 'driver:takings:'.$vehicleId.':'.Carbon::today()->toDateString();
    }

    private function forgetTakings(int $vehicleId): void
    {
        Cache::forget($this->takingsKey($vehicleId));
    }

    /** @return array<string,mixed> */
    private function takingsFor(int $vehicleId, Carbon $date): array
    {
        // Half-open window: trans_date is a timestamp, and an inclusive
        // between() counts the next day's 00:00:00 rows into both days.
        $from = $date->copy()->startOfDay();
        $to = $from->copy()->addDay();

        $r = Transaction::where('vehicle_id', $vehicleId)
            ->where('trans_date', '>=', $from)->where('trans_date', '<', $to)
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->selectRaw('COALESCE(SUM(CASE WHEN mpesa_id > 0 THEN amount ELSE 0 END), 0) as mpesa')
            ->selectRaw('COALESCE(SUM(CASE WHEN cash_id > 0 THEN amount ELSE 0 END), 0) as cash')
            ->selectRaw('COUNT(*) as payments')
            ->selectRaw('MIN(trans_date) as first_at')
            ->selectRaw('MAX(trans_date) as last_at')
            ->first();

        $expenses = (float) VehicleExpenseAndFee::where('vehicle_id', $vehicleId)
            ->whereBetween('trans_date', [$from, $to->copy()->subSecond()])->sum('amount');

        $trips = Queue::where('vehicle_id', $vehicleId)
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)->count();

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

    /** @return array<string,mixed> */
    private function expensesFor(int $vehicleId, Carbon $date): array
    {
        $from = $date->copy()->startOfDay();

        return [
            'total' => (float) VehicleExpenseAndFee::where('vehicle_id', $vehicleId)
                ->whereBetween('trans_date', [$from, $from->copy()->endOfDay()])->sum('amount'),
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
        $seats = (int) (optional($vehicle->seat)->seats ?? 0);

        $queue = Queue::where('vehicle_id', $vehicle->id)
            ->whereHas('queue_status', fn ($q) => $q->whereIn('status', ['Active', 'Pending']))
            ->latest('id')->first();

        $occupied = $queue === null ? 0 : (int) DB::table('seat_bookings')
            ->join('bookings', 'bookings.id', 'seat_bookings.booking_id')
            ->where('bookings.queue_id', $queue->id)
            ->where('seat_bookings.status', true)
            ->count();

        return [
            'seats' => $seats,
            'occupied' => $occupied,
            'available' => max($seats - $occupied, 0),
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
