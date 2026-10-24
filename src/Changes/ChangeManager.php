<?php

namespace Atoria\Alfred\Changes;

use Atoria\Alfred\Agent\Agent;
use Atoria\Alfred\Agent\AgentState;
use Atoria\Alfred\Agent\PromptBuilder;
use Atoria\Alfred\Agent\Tools\ProposeEdit;
use Atoria\Alfred\Alfred;
use Atoria\Alfred\Context\ElementDescriptor;
use Atoria\Alfred\Context\PageContext;
use Atoria\Alfred\Explanations\TooManyGenerations;
use Atoria\Alfred\Llm\LlmException;
use Atoria\Alfred\Privacy\Redactor;
use Atoria\Alfred\Security\PathGuard;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * "Change this": the agent proposes search/replace edits, we store them with
 * a diff; in write mode they can be applied to disk and undone.
 *
 * Safety rules: only files under alfred.edit.paths, never deny-listed ones;
 * apply refuses if a file changed since the proposal; undo refuses if a file
 * changed since it was applied. Writes are atomic per file, and a failure
 * part-way through rolls back the files already written.
 */
class ChangeManager
{
    public function __construct(
        protected Agent $agent,
        protected PromptBuilder $prompts,
        protected PathGuard $guard,
        protected Redactor $redactor,
        protected DiffBuilder $diffs,
        protected Alfred $alfred,
    ) {}

    public static function editableGuard(): PathGuard
    {
        return new PathGuard(
            config('alfred.base_path') ?: base_path(),
            (array) config('alfred.edit.paths', ['app', 'resources/views']),
            array_merge((array) config('alfred.deny', []), (array) config('alfred.edit.deny', [])),
            (array) config('alfred.extensions', []),
        );
    }

    /** @param  array<string, mixed>|null  $known  the element's explanation and follow-ups, when the change was asked for from an explanation */
    public function propose(PageContext $page, ElementDescriptor $element, string $instruction, string $userKey, ?string $userLabel, ?array $known = null, ?array $revision = null): Change
    {
        $this->throttle($userKey);

        $editable = self::editableGuard();
        $state = new AgentState($this->guard, $page, $this->redactor);
        $base = [
            'user_key' => $userKey,
            'requested_by' => $userLabel,
            'environment' => (string) app()->environment(),
            'route' => mb_substr($page->routeKey(), 0, 255),
            'url' => $page->url,
            'element' => [
                'kind' => $element->kind,
                'tag' => $element->tag,
                'labels' => $element->labels,
                'column' => $element->column,
                'components' => $element->components,
                'selector' => $element->selector,
            ],
            'instruction' => $instruction,
            'location' => app(\Atoria\Alfred\Context\CodeLocator::class)->locate($page, $element) + array_filter([
                'explanation' => \Atoria\Alfred\FollowUps\Known::forTicket($known),
                'refines' => $revision['id'] ?? null,
            ]),
        ];

        try {
            $result = $this->editAgent()->loop(
                $state,
                $this->prompts->editInstructions(),
                $this->prompts->editMessage($page, $element, $instruction, $this->redactor, $this->guard, $editable, $known, $revision),
                [...$this->agent->readTools(), new ProposeEdit($editable, (int) config('alfred.edit.max_files', 5), (int) config('alfred.edit.max_search_lines', 60))],
                ProposeEdit::NAME,
                (int) config('alfred.edit.max_steps', 16),
            );
        } catch (LlmException $e) {
            // Keep the attempt (and what the agent tried) so it can be looked at.
            return Change::query()->create($base + [
                'summary' => 'Could not prepare this change',
                'status' => Change::FAILED,
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'trace' => $state->trace,
                'edits' => [],
                'files' => [],
                'model' => $this->agent->llm()->model(),
            ]);
        }

        $submission = $result->explanation;
        $files = [];
        $diffInput = [];
        foreach ($submission['files'] as $path => $file) {
            $files[$path] = ['before_sha1' => sha1($file['before']), 'after_sha1' => sha1($file['after'])];
            $diffInput[$path] = [$file['before'], $file['after']];
        }

        $change = Change::query()->create($base + [
            'summary' => $submission['summary'],
            'notes' => $submission['notes'],
            'edits' => $submission['edits'],
            'files' => $files,
            'diff' => $this->diffs->files($diffInput),
            'trace' => $state->trace,
            'status' => Change::PROPOSED,
            'model' => $result->model,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
        ]);
        \Atoria\Alfred\Events\ChangeProposed::dispatch($change, $userLabel);

        return $change;
    }

    /**
     * "That didn't work": a new attempt at the same request, given the
     * previous diff, what the person says is wrong and any error the preview
     * ran into. The previous proposal is replaced (discarded) once the new
     * one is ready.
     */
    public function refine(Change $previous, PageContext $page, string $feedback, string $userKey, ?string $userLabel): Change
    {
        $element = ElementDescriptor::fromArray((array) $previous->element);
        $original = trim(\Illuminate\Support\Str::before((string) $previous->instruction, "\n\nThen: "));
        $revision = [
            'id' => (int) $previous->id,
            'summary' => (string) $previous->summary,
            'diff' => (string) $previous->diff,
            'notes' => $previous->notes,
            'applied' => $previous->status === Change::APPLIED,
            'feedback' => trim($feedback),
            'preview_error' => \Atoria\Alfred\Preview\PreviewErrors::get((int) $previous->id),
        ];

        $change = $this->propose($page, $element, $original."\n\nThen: ".trim($feedback), $userKey, $userLabel, null, $revision);

        if ($change->status === Change::PROPOSED && in_array($previous->status, [Change::PROPOSED, Change::REVERTED], true)) {
            $previous->status = Change::DISCARDED;
            $previous->notes = trim(($previous->notes ? $previous->notes."\n\n" : '').'Replaced by change #'.$change->id.'.');
            $previous->save();
        }

        return $change;
    }

    /** The agent, with the reasoning effort configured for edits. */
    protected function editAgent(): Agent
    {
        $effort = config('alfred.edit.reasoning_effort');

        // Only for reasoning models (when explanations use reasoning too).
        $base = config('alfred.provider', 'openai') === 'laragent'
            ? config('alfred.laragent.reasoning_effort')
            : config('alfred.openai.reasoning_effort');
        if (! empty($effort) && ! empty($base)) {
            return $this->agent->using($this->agent->llm()->withReasoningEffort((string) $effort));
        }

        return $this->agent;
    }

    /** Write the change to disk (write mode only). */
    public function apply(Change $change, ?string $by): Change
    {
        $this->assertWritable();

        if (! in_array($change->status, [Change::PROPOSED, Change::REVERTED], true)) {
            throw new ChangeConflict("This change is {$change->status} and cannot be applied.");
        }
        if (! $change->hasEdits()) {
            throw new ChangeConflict('This proposal has no edits to apply.');
        }

        $editable = self::editableGuard();
        $targets = [];

        // Check everything before touching anything.
        foreach ((array) $change->files as $path => $hashes) {
            $abs = $editable->file($path);
            $current = (string) file_get_contents($abs);
            if (sha1($current) !== $hashes['before_sha1']) {
                throw new ChangeConflict("{$path} has changed since this was proposed. Ask ".\Atoria\Alfred\Alfred::name().' again to get a fresh change.');
            }
            $targets[$path] = ['abs' => $abs, 'before' => $current, 'after' => $current];
        }

        foreach (EditApplier::byFile((array) $change->edits) as $path => $edits) {
            if (! isset($targets[$path])) {
                throw new ChangeConflict("Edit for {$path} no longer matches the file.");
            }
            try {
                $targets[$path]['after'] = EditApplier::apply($targets[$path]['after'], $edits);
            } catch (EditMismatch) {
                throw new ChangeConflict("Edit for {$path} no longer matches the file.");
            }
        }

        foreach ($targets as $path => $t) {
            if (sha1($t['after']) !== ($change->files[$path]['after_sha1'] ?? null)) {
                throw new ChangeConflict("Re-applying the edits to {$path} gave a different result than proposed.");
            }
        }

        $this->writeAll(array_map(fn ($t) => [$t['abs'], $t['after'], $t['before']], $targets));

        $change->forceFill([
            'backups' => array_map(fn ($t) => $t['before'], $targets),
            'status' => Change::APPLIED,
            'applied_by' => $by,
            'applied_at' => now(),
            'error' => null,
        ])->save();
        \Atoria\Alfred\Events\ChangeApplied::dispatch($change, $by);

        return $change;
    }

    /** Restore the files to how they were before the change was applied. */
    public function revert(Change $change, ?string $by): Change
    {
        $this->assertWritable();

        if ($change->status !== Change::APPLIED) {
            throw new ChangeConflict('Only an applied change can be undone.');
        }

        $editable = self::editableGuard();
        $targets = [];

        foreach ((array) $change->files as $path => $hashes) {
            $abs = $editable->file($path);
            $current = (string) file_get_contents($abs);
            if (sha1($current) !== $hashes['after_sha1']) {
                throw new ChangeConflict("{$path} has been edited since this change was applied, so it can't be undone automatically. Use the patch to revert it by hand.");
            }
            if (! isset($change->backups[$path])) {
                throw new ChangeConflict("No backup of {$path} was kept.");
            }
            $targets[$path] = [$abs, (string) $change->backups[$path], $current];
        }

        $this->writeAll($targets);

        $change->forceFill([
            'status' => Change::REVERTED,
            'reverted_by' => $by,
            'reverted_at' => now(),
        ])->save();
        \Atoria\Alfred\Events\ChangeReverted::dispatch($change, $by);

        return $change;
    }

    public function discard(Change $change): Change
    {
        if ($change->status !== Change::PROPOSED) {
            throw new ChangeConflict("This change is {$change->status}; only a proposal can be discarded.");
        }

        $change->forceFill(['status' => Change::DISCARDED])->save();

        return $change;
    }

    /**
     * @param  array<string, array{0: string, 1: string, 2: string}>  $targets  path => [absolute, new contents, contents to roll back to]
     */
    protected function writeAll(array $targets): void
    {
        $written = [];

        try {
            foreach ($targets as $path => [$abs, $contents]) {
                $this->writeFile($abs, $contents);
                $written[$path] = true;
            }
        } catch (\Throwable $e) {
            foreach (array_keys($written) as $path) {
                [$abs, , $rollback] = $targets[$path];
                @$this->writeFile($abs, $rollback);
            }

            throw new RuntimeException('Could not write the change: '.$e->getMessage(), 0, $e);
        }
    }

    protected function writeFile(string $abs, string $contents): void
    {
        $dir = dirname($abs);
        $tmp = tempnam($dir, '.alfred');
        if ($tmp === false || file_put_contents($tmp, $contents) === false) {
            throw new RuntimeException("Cannot write to {$dir} (permissions?).");
        }

        $perms = @fileperms($abs);
        if ($perms !== false) {
            @chmod($tmp, $perms & 0777);
        }

        if (! @rename($tmp, $abs)) {
            @unlink($tmp);
            throw new RuntimeException("Cannot replace {$abs} (permissions?).");
        }

        clearstatcache(true, $abs);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($abs, true);
        }
    }

    protected function assertWritable(): void
    {
        if ($this->alfred->editMode() !== 'write') {
            throw new ChangeConflict(\Atoria\Alfred\Alfred::name().' is in "propose" mode here: download the patch and apply it in your repository.');
        }
    }

    protected function throttle(string $userKey): void
    {
        \Atoria\Alfred\Usage\Usage::ensureBudget($userKey);

        $limit = (int) config('alfred.rate_limit', 6);
        if ($limit <= 0) {
            return;
        }

        $key = 'alfred:edit:'.$userKey;
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            throw new TooManyGenerations(RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, 60);
    }
}
