<?php

namespace Atoria\Alfred\Tenancy;

/** stancl/tenancy v3+. */
class StanclTenancy implements TenancyAdapter
{
    public function available(): bool
    {
        return function_exists('tenancy') && class_exists(\Stancl\Tenancy\Tenancy::class);
    }

    public function current(): ?string
    {
        $tenancy = tenancy();
        if (! $tenancy->initialized || ! $tenancy->tenant) {
            return null;
        }

        return (string) $tenancy->tenant->getTenantKey();
    }

    public function initialize(string $key): void
    {
        if ($this->current() === $key) {
            return;
        }
        tenancy()->initialize($key);
    }

    public function end(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }

    public function isTenantRequest(\Illuminate\Http\Request $request): ?bool
    {
        $central = array_map('strtolower', (array) config('tenancy.central_domains', []));
        if ($central === []) {
            return null;
        }

        return ! in_array(strtolower($request->getHost()), $central, true);
    }

    public function centralConnection(): ?string
    {
        return config('tenancy.database.central_connection') ?: null;
    }
}
