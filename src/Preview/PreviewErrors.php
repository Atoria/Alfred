<?php

namespace Atoria\Alfred\Preview;

use Illuminate\Support\Facades\Cache;
use Throwable;

/** The last error a preview of a change ran into, so "Refine" can hand it to the agent. Kept a day. */
class PreviewErrors
{
    public static function put(int $changeId, string $error): void
    {
        try {
            Cache::store()->put(self::key($changeId), mb_substr($error, 0, 1500), 86400);
        } catch (Throwable) {
        }
    }

    public static function get(int $changeId): ?string
    {
        try {
            $error = Cache::store()->get(self::key($changeId));
        } catch (Throwable) {
            return null;
        }

        return is_string($error) && $error !== '' ? $error : null;
    }

    protected static function key(int $changeId): string
    {
        return 'alfred:preview-error:'.$changeId;
    }
}
