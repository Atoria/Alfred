<?php

namespace Atoria\Alfred\Agent\Tools;

use Atoria\Alfred\Agent\AgentState;

interface Tool
{
    public function name(): string;

    public function description(): string;

    /** JSON schema of the arguments. */
    public function parameters(): array;

    /** Whether the provider should enforce the schema strictly. */
    public function strict(): bool;

    /** Run the tool. The return value is JSON-encoded (strings are sent as-is). */
    public function handle(array $arguments, AgentState $state): mixed;
}
