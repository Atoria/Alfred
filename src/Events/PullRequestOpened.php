<?php

namespace Atoria\Alfred\Events;

use Atoria\Alfred\Changes\Change;
use Illuminate\Foundation\Events\Dispatchable;

/** A pull request was opened for a change (pr_* fields are set). */
class PullRequestOpened
{
    use Dispatchable;

    public function __construct(public readonly Change $change, public readonly ?string $by = null) {}
}
