<?php

declare(strict_types=1);

namespace App\Brands;

/**
 * Resolves the brand for the current request.
 *
 * Resolution always fails closed: an unknown host or app key returns null so
 * the caller can abort. Falling back to a default brand would mean serving one
 * brand's data from another brand's database.
 */
final readonly class BrandRegistry
{
    /** @var array<string, Brand> */
    private array $brands;

    /** @var array<string, string> hostname => brand key */
    private array $hostIndex;

    /** @var array<string, string> app key => brand key */
    private array $appKeyIndex;

    /**
     * @param  array<string, array<string, mixed>>  $config
     */
    public function __construct(array $config)
    {
        $brands = [];
        $hosts = [];
        $appKeys = [];

        foreach ($config as $key => $definition) {
            $brands[$key] = Brand::fromConfig($key, $definition);

            foreach ((array) ($definition['hosts'] ?? []) as $host) {
                $normalised = self::normaliseHost((string) $host);

                if ($normalised !== null) {
                    $hosts[$normalised] = $key;
                }
            }

            $appKey = $definition['app_key'] ?? null;

            if (is_string($appKey) && $appKey !== '') {
                $appKeys[$appKey] = $key;
            }
        }

        $this->brands = $brands;
        $this->hostIndex = $hosts;
        $this->appKeyIndex = $appKeys;
    }

    /** @return array<string, Brand> */
    public function all(): array
    {
        return $this->brands;
    }

    public function get(string $key): ?Brand
    {
        return $this->brands[$key] ?? null;
    }

    public function resolveByHost(?string $host): ?Brand
    {
        $normalised = self::normaliseHost($host);

        if ($normalised === null) {
            return null;
        }

        $key = $this->hostIndex[$normalised] ?? null;

        return $key === null ? null : $this->brands[$key];
    }

    public function resolveByAppKey(?string $appKey): ?Brand
    {
        if ($appKey === null || $appKey === '') {
            return null;
        }

        foreach ($this->appKeyIndex as $candidate => $key) {
            if (hash_equals($candidate, $appKey)) {
                return $this->brands[$key];
            }
        }

        return null;
    }

    /**
     * Lowercases and strips any port, so `Portal.Komiut.CO.KE:8080` matches
     * a configured `portal.komiut.co.ke`.
     */
    private static function normaliseHost(?string $host): ?string
    {
        if ($host === null) {
            return null;
        }

        $host = strtolower(trim($host));

        if (str_contains($host, ':')) {
            $host = strstr($host, ':', true) ?: $host;
        }

        return $host === '' ? null : $host;
    }
}
