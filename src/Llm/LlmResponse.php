<?php

namespace Atoria\Alfred\Llm;

class LlmResponse
{
    /**
     * @param  array<int, array<string, mixed>>  $items  raw output items, to append to the conversation as-is
     * @param  array<int, array{id: string, name: string, arguments: array<string, mixed>, raw: string}>  $calls
     */
    public function __construct(
        public array $items,
        public array $calls,
        public ?string $text,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
    ) {}
}
