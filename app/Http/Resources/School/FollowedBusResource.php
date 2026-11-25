<?php

declare(strict_types=1);

namespace App\Http\Resources\School;

use App\Models\Place;
use App\Models\VehicleFollower;
use App\Models\VehicleLocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * FollowedBus, as docs/school-bus-tracking.md defines it: one school bus a
 * parent follows, as the parent's app draws it.
 *
 * Built from a VehicleFollower prepared by
 * App\Services\School\Parent\FollowedBuses (bus_plate, school_id, school_name;
 * relations stopPlace, location, run). Every key is listed explicitly, so a
 * column added to vehicles or saccos later -- or the follow code -- can never
 * reach a parent by riding along on a model.
 *
 * `live` is null unless the bus is broadcasting inside the live window; the
 * service never loads a stale position. `trip` is null unless that position
 * is on an open run of this bus.
 *
 * @mixin VehicleFollower
 */
class FollowedBusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var VehicleFollower $follower */
        $follower = $this->resource;
        $vehicleId = (int) $follower->vehicle_id;

        return [
            'id' => $vehicleId,
            'plate' => $follower->bus_plate,
            'school' => ['id' => (int) $follower->school_id, 'name' => $follower->school_name],
            'stop' => $this->stop($follower),
            'live' => $this->live($follower->getRelation('location')),
            'trip' => $this->trip($follower->getRelation('run')),
            'channel' => 'private-school-bus.'.$vehicleId,
            'followed_at' => $follower->created_at?->toIso8601String(),
        ];
    }

    /** The parent's own pin first, else the route stop -- the order VehicleFollower::stopPoint() alerts on. */
    private function stop(VehicleFollower $follower): ?array
    {
        if ($follower->stop_latitude !== null && $follower->stop_longitude !== null) {
            return [
                'place_id' => null,
                'name' => null,
                'latitude' => (float) $follower->stop_latitude,
                'longitude' => (float) $follower->stop_longitude,
            ];
        }

        /** @var Place|null $place */
        $place = $follower->getRelation('stopPlace');
        if ($place === null) {
            return null;
        }

        return [
            'place_id' => (int) $place->id,
            'name' => $place->name,
            'latitude' => $place->latitude !== null ? (float) $place->latitude : null,
            'longitude' => $place->longitude !== null ? (float) $place->longitude : null,
        ];
    }

    private function live(?VehicleLocation $location): ?array
    {
        if ($location === null || $location->recorded_at === null) {
            return null;
        }

        return [
            'latitude' => (float) $location->latitude,
            'longitude' => (float) $location->longitude,
            'heading' => (int) $location->heading,
            'recorded_at' => $location->recorded_at->toIso8601String(),
            'age_seconds' => max(0, (int) $location->recorded_at->diffInSeconds(now(), false)),
        ];
    }

    private function trip(?object $run): ?array
    {
        if ($run === null) {
            return null;
        }

        return [
            'queue_id' => (int) $run->id,
            'route' => $run->route_id !== null && $run->route_name !== null
                ? ['id' => (int) $run->route_id, 'name' => $run->route_name]
                : null,
        ];
    }
}
