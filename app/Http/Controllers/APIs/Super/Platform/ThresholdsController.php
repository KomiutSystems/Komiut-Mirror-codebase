<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Super\Platform;

use App\Brands\BrandRegistry;
use App\Http\Controllers\Controller;
use App\Models\PlatformThreshold;
use App\Services\Platform\AuditLogger;
use App\Services\Platform\Thresholds;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Read and retune the alert thresholds the burst detectors run on.
 *
 * Retuning these is a security-relevant act — raising driver_login_burst high
 * enough stops a real credential-stuffing alert from ever firing — so every
 * change is audited with its before/after, the same treatment role changes get.
 *
 * `brand` selects the scope: omitted means the platform-wide layer, otherwise a
 * brand from the registry. Unknown brands are rejected rather than silently
 * writing an override nothing will ever read.
 */
final class ThresholdsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $brand = $request->input('brand');
        if ($brand !== null && ! $this->brandExists($brand)) {
            return response()->json(['message' => 'Unknown brand'], 422);
        }

        return response()->json([
            'brand' => $brand,
            'thresholds' => Thresholds::all($brand),
            // The shipped values, so the console can show what "reset" returns
            // to and mark which rows are actually overridden.
            'defaults' => (array) config('platform.thresholds.defaults', []),
            'overridden' => PlatformThreshold::query()
                ->when($brand === null, fn ($q) => $q->whereNull('brand'))
                ->when($brand !== null, fn ($q) => $q->where('brand', $brand))
                ->pluck('key')->values()->all(),
        ]);
    }

    /**
     * Replace the overrides for a scope. Only keys present in the payload are
     * touched; sending null for a key REMOVES its override and falls back to
     * the shipped default, which is how the console's "reset" works.
     */
    public function update(Request $request): JsonResponse
    {
        $brand = $request->input('brand');
        if ($brand !== null && ! $this->brandExists($brand)) {
            return response()->json(['message' => 'Unknown brand'], 422);
        }

        try {
            $validated = Validator::make($request->all(), [
                'thresholds' => ['required', 'array', 'min:1'],
            ])->validate();
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }

        $known = Thresholds::keys();
        $unknown = array_diff(array_keys($validated['thresholds']), $known);
        if ($unknown !== []) {
            return response()->json([
                'message' => 'Unknown threshold key(s): '.implode(', ', $unknown),
                'known' => $known,
            ], 422);
        }

        if (($shapeError = $this->shapeError($validated['thresholds'])) !== null) {
            return response()->json(['message' => $shapeError], 422);
        }

        $before = Thresholds::all($brand);

        DB::transaction(function () use ($validated, $brand): void {
            foreach ($validated['thresholds'] as $key => $value) {
                if ($value === null) {
                    PlatformThreshold::query()
                        ->when($brand === null, fn ($q) => $q->whereNull('brand'))
                        ->when($brand !== null, fn ($q) => $q->where('brand', $brand))
                        ->where('key', $key)->delete();

                    continue;
                }

                PlatformThreshold::updateOrCreate(
                    ['brand' => $brand, 'key' => $key],
                    ['value' => Thresholds::wrap($value)],
                );
            }
        });

        Thresholds::bust($brand);

        $after = Thresholds::all($brand);

        $changed = [];
        foreach ($validated['thresholds'] as $key => $_) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                $changed[$key] = ['from' => $before[$key] ?? null, 'to' => $after[$key] ?? null];
            }
        }

        if ($changed !== []) {
            AuditLogger::record(
                action: 'platform.thresholds.changed',
                data: ['scope' => $brand ?? 'platform', 'changed' => $changed],
                subject: ['type' => 'platform_thresholds', 'id' => $brand ?? 'platform'],
            );
        }

        return response()->json(['brand' => $brand, 'thresholds' => $after, 'changed' => $changed]);
    }

    /**
     * A shaped threshold must stay shaped. Sending a bare number for
     * driver_login_burst would merge into nothing and silently disable the
     * window, so the shape is checked against the shipped default.
     */
    private function shapeError(array $incoming): ?string
    {
        $defaults = (array) config('platform.thresholds.defaults', []);

        foreach ($incoming as $key => $value) {
            if ($value === null) {
                continue;
            }

            $default = $defaults[$key] ?? null;

            if (is_array($default) && ! is_array($value)) {
                return "Threshold '{$key}' expects an object with keys: ".implode(', ', array_keys($default));
            }
            if (! is_array($default) && is_array($value)) {
                return "Threshold '{$key}' expects a single value, not an object.";
            }
            if (is_array($default) && ($bad = array_diff(array_keys($value), array_keys($default))) !== []) {
                return "Threshold '{$key}' has unknown field(s): ".implode(', ', $bad);
            }
            if (! is_array($value) && ! is_numeric($value)) {
                return "Threshold '{$key}' must be numeric.";
            }
            foreach (is_array($value) ? $value : [] as $field => $inner) {
                if (! is_numeric($inner)) {
                    return "Threshold '{$key}.{$field}' must be numeric.";
                }
            }
        }

        return null;
    }

    private function brandExists(string $brand): bool
    {
        return app(BrandRegistry::class)->get($brand) !== null;
    }
}
