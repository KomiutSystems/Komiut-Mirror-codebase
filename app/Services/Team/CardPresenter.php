<?php

declare(strict_types=1);

namespace App\Services\Team;

use App\Models\Sacco;
use App\Models\Team\TeamCard;
use App\Models\Vehicle;
use Illuminate\Support\Collection;

/**
 * Cards in the shape the console reads. Link labels are resolved here, in one
 * query per link type for the whole board -- never one per card.
 */
final class CardPresenter
{
    /**
     * @param  iterable<TeamCard>  $cards  with assignee, creator and comments_count loaded
     * @return list<array<string, mixed>>
     */
    public static function many(iterable $cards): array
    {
        $cards = collect($cards);
        $links = self::resolveLinks($cards);

        return $cards->map(fn (TeamCard $card) => self::shape($card, $links))->values()->all();
    }

    /** @return array<string, mixed> */
    public static function one(TeamCard $card): array
    {
        $card->loadMissing(['assignee', 'creator'])->loadCount('comments');

        return self::many([$card])[0];
    }

    /**
     * Resolve every link on these cards.
     *
     * @param  Collection<int, TeamCard>  $cards
     * @return array<string, array<int, array{label:string,sacco_id:?int,brand:?string}>>
     */
    public static function resolveLinks(Collection $cards): array
    {
        $ids = fn (array $types) => $cards->filter(fn (TeamCard $c) => in_array($c->link_type, $types, true))
            ->pluck('link_id')->filter()->unique()->values()->all();

        $out = ['sacco' => [], 'vehicle' => [], 'till' => []];

        if ($saccoIds = $ids(['sacco'])) {
            foreach (Sacco::withoutGlobalScopes()->whereIn('id', $saccoIds)->get(['id', 'name', 'brand']) as $s) {
                $out['sacco'][(int) $s->id] = ['label' => (string) $s->name, 'sacco_id' => (int) $s->id, 'brand' => TeamWorkspace::brand($s->brand)];
            }
        }

        if ($vehicleIds = $ids(['vehicle', 'till'])) {
            foreach (Vehicle::withoutGlobalScopes()->whereIn('id', $vehicleIds)->get(['id', 'plate', 'merchant_short_code', 'sacco_id', 'brand']) as $v) {
                $base = ['sacco_id' => $v->sacco_id !== null ? (int) $v->sacco_id : null, 'brand' => TeamWorkspace::brand($v->brand)];
                $out['vehicle'][(int) $v->id] = ['label' => (string) $v->plate] + $base;
                $out['till'][(int) $v->id] = ['label' => $v->merchant_short_code
                    ? 'Till '.$v->merchant_short_code.' · '.$v->plate
                    : (string) $v->plate] + $base;
            }
        }

        return $out;
    }

    /** @param  array<string, array<int, array{label:string,sacco_id:?int,brand:?string}>>  $links */
    private static function shape(TeamCard $card, array $links): array
    {
        $link = null;
        if ($card->link_type !== null && $card->link_id !== null) {
            $resolved = $links[$card->link_type][$card->link_id] ?? null;
            $link = [
                'type' => $card->link_type,
                'id' => $card->link_id,
                // A linked thing that has since been deleted still shows what it was.
                'label' => $resolved['label'] ?? ucfirst($card->link_type).' #'.$card->link_id.' (removed)',
                'sacco_id' => $resolved['sacco_id'] ?? null,
                'brand' => $resolved['brand'] ?? null,
            ];
        }

        return [
            'id' => (int) $card->id,
            'title' => $card->title,
            'description' => $card->description,
            'status' => $card->status,
            'priority' => $card->priority,
            'due_on' => $card->due_on?->format('Y-m-d'),
            'assignee' => TeamWorkspace::member($card->assignee),
            'link' => $link,
            'source' => $card->source,
            'comments_count' => (int) ($card->comments_count ?? 0),
            'created_by' => TeamWorkspace::member($card->creator),
            'created_at' => $card->created_at?->toIso8601String(),
            'updated_at' => $card->updated_at?->toIso8601String(),
        ];
    }
}
