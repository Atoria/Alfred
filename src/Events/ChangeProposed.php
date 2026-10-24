<?php

namespace Atoria\Alfred\Events;

use Atoria\Alfred\Changes\Change;
use Illuminate\Foundation\Events\Dispatchable;

/** The AI proposed a code change (nothing is written yet). */
class ChangeProposed
{
    use Dispatchable;

    public function __construct(public readonly Change $change, public readonly ?string $by = null) {}
}
