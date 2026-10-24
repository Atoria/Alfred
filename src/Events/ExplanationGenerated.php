<?php

namespace Atoria\Alfred\Events;

use Atoria\Alfred\Explanations\Explanation;
use Illuminate\Foundation\Events\Dispatchable;

/** A new explanation was generated (or regenerated) by the AI. */
class ExplanationGenerated
{
    use Dispatchable;

    public function __construct(public readonly Explanation $explanation, public readonly ?string $by = null) {}
}
