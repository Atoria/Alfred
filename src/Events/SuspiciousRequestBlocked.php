<?php

namespace Atoria\Alfred\Events;

use Atoria\Alfred\Security\SecurityEvent;
use Illuminate\Foundation\Events\Dispatchable;

/** Alfred refused a request (another tenant's/person's data, secrets, rule overrides). Listen to alert someone. */
class SuspiciousRequestBlocked
{
    use Dispatchable;

    public function __construct(public SecurityEvent $event) {}
}
