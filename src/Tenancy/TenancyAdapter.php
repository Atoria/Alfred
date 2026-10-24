<?php

namespace Atoria\Alfred\Tenancy;

/**
 * How Alfred talks to your multi-tenancy package. Built in: stancl/tenancy
 * and spatie/laravel-multitenancy. Implement this for anything else and set
 * config('alfred.tenancy.driver') to your class.
 */
interface TenancyAdapter
{
    /** Is the package installed and usable? */
    public function available(): bool;

    /** The current tenant's key, or null on central pages. */
    public function current(): ?string;

    /** Switch this request into the given tenant. Throws if it does not exist. */
    public function initialize(string $key): void;

    /** Leave tenant context. */
    public function end(): void;

    /** The connection that holds central (non-tenant) tables, if the package defines one. */
    public function centralConnection(): ?string;

    /**
     * Is this request on a tenant's domain? true / false, or null when the
     * package can't tell from the request alone. On a tenant domain, Alfred's
     * own requests must carry the page's signed tenant reference.
     */
    public function isTenantRequest(\Illuminate\Http\Request $request): ?bool;
}
