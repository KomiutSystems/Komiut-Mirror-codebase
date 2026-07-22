<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SeatBooking extends Model
{
    use HasFactory;
    protected $fillable = ["seat_id","booking_id", "paid","status"];
    public function booking(){
        return $this->belongsTo(Booking::class);
    }
    public function seat(){
        return $this->belongsTo(SeatArrangement::class);
    }

    /**
     * The single definition of "this seat is taken on this queue", shared by the
     * seat map (getVehicleSeats) and the booking conflict check (addBooking) so
     * the two can never disagree. A seat counts as occupied when its seat-booking
     * is active AND the parent booking is active AND either already paid or still
     * inside the unpaid-hold window — expired unpaid holds free their seats even
     * before the release sweep runs.
     */
    public function scopeOccupiedForQueue(Builder $query, int $queueId): Builder
    {
        $cutoff = now()->subMinutes((int) config('booking.hold_minutes', 10));

        return $query->where('status', true)
            ->whereHas('booking', function (Builder $booking) use ($queueId, $cutoff) {
                $booking->where('queue_id', $queueId)
                    ->where('status', true)
                    ->where(function (Builder $held) use ($cutoff) {
                        $held->where('paid', true)
                            ->orWhere('created_at', '>=', $cutoff);
                    });
            });
    }
}
