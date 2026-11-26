<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Scopes\BrandScope;
use App\Models\Vehicle;
use App\Services\Sql\PlateSql;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Which bus a scanned sticker names.
 *
 * Only our own signed code (QrTokenService) used to be accepted, so every
 * sticker printed by the old system -- or signed with a key the server has
 * since replaced -- came back "Invalid or tampered QR code" and the passenger
 * never reached the payment: six QR payments ever, none since 28 Sep 2026.
 * A sticker now resolves when it is:
 *
 *   signed  our code, verified against the signing key and every older key;
 *   a till  the bus's till number (or its store number), digits only;
 *   a plate the bus's plate, written any way ("KDA 123A", "kda-123a");
 *   a link  a URL carrying any of the above in its query or last path part.
 *
 * Unsigned forms are exactly as trustworthy as the till number printed beside
 * the sticker, which a passenger can already type in (qrcode/vehicle): they
 * can only name a bus that exists, and the fare goes to that bus's own till.
 * A match on two buses resolves to nothing rather than a guess.
 *
 * ANY BRAND. A sticker names the bus it is stuck to, whichever of our apps
 * scans it: a passenger in the Komiut app standing in a 2Safiri bus is paying
 * that bus. So the brand wall is not applied here; the caller then runs the
 * payment under the bus's own brand (BusBrand::adopt). Every other scope stays.
 */
final class QrStickerResolver
{
    public function __construct(private readonly QrTokenService $tokens) {}

    /** @return array{vehicle: Vehicle, signed: bool}|null */
    public function resolve(string $raw, bool $insideUrl = false): ?array
    {
        $raw = trim($raw);
        if ($raw === '' || strlen($raw) > 2048) {
            return null;
        }

        if (! $insideUrl && preg_match('#^https?://#i', $raw) === 1) {
            foreach ($this->fromUrl($raw) as $candidate) {
                if (($found = $this->resolve($candidate, true)) !== null) {
                    return $found;
                }
            }

            return null;
        }

        if (str_contains($raw, '.')) {
            $claims = $this->tokens->validate($raw);
            $vehicle = empty($claims['vehicle_id']) ? null : self::vehicles()->find((int) $claims['vehicle_id']);

            return $vehicle === null ? null : ['vehicle' => $vehicle, 'signed' => true];
        }

        if (preg_match('/^\d{5,10}$/', $raw) === 1) {
            return $this->unique(self::vehicles()->where('till_number', $raw)->limit(2)->get())
                ?? $this->unique(self::vehicles()->where('merchant_short_code', $raw)->limit(2)->get());
        }

        $plate = PlateSql::normalise($raw);
        if (preg_match('/^[A-Z]{2,4}\d{1,4}[A-Z]{0,2}$/', $plate) === 1) {
            return $this->unique(self::vehicles()->whereRaw(PlateSql::normaliseColumn('plate').' = ?', [$plate])->limit(2)->get());
        }

        return null;
    }

    /**
     * Buses of every brand -- see ANY BRAND above.
     *
     * @return Builder<Vehicle>
     */
    public static function vehicles(): Builder
    {
        return Vehicle::withoutGlobalScope(BrandScope::class);
    }

    /** @param  Collection<int, Vehicle>  $matches */
    private function unique(Collection $matches): ?array
    {
        return $matches->count() === 1 ? ['vehicle' => $matches->first(), 'signed' => false] : null;
    }

    /** @return list<string>  the URL's query values, then its last path segment */
    private function fromUrl(string $url): array
    {
        $parts = parse_url($url) ?: [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        $out = [];
        foreach (['token', 't', 'qr', 'code', 'till', 'till_number', 'plate', 'v'] as $key) {
            if (isset($query[$key]) && is_string($query[$key])) {
                $out[] = $query[$key];
            }
        }
        $segments = array_values(array_filter(explode('/', (string) ($parts['path'] ?? ''))));
        if ($segments !== []) {
            $out[] = rawurldecode((string) end($segments));
        }

        return $out;
    }
}
