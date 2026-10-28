<?php

namespace App\Payments\Providers;

use InvalidArgumentException;

class ProviderRegistry
{
    /**
     * @return array<string, string> provider key => display name
     */
    public function options(): array
    {
        return collect(config('gateway.providers'))->map(fn (array $provider) => $provider['name'])->all();
    }

    public function has(string $key): bool
    {
        return config("gateway.providers.{$key}") !== null;
    }

    public function make(string $key): QrProvider
    {
        $config = config("gateway.providers.{$key}");

        if ($config === null) {
            throw new InvalidArgumentException("Unknown QR provider [{$key}].");
        }

        return new $config['adapter']($key, $config['rules'] ?? []);
    }
}
