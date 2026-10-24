<?php

namespace Atoria\Alfred\Support;

/**
 * Page URLs as Alfred stores them (in changes, tickets, pull requests):
 * without query parameters that may carry secrets or personal data (reset
 * tokens, signed-URL signatures, OAuth codes, API keys, emails), and with
 * token-like path segments (/reset-password/{token}) masked.
 */
class SafeUrl
{
    protected const SENSITIVE = '/(token|signature|expires|code|state|pass(word)?|secret|key|auth|session|sid|otp|nonce|hash|ticket|jwt|credential|e-?mail)/i';

    public const MASK = '…';

    public static function clean(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }

        $base = (string) strtok($url, '?#');
        if (isset($parts['path']) && $parts['path'] !== '') {
            $prefix = substr($base, 0, (int) strrpos($base, $parts['path']));
            $base = $prefix.self::path($parts['path']);
        }
        if (empty($parts['query'])) {
            return $base;
        }

        parse_str($parts['query'], $query);
        $kept = array_filter($query, fn ($v, $k) => ! preg_match(self::SENSITIVE, (string) $k), ARRAY_FILTER_USE_BOTH);

        return $kept === [] ? $base : $base.'?'.http_build_query($kept);
    }

    /** A path with segments that look like tokens or emails masked; ordinary slugs and ids are kept. */
    public static function path(string $path): string
    {
        return implode('/', array_map(function (string $segment): string {
            $plain = rawurldecode($segment);
            // Long, mixed letters and digits, no dashes: a token (slugs have dashes, ids are short).
            $tokenLike = strlen($plain) >= 20 && ! str_contains($plain, '-') && preg_match('/\d/', $plain) && preg_match('/[a-z]/i', $plain);

            return $tokenLike || str_contains($plain, '@') ? self::MASK : $segment;
        }, explode('/', $path)));
    }
}
