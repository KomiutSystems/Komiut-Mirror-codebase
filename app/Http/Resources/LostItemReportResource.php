<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LostItemReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * One shape for both audiences. The passenger and the crew see the same report,
 * and neither sees `contact_phone` — that is the SACCO office's alone, so it is
 * simply never built here rather than conditionally hidden.
 *
 * @mixin LostItemReport
 */
class LostItemReportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->publicId(),
            'reference' => $this->reference(),
            'status' => $this->status->value,
            'category' => $this->category,
            'description' => $this->description,
            'plate' => $this->whenLoaded('vehicle', fn () => $this->vehicle?->plate),
            'route' => $this->route_label,
            'trip_date' => $this->trip_date?->toDateString(),
            'reported_at' => $this->created_at?->toIso8601String(),
            'found' => $this->found_at === null ? null : [
                'where' => $this->found_where,
                'place_name' => $this->found_place_name,
                'note' => $this->found_note,
                'photo_url' => $this->photoUrl(),
                'found_at' => $this->found_at->toIso8601String(),
            ],
            'timeline' => $this->whenLoaded('events', fn () => $this->events
                ->sortBy('id')
                ->map(fn ($e) => [
                    'status' => $e->status,
                    'at' => $e->created_at?->toIso8601String(),
                    'note' => $e->note,
                ])->values()),
            'keep_until' => $this->keep_until?->toDateString(),
        ];
    }

    /**
     * A signed, expiring link rather than a storage URL: this is a photo of
     * someone's belongings, and the `local` disk cannot mint temporary URLs at
     * all, so the file is served through a signed route on any disk.
     */
    private function photoUrl(): ?string
    {
        if ($this->found_photo_path === null) {
            return null;
        }

        return URL::temporarySignedRoute(
            'lost-items.photo',
            now()->addHours(6),
            ['report' => $this->id],
        );
    }
}
