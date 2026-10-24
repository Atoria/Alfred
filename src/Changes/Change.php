<?php

namespace Atoria\Alfred\Changes;

use Atoria\Alfred\Alfred;
use Atoria\Alfred\Tenancy\BelongsToTenant;
use Atoria\Alfred\Tickets\Screenshots;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Carbon;

/**
 * A proposed (and possibly applied) code change requested from the overlay.
 *
 * @property int $id
 * @property string $user_key
 * @property ?string $requested_by
 * @property string $environment
 * @property ?string $route
 * @property ?string $url
 * @property ?array $element
 * @property string $instruction
 * @property ?string $summary
 * @property ?string $notes
 * @property array $edits
 * @property array $files
 * @property ?string $diff
 * @property ?array $backups
 * @property string $status
 * @property ?string $error
 * @property ?Carbon $applied_at
 * @property ?Carbon $reverted_at
 * @property ?array $screenshots
 * @property ?string $jira_key
 * @property ?string $jira_url (older tickets; see ticket_*)
 * @property ?string $ticket_provider jira, github, gitlab, linear, trello, clickup, asana or a custom one
 * @property ?string $ticket_key
 * @property ?string $ticket_url
 * @property ?string $pr_provider github, gitlab, bitbucket or a custom one
 * @property ?string $pr_key
 * @property ?string $pr_url
 * @property ?string $pr_branch
 * @property ?string $pr_opened_by
 * @property ?Carbon $pr_opened_at
 * @property ?string $ticketed_by
 * @property ?Carbon $ticketed_at
 * @property ?string $applied_by
 * @property ?string $reverted_by
 * @property ?array $location where the element lives in the code, plus the explanation it started from
 * @property ?array $trace
 * @property ?string $model
 * @property ?int $input_tokens
 * @property ?int $output_tokens
 * @property ?string $tenant
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class Change extends Model
{
    use BelongsToTenant;
    use Prunable;

    /** Old change records, for `php artisan model:prune` (config alfred.retention). */
    public function prunable(): Builder
    {
        $days = (int) config('alfred.retention.changes_days', 0);
        if ($days <= 0) {
            return static::query()->whereRaw('1 = 0');
        }

        return static::query()->withoutGlobalScope('alfred_tenant')
            ->where('created_at', '<', now()->subDays($days))
            ->when(! config('alfred.retention.prune_applied', false), fn ($q) => $q->where('status', '!=', self::APPLIED));
    }

    protected function pruning(): void
    {
        $disk = app(Screenshots::class)->disk();
        foreach ((array) $this->screenshots as $path) {
            $disk->delete($path);
        }
        $disk->deleteDirectory('alfred/changes/'.$this->id);
    }

    public const PROPOSED = 'proposed';

    public const APPLIED = 'applied';

    public const REVERTED = 'reverted';

    public const DISCARDED = 'discarded';

    public const FAILED = 'failed';

    public const REQUESTED = 'requested'; // "Report / request": a ticket-only request, no code change

    protected $guarded = ['id'];

    protected $hidden = ['backups'];

    protected $casts = [
        'element' => 'array',
        'location' => 'array',
        'edits' => 'array',
        'files' => 'array',
        'backups' => 'array',
        'screenshots' => 'array',
        'trace' => 'array',
        'ticketed_at' => 'datetime',
        'applied_at' => 'datetime',
        'reverted_at' => 'datetime',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
    ];

    public function getTable(): string
    {
        return config('alfred.changes_table', 'alfred_changes');
    }

    public function getConnectionName(): ?string
    {
        return Alfred::connection() ?? parent::getConnectionName();
    }

    public function hasEdits(): bool
    {
        return ! empty($this->edits);
    }

    /** @return string[] */
    public function paths(): array
    {
        return array_keys((array) $this->files);
    }
}
