<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Driver;

use App\Http\Controllers\Concerns\ResolvesDriverVehicle;
use App\Http\Controllers\Controller;
use App\Models\CashSubmission;
use App\Models\Transaction;
use App\Support\BusinessDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * @group Driver — end-of-shift cash reconciliation
 *
 * At knock-off a driver DECLARES the cash they physically hold for the bus.
 * This is a manual count, not the M-Pesa callback: it is the crew's own number,
 * to be set against what the system recorded. Like every driver endpoint it
 * keys on the vehicle from the caller's current assignment, never on the driver
 * — crews rotate, and the cash belongs to the till, not the person.
 */
class DriverCashController extends Controller
{
    use ResolvesDriverVehicle;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Declare (or re-declare) today's cash in hand.
     *
     * PUT/upsert on (vehicle, business day): there is exactly one declaration per
     * bus per day, so resubmitting the same shift corrects the number in place
     * rather than logging a second count. The business date is the 03:00-EAT day
     * so a night run still files under the day it started.
     */
    public function submit(Request $request): JsonResponse
    {
        $vehicle = $this->vehicle();
        if ($vehicle === null) {
            return $this->noAssignment();
        }

        $validator = Validator::make($request->all(), [
            'declared_amount' => 'required|numeric|min:0',
            'note' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $businessDate = BusinessDay::current()->toDateString();

        $submission = CashSubmission::updateOrCreate(
            [
                'vehicle_id' => $vehicle->id,
                'business_date' => $businessDate,
            ],
            [
                'declared_amount' => $request->input('declared_amount'),
                // Stamped every time: whoever is on shift now owns the correction.
                'user_id' => auth()->id(),
                'note' => $request->input('note'),
            ],
        );

        // Nice-to-have: what the system RECORDED in cash for the same window, so
        // the app can show the variance the reconciliation exists to surface.
        // Cheap — one indexed aggregate over the vehicle's day.
        $expected = $this->recordedCash((int) $vehicle->id);
        $declared = (float) $submission->declared_amount;

        return response()->json([
            'submission' => [
                'id' => (int) $submission->id,
                'vehicle_id' => (int) $submission->vehicle_id,
                'user_id' => (int) $submission->user_id,
                'business_date' => $businessDate,
                'declared_amount' => $declared,
                'note' => $submission->note,
            ],
            'expected' => $expected,
            // Positive: more cash counted than recorded (unrecorded fares).
            // Negative: a shortfall against what the till logged.
            'variance' => round($declared - $expected, 2),
        ], 200);
    }

    /**
     * The vehicle's RECORDED cash for today's business day.
     *
     * Cash payments only (cash_id > 0), matching how the home screen splits
     * takings; the half-open [start, end) window keeps the boundary row out of
     * two days at once.
     */
    private function recordedCash(int $vehicleId): float
    {
        [$from, $to] = BusinessDay::windowFor();

        // trans_date stores Nairobi wall-clock, not UTC. See forLocalColumn().
        return (float) Transaction::where('vehicle_id', $vehicleId)
            ->where('cash_id', '>', 0)
            ->where('trans_date', '>=', BusinessDay::forLocalColumn($from))
            ->where('trans_date', '<', BusinessDay::forLocalColumn($to))
            ->sum('amount');
    }
}
