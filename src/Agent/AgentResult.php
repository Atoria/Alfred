<?php

namespace Atoria\Alfred\Agent;

class AgentResult
{
    /**
     * @param  array<string, mixed>  $explanation  the submitted fields
     * @param  array<string, string>  $files  relative path => sha1, every file the agent read
     */
    public function __construct(
        public array $explanation,
        public array $files,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public int $steps,
    ) {}
}
