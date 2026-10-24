<?php

namespace Atoria\Alfred\Explanations;

use RuntimeException;

class TooManyGenerations extends RuntimeException
{
    public function __construct(public int $retryAfter, ?string $message = null)
    {
        parent::__construct($message ?? "Too many new explanations requested. Try again in {$retryAfter}s.");
    }
}
