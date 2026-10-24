<?php

namespace Atoria\Alfred\Tests\Fixtures\Tenancy;

use BadMethodCallException;
use Illuminate\Cache\CacheManager;

/** Like stancl's CacheTenancyBootstrapper: facade calls in a tenant get tagged, which "database"/"file" stores can't do. */
class TaggingCacheManager extends CacheManager
{
    public function __call($method, $parameters)
    {
        if (FakeTenancy::$current !== null) {
            throw new BadMethodCallException('This cache store does not support tagging.');
        }

        return parent::__call($method, $parameters);
    }
}
