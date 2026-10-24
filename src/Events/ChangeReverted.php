<?php

namespace Atoria\Alfred\Events;

use Atoria\Alfred\Changes\Change;
use Illuminate\Foundation\Events\Dispatchable;

/** An applied change was undone. */
class ChangeReverted
{
    use Dispatchable;

    public function __construct(public readonly Change $change, public readonly ?string $by = null) {}
}
