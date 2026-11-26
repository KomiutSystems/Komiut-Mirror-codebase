<?php

declare(strict_types=1);

namespace App\Services\Team\AutoCards;

use App\Events\PlatformNotificationResolved;
use App\Events\TillRegistrationRecorded;
use App\Models\MpesaPaymentSetting;
use App\Models\PlatformNotification;
use App\Models\Sacco;
use App\Models\Vehicle;
use App\Observers\Team\FraudCardsObserver;
use App\Observers\Team\HeadOfficeCardsObserver;
use App\Observers\Team\SaccoCardsObserver;
use App\Observers\Team\VehicleCardsObserver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Throwable;

/**
 * The ops board's automatic cards: the platform opening a card when
 * something needs the team, and closing it when the problem goes away.
 * Contract: KomiutWebApp docs/TEAM-WORKSPACE-API.md, "Automatic cards".
 *
 *   till_registration  a bus's till registration failed   TillRegistrationCards
 *   head_office        buses wait on a head office's keys  HeadOfficeCards
 *   directory_claim    a new SACCO waits for review        DirectoryClaimCards
 *   fraud              a fraud flag on a bus or SACCO      FraudCards
 *
 * NEVER IN THE WAY. The writes these hang off are a till registration, a
 * directory review, a fraud flag being raised -- none of them may fail, or
 * slow down by more than a few queries, because of the board. So observers
 * and listeners only note WHAT to look at (ids, no queries) and only when the
 * column they care about actually changed; the card work runs once the
 * writer's transaction has committed (a rolled-back write raises no card),
 * each piece in its own transaction, and anything that goes wrong is
 * reported and swallowed.
 *
 * Every piece of card work re-reads the current state rather than trusting
 * the event, so running it twice, late, or out of order lands in the same
 * place -- which is also what lets the hourly team:sync-auto-cards sweep heal
 * whatever an event missed.
 */
final class AutoCards
{
    /** @var array<string, array<int, bool>> kind => id => flag, waiting for the commit */
    private array $pending = [];

    /** Wire the observers and listeners; called once from EventServiceProvider. */
    public static function listen(): void
    {
        app()->singletonIf(self::class);

        Vehicle::observe(VehicleCardsObserver::class);
        Sacco::observe(SaccoCardsObserver::class);
        MpesaPaymentSetting::observe(HeadOfficeCardsObserver::class);
        PlatformNotification::observe(FraudCardsObserver::class);

        Event::listen(TillRegistrationRecorded::class, static function (TillRegistrationRecorded $e): void {
            $cards = app(self::class);
            $cards->till($e->vehicleId);
            // A bulk Register tills writes hundreds of these: the SACCO's
            // head-office card only moves when a bus starts or stops waiting.
            if ($e->saccoId !== null && (in_array($e->from, HeadOfficeCards::WAITING, true) || in_array($e->status, HeadOfficeCards::WAITING, true))) {
                $cards->headOffice($e->saccoId);
            }
        });
        Event::listen(PlatformNotificationResolved::class, static function (PlatformNotificationResolved $e): void {
            app(self::class)->fraud($e->notificationId);
        });
    }

    /** A bus's till status changed (or the bus is gone). */
    public function till(int $vehicleId): void
    {
        $this->defer('till', $vehicleId);
    }

    /** Something changed about a SACCO's waiting buses or its head offices. */
    public function headOffice(int $saccoId): void
    {
        $this->defer('head_office', $saccoId);
    }

    /** A head office's keys or environment changed: every SACCO waiting on it. */
    public function connection(int $connectionId): void
    {
        $this->defer('connection', $connectionId);
    }

    public function directoryClaim(int $saccoId): void
    {
        $this->defer('directory_claim', $saccoId);
    }

    /** $new: a fresh flag rather than a resolve. */
    public function fraud(int $notificationId, bool $new = false): void
    {
        $this->defer('fraud', $notificationId, $new);
    }

    /**
     * The hourly reconciliation, every source.
     *
     * @return array<string, array{opened:int, closed:int}>
     */
    public function sweep(): array
    {
        $out = [];
        foreach ([TillRegistrationCards::class, HeadOfficeCards::class, DirectoryClaimCards::class, FraudCards::class] as $class) {
            $source = app($class);
            try {
                $out[$source->source()] = $source->sweep();
            } catch (Throwable $e) {
                // One source failing must not stop the others healing.
                report($e);
                $out[$source->source()] = ['opened' => 0, 'closed' => 0, 'failed' => true];
            }
        }

        return $out;
    }

    /**
     * Note it, and have it worked after the commit. Several notes in one
     * transaction (a bulk Register tills, a merge) fold into one pass, and a
     * SACCO named by thirty of them is looked at once.
     */
    private function defer(string $kind, int $id, bool $flag = false): void
    {
        try {
            $this->pending[$kind][$id] = ($this->pending[$kind][$id] ?? false) || $flag;
            // One callback per note, but the first to run takes the whole
            // batch and the rest find it empty. A callback lost to a rollback
            // costs nothing: its notes are picked up by the next flush, and
            // working a note re-reads the truth anyway.
            DB::afterCommit(fn () => $this->flush());
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Work everything noted so far. Public for the tests and the command. */
    public function flush(): void
    {
        while ($this->pending !== []) {
            $batch = $this->pending;
            $this->pending = [];

            // Connections first: they name more SACCOs for head-office cards.
            foreach (array_keys($batch['connection'] ?? []) as $id) {
                $this->safely(function () use ($id, &$batch): void {
                    $connection = MpesaPaymentSetting::withoutGlobalScopes()->find($id, ['id', 'sacco_id']);
                    foreach (app(HeadOfficeCards::class)->saccosWaitingOn($id, $connection?->sacco_id === null ? null : (int) $connection->sacco_id) as $saccoId) {
                        $batch['head_office'][$saccoId] = false;
                    }
                });
            }
            unset($batch['connection']);

            $tills = app(TillRegistrationCards::class);
            $headOffices = app(HeadOfficeCards::class);
            $claims = app(DirectoryClaimCards::class);
            $fraud = app(FraudCards::class);

            foreach ($batch as $kind => $ids) {
                foreach ($ids as $id => $flag) {
                    $this->safely(fn () => match ($kind) {
                        'till' => $tills->sync($id),
                        'head_office' => $headOffices->sync($id),
                        'directory_claim' => $claims->sync($id),
                        'fraud' => $fraud->sync($id, $flag),
                    });
                }
            }
        }
    }

    /**
     * One piece of card work, in its own transaction: a failed query rolls
     * back only this, never the writer's work nor the next piece.
     */
    private function safely(callable $work): void
    {
        try {
            DB::transaction(fn () => $work());
        } catch (Throwable $e) {
            report($e);
        }
    }
}
