<?php

namespace Atoria\Alfred\Tests\Fixtures\Tenancy;

use Atoria\Alfred\Tenancy\TenancyAdapter;
use RuntimeException;

class FakeTenancy implements TenancyAdapter
{
    public static ?string $current = null;

    /** @var string[] */
    public static array $initialized = [];

    public static array $tenants = ['acme', 'globex'];

    public static function reset(): void
    {
        self::$current = null;
        self::$initialized = [];
        self::$tenantDomain = null;
    }

    public function available(): bool
    {
        return true;
    }

    public function current(): ?string
    {
        return self::$current;
    }

    public function initialize(string $key): void
    {
        if (! in_array($key, self::$tenants, true)) {
            throw new RuntimeException("No tenant {$key}");
        }
        self::$current = $key;
        self::$initialized[] = $key;
    }

    public function end(): void
    {
        self::$current = null;
    }

    /** @var bool|null what isTenantRequest() answers */
    public static ?bool $tenantDomain = null;

    public function isTenantRequest(\Illuminate\Http\Request $request): ?bool
    {
        return self::$tenantDomain;
    }

    public function centralConnection(): ?string
    {
        return 'testing';
    }
}
