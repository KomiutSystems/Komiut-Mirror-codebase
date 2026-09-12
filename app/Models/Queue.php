<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\BelongsToBrand;
use App\Models\Concerns\BelongsToSacco;
use Illuminate\Database\Eloquent\Model;

class Queue extends Model
{
    use HasFactory, BelongsToSacco, BelongsToBrand;

    /**
     * Readable by a caller with no SACCO of their own. See
     * BelongsToSacco::allowsCrossTenantBrowsing() for what this does and does
     * not permit — it exempts the TENANTLESS caller only; a user who has a
     * SACCO is still filtered to it.
     *
     * The trips on offer. A passenger books onto someone else's queue by
     * definition; scoping this to their own SACCO offers them nothing.
     */
    protected bool $saccoCrossTenantBrowsing = true;

    /** Reaches brand via vehicle. */
    protected ?string $brandVia = 'vehicle';

    /** Reaches sacco_id via the vehicle relation. */
    protected $saccoVia = 'vehicle';
    protected $fillable = ["queue_number", "vehicle_id","terminus_id",
    "queue_status_id","route_id","user_id", 'amount','schedule_time','start_time','departed_at','end_time', 'queue_type'];

    protected static function booted(): void
    {
        // WHEN A TRIP ENDS, WHOEVER WAS STILL WAITING ON IT IS SETTLED. Every
        // Eloquent save that moves this queue onto Completed or Cancelled --
        // the crew ending or leaving, the stale-queue sweep, the dashboard, the
        // last-stop pick-up -- releases and refunds the bookings still live on
        // it, through App\Services\Booking\BookingCancellation. Seven paths
        // ended trips; one of them settled passengers. Now the queue does.
        //
        // The crew's trip/end still refuses to finish with paid, unmarked
        // passengers (that decision belongs at the door); by the time it saves
        // the queue there is nothing left here to settle. This is the net for
        // every end nobody at the door decided.
        //
        // Mass updates (Queue::where()->update()) never reach here. The two
        // that used to close queues that way now save each row, for this.
        static::updated(function (self $queue): void {
            if (! $queue->wasChanged('queue_status_id')) {
                return;
            }

            $status = QueueStatus::withoutGlobalScopes()->whereKey($queue->queue_status_id)->value('status');
            if (! in_array($status, \App\Services\Booking\BookingCancellation::TRIP_OVER_STATUSES, true)) {
                return;
            }

            app(\App\Services\Booking\BookingCancellation::class)->settleTripOver($queue);
        });
    }

    public function vehicle(){
        return $this->belongsTo(Vehicle::class);
    }
    public function user(){
        return $this->belongsTo(User::class);
    }
    public function terminus(){
        return $this->belongsTo(Terminus::class);
    }
    public function queue_status(){
        return $this->belongsTo(QueueStatus::class);
    }
    public function route(){
        return $this->belongsTo(Route::class);
    }
    public function queue_places(){
        return $this->hasMany(QueuePlace::class);
    }

    public function bookings(){
        return $this->hasMany(Booking::class);
    }
}
