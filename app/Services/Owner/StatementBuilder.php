<?php

declare(strict_types=1);

namespace App\Services\Owner;

use App\Models\Queue;
use App\Models\Summary;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleExpenseAndFee;
use Illuminate\Support\Carbon;

/**
 * One day's takings for every bus an owner holds.
 *
 * Figures come from `summaries`, the same rows the SACCO's own screens read, so
 * an owner and their SACCO never quote different numbers at each other. Money
 * is whole shillings as integers, per the mobile contract.
 */
class StatementBuilder
{
    /**
     * @return array{totals: array<string, int>, vehicles: array<int, array<string, mixed>>}|null
     *                                                                                            null when the owner holds no buses at all.
     */
    public function build(User $owner, Carbon $date): ?array
    {
        $vehicleIds = OwnedVehicles::forUser($owner);

        if ($vehicleIds === []) {
            return null;
        }

        $day = $date->toDateString();

        $plates = Vehicle::withoutGlobalScopes()
            ->whereIn('id', $vehicleIds)
            ->pluck('plate', 'id');

        $summaries = Summary::withoutGlobalScopes()
            ->whereIn('vehicle_id', $vehicleIds)
            ->whereDate('trans_date', $day)
            ->get()
            ->keyBy('vehicle_id');

        $trips = Queue::withoutGlobalScopes()
            ->whereIn('vehicle_id', $vehicleIds)
            // "Departed", not "created": a queue that never left is not a trip.
            ->whereNotNull('departed_at')
            ->whereDate('departed_at', $day)
            ->selectRaw('vehicle_id, count(*) as c')
            ->groupBy('vehicle_id')
            ->pluck('c', 'vehicle_id');

        $expenses = VehicleExpenseAndFee::withoutGlobalScopes()
            ->with('expense_fee')
            ->whereIn('vehicle_id', $vehicleIds)
            ->where('status', true)
            ->whereDate('trans_date', $day)
            ->get()
            ->groupBy('vehicle_id');

        $lastPayments = Transaction::withoutGlobalScopes()
            ->whereIn('vehicle_id', $vehicleIds)
            ->whereDate('trans_date', $day)
            ->selectRaw('vehicle_id, max(trans_date) as last_at')
            ->groupBy('vehicle_id')
            ->pluck('last_at', 'vehicle_id');

        $rows = [];

        foreach ($vehicleIds as $id) {
            $summary = $summaries->get($id);
            $mpesa = (int) round((float) ($summary->mpesa_amount ?? 0));
            $cash = (int) round((float) ($summary->cash_amount ?? 0));
            // `summaries` carries no points column, so this is always 0 today.
            // The app already renders the field; when loyalty starts settling
            // into a daily figure it goes here and nothing else changes.
            $points = 0;

            $lines = ($expenses->get($id) ?? collect())
                ->map(fn ($e): array => [
                    'label' => $e->expense_fee?->name ?? 'Expense',
                    'amount' => (int) round((float) $e->amount),
                ])
                ->values()
                ->all();

            $spent = array_sum(array_column($lines, 'amount'));
            $collections = $mpesa + $cash + $points;

            $rows[] = [
                'vehicle_id' => $id,
                'plate' => $plates[$id] ?? null,
                'collections' => $collections,
                'mpesa' => $mpesa,
                'cash' => $cash,
                'points' => $points,
                'payments' => (int) ($summary->mpesa_txn ?? 0) + (int) ($summary->cash_txn ?? 0),
                'trips' => (int) ($trips[$id] ?? 0),
                'expenses' => $spent,
                'net' => $collections - $spent,
                'last_payment_at' => isset($lastPayments[$id])
                    ? Carbon::parse($lastPayments[$id])->toIso8601String()
                    : null,
                'expense_lines' => $lines,
            ];
        }

        return ['totals' => $this->totals($rows), 'vehicles' => $rows];
    }

    /**
     * The fleet line, summed from the bus rows rather than queried again — so
     * the footer can never disagree with the rows above it.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function totals(array $rows): array
    {
        $sum = fn (string $key): int => (int) array_sum(array_column($rows, $key));

        return [
            'collections' => $sum('collections'),
            'mpesa' => $sum('mpesa'),
            'cash' => $sum('cash'),
            'points' => $sum('points'),
            'payments' => $sum('payments'),
            'trips' => $sum('trips'),
            'expenses' => $sum('expenses'),
            'net' => $sum('net'),
        ];
    }
}
