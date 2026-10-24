<?php

namespace Atoria\Alfred\Console;

use Atoria\Alfred\Explanations\Explanation;
use Atoria\Alfred\Security\PathGuard;
use Illuminate\Console\Command;

class ClearCommand extends Command
{
    protected $signature = 'alfred:clear
        {--stale : Only explanations whose source code has changed}
        {--unverified : Keep verified explanations}
        {--previews : Only remove preview copies of proposed changes (storage/framework/alfred-preview)}';

    protected $description = 'Delete cached Alfred explanations so they are regenerated on next use.';

    public function handle(PathGuard $guard): int
    {
        if ($this->option('previews')) {
            $this->info('Removed '.\Atoria\Alfred\Preview\Preview::prune(0).' preview folder(s).');

            return self::SUCCESS;
        }

        $query = Explanation::query()->withoutGlobalScope('alfred_tenant'); // every tenant's

        if ($this->option('unverified')) {
            $query->where('status', '!=', Explanation::VERIFIED);
        }

        $deleted = 0;
        $query->lazyById()->each(function (Explanation $e) use ($guard, &$deleted) {
            if ($this->option('stale') && ! $e->isStale($guard)) {
                return;
            }
            $e->delete();
            $deleted++;
        });

        $this->info("Deleted {$deleted} explanation(s).");

        return self::SUCCESS;
    }
}
