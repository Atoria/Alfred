<?php

namespace Atoria\Alfred\Agent\Tools;

use Atoria\Alfred\Agent\AgentState;
use Atoria\Alfred\Security\SecretScrubber;

/**
 * Terminal tool: the agent's structured answer, in two parts.
 *
 *  - business: for someone who has never seen the code. Checked for code
 *    jargon (class names, methods, files, tables, SQL); if any slips in,
 *    the submission goes back to the agent to rewrite.
 *  - developer: for engineers — entry point, flow with file:line, queries,
 *    caching, edge cases.
 */
class SubmitExplanation implements Tool
{
    public const NAME = 'submit_explanation';

    protected int $rejections = 0;

    public function __construct(protected int $maxRejections = 2) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Submit the final explanation: a business part for non-technical users and a developer part for engineers. Call once you understand how the value is produced (or have concluded you cannot tell).';
    }

    public function parameters(): array
    {
        $strings = fn (string $d) => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => $d];

        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'What this is, in a few plain words, e.g. "Monthly conversion rate"'],
                'summary' => ['type' => 'string', 'description' => '2–3 plain sentences: what question this answers for the business and what the number means'],
                'formula' => ['type' => ['string', 'null'], 'description' => 'Plain-language formula, e.g. "Orders ÷ unique visitors × 100", or null if not a calculation'],
                'example' => ['type' => ['string', 'null'], 'description' => 'A short worked example with simple made-up numbers that shows how to read it, e.g. "If 200 people visited and 10 ordered, the rate is 5%." Null if not useful'],
                'steps' => $strings('2–6 plain steps of how it is worked out, in business terms: who/what is counted, over which period, what is left out'),
                'data_sources' => $strings('Where the data comes from, in business words (e.g. "Orders placed in the shop", "Visits from the analytics import")'),
                'caveats' => $strings('Things a reader could misread: exclusions, timing, refresh delays, manual inputs, what an empty value means'),
                'glossary' => $strings('Terms shown on screen that need explaining, as "Term — meaning", e.g. "OOH — out of hours: weekday evenings, nights and weekends"'),
                'developer' => [
                    'type' => 'object',
                    'description' => 'For engineers. Code names, files and SQL belong HERE, not in the fields above.',
                    'properties' => [
                        'summary' => ['type' => 'string', 'description' => 'Where the value is computed and how, in 1–3 sentences (class::method names welcome)'],
                        'flow' => $strings('Ordered steps with file:line references, from the page entry point to the value'),
                        'queries' => $strings('The queries/scopes involved and what they filter on (tables, columns, conditions)'),
                        'caching' => ['type' => ['string', 'null'], 'description' => 'Caching / refresh behaviour (keys, TTLs, invalidation), or null'],
                        'gotchas' => $strings('Edge cases, surprising rules, places a change would need to be made'),
                    ],
                    'required' => ['summary', 'flow', 'queries', 'caching', 'gotchas'],
                    'additionalProperties' => false,
                ],
                'query_index' => ['type' => ['integer', 'null'], 'description' => 'Index (from the page context) of the recorded query whose rows make up this value, or null if none applies'],
                'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
            ],
            'required' => ['title', 'summary', 'formula', 'example', 'steps', 'data_sources', 'caveats', 'glossary', 'developer', 'query_index', 'confidence'],
            'additionalProperties' => false,
        ];
    }

    public function strict(): bool
    {
        return true;
    }

    public function handle(array $arguments, AgentState $state): mixed
    {
        $list = fn ($v) => array_values(array_filter(array_map(fn ($s) => trim((string) $s), (array) $v), fn ($s) => $s !== ''));
        $text = fn ($v) => isset($v) && trim((string) $v) !== '' ? trim((string) $v) : null;

        $business = [
            'title' => mb_substr(trim((string) ($arguments['title'] ?? 'Explanation')), 0, 200) ?: 'Explanation',
            'summary' => trim((string) ($arguments['summary'] ?? '')),
            'formula' => $text($arguments['formula'] ?? null),
            'example' => $text($arguments['example'] ?? null),
            'steps' => $list($arguments['steps'] ?? []),
            'data_sources' => $list($arguments['data_sources'] ?? []),
            'caveats' => $list($arguments['caveats'] ?? []),
            'glossary' => $list($arguments['glossary'] ?? []),
        ];

        // Keep the business part free of code — that's the whole point of it.
        $jargon = self::jargon($business);
        if ($jargon !== [] && $this->rejections < $this->maxRejections) {
            $this->rejections++;

            return 'Not accepted: the business part (title, summary, formula, example, steps, data_sources, caveats, glossary) must be readable by someone who has never seen the code, but it contains: '
                .implode(', ', array_map(fn ($j) => '"'.$j.'"', array_slice($jargon, 0, 12)))
                .'. Rewrite those parts in plain business language (describe what is counted and why, not which class or table does it) and move code details into "developer". Call submit_explanation again with everything.';
        }

        $dev = (array) ($arguments['developer'] ?? []);

        $state->submission = SecretScrubber::scrubAll($business + [
            'developer' => [
                'summary' => trim((string) ($dev['summary'] ?? '')),
                'flow' => $list($dev['flow'] ?? []),
                'queries' => $list($dev['queries'] ?? []),
                'caching' => $text($dev['caching'] ?? null),
                'gotchas' => $list($dev['gotchas'] ?? []),
            ],
            'query_index' => isset($arguments['query_index']) && is_numeric($arguments['query_index']) ? (int) $arguments['query_index'] : null,
            'confidence' => in_array($arguments['confidence'] ?? null, ['high', 'medium', 'low'], true) ? $arguments['confidence'] : 'medium',
        ]);

        return 'Submitted.';
    }

    /**
     * Code-looking fragments in business text.
     *
     * @param  array<string, mixed>  $business
     * @return string[]
     */
    public static function jargon(array $business): array
    {
        $texts = [];
        array_walk_recursive($business, function ($v) use (&$texts) {
            if (is_string($v)) {
                $texts[] = $v;
            }
        });
        $text = implode("\n", $texts);

        $patterns = [
            '/\b[A-Za-z_]\w*::\w+(\(\))?/u',                         // Class::method
            '/\b[a-z_][A-Za-z0-9_]*\(\)/u',                            // method()
            '/\$[a-zA-Z_]\w*/u',                                       // $variable
            '/\b[\w\/.-]+\.(php|js|ts|vue|sql|blade)\b/u',             // files
            '/\b(?:app|resources|src|routes|database)\/[\w\/.-]+/u',   // paths
            '/\b[A-Z][a-z0-9]+(?:[A-Z][a-z0-9]+){2,}\b/u',             // CamelCase, 3+ words (BookingPerformanceReport)
            '/\b[A-Z][a-z0-9]+(?:Service|Controller|Resource|Widget|Model|Report|Sheet|Job|Query|Builder|Manager|Provider|Repository|Component|Page|Table|Scope|Command|Event|Listener|Policy|Request)\b/u', // OrderService, StatsWidget
            '/\b[a-z]+(?:_[a-z0-9]+){1,}\b/u',                         // snake_case (created_at, weekly_sales_pending)
            '/\b(?:SELECT|INSERT|UPDATE|DELETE|JOIN|WHERE|GROUP BY|CTEs?|SQL|NULL)\b/u',
            '/->\w+/u',                                                // ->method
        ];

        $found = [];
        foreach ($patterns as $p) {
            if (preg_match_all($p, $text, $m)) {
                foreach ($m[0] as $hit) {
                    $found[$hit] = true;
                }
            }
        }

        return array_keys($found);
    }
}
