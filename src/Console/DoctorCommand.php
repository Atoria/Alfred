<?php

namespace Atoria\Alfred\Console;

use Atoria\Alfred\Alfred;
use Atoria\Alfred\PullRequests\Providers\GitProviderManager;
use Atoria\Alfred\Tenancy\Tenancy;
use Atoria\Alfred\Tickets\Trackers\TrackerManager;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use LarAgent\Agent;
use Throwable;

/** Checks Alfred's setup and says what to fix. Never prints secrets: only whether they are set. */
class DoctorCommand extends Command
{
    protected $signature = 'alfred:doctor';

    protected $description = 'Check that Alfred is set up correctly for this environment.';

    /** @var array<int, array{0: string, 1: string, 2: string}> */
    protected array $rows = [];

    protected int $problems = 0;

    public function handle(Alfred $alfred, Tenancy $tenancy): int
    {
        $env = (string) app()->environment();
        $production = app()->environment('production');

        $this->check('Enabled', $alfred->enabled(), $alfred->enabled() ? "yes ({$env})" : 'no — set ALFRED_ENABLED=true', warnOnly: true);

        // AI provider
        $provider = (string) config('alfred.provider', 'openai');
        if ($provider === 'laragent') {
            $installed = class_exists(Agent::class);
            $this->check('AI provider', $installed, $installed ? 'LarAgent ('.config('alfred.laragent.provider').')' : 'LarAgent selected but not installed — composer require maestroerror/laragent');
        } else {
            $key = (string) config('alfred.openai.api_key');
            $this->check('AI provider', $key !== '', $key !== '' ? 'OpenAI, key set ('.strlen($key).' characters), model '.config('alfred.openai.model') : 'OpenAI key missing — set ALFRED_OPENAI_API_KEY (or OPENAI_API_KEY)');
        }

        // Database
        $tables = [
            'explanations' => config('alfred.table', 'alfred_explanations'),
            'changes' => config('alfred.changes_table', 'alfred_changes'),
            'versions' => config('alfred.versions_table', 'alfred_explanation_versions'),
            'security events' => config('alfred.security_events_table', 'alfred_security_events'),
            'usage' => config('alfred.usage_table', 'alfred_usage'),
        ];
        try {
            $schema = Schema::connection(Alfred::connection());
            $missing = array_keys(array_filter($tables, fn ($t) => ! $schema->hasTable($t)));
            if ($missing === [] && ! $schema->hasColumn($tables['explanations'], 'tenant')) {
                $missing[] = 'tenant columns';
            }
            $this->check('Database', $missing === [], $missing === [] ? 'all tables present' : 'missing: '.implode(', ', $missing).' — run php artisan migrate');
        } catch (Throwable $e) {
            $this->check('Database', false, 'cannot connect: '.$e->getMessage());
        }

        // Cache locks (one generation / one ticket at a time)
        $locks = Cache::store()->getStore() instanceof LockProvider;
        $this->check('Cache locks', $locks, $locks ? 'supported ('.config('cache.default').')' : 'the cache store has no locks — use redis, database, file or memcached', warnOnly: true);

        // Tenancy
        $adapter = $tenancy->adapter();
        $this->rows[] = ['Tenancy', 'info', $adapter ? class_basename($adapter) : 'none detected'];

        // Access
        $users = count((array) config('alfred.users', [])) + count((array) config('alfred.verifiers', []));
        $this->rows[] = ['Who sees Alfred', 'info', $users > 0 ? "{$users} listed in ALFRED_USERS / ALFRED_VERIFIERS (or your useAlfred gate)" : 'nobody unless your useAlfred gate allows it'];

        $guests = (bool) config('alfred.guests.enabled');
        if ($guests) {
            $this->check('Guests', ! $production, $production ? 'guests are on in production — turn ALFRED_GUESTS off' : 'on (staging/dev only)');
        }

        // Editing
        if (config('alfred.edit.enabled')) {
            $mode = $alfred->editMode();
            $this->check('Changes', ! ($production && $mode === 'write'), $production && $mode === 'write'
                ? 'write mode in production — use propose (changes go through pull requests)'
                : "on, {$mode} mode".($alfred->editingAvailable() ? '' : " (not available in {$env})"));

            $previews = storage_path('framework/alfred-preview');
            $writable = is_dir($previews) ? is_writable($previews) : is_writable(dirname($previews));
            $this->check('Preview folder', $writable, $writable ? 'writable' : "{$previews} is not writable", warnOnly: true);
        } else {
            $this->rows[] = ['Changes', 'info', 'off'];
        }

        // Tickets and pull requests
        $trackers = app(TrackerManager::class);
        $listed = $trackers->listed();
        if ($listed !== []) {
            $ready = array_map(fn ($t) => $t->name(), $trackers->available());
            $notReady = array_values(array_diff($listed, $ready));
            $this->check('Ticket trackers', $notReady === [], $notReady === [] ? implode(', ', $ready) : 'not fully configured: '.implode(', ', $notReady));
        }
        $listedGit = array_filter((array) config('alfred.pull_requests.providers', []));
        if ($listedGit !== []) {
            $ready = array_map(fn ($p) => $p->name(), app(GitProviderManager::class)->available());
            $this->check('Pull requests', $ready !== [], $ready !== [] ? implode(', ', $ready) : 'listed but not configured: '.implode(', ', $listedGit));
        }

        // Budgets
        $budget = (int) config('alfred.usage.daily_tokens', 0);
        $this->rows[] = ['Daily token budget', 'info', $budget > 0 ? number_format($budget) : 'none (see alfred:usage)'];

        $this->table(['Check', 'Status', 'Details'], $this->rows);

        if ($this->problems > 0) {
            $this->error("{$this->problems} problem(s) to fix.");

            return self::FAILURE;
        }
        $this->info('Alfred looks good.');

        return self::SUCCESS;
    }

    protected function check(string $name, bool $ok, string $details, bool $warnOnly = false): void
    {
        if (! $ok && ! $warnOnly) {
            $this->problems++;
        }
        $this->rows[] = [$name, $ok ? 'ok' : ($warnOnly ? 'warning' : 'problem'), $details];
    }
}
