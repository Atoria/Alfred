<?php

namespace Atoria\Alfred\Security;

/**
 * Removes things that look like secrets from text, both before it is sent
 * to the AI (file contents, search results) and before AI output is stored
 * or shown. The path guard keeps secret *files* away; this catches secrets
 * hard-coded into ordinary files.
 */
class SecretScrubber
{
    public const MASK = '[secret removed]';

    protected const PATTERNS = [
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s',
        '/\bsk-(?:proj-|ant-|live-|test-)?[A-Za-z0-9_-]{20,}\b/',      // OpenAI, Anthropic, Stripe
        '/\b(?:sk|rk|pk|whsec)_(?:live|test)_[A-Za-z0-9]{16,}\b/',       // Stripe
        '/\bAKIA[0-9A-Z]{16}\b/',                                       // AWS access key id
        '/\bAIza[0-9A-Za-z_-]{35}\b/',                                  // Google API key
        '/\b(?:ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{30,}\b/',               // GitHub
        '/\bgithub_pat_[A-Za-z0-9_]{40,}\b/',
        '/\bglpat-[A-Za-z0-9_-]{20,}\b/',                               // GitLab
        '/\bxox[abprs]-[A-Za-z0-9-]{10,}\b/',                           // Slack
        '/\bATATT[A-Za-z0-9_=-]{20,}\b/',                               // Atlassian API token
        '/\beyJ[A-Za-z0-9_-]{10,}\.eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/', // JWT
        '/\bbase64:[A-Za-z0-9+\/]{40,}={0,2}/',                         // Laravel APP_KEY
        '/(?<=[\'"])(?=[A-Za-z0-9+\/_-]*\d)(?=[A-Za-z0-9+\/_-]*[a-z])(?=[A-Za-z0-9+\/_-]*[A-Z])[A-Za-z0-9+\/_-]{32,}(?=[\'"])/', // long random-looking quoted strings
    ];

    /** password = '…', 'secret' => "…", API_KEY: … */
    protected const ASSIGNMENT = '/((?:password|passwd|pwd|secret|api[_-]?key|access[_-]?token|auth[_-]?token|client[_-]?secret|private[_-]?key)[\'"]?\s*(?:=>|=|:)\s*)([\'"])(?!\s*\2)[^\'"\n]{4,}\2/i';

    public static function scrub(string $text): string
    {
        $text = preg_replace(self::PATTERNS, self::MASK, $text) ?? $text;

        return preg_replace(self::ASSIGNMENT, '$1$2'.self::MASK.'$2', $text) ?? $text;
    }

    /** Scrub every string in a nested array. */
    public static function scrubAll(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::scrub($value);
        }
        if (is_array($value)) {
            return array_map([self::class, 'scrubAll'], $value);
        }

        return $value;
    }
}
