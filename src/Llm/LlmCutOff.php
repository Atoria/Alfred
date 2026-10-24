<?php

namespace Atoria\Alfred\Llm;

/**
 * The model's reply was cut off (or its tool call came back malformed), so
 * the step produced nothing usable. The agent asks again once with a nudge
 * to keep it short; the tokens it spent are still counted.
 */
class LlmCutOff extends LlmException
{
    public function __construct(string $message, public readonly int $inputTokens = 0, public readonly int $outputTokens = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
