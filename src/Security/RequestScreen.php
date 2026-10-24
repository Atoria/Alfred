<?php

namespace Atoria\Alfred\Security;

use Atoria\Alfred\Alfred;

/**
 * Checks text a person types for the AI (follow-up questions, change
 * requests, refine feedback) before any AI sees it. Blocks attempts to:
 *  - get another tenant's / workspace's data, or another person's data or
 *    view ("what does anna@… see", "log in as …", "pretend to be …");
 *  - get secrets, credentials, environment values or Alfred's own
 *    instructions;
 *  - override the rules ("ignore previous instructions", "you are now …").
 *
 * The patterns are deliberately narrow (high precision): ordinary business
 * questions — including about other customers, clients or trusts that are
 * simply data in the app — pass. Behind this sit the agents' own rules,
 * tenant-scoped storage and secret scrubbing.
 */
class RequestScreen
{
    /** @var array<string, array<int, string>> category => patterns */
    protected const PATTERNS = [
        'instructions' => [
            '/\b(ignore|disregard|forget|override|bypass)\b.{0,30}\b(previous|prior|above|earlier|all|any|your|these|the)\b.{0,20}\b(instructions?|rules?|prompts?|guidelines|restrictions?|directives?)\b/i',
            '/\b(system|developer|hidden|original|initial)\s+(prompt|instructions?|message)\b/i',
            '/\b(reveal|show|print|repeat|output|tell me|give me|what (are|is|were))\b.{0,30}\b(your|the)\s+(instructions?|rules|prompt|guidelines)\b/i',
            '/\b(jailbreak|DAN mode|developer mode|god mode)\b/i',
            '/\byou are now\b.{0,40}\b(admin|administrator|root|unrestricted|developer|system|another|a different)\b/i',
        ],
        'secrets' => [
            '/\b(show|give|reveal|print|dump|list|tell me|what(\'s| is| are)|read|cat|display|leak|send)\b.{0,60}(\.env\b|\benv(ironment)? (file|variables?|values?)\b|\bAPP_KEY\b|\bapi[\s_-]?keys?\b|\bsecret(s| key| keys)?\b|\bpasswords?\b|\bcredentials?\b|\bprivate keys?\b|\baccess tokens?\b|\bbearer tokens?\b|\bdatabase (password|credentials)\b)/i',
        ],
        'other_tenant' => [
            '/\b(another|other|different|every|all|each|any)\s+(tenant|workspace)s?(\'s|\')?\b/i',
            '/\b(tenant|workspace)\s*(id|#|number|no\.?)?\s*[:=#]?\s*[\w-]+\b.{0,40}\b(data|records|users?|details|info(rmation)?|explanations?|changes?|bookings?|rows)\b/i',
            '/\b(data|records|users?|details|info(rmation)?|explanations?|changes?|bookings?|rows)\b.{0,40}\b(tenant|workspace)\s*(id|#|number|no\.?)?\s*[:=#]?\s*[\w-]*\d[\w-]*\b/i',
            '/\b(switch|change|move|jump)\b.{0,20}\b(to|into)\b.{0,20}\b(another|other|different)\s+(tenant|workspace)\b/i',
        ],
        'other_person' => [
            '/\b(log\s*in|sign\s*in|logged\s*in|signed\s*in)\s+as\s+(?!a\s+(user|visitor|customer|guest)\b)\S+/i',
            '/\b(act|pose|pretend|masquerade)\b.{0,10}\b(as|to be)\s+(an?\s+|the\s+)?([\w.+-]+@[\w-]+\.[\w.]+|admin\w*|root|super\s?user|owner|another (user|person|employee)|someone else|user\s*#?\s*\d+)\b/i',
            '/\bimpersonat(e|ing|ion)\b/i',
            '/\b(what|which|how much)\b.{0,40}\b(does|do|can|did)\s+(?:[\w.+-]+@[\w-]+\.[\w.]+|user\s*#?\s*\d+|someone else|another (user|person|employee|colleague))\s+(see|view|have access|access)\b/i',
            '/\b(show|give|tell|list|read)\b.{0,20}\b(me)?\b.{0,20}\b(another|other|someone else\'s|a different)\s+(user|person|employee|colleague|account)(\'s|s\'|s)?\s+(data|details|view|screen|session|account|password|emails?|messages?|explanations?|changes?|requests?|questions?)\b/i',
            '/\b(session|cookie|token)s?\s+of\s+(another|other|a different)\s+(user|person|account)\b/i',
        ],
    ];

    protected const REASONS = [
        'instructions' => 'it tries to change how %s works',
        'secrets' => 'it asks for secrets or credentials',
        'other_tenant' => 'it asks about another workspace',
        'other_person' => 'it asks to see or act as another person',
    ];

    /** @return array{category: string, reason: string, match: string}|null */
    public function check(string $text): ?array
    {
        if (! (bool) config('alfred.screening.enabled', true)) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', $text) ?? $text;

        foreach (self::PATTERNS as $category => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $normalized, $m) === 1) {
                    return [
                        'category' => $category,
                        'reason' => sprintf(self::REASONS[$category], Alfred::name()),
                        'match' => mb_substr($m[0], 0, 120),
                    ];
                }
            }
        }

        return null;
    }
}
