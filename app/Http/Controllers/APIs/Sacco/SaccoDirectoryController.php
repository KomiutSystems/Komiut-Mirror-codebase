<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Sacco;

use App\Http\Controllers\Controller;
use App\Models\Sacco as SaccoModel;
use App\Services\Sacco\SaccoDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group SACCO directory
 *
 * A public type-ahead over the SACCO directory, used while a driver is being
 * onboarded — before they have a token, and usually before their SACCO has an
 * account at all.
 *
 * The response is shaped by hand to id + name. Directory rows carry the SACCO's
 * own contact details, and this endpoint is unauthenticated, so serialising the
 * model (as the dashboard's saccos endpoint does) would publish them.
 */
class SaccoDirectoryController extends Controller
{
    /**
     * Search the SACCO directory
     *
     * Returns SACCO names matching `q`, ordered by name and capped at 20.
     * A query shorter than 2 characters returns an empty list rather than the
     * whole register.
     *
     * @unauthenticated
     *
     * @queryParam q string required Name fragment to match, minimum 2 characters. Example: nic
     *
     * @response 200 {"saccos": [{"id": 1, "name": "Nicco SACCO"}]}
     */
    public function index(Request $request, SaccoDirectory $directory): JsonResponse
    {
        $query = $request->query('q');

        $saccos = $directory->search(is_string($query) ? $query : '')
            ->map(fn (SaccoModel $sacco): array => [
                'id' => (int) $sacco->id,
                'name' => (string) $sacco->name,
            ])
            ->values();

        return response()->json(['saccos' => $saccos]);
    }
}
