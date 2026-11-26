<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Passenger;

use App\Http\Controllers\Controller;
use App\Services\Payments\PassengerPayments\PassengerPayment;
use App\Services\Payments\PassengerPayments\PassengerPaymentFeed;
use App\Services\Payments\PassengerPayments\PaymentStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * @group Passenger payments
 *
 * My Bookings -> Payments: every payment the passenger started in the app --
 * bookings and QR fares, by M-Pesa prompt, points or wallet -- in every state,
 * newest first. The contract is docs/passenger-payments-api.md.
 *
 * Not payments/history, which stays exactly as it is for the screens that
 * read it: that one is paid bookings only, and a QR fare never reached it.
 *
 * SCOPED TO auth()->id() AND NOTHING ELSE. There is no permission branch --
 * staff holding View Passengers see their own payments here like anyone, which
 * for a crew or office account is none -- and no parameter names a user.
 * Another passenger's payment is a 404, never a 403: the answer must not say
 * whether the id exists.
 */
class PassengerPaymentFeedController extends Controller
{
    public function __construct(private readonly PassengerPaymentFeed $feed)
    {
        // NOT optional: this group's CheckAPIUserStatus reads ->status on the
        // user, and without auth that is a null user (see PassengerActivityController).
        $this->middleware('auth:sanctum');
    }

    /**
     * List my payments
     *
     * @authenticated
     *
     * @queryParam status string One of pending, paid, failed, cancelled, refunded, or active (= pending). Example: active
     * @queryParam from string First Nairobi day, inclusive (Y-m-d). Example: 2026-11-01
     * @queryParam to string Last Nairobi day, inclusive (Y-m-d). Example: 2026-11-26
     * @queryParam cursor string The next_cursor from the previous page.
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'status' => 'nullable|in:'.implode(',', [...PaymentStatus::ALL, PaymentStatus::ACTIVE]),
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'cursor' => 'nullable|string|max:200',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $status = $request->query('status');
        if ($status === PaymentStatus::ACTIVE) {
            $status = PaymentStatus::PENDING;
        }

        return response()->json($this->feed->page(
            (int) auth()->id(),
            ['status' => $status ?: null, 'from' => $request->query('from'), 'to' => $request->query('to')],
            $request->query('cursor'),
        ));
    }

    /**
     * One of my payments
     *
     * What the app re-reads when payment.updated names a payment, and what the
     * receipt sheet opens. Same shape as a list row.
     *
     * @authenticated
     *
     * @urlParam id string required booking:{id} or qr_fare:{id}. Example: qr_fare:4812
     */
    public function show(string $id): JsonResponse
    {
        $key = PassengerPayment::parseKey($id);
        $payment = $key === null ? null : $this->feed->find($key[0], $key[1], (int) auth()->id());

        if ($payment === null) {
            return response()->json(['error' => 'Payment not found.'], 404);
        }

        return response()->json($payment->toArray());
    }
}
