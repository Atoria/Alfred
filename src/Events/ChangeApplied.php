<?php

namespace Atoria\Alfred\Events;

use Atoria\Alfred\Changes\Change;
use Illuminate\Foundation\Events\Dispatchable;

/** A proposed change was written to the codebase. */
class ChangeApplied
{
    use Dispatchable;

    public function __construct(public readonly Change $change, public readonly ?string $by = null) {}
}
