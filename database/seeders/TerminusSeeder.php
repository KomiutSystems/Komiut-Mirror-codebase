<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Place;
use App\Models\Terminus;
use Illuminate\Database\Seeder;

/**
 * The stages a matatu queues at.
 *
 * `termini` was empty, so queues/join rejected every attempt with "the selected
 * terminus id is invalid" — and because a trip starts from a queue, and a
 * location broadcast needs a queue, that one empty table closed the whole
 * driver shift workflow.
 *
 * A terminus needs only a Place, NOT a route, so this fills the gap without
 * touching the unresolved routes question: the seeded Nairobi routes already
 * satisfy queues/join's other requirement.
 *
 * The place is looked up by name and created if missing, so the seeder is
 * self-sufficient rather than depending on NairobiRoutesSeeder having run.
 * Idempotent on terminus name.
 */
class TerminusSeeder extends Seeder
{
    /** Major Nairobi termini matatus actually queue at. */
    private const TERMINI = [
        'Railways Bus Station',
        'Kencom',
        'Ambassadeur',
        'Odeon',
        'Tea Room',
        'Machakos Country Bus',
        'Muthurwa',
        'Ngara',
        'Westlands',
        'Kangemi',
        'Dandora',
        'Kasarani',
        'Rongai Terminus',
        'Kikuyu Terminus',
        'Thika Terminus',
    ];

    public function run(): void
    {
        foreach (self::TERMINI as $name) {
            $place = Place::firstOrCreate(['name' => $name], ['status' => true]);

            Terminus::firstOrCreate(
                ['name' => $name],
                ['place_id' => $place->id, 'status' => true],
            );
        }
    }
}
