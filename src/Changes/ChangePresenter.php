<?php

namespace Atoria\Alfred\Changes;

use Atoria\Alfred\Context\FrontendSource;
use Atoria\Alfred\Preview\Preview;
use Atoria\Alfred\Preview\PreviewErrors;
use Atoria\Alfred\PullRequests\PullRequestOpener;
use Atoria\Alfred\Tickets\Trackers\TrackerManager;

class ChangePresenter
{
    /**
     * @param  bool  $canViewCode  without it, the code (diff, what the agent read, where it lives) is left out
     */
    public static function toArray(Change $c, string $mode, bool $canViewCode = false): array
    {
        $writable = $mode === 'write';
        $paths = $c->paths();
        $frontend = array_values(array_filter($paths, fn ($p) => FrontendSource::frontendFile($p)));
        // Preview swaps PHP classes and Blade views; front-end files need a build.
        $previewable = count($frontend) < count($paths);

        return [
            'id' => $c->id,
            'status' => $c->status,
            'kind' => $c->status === Change::REQUESTED ? 'request' : 'change',
            'location' => $canViewCode ? $c->location : self::businessLocation((array) $c->location),
            'instruction' => $c->instruction,
            'summary' => $c->summary,
            'notes' => $c->notes,
            'diff' => $canViewCode ? $c->diff : null,
            'files' => $paths,
            'frontend_files' => $frontend,
            'needs_build' => $frontend !== [],
            'has_edits' => $c->hasEdits(),
            'mode' => $mode,
            'can_apply' => $writable && $c->hasEdits() && in_array($c->status, [Change::PROPOSED, Change::REVERTED], true),
            'can_revert' => $writable && $c->status === Change::APPLIED,
            'can_discard' => $c->status === Change::PROPOSED,
            'can_preview' => $previewable && $c->hasEdits() && in_array($c->status, [Change::PROPOSED, Change::REVERTED], true) && app(Preview::class)->enabled(),
            'requested_by' => $c->requested_by,
            'environment' => $c->environment,
            'route' => $c->route,
            'url' => $c->url,
            'created_at' => $c->created_at?->toIso8601String(),
            'applied_at' => $c->applied_at?->toIso8601String(),
            'applied_by' => $c->applied_by,
            'reverted_at' => $c->reverted_at?->toIso8601String(),
            'model' => $c->model,
            'screenshots' => array_values(array_filter(['before', 'after'], fn ($k) => isset(((array) $c->screenshots)[$k]))),
            'pull_request' => $c->pr_key ? app(PullRequestOpener::class)->info($c) : null,
            'ticket' => $c->ticket_key ? ['provider' => $c->ticket_provider, 'label' => self::trackerLabel($c->ticket_provider), 'key' => $c->ticket_key, 'url' => $c->ticket_url] : null,
            'error' => $c->error,
            'preview_error' => in_array($c->status, [Change::PROPOSED, Change::REVERTED], true) ? PreviewErrors::get((int) $c->id) : null,
            'refines' => ((array) $c->location)['refines'] ?? null,
            'can_refine' => in_array($c->status, [Change::PROPOSED, Change::REVERTED, Change::APPLIED, Change::FAILED], true),
            'trace' => $canViewCode ? array_values((array) $c->trace) : [], // holds code the agent read
            'tokens' => ['input' => (int) $c->input_tokens, 'output' => (int) $c->output_tokens],
        ];
    }

    /** What someone without code access may see of where a change lives: the explanation, nothing from the code. */
    protected static function businessLocation(array $location): ?array
    {
        $kept = array_filter([
            'explanation' => isset($location['explanation']) ? array_diff_key((array) $location['explanation'], ['flow' => true]) : null,
            'refines' => $location['refines'] ?? null,
        ], fn ($v) => $v !== null);

        return $kept ?: null;
    }

    protected static function trackerLabel(?string $provider): ?string
    {
        if (! $provider) {
            return null;
        }
        try {
            return app(TrackerManager::class)->get($provider)->label();
        } catch (\InvalidArgumentException) {
            return ucfirst($provider);
        }
    }
}
