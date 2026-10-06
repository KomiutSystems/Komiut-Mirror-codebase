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

    /**
     * A place in a stage's line. Chosen by stage alone, carries no route on
     * rows made since 2026-10-06, and is never bookable.
     */
    public const KIND_STAGE = 'stage';

    /**
     * The bus running a route because its driver went live on it. The only
     * row passengers find, book and track.
     */
    public const KIND_LIVE = 'live';

    protected $fillable = ["queue_number", "vehicle_id","terminus_id",
    "queue_status_id","route_id","user_id", 'amount','schedule_time','start_time','departed_at','end_time', 'queue_type', 'kind'];

    protected $attributes = ['kind' => self::KIND_STAGE];

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

    public function scopeStage($query)
    {
        return $query->where($this->qualifyColumn('kind'), self::KIND_STAGE);
    }

    public function scopeLive($query)
    {
        return $query->where($this->qualifyColumn('kind'), self::KIND_LIVE);
    }

    public function isLive(): bool
    {
        return $this->kind === self::KIND_LIVE;
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<self>  $query */
    public function scopeTrips($query)
    {
        return self::whereCountsAsTrip($query);
    }

    /**
     * Leave out the stage row that ended WITH a live run.
     *
     * The driver who waits at a stage, departs and goes live on a route made
     * two rows for one journey: the place in the line and the run. Ending the
     * trip closes both with the same end_time, and that pairing is how the
     * stage row is told apart here. A stage row with no run beside it -- a
     * driver who departed the line without going live -- is still a trip,
     * as it always was.
     *
     * Static and builder-agnostic so the raw DB::table('queues') reports can
     * use exactly the same rule as the Eloquent ones. Expects the table to be
     * addressed as `queues`.
     *
     * @template TBuilder of \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function whereCountsAsTrip($query)
    {
        return $query->where(fn ($q) => $q->where('queues.kind', self::KIND_LIVE)
            ->orWhereNotExists(fn ($run) => $run->selectRaw('1')
                ->from('queues as run')
                ->whereColumn('run.vehicle_id', 'queues.vehicle_id')
                ->where('run.kind', self::KIND_LIVE)
                ->whereColumn('run.end_time', 'queues.end_time')));
    }
}
