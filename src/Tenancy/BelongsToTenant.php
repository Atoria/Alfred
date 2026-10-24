<?php

namespace Atoria\Alfred\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Every Alfred record belongs to the tenant it was created in, and is only
 * ever found from that tenant: queries, route-model binding and lookups by
 * id are all filtered. Central (non-tenant) pages only see central records.
 * In apps without a tenancy package nothing changes (tenant stays null).
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('alfred_tenant', function (Builder $query) {
            $tenancy = app(Tenancy::class);
            if (! $tenancy->adapter()) {
                return;
            }

            $column = $query->getModel()->qualifyColumn('tenant');
            ($tenant = $tenancy->current()) !== null
                ? $query->where($column, $tenant)
                : $query->whereNull($column);
        });

        static::creating(function (Model $model) {
            if ($model->getAttribute('tenant') === null) {
                $model->setAttribute('tenant', app(Tenancy::class)->current());
            }
        });
    }
}
