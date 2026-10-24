<?php

namespace Atoria\Alfred\Usage;

use Atoria\Alfred\Alfred;
use Atoria\Alfred\Explanations\TooManyGenerations;
use Atoria\Alfred\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * AI usage per day, person, tenant, kind and model — what `alfred:usage`
 * reports — and the optional daily token budgets that stop new AI work
 * once they're spent.
 *
 * @property int $id
 * @property string $day Y-m-d (kept a plain string so it matches exactly in every database)
 * @property string $tenant
 * @property string $user_key
 * @property string $kind
 * @property string $model
 * @property int $requests
 * @property int $input_tokens
 * @property int $output_tokens
 */
class Usage extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['requests' => 'integer', 'input_tokens' => 'integer', 'output_tokens' => 'integer'];

    public function getTable(): string
    {
        return config('alfred.usage_table', 'alfred_usage');
    }

    public function getConnectionName(): ?string
    {
        return Alfred::connection() ?? parent::getConnectionName();
    }

    /** Count one AI call. Never throws: usage tracking must not break the work. */
    public static function record(string $kind, ?string $model, int $input, int $output, ?string $userKey = null): void
    {
        if (! config('alfred.usage.enabled', true)) {
            return;
        }

        try {
            $bucket = [
                'day' => now()->toDateString(),
                'tenant' => (string) app(Tenancy::class)->current(),
                'user_key' => mb_substr($userKey ?? self::currentUserKey(), 0, 191),
                'kind' => mb_substr($kind, 0, 32),
                'model' => mb_substr((string) $model, 0, 100),
            ];
            $add = fn () => static::query()->where($bucket)->update([
                'requests' => DB::raw('requests + 1'),
                'input_tokens' => DB::raw('input_tokens + '.max(0, $input)),
                'output_tokens' => DB::raw('output_tokens + '.max(0, $output)),
                'updated_at' => now(),
            ]);

            if ($add() === 0) {
                try {
                    static::query()->create($bucket + ['requests' => 1, 'input_tokens' => max(0, $input), 'output_tokens' => max(0, $output)]);
                } catch (QueryException) {
                    $add(); // created by a parallel request in the meantime
                }
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Refuses new AI work once today's budget is spent: for this workspace
     * (alfred.usage.daily_tokens: the tenant, or the whole app without
     * tenancy) or for this person (daily_tokens_per_user).
     *
     * @throws TooManyGenerations
     */
    public static function ensureBudget(string $userKey): void
    {
        $total = (int) config('alfred.usage.daily_tokens', 0);
        $perUser = (int) config('alfred.usage.daily_tokens_per_user', 0);
        if ($total <= 0 && $perUser <= 0) {
            return;
        }

        try {
            // Per workspace: one tenant spending its budget never blocks another.
            $today = static::query()->where('day', now()->toDateString())->where('tenant', (string) app(Tenancy::class)->current());
            $sum = fn ($q) => (int) $q->sum(DB::raw('input_tokens + output_tokens'));
            $spentByAll = $total > 0 ? $sum(clone $today) : 0;
            $spentByUser = $perUser > 0 ? $sum((clone $today)->where('user_key', $userKey)) : 0;
        } catch (Throwable $e) {
            report($e);

            return; // can't tell: don't block people over a missing table
        }

        $untilTomorrow = (int) now()->diffInSeconds(now()->copy()->addDay()->startOfDay(), true);
        if ($total > 0 && $spentByAll >= $total) {
            throw new TooManyGenerations($untilTomorrow, Alfred::name()."'s AI budget for today has been used up here. It resets at midnight.");
        }
        if ($perUser > 0 && $spentByUser >= $perUser) {
            throw new TooManyGenerations($untilTomorrow, 'You have used your '.Alfred::name().' AI budget for today. It resets at midnight.');
        }
    }

    protected static function currentUserKey(): string
    {
        try {
            return app()->bound('request') ? app(Alfred::class)->userKey(request()) : 'system';
        } catch (Throwable) {
            return 'system';
        }
    }
}
