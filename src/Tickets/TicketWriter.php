<?php

namespace Atoria\Alfred\Tickets;

use Atoria\Alfred\Changes\Change;
use Atoria\Alfred\Context\CodeLocator;
use Atoria\Alfred\Llm\LlmClient;
use Atoria\Alfred\Llm\LlmException;
use Atoria\Alfred\Privacy\Redactor;
use Atoria\Alfred\Security\SecretScrubber;
use Atoria\Alfred\Usage\Usage;
use Illuminate\Support\Str;

/**
 * Writes the ticket's name and body with the AI: one short model call that
 * turns what someone typed (and, for a proposed change, what Alfred changed)
 * into a clear title, a summary, the expected result and acceptance criteria.
 *
 * The requester's own words are always quoted at the end verbatim, so
 * nothing they said is lost in the rewrite. The fixed details (page,
 * environment, files, diff, screenshots) are added by TicketBuilder as before.
 */
class TicketWriter
{
    public const TOOL = 'write_ticket';

    protected const MAX_DIFF = 6000;

    public function __construct(protected LlmClient $llm, protected Redactor $redactor) {}

    public function enabled(): bool
    {
        if (! config('alfred.tickets.ai_draft', true)) {
            return false;
        }

        // OpenAI without a key would only fail; other providers are checked when called.
        return config('alfred.provider', 'openai') !== 'openai' || trim((string) config('alfred.openai.api_key')) !== '';
    }

    /**
     * @return array{title: string, description: string}
     *
     * @throws LlmException
     */
    public function write(Change $change, string $userKey): array
    {
        Usage::ensureBudget($userKey);

        $llm = $this->llm->withReasoningEffort(config('alfred.tickets.reasoning_effort', 'low') ?: null);
        $response = $llm->respond($this->instructions(), [$llm->userMessage($this->brief($change))], [$this->tool()], self::TOOL);
        Usage::record('ticket', $llm->model(), $response->inputTokens, $response->outputTokens, $userKey);

        $args = null;
        foreach ($response->calls as $call) {
            if ($call['name'] === self::TOOL) {
                $args = $call['arguments'];
            }
        }
        $title = trim((string) ($args['title'] ?? ''));
        $body = trim((string) ($args['body'] ?? ''));
        if ($title === '' || $body === '') {
            throw new LlmException('The AI did not write a ticket. Try again, or edit the draft by hand.');
        }

        return [
            'title' => Str::limit(trim(preg_replace('/\s+/', ' ', $title) ?? $title, " \"'“”.#"), 200, '…'),
            'description' => mb_substr($this->finish($change, $body), 0, 19000),
        ];
    }

    protected function instructions(): string
    {
        return <<<'TXT'
You write tickets for a software team's issue tracker (Jira, GitHub, Linear, Trello…). Someone using the app pointed at an element on a page and either reported a problem / asked for something, or had an AI propose a UI change. Turn that into a ticket a developer can pick up without asking questions.

Call write_ticket once with:

title — the ticket name. 4–12 words, at most 80 characters. Specific and scannable in a backlog: say what changes and where (page or element). Start with a verb for requests and changes ("Exclude cancelled orders from Revenue total on Dashboard"), or describe the defect for bugs ("Revenue total on Dashboard includes cancelled orders"). No ticket prefixes like "[Bug]", no quotes, no trailing period. Never just repeat the requester's sentence.

body — the ticket body, in this light Markdown only: "### " headings, "- " bullets, plain paragraphs. No bold, no tables, no links, no code fences. Sections, in order:
1. An opening paragraph of 1–3 sentences: what is wanted and why it matters, in plain language.
2. "### Current behaviour" — what happens now (skip if unknown, e.g. a pure feature request).
3. "### Expected result" — what should happen instead.
4. "### Proposed change" — only when a code change was proposed: what it does, in a sentence or two, and whether it was applied.
5. "### Acceptance criteria" — 2–5 "- " bullets, each concrete and testable.
6. "### Open questions" — only if something genuinely needs deciding (ambiguous scope, missing detail). Skip otherwise.

Rules:
- Use only the facts given. Do not invent numbers, names, causes or code. If the cause is unknown, do not guess one.
- Do not repeat the page URL, environment, file list, diff, screenshots or the requester's quote: they are attached below the body automatically.
- Write in the same language as the requester's words.
- Keep the whole body under about 200 words.
TXT;
    }

    protected function tool(): array
    {
        return [
            'name' => self::TOOL,
            'description' => 'Submit the ticket name and body.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'description' => 'Ticket name, at most 80 characters.'],
                    'body' => ['type' => 'string', 'description' => 'Ticket body in light Markdown (### headings, - bullets).'],
                ],
                'required' => ['title', 'body'],
                'additionalProperties' => false,
            ],
            'strict' => true,
        ];
    }

    /** What the model gets to work from. Element labels go through the privacy redactor; secrets are scrubbed throughout. */
    protected function brief(Change $change): string
    {
        $element = (array) $change->element;
        $isRequest = $change->status === Change::REQUESTED;
        $lines = [
            'Kind: '.($isRequest ? 'report / request (no code change proposed)' : 'UI change proposed by the AI'),
            'Page: '.($this->pagePath($change) ?? 'unknown').($change->route ? " (route {$change->route})" : ''),
        ];

        $what = array_filter([
            ! empty($element['kind']) ? 'kind '.$element['kind'] : null,
            ! empty($element['column']) ? 'column "'.$this->redactor->label((string) $element['column']).'"' : null,
            ($labels = array_filter(array_map(fn ($l) => $this->redactor->label((string) $l), array_slice((array) ($element['labels'] ?? []), 0, 3))))
                ? 'labelled '.implode(' / ', array_map(fn ($l) => '"'.$l.'"', $labels)) : null,
        ]);
        if ($what) {
            $lines[] = 'Element: '.implode(', ', $what);
        }

        $lines[] = '';
        $lines[] = 'Requester\'s words:';
        $lines[] = '"""'.trim($change->instruction).'"""';

        $explanation = (array) (((array) $change->location)['explanation'] ?? []);
        if (! empty($explanation['title'])) {
            $lines[] = '';
            $lines[] = 'How the app explained this element to them: '.$explanation['title'].' — '.($explanation['summary'] ?? '');
            foreach (array_slice((array) ($explanation['followups'] ?? []), 0, 4) as $t) {
                $lines[] = '- They asked: '.Str::limit((string) ($t['question'] ?? ''), 300).' — Answer: '.Str::limit((string) ($t['answer'] ?? ''), 500);
            }
        }

        if (! $isRequest) {
            $lines[] = '';
            if ($change->summary) {
                $lines[] = 'Proposed change: '.$change->summary;
            }
            if ($change->notes) {
                $lines[] = 'AI notes: '.Str::limit(trim($change->notes), 1200);
            }
            $lines[] = 'Status: '.match ($change->status) {
                Change::APPLIED => "applied on {$change->environment} (needs review and bringing into the codebase)",
                Change::REVERTED => "tried on {$change->environment}, then undone",
                Change::DISCARDED => 'proposal discarded',
                default => 'proposed, not applied',
            };
            if ($change->paths()) {
                $lines[] = 'Files: '.implode(', ', array_slice($change->paths(), 0, 10));
            }
            if ($change->diff) {
                $diff = (string) $change->diff;
                $lines[] = "Diff:\n".(strlen($diff) > self::MAX_DIFF ? substr($diff, 0, self::MAX_DIFF)."\n… (truncated)" : $diff);
            }
        } elseif ($where = array_slice(CodeLocator::lines($change->location), 0, 6)) {
            $lines[] = '';
            $lines[] = 'Where it lives in the code (for context only):';
            foreach ($where as $l) {
                $lines[] = '- '.$l;
            }
        }

        return SecretScrubber::scrub(implode("\n", $lines));
    }

    /** The AI's body plus the requester's words, verbatim. */
    protected function finish(Change $change, string $body): string
    {
        $who = $change->requested_by ?: 'the requester';
        $quote = implode("\n", array_map(fn ($l) => '> '.$l, explode("\n", trim($change->instruction))));

        return rtrim($body)."\n\n### Original request\n{$quote}\n— {$who}";
    }

    protected function pagePath(Change $change): ?string
    {
        $path = $change->url ? parse_url($change->url, PHP_URL_PATH) : null;

        return $path ?: null;
    }
}
