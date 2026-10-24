<?php

namespace Atoria\Alfred\Explanations;

use Atoria\Alfred\Context\ElementDescriptor;
use Atoria\Alfred\Context\PageContext;
use Atoria\Alfred\Tenancy\Tenancy;

/**
 * Identity of "this thing on this page", shared by every user and every
 * record (within one tenant). The logic behind "Conversion rate" on the
 * dashboard is the same for everyone, so it is explained once and cached.
 * The key covers everything the AI is given about the element.
 */
class ExplanationKey
{
    public static function for(PageContext $page, ElementDescriptor $element): string
    {
        $normalize = fn (?string $s) => $s === null ? '' : mb_strtolower(trim(preg_replace(['/\d+([.,]\d+)?/u', '/\s+/u'], ['#', ' '], $s) ?? ''));

        // Exactly what the AI sees (ElementDescriptor::forExplanation), so two
        // requests share an explanation only if the AI got the same input.
        $element = $element->forExplanation();

        if ($element->explainKey) {
            // The route too: the AI also reads the page (route, views, queries), so a
            // key explained from one page must not become another page's explanation.
            $parts = ['key', $element->explainKey, $page->routeKey(), $element->kind, $element->tag];
        } else {
            $front = $element->frontend;
            $parts = [
                'auto',
                $page->routeKey(),
                implode('|', $element->components),
                $element->kind,
                $element->tag,
                $normalize($element->column),
                implode('|', array_map($normalize, $element->labels)),
                (string) ($front['page'] ?? ''),
                implode('|', (array) ($front['files'] ?? [])),
                implode('|', (array) ($front['components'] ?? [])),
            ];
            // Page values are only sent with privacy redacted/full; then they are part of the input too.
            if (self::privacy() !== 'code_only') {
                $parts[] = $normalize($element->text);
            }
        }

        if (($tenant = self::tenantScope()) !== null) {
            $parts[] = 'tenant:'.$tenant;
        }

        return hash('sha256', implode("\n", $parts));
    }

    protected static function privacy(): string
    {
        try {
            return (string) config('alfred.privacy', 'code_only');
        } catch (\Throwable) {
            return 'code_only'; // no app (unit tests)
        }
    }

    /** The key of an explanation only its author sees (they may not create shared ones). */
    public static function private(string $sharedKey, string $owner): string
    {
        return hash('sha256', $sharedKey."\nprivate\n".$owner);
    }

    /** Is $e the explanation of this element on this page, for this person (shared, or their own private one)? */
    public static function accepts(Explanation $e, PageContext $page, ElementDescriptor $element, string $userKey): bool
    {
        $shared = self::for($page, $element);

        return hash_equals($shared, (string) $e->key)
            || ($e->owner !== null && hash_equals((string) $e->owner, $userKey) && hash_equals(self::private($shared, $userKey), (string) $e->key));
    }

    /**
     * In a multi-tenant app every tenant has its own explanations, always:
     * an explanation is generated from one tenant's page (its labels, its
     * components, its follow-ups) and must never be shown to another.
     */
    protected static function tenantScope(): ?string
    {
        return app(Tenancy::class)->current();
    }
}
