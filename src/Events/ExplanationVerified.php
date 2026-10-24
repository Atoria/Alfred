<?php

namespace Atoria\Alfred\Events;

use Atoria\Alfred\Explanations\Explanation;
use Illuminate\Foundation\Events\Dispatchable;

/** A verifier approved (or edited and approved) an explanation. */
class ExplanationVerified
{
    use Dispatchable;

    public function __construct(public readonly Explanation $explanation, public readonly ?string $by = null) {}
}
