<?php

namespace Atoria\Alfred\Changes;

use RuntimeException;

class EditMismatch extends RuntimeException
{
    public function __construct(public readonly int $index, string $reason)
    {
        parent::__construct('Edit '.($index + 1).': '.$reason);
    }
}
