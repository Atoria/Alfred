<?php

namespace Atoria\Alfred\Agent\Tools;

use Atoria\Alfred\Agent\AgentState;
use Atoria\Alfred\Changes\EditApplier;
use Atoria\Alfred\Changes\MatchProblem;
use Atoria\Alfred\Changes\TextMatcher;
use Atoria\Alfred\Security\AccessDenied;
use Atoria\Alfred\Security\PathGuard;
use Atoria\Alfred\Security\SecretScrubber;
use Illuminate\Support\Facades\Blade;
use ParseError;
use Throwable;

/**
 * Terminal tool for UI changes. Edits are exact search/replace blocks on
 * files the agent has read in this run. Every edit is validated before the
 * proposal is accepted — a bad one goes back to the model to fix, so what
 * reaches the user always applies cleanly and still parses.
 */
class ProposeEdit implements Tool
{
    public const NAME = 'propose_edit';

    /** Escaped-HTML warnings are given once; a second identical submission is accepted (the agent may know better). */
    protected bool $warnedAboutHtml = false;

    public function __construct(protected PathGuard $editable, protected int $maxFiles = 5, protected int $maxSearchLines = 60) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Propose the code change. Each edit replaces an exact, unique snippet of a file you have read with read_file (copy it verbatim WITHOUT the line-number prefix). Keep each snippet small: the lines you change plus just enough context to be unique — never a whole file. Use an empty edits list with notes if the change cannot or should not be made.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string', 'description' => 'One short line describing the change, e.g. "Align the Booked stat to the left"'],
                'notes' => ['type' => ['string', 'null'], 'description' => 'Anything the user should know: side effects, other places affected, why it could not be done'],
                'edits' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => ['type' => 'string', 'description' => 'File path relative to the project root'],
                            'search' => ['type' => 'string', 'description' => 'Exact existing text to replace (must occur exactly once)'],
                            'replace' => ['type' => 'string', 'description' => 'New text'],
                        ],
                        'required' => ['path', 'search', 'replace'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['summary', 'notes', 'edits'],
            'additionalProperties' => false,
        ];
    }

    public function strict(): bool
    {
        return true;
    }

    public function handle(array $arguments, AgentState $state): mixed
    {
        $edits = array_values(array_filter((array) ($arguments['edits'] ?? []), 'is_array'));
        $summary = mb_substr(trim((string) ($arguments['summary'] ?? '')), 0, 250) ?: 'UI change';
        $notes = isset($arguments['notes']) && trim((string) $arguments['notes']) !== '' ? trim((string) $arguments['notes']) : null;

        /** @var array<string, array{abs: string, before: string, after: string}> $files */
        $files = [];
        $problems = [];
        $normalized = []; // edits rewritten to the file's exact text

        foreach ($edits as $n => $edit) {
            $label = 'Edit '.($n + 1);
            $path = (string) ($edit['path'] ?? '');
            $search = (string) ($edit['search'] ?? '');
            $replace = (string) ($edit['replace'] ?? '');

            try {
                $abs = $this->editable->file($path);
            } catch (AccessDenied $e) {
                $problems[] = "{$label}: {$path} cannot be changed ({$e->getMessage()}). Editable: ".implode(', ', array_map(fn ($r) => $this->editable->relative($r).'/', $this->editable->roots())).'.';

                continue;
            }

            $relative = $this->editable->relative($abs);

            if (! isset($state->files[$relative])) {
                $problems[] = "{$label}: read {$relative} with read_file before editing it.";

                continue;
            }

            if (! isset($files[$relative]) && count($files) >= $this->maxFiles) {
                $problems[] = "{$label}: a change may touch at most {$this->maxFiles} files.";

                continue;
            }

            $files[$relative] ??= ['abs' => $abs, 'before' => (string) file_get_contents($abs), 'after' => (string) file_get_contents($abs)];
            $current = $files[$relative]['after'];

            if ($search === '') {
                $problems[] = "{$label}: search is empty.";

                continue;
            }
            if ($this->maxSearchLines > 0 && substr_count($search, "\n") + 1 > $this->maxSearchLines) {
                $problems[] = "{$label}: the search text is ".(substr_count($search, "\n") + 1)." lines long. Target only the few lines you change (plus enough context to be unique, at most {$this->maxSearchLines} lines) — split it into several edits if needed.";

                continue;
            }
            if ($search === $replace) {
                $problems[] = "{$label}: search and replace are identical.";

                continue;
            }

            try {
                $placed = (new TextMatcher)->locate($current, $search, $replace);
            } catch (MatchProblem $e) {
                $problems[] = "{$label} ({$relative}): ".$e->getMessage();

                continue;
            }

            if ($placed['search'] === $placed['replace']) {
                $problems[] = "{$label}: the replacement is the same as the existing text.";

                continue;
            }

            $search = $placed['search'];
            $replace = $placed['replace'];
            $normalized[] = ['path' => $relative, 'search' => $search, 'replace' => $replace];
            // The same applier preview, apply and pull requests use, so the stored result is what they produce.
            $files[$relative]['after'] = EditApplier::apply($current, [['search' => $search, 'replace' => $replace]]);
        }

        foreach ($files as $relative => $file) {
            if (($error = $this->syntaxError($relative, $file['after'])) !== null) {
                $problems[] = "{$relative} would not parse after the change: {$error}";
            }
        }

        if ($problems !== []) {
            return "Not accepted — fix these and call propose_edit again with the complete set of edits:\n- ".implode("\n- ", $problems);
        }

        if (! $this->warnedAboutHtml && ($escaped = self::escapedHtml($files)) !== []) {
            $this->warnedAboutHtml = true;

            return "Not accepted — this would show HTML tags as text on the page:\n- ".implode("\n- ", $escaped)
                ."\nFilament, Livewire and Blade's {{ }} escape strings, so markup inside a PHP string or {{ }} appears literally (e.g. '<span class=\"text-red-600\">0%</span>' is shown as that text)."
                ."\nUse the framework's own styling instead: Filament stats/columns/badges have ->color(), ->descriptionColor(), ->iconColor(), ->badge(), ->weight(), ->extraAttributes(['class' => …]) (pass a closure or a conditional for value-dependent styling, e.g. ->descriptionColor(\$rate === 0 ? 'danger' : 'success')); in Blade put the class on the element."
                ."\nOnly if real markup is unavoidable, wrap it in new \\Illuminate\\Support\\HtmlString(...) and escape dynamic values with e(). Then call propose_edit again.";
        }

        $state->submission = [
            'summary' => SecretScrubber::scrub($summary),
            'notes' => $notes !== null ? SecretScrubber::scrub($notes) : null,
            'edits' => $normalized,
            'files' => array_map(fn ($f) => ['before' => $f['before'], 'after' => $f['after']], $files),
        ];

        return 'Proposed.';
    }

    /**
     * Markup the change adds where it will be escaped: in a PHP string (not
     * wrapped in HtmlString) or inside Blade's {{ }}.
     *
     * @param  array<string, array{before: string, after: string}>  $files
     * @return string[]
     */
    public static function escapedHtml(array $files): array
    {
        $tag = '/<\s*\/?\s*[a-z][a-z0-9-]*(\s[^<>]*)?>/i';
        $found = [];

        foreach ($files as $path => $file) {
            if (str_ends_with($path, '.blade.php')) {
                $echoes = fn (string $code) => preg_match_all('/\{\{(?!--)(.*?)\}\}/s', $code, $m) ? array_values(array_filter($m[1], fn ($e) => preg_match($tag, $e) === 1)) : [];
                foreach (array_diff($echoes($file['after']), $echoes($file['before'])) as $echo) {
                    $found[] = "{$path}: {{".trim(mb_substr($echo, 0, 120)).'}} escapes the tags inside it';
                }

                continue;
            }
            if (! str_ends_with($path, '.php') || str_contains($file['after'], 'HtmlString') && ! str_contains($file['before'], 'HtmlString')) {
                continue; // markup deliberately wrapped as HTML
            }

            $strings = function (string $code) use ($tag): array {
                $out = [];
                try {
                    foreach (token_get_all($code) as $t) {
                        if (is_array($t) && in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) && preg_match($tag, $t[1]) === 1) {
                            $out[] = $t[1];
                        }
                    }
                } catch (Throwable) {
                }

                return $out;
            };
            foreach (array_diff($strings($file['after']), $strings($file['before'])) as $literal) {
                $found[] = "{$path}: ".mb_substr(trim($literal), 0, 120);
            }
        }

        return array_values(array_unique($found));
    }

    protected function syntaxError(string $path, string $code): ?string
    {
        try {
            if (str_ends_with($path, '.blade.php')) {
                token_get_all(Blade::compileString($code), TOKEN_PARSE); // @phpstan-ignore function.resultUnused (throws ParseError)
            } elseif (str_ends_with($path, '.php')) {
                token_get_all($code, TOKEN_PARSE); // @phpstan-ignore function.resultUnused (throws ParseError)
            }
        } catch (ParseError $e) {
            return $e->getMessage().' (line '.$e->getLine().')';
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }
}
