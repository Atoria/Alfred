<?php

namespace Atoria\Alfred\Agent;

use Atoria\Alfred\Context\PageContext;
use Atoria\Alfred\Privacy\Redactor;
use Atoria\Alfred\Security\PathGuard;

/** Shared state for one agent run: what it may touch and what it has read. */
class AgentState
{
    /** @var array<string, string> relative path => sha1 of contents */
    public array $files = [];

    public ?array $submission = null;

    /** @var array<int, array{step: int, tool: string, arguments: string, result: string}> what the agent did, for debugging */
    public array $trace = [];

    protected int $traceBytes = 0;

    public function __construct(
        public PathGuard $guard,
        public PageContext $page,
        public Redactor $redactor,
    ) {}

    public function record(int $step, string $tool, string $arguments, string $result): void
    {
        if ($this->traceBytes > 150_000) {
            return;
        }

        $entry = [
            'step' => $step,
            'tool' => $tool,
            'arguments' => mb_substr($arguments, 0, 4000),
            'result' => mb_substr($result, 0, 4000),
        ];
        $this->traceBytes += strlen($entry['arguments']) + strlen($entry['result']);
        $this->trace[] = $entry;
    }

    public function remember(string $absolutePath): void
    {
        $relative = $this->guard->relative($absolutePath);

        if (! isset($this->files[$relative]) && is_file($absolutePath)) {
            $this->files[$relative] = sha1_file($absolutePath) ?: '';
        }
    }
}
