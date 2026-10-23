<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\LostItemStatus;
use App\Models\LostItemReport;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Closes reports nobody answered, and reports whose collection window has
 * passed, then deletes the photos of long-closed ones.
 *
 * Runs after the 03:00 business-day boundary so a report filed late at night
 * still counts against the day it was made on.
 */
class ExpireLostItems extends Command
{
    protected $signature = 'lostitems:expire';

    protected $description = 'Close unanswered and uncollected lost-item reports, and prune their photos';

    public function handle(): int
    {
        $now = Carbon::now();

        $unanswered = LostItemReport::where('status', LostItemStatus::Open->value)
            ->where('created_at', '<', $now->copy()->subDays(LostItemReport::UNANSWERED_DAYS))
            ->get();

        foreach ($unanswered as $report) {
            $report->forceFill(['status' => LostItemStatus::Closed->value])->save();
            $report->recordEvent(LostItemStatus::Closed, 'No answer from the crew');
        }

        // Past keep_until the item has gone to the SACCO office, so the crew
        // answer no longer describes where it is.
        $uncollected = LostItemReport::where('status', LostItemStatus::Found->value)
            ->whereNotNull('keep_until')
            ->whereDate('keep_until', '<', $now->toDateString())
            ->get();

        foreach ($uncollected as $report) {
            $report->forceFill(['status' => LostItemStatus::Closed->value])->save();
            $report->recordEvent(LostItemStatus::Closed, 'Handed to the SACCO office');
        }

        $pruned = $this->prunePhotos($now);

        $this->info(sprintf(
            'lost items: %d unanswered closed, %d uncollected closed, %d photos deleted',
            $unanswered->count(),
            $uncollected->count(),
            $pruned,
        ));

        return self::SUCCESS;
    }

    /** A photo of someone's belongings is not kept indefinitely after the case shuts. */
    private function prunePhotos(Carbon $now): int
    {
        $disk = Storage::disk(config('lostfound.photo_disk', 'local'));
        $cutoff = $now->copy()->subDays((int) config('lostfound.photo_retention_days', 30));
        $pruned = 0;

        $stale = LostItemReport::whereNotNull('found_photo_path')
            ->whereIn('status', [LostItemStatus::Closed->value, LostItemStatus::Collected->value])
            ->where('updated_at', '<', $cutoff)
            ->get();

        foreach ($stale as $report) {
            if ($disk->exists($report->found_photo_path)) {
                $disk->delete($report->found_photo_path);
            }

            $report->forceFill(['found_photo_path' => null])->save();
            $pruned++;
        }

        return $pruned;
    }
}
