<?php

namespace Atoria\Alfred\Console;

use Atoria\Alfred\Usage\Usage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UsageCommand extends Command
{
    protected $signature = 'alfred:usage
        {--days=7 : How many days back, today included}
        {--by=kind : Group by kind, user, tenant, model or day}';

    protected $description = 'Show how many AI calls and tokens Alfred used.';

    public function handle(): int
    {
        $groups = ['kind' => 'kind', 'user' => 'user_key', 'tenant' => 'tenant', 'model' => 'model', 'day' => 'day'];
        $by = (string) $this->option('by');
        if (! isset($groups[$by])) {
            $this->error('--by must be one of: '.implode(', ', array_keys($groups)));

            return self::INVALID;
        }
        $days = max(1, (int) $this->option('days'));
        $column = $groups[$by];

        $rows = Usage::query()
            ->where('day', '>=', now()->subDays($days - 1)->toDateString())
            ->groupBy($column)
            ->orderBy($column)
            ->toBase() // plain rows: grp and the sums aren't model attributes
            ->get([
                DB::raw($column.' as grp'),
                DB::raw('sum(requests) as requests'),
                DB::raw('sum(input_tokens) as input_tokens'),
                DB::raw('sum(output_tokens) as output_tokens'),
            ]);

        if ($rows->isEmpty()) {
            $this->info("No AI usage in the last {$days} day(s).");

            return self::SUCCESS;
        }

        $fmt = fn ($n) => number_format((int) $n);
        $table = $rows->map(fn ($r) => [
            $by === 'day' ? substr((string) $r->grp, 0, 10) : ((string) $r->grp !== '' ? (string) $r->grp : '—'),
            $fmt($r->requests), $fmt($r->input_tokens), $fmt($r->output_tokens), $fmt($r->input_tokens + $r->output_tokens),
        ])->all();
        $table[] = ['Total', $fmt($rows->sum('requests')), $fmt($rows->sum('input_tokens')), $fmt($rows->sum('output_tokens')), $fmt($rows->sum('input_tokens') + $rows->sum('output_tokens'))];

        $this->table([ucfirst($by), 'AI calls', 'Input tokens', 'Output tokens', 'Total tokens'], $table);

        $budget = (int) config('alfred.usage.daily_tokens', 0);
        if ($budget > 0) {
            $today = (int) Usage::query()->where('day', now()->toDateString())->sum(DB::raw('input_tokens + output_tokens'));
            $this->line("Today: {$fmt($today)} of {$fmt($budget)} tokens (daily budget).");
        }

        return self::SUCCESS;
    }
}
