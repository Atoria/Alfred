<?php

namespace Atoria\Alfred\Tenancy;

use Illuminate\Http\Request;
use RuntimeException;
use Spatie\Multitenancy\Models\Tenant;

/** spatie/laravel-multitenancy v3+. */
class SpatieTenancy implements TenancyAdapter
{
    public function available(): bool
    {
        return class_exists(Tenant::class);
    }

    public function current(): ?string
    {
        $tenant = $this->model()::current();

        return $tenant ? (string) $tenant->getKey() : null;
    }

    public function initialize(string $key): void
    {
        if ($this->current() === $key) {
            return;
        }
        $tenant = $this->model()::query()->find($key);
        if (! $tenant) {
            throw new RuntimeException("Tenant [{$key}] not found.");
        }
        $tenant->makeCurrent();
    }

    public function end(): void
    {
        $this->model()::forgetCurrent();
    }

    /** Spatie identifies the tenant itself, early in the request: Alfred then checks it matches the page's. */
    public function isTenantRequest(Request $request): ?bool
    {
        return null;
    }

    public function centralConnection(): ?string
    {
        return config('multitenancy.landlord_database_connection_name') ?: null;
    }

    /** @return class-string<Tenant> */
    protected function model(): string
    {
        return config('multitenancy.tenant_model') ?: Tenant::class;
    }
}
