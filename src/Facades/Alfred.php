<?php

namespace Atoria\Alfred\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool enabled()
 * @method static bool canUse(?\Illuminate\Contracts\Auth\Authenticatable $user)
 * @method static bool canVerify(?\Illuminate\Contracts\Auth\Authenticatable $user)
 * @method static bool canViewRecords(?\Illuminate\Contracts\Auth\Authenticatable $user)
 * @method static \Illuminate\Support\HtmlString scriptTag(\Illuminate\Http\Request $request, ?string $token = null)
 *
 * @see \Atoria\Alfred\Alfred
 */
class Alfred extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Atoria\Alfred\Alfred::class;
    }
}
