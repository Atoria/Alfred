<?php

namespace Atoria\Alfred\Events;

use Atoria\Alfred\Changes\Change;
use Illuminate\Foundation\Events\Dispatchable;

/** A report or change was sent to a ticket tracker (ticket_* fields are set). */
class TicketCreated
{
    use Dispatchable;

    public function __construct(public readonly Change $change, public readonly ?string $by = null) {}
}
