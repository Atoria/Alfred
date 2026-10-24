<?php

namespace Atoria\Alfred\Security;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A request Alfred refused because it tried to reach another tenant's or
 * person's data, secrets, or to override Alfred's rules.
 *
 * @property int $id
 * @property ?string $tenant
 * @property string $user_key
 * @property ?string $user_label
 * @property string $category
 * @property string $where
 * @property string $excerpt
 * @property ?string $matched
 * @property ?string $route
 * @property ?string $ip_hash
 * @property ?Carbon $created_at
 */
class SecurityEvent extends Model
{
    use \Atoria\Alfred\Tenancy\BelongsToTenant;
    use \Illuminate\Database\Eloquent\MassPrunable;

    public function prunable(): \Illuminate\Database\Eloquent\Builder
    {
        $days = (int) config('alfred.retention.security_events_days', 180);

        return $days <= 0
            ? static::query()->whereRaw('1 = 0')
            : static::query()->withoutGlobalScope('alfred_tenant')->where('created_at', '<', now()->subDays($days));
    }

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public function getTable(): string
    {
        return config('alfred.security_events_table', 'alfred_security_events');
    }

    public function getConnectionName(): ?string
    {
        return \Atoria\Alfred\Alfred::connection() ?? parent::getConnectionName();
    }
}
