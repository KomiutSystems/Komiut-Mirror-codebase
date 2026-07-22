<?php

declare(strict_types=1);

namespace App\Brands;

final readonly class Brand
{
    /**
     * @param  array<string, bool>  $features
     */
    public function __construct(
        public string $key,
        public string $name,
        public array $features = [],
        public ?string $sessionCookie = null,
        public ?string $sessionDomain = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $key, array $config): self
    {
        $session = is_array($config['session'] ?? null) ? $config['session'] : [];

        return new self(
            key: $key,
            name: (string) ($config['name'] ?? $key),
            features: array_map(
                static fn (mixed $enabled): bool => (bool) $enabled,
                is_array($config['features'] ?? null) ? $config['features'] : [],
            ),
            sessionCookie: isset($session['cookie']) ? (string) $session['cookie'] : null,
            sessionDomain: isset($session['domain']) ? (string) $session['domain'] : null,
        );
    }

    public function has(Feature $feature): bool
    {
        return (bool) ($this->features[$feature->value] ?? false);
    }
}
