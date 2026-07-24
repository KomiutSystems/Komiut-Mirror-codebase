<?php

namespace Database\Seeders;

use App\Enums\UserType;
use App\Models\Gender;
use App\Models\Place;
use App\Models\Route;
use App\Models\RouteFare;
use App\Models\RouteStage;
use App\Models\Sacco;
use App\Models\SaccoRoute;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo Nairobi corridors — a realistic STARTER set for building/clicking the
 * point-first ("Uber with fixed stops") booking UX. NOT authoritative: in
 * production SACCOs own and enter their real routes + stops via the dashboard.
 *
 * Each corridor is a route whose stops are ordered by `distance` (km from the
 * origin). Shared stops (Nairobi CBD, Donholm, Kayole, Nyayo Stadium, Ngara…)
 * are created once via firstOrCreate and reused across corridors — which is
 * exactly what exercises the point→route lookup (a stop can serve many routes).
 * Idempotent: safe to re-run.
 */
class NairobiRoutesSeeder extends Seeder
{
    private const BRAND = 'komiut';

    /**
     * [name, flat fare (KES), min fare, per-km rate] plus ordered stops
     * [place name, distance km]. Origin is always the first stop.
     */
    private function corridors(): array
    {
        return [
            ['CBD – Thika (Thika Road)', 100, 30, 4, [
                ['Nairobi CBD', 0], ['Ngara', 2], ['Pangani', 3], ['Roysambu', 9],
                ['Kasarani', 12], ['Githurai 45', 15], ['Ruiru', 22], ['Juja', 30], ['Thika', 40],
            ]],
            ['CBD – Kayole (Jogoo Road)', 60, 30, 5, [
                ['Nairobi CBD', 0], ['Muthurwa', 1], ['Makadara', 4], ['Buruburu', 7],
                ['Donholm', 9], ['Umoja', 11], ['Kayole', 14],
            ]],
            ['CBD – Athi River (Mombasa Road)', 80, 40, 4, [
                ['Nairobi CBD', 0], ['Nyayo Stadium', 3], ['South B', 5],
                ['Imara Daima', 9], ['Mlolongo', 18], ['Athi River', 25],
            ]],
            ['CBD – Ngong (Ngong Road)', 90, 40, 5, [
                ['Nairobi CBD', 0], ['Kenyatta Hospital', 3], ['Adams Arcade', 5],
                ['Dagoretti Corner', 8], ['Karen', 14], ['Ngong', 22],
            ]],
            ['CBD – Rongai (Langata Road)', 100, 50, 5, [
                ['Nairobi CBD', 0], ['Nyayo Stadium', 3], ['Wilson Airport', 6],
                ['Bomas', 12], ['Galleria', 16], ['Ongata Rongai', 20],
            ]],
            ['CBD – Kikuyu (Waiyaki Way)', 80, 40, 4, [
                ['Nairobi CBD', 0], ['Westlands', 4], ['Kangemi', 8],
                ['Uthiru', 12], ['Kinoo', 16], ['Kikuyu', 20],
            ]],
            ['CBD – Huruma (Juja Road)', 50, 30, 5, [
                ['Nairobi CBD', 0], ['Ngara', 2], ['Pangani', 3],
                ['Eastleigh', 5], ['Mathare', 6], ['Huruma', 8],
            ]],
            ['CBD – Kiambu (Kiambu Road)', 70, 40, 4, [
                ['Nairobi CBD', 0], ['Muthaiga', 5], ['Ridgeways', 9],
                ['Kirigiti', 14], ['Kiambu', 17],
            ]],
            ['CBD – Ruai (Kangundo Road)', 90, 40, 4, [
                ['Nairobi CBD', 0], ['Donholm', 9], ['Kayole', 14],
                ['Njiru', 18], ['Ruai', 24],
            ]],
        ];
    }

    public function run(): void
    {
        $sacco = Sacco::firstOrCreate(
            ['name' => 'Nairobi CBD SACCO'],
            ['slogan' => 'Moving the city', 'phone' => '0700000100', 'status' => 1, 'brand' => self::BRAND],
        );

        $creatorId = User::query()->value('id') ?? $this->systemUser($sacco->id);

        foreach ($this->corridors() as [$name, $fare, $min, $rate, $stops]) {
            // Places (stops), reused across corridors by name.
            $places = [];
            foreach ($stops as [$placeName, $distance]) {
                $place = Place::firstOrCreate(['name' => $placeName], ['county_name' => 'Nairobi', 'status' => 1]);
                $places[] = ['place' => $place, 'distance' => $distance];
            }

            $origin = $places[0]['place'];
            $terminus = end($places)['place'];

            $route = Route::firstOrCreate(
                ['name' => $name],
                ['from_id' => $origin->id, 'to_id' => $terminus->id, 'status' => 1],
            );

            foreach ($places as $stop) {
                RouteStage::firstOrCreate(
                    ['route_id' => $route->id, 'place_id' => $stop['place']->id],
                    ['distance' => $stop['distance'], 'status' => 1],
                );
            }

            // This SACCO serves the corridor (flat fare fallback).
            SaccoRoute::firstOrCreate(
                ['sacco_id' => $sacco->id, 'route_id' => $route->id],
                ['user_id' => $creatorId, 'amount' => $fare, 'min_amount' => $min, 'status' => 1],
            );

            // Per-stop fares from the origin to each downstream stop (demonstrates
            // stop-pair pricing that the fare resolver returns for a segment).
            foreach (array_slice($places, 1) as $stop) {
                RouteFare::updateOrCreate(
                    [
                        'sacco_id' => $sacco->id, 'route_id' => $route->id,
                        'from_place_id' => $origin->id, 'to_place_id' => $stop['place']->id,
                    ],
                    ['amount' => max($min, round($stop['distance'] * $rate)), 'status' => true],
                );
            }
        }
    }

    /** Fallback creator when the seeder runs standalone with no users yet. */
    private function systemUser(int $saccoId): int
    {
        $gender = Gender::firstOrCreate(['name' => 'Male'], ['status' => true]);

        return User::firstOrCreate(
            ['email' => 'system@komiut.test'],
            [
                'firstname' => 'System', 'lastname' => 'Seed', 'phone' => '0700000000',
                'password' => 'password', 'gender_id' => $gender->id, 'sacco_id' => $saccoId,
                'status' => true, 'type' => UserType::Admin,
            ],
        )->id;
    }
}
