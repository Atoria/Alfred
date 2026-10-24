<?php

namespace Atoria\Alfred\Llm;

interface LlmClient
{
    /**
     * One turn of a tool-calling conversation.
     *
     * @param  string  $instructions  system prompt
     * @param  array<int, array<string, mixed>>  $input  conversation items so far (provider format)
     * @param  array<int, array<string, mixed>>  $tools  function tool definitions (name, description, parameters)
     * @param  string|null  $forceTool  if set, the model must call this tool
     */
    public function respond(string $instructions, array $input, array $tools, ?string $forceTool = null): LlmResponse;

    public function model(): string;

    /** Conversation item that returns a tool's result to the model. */
    public function toolResult(string $callId, string $output): array;

    /** A copy of this client with a different reasoning effort (null = provider default). */
    public function withReasoningEffort(?string $effort): static;

    /** Conversation item for a plain user message. */
    public function userMessage(string $text): array;
}
