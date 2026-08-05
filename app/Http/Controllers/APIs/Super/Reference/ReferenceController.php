<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Super\Reference;

use App\Http\Controllers\Controller;
use App\Http\Resources\Super\SlimPage;
use App\Models\ExpenseFee;
use App\Models\Gender;
use App\Models\Place;
use App\Models\Queue;
use App\Models\QueueStatus;
use App\Models\Seat;
use App\Models\Terminus;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Generic reference-data CRUD for exactly six sets: genders, seat_layouts,
 * queue_statuses, expense_types, places, termini.
 *
 * {set} is resolved against a fixed config map — never used to reach an
 * arbitrary model — so this is not a mass-assignment/IDOR surface. Unknown
 * sets get 422 "Unknown reference set" (deliberately not a route constraint,
 * which would 404 instead of matching the required contract).
 *
 * Every set exposes a uniform row: {id,name,meta:{},status,in_use_count}.
 * `name` is genuinely `name` on all six underlying models. `status` is the
 * model's own boolean flag — for queue_statuses that flag is actually the
 * `active` column (the model's own `status` column is a business enum
 * Pending/Active/Suspended/Cancelled/Completed, so it rides in `meta` instead;
 * see the config for that set below). `meta` is a whitelist of each model's
 * other fillable fields — never an arbitrary passthrough of the request body.
 *
 * NEVER hard-deletes: there is no DELETE route. The existing "remove" concept
 * for all six of these models is status=false, which PATCH already covers.
 */
final class ReferenceController extends Controller
{
    /**
     * @return array{
     *     model: class-string<Model>,
     *     status_field: string,
     *     meta_fields: array<int,string>,
     *     required_meta: array<int,string>,
     *     meta_defaults: array<string,mixed>,
     *     unique_name: bool,
     *     in_use_count: callable(int):int
     * }
     */
    private function config(string $set): ?array
    {
        return match ($set) {
            'genders' => [
                'model' => Gender::class,
                'status_field' => 'status',
                'meta_fields' => [],
                'required_meta' => [],
                'meta_defaults' => [],
                'unique_name' => true,
                // approximated: only counts users whose gender_id points here.
                'in_use_count' => fn (int $id): int => User::withoutGlobalScopes()->where('gender_id', $id)->count(),
            ],
            'seat_layouts' => [
                'model' => Seat::class,
                'status_field' => 'status',
                'meta_fields' => ['seats', 'rows', 'columns'],
                'required_meta' => [],
                // seats/rows/columns are NOT NULL with no DB default.
                'meta_defaults' => ['seats' => 0, 'rows' => 0, 'columns' => 0],
                'unique_name' => true,
                'in_use_count' => fn (int $id): int => Vehicle::withoutGlobalScopes()->where('seat_id', $id)->count(),
            ],
            'queue_statuses' => [
                'model' => QueueStatus::class,
                // The boolean enable/disable flag is `active`; the model's own
                // `status` column is a business enum and lives in meta instead.
                'status_field' => 'active',
                'meta_fields' => ['status'],
                'required_meta' => [],
                'meta_defaults' => ['status' => 'Pending'],
                'unique_name' => true,
                'in_use_count' => fn (int $id): int => Queue::withoutGlobalScopes()->where('queue_status_id', $id)->count(),
            ],
            'expense_types' => [
                'model' => ExpenseFee::class,
                'status_field' => 'status',
                'meta_fields' => ['sacco_id', 'type'],
                'required_meta' => [],
                'meta_defaults' => [],
                'unique_name' => false,
                'in_use_count' => fn (int $id): int => DB::table('vehicle_expense_and_fees')->where('expense_fee_id', $id)->count(),
            ],
            'places' => [
                'model' => Place::class,
                'status_field' => 'status',
                'meta_fields' => ['county_name', 'longitude', 'latitude'],
                'required_meta' => [],
                'meta_defaults' => [],
                'unique_name' => false,
                // Approximated: only counts routes (from/to) and termini that
                // reference this place directly — route_stages/parcels are not
                // walked, that would no longer be a cheap query.
                'in_use_count' => fn (int $id): int => DB::table('routes')->where('from_id', $id)->orWhere('to_id', $id)->count()
                    + DB::table('termini')->where('place_id', $id)->count(),
            ],
            'termini' => [
                'model' => Terminus::class,
                'status_field' => 'status',
                'meta_fields' => ['longitude', 'latitude', 'place_id'],
                'required_meta' => ['place_id'], // NOT NULL FK, no DB default.
                'meta_defaults' => [],
                'unique_name' => false,
                // Approximated: queues raised at this terminus; sacco_termini
                // links are not counted (would double up "in use" meanings).
                'in_use_count' => fn (int $id): int => Queue::withoutGlobalScopes()->where('terminus_id', $id)->count(),
            ],
            default => null,
        };
    }

    public function index(Request $request, string $set): JsonResponse
    {
        $config = $this->config($set);
        if ($config === null) {
            return response()->json(['message' => 'Unknown reference set'], 422);
        }

        /** @var class-string<Model> $model */
        $model = $config['model'];
        $query = $model::query();

        $query->when($request->filled('q'), fn ($q) => $q->where('name', 'LIKE', '%'.$request->input('q').'%'));
        $query->when($request->has('status'), fn ($q) => $q->where($config['status_field'], $request->boolean('status')));

        $perPage = max(1, min((int) $request->input('per_page', 25), 100));
        $paginator = $query->orderBy('name')->paginate($perPage)->appends($request->query());

        return SlimPage::of($paginator, fn (Model $row): array => $this->present($row, $config))->response();
    }

    public function store(Request $request, string $set): JsonResponse
    {
        $config = $this->config($set);
        if ($config === null) {
            return response()->json(['message' => 'Unknown reference set'], 422);
        }

        /** @var class-string<Model> $model */
        $model = $config['model'];
        $table = (new $model)->getTable();

        $rules = [
            'name' => [
                'required', 'string', 'max:255',
                ...($config['unique_name'] ? [Rule::unique($table, 'name')] : []),
            ],
            'status' => ['sometimes', 'boolean'],
            'meta' => ['sometimes', 'array'],
        ];

        try {
            $validated = Validator::make($request->all(), $rules)->validate();
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }

        $meta = $this->resolveMeta($request->input('meta', []), $config);
        if ($meta === null) {
            return response()->json(['message' => 'Missing required meta field(s): '.implode(', ', $config['required_meta'])], 422);
        }

        $attributes = array_merge(
            ['name' => $validated['name'], $config['status_field'] => $validated['status'] ?? true],
            $meta,
        );

        $row = $model::create($attributes);

        return response()->json($this->present($row, $config), 201);
    }

    public function update(Request $request, string $set, int $id): JsonResponse
    {
        $config = $this->config($set);
        if ($config === null) {
            return response()->json(['message' => 'Unknown reference set'], 422);
        }

        /** @var class-string<Model> $model */
        $model = $config['model'];
        $row = $model::find($id);
        if ($row === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $table = $row->getTable();

        $rules = [
            'name' => [
                'sometimes', 'string', 'max:255',
                ...($config['unique_name'] ? [Rule::unique($table, 'name')->ignore($id)] : []),
            ],
            'status' => ['sometimes', 'boolean'],
            'meta' => ['sometimes', 'array'],
        ];

        try {
            $validated = Validator::make($request->all(), $rules)->validate();
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }

        $attributes = [];
        if (array_key_exists('name', $validated)) {
            $attributes['name'] = $validated['name'];
        }
        if (array_key_exists('status', $validated)) {
            $attributes[$config['status_field']] = $validated['status'];
        }
        if ($request->has('meta')) {
            foreach ((array) $request->input('meta', []) as $key => $value) {
                if (in_array($key, $config['meta_fields'], true)) {
                    $attributes[$key] = $value;
                }
            }
        }

        if ($attributes !== []) {
            $row->update($attributes);
        }

        return response()->json($this->present($row->refresh(), $config));
    }

    /**
     * Whitelist + fill in NOT-NULL defaults for a create payload's meta block.
     * Returns null when a set's required meta field is still missing after
     * defaults are applied (e.g. termini.place_id, which has no sane default).
     *
     * @param  array<mixed>  $rawMeta
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>|null
     */
    private function resolveMeta(array $rawMeta, array $config): ?array
    {
        $meta = $config['meta_defaults'];

        foreach ($rawMeta as $key => $value) {
            if (in_array($key, $config['meta_fields'], true)) {
                $meta[$key] = $value;
            }
        }

        foreach ($config['required_meta'] as $field) {
            if (! array_key_exists($field, $meta) || $meta[$field] === null || $meta[$field] === '') {
                return null;
            }
        }

        return $meta;
    }

    /** @param  array<string,mixed>  $config */
    private function present(Model $row, array $config): array
    {
        $meta = [];
        foreach ($config['meta_fields'] as $field) {
            $meta[$field] = $row->getAttribute($field);
        }

        return [
            'id' => $row->getKey(),
            'name' => $row->getAttribute('name'),
            'meta' => $meta,
            'status' => (bool) $row->getAttribute($config['status_field']),
            'in_use_count' => ($config['in_use_count'])((int) $row->getKey()),
        ];
    }
}
