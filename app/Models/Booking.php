<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToBrand;
use App\Models\Concerns\BelongsToSacco;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    use HasFactory, BelongsToSacco, BelongsToBrand;

    /**
     * Readable by a caller with no SACCO of their own. See
     * BelongsToSacco::allowsCrossTenantBrowsing() for what this does and does
     * not permit — it exempts the TENANTLESS caller only; a user who has a
     * SACCO is still filtered to it.
     *
     * The passenger's OWN trips — and only those. BookingsAPIController already
     * narrows to `user_id = auth()->id()` for anyone without View Passengers, so
     * the boundary here is the caller's identity rather than their tenant. Left
     * closed, a passenger could not read the booking they had just paid for,
     * because it hangs off a SACCO they will never be a member of.
     */
    protected bool $saccoCrossTenantBrowsing = true;

    /** Reaches brand via queue.vehicle. */
    protected ?string $brandVia = 'queue.vehicle';

    /** Reaches sacco_id via the queue.vehicle relation. */
    protected $saccoVia = 'queue.vehicle';
    protected $fillable = ["name", "phone","passengers", "user_id","queue_id", 'from_id', 'to_id',"amount",
    'booking_type','payment_method','boarded','paid',"stk_response","start_time","stop_time",'created_by','status',
    // Roadside (pick-as-you-go) flag-down point. NULL for terminus bookings,
    // where from_id already IS the passenger's location.
    'pickup_latitude','pickup_longitude'];

    protected $casts = [
        'booking_type' => BookingType::class,
        'payment_method' => PaymentMethod::class,
        'paid' => 'boolean',
        'boarded' => 'boolean',
    ];

    protected static function booted(): void
    {
        // The booking lifecycle lives here, not in controllers, for one reason:
        // there are already four write paths (BookARideQueuesAPIController,
        // MpesaPaymentsController, the loyalty redeem, the two sweeps) and every
        // one of them would otherwise have to remember to dispatch.
        //
        // CAVEAT that matters more than the rule: these hooks only fire on MODEL
        // writes. A mass `Booking::where(...)->update([...])` fires nothing at
        // all, and two scheduled commands cancel bookings exactly that way —
        // ReleaseExpiredBookings and CheckPassengerPayments. Both dispatch
        // BookingCancelled explicitly; see App\Events\BookingCancelled.

        // A held seat, not yet paid for. Fires once per row, on insert only.
        static::created(function (self $booking): void {
            \App\Events\BookingCreated::dispatch($booking);
        });

        static::updated(function (self $booking): void {
            // Fire once when a booking becomes paid (M-Pesa callback, points
            // redeem, etc.) — drives loyalty earning. Every settlement path
            // routes through here.
            if ($booking->wasChanged('paid') && $booking->paid) {
                \App\Events\BookingPaid::dispatch($booking);
            }

            // status true -> false is this system's "cancelled": the seats are
            // released and the booking no longer occupies the trip. wasChanged()
            // (not isDirty) so a save that merely rewrites an already-false
            // status does not re-announce a cancellation.
            if ($booking->wasChanged('status') && ! $booking->status) {
                \App\Events\BookingCancelled::dispatch(
                    $booking,
                    \App\Enums\BookingCancellationReason::Cancelled,
                );
            }
        });
    }

    public function from(){
        return $this->belongsTo(Place::class, 'from_id');
    }
    public function to(){
        return $this->belongsTo(Place::class, 'to_id');
    }

    public function creator(){
        return $this->belongsTo(User::class, 'created_by');
    }
    public function user(){
        return $this->belongsTo(User::class);
    }

    public function queue(){
        return $this->belongsTo(Queue::class);
    }
    public function seats(){
        return $this->hasMany(SeatBooking::class);
    }
    public function mpesa_booking_callbacks(){
        return $this->hasMany(MpesaBookingCallback::class);
    }

    /**
     * The booking's lifecycle state, derived from its three flags.
     *
     * Exposed as an attribute so the driver app and the dashboard render the
     * same word for the same row instead of each re-deriving it.
     */
    public function getStatusLabelAttribute(): string
    {
        return BookingStatus::of(
            (bool) $this->status,
            (bool) $this->paid,
            (bool) $this->boarded,
        )->value;
    }

    /**
     * Filter by lifecycle state.
     *
     * `failed` is the one that did not exist before: CheckPassengerPayments
     * cancels unpaid bookings by setting status = 0 and releasing the seat, and
     * every listing silently mixed those in with live ones.
     */
    public function scopeStatusIs($query, ?string $state)
    {
        return match ($state) {
            'failed' => $query->where('status', false),
            'boarded' => $query->where('status', true)->where('boarded', true),
            'confirmed' => $query->where('status', true)->where('paid', true)->where('boarded', false),
            'reserved' => $query->where('status', true)->where('paid', false),
            // 'all' or anything unrecognised leaves the query untouched, so a
            // listing defaults to showing everything rather than silently
            // hiding cancelled rows.
            default => $query,
        };
    }

}
