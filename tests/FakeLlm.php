<?php

namespace Atoria\Alfred\Tests;

use Atoria\Alfred\Llm\LlmClient;
use Atoria\Alfred\Llm\LlmResponse;
use Closure;
use PHPUnit\Framework\Assert;

/** Scripted LLM: each queued step returns a list of tool calls. */
class FakeLlm implements LlmClient
{
    /** @var array<int, Closure|array> */
    public array $script = [];

    /** @var array<int, array{instructions: string, input: array, tools: array, force: ?string}> */
    public array $requests = [];

    /** @param  array<int, array{0: string, 1: array}>|Closure  $calls  [[name, args], ...] */
    public function then(array|Closure $calls): static
    {
        $this->script[] = $calls;

        return $this;
    }

    public function submit(array $overrides = []): static
    {
        return $this->then([['submit_explanation', array_merge([
            'title' => 'Fill rate',
            'summary' => 'Booked shifts as a share of all shifts.',
            'formula' => 'Booked shifts ÷ all shifts × 100',
            'steps' => ['Count booked shifts', 'Count all shifts', 'Divide and round'],
            'data_sources' => ['Shifts'],
            'caveats' => ['Cancelled shifts are counted in the total'],
            'example' => 'If 4 shifts are posted and 2 are booked, it shows 50%.',
            'glossary' => ['Booked — a shift that has someone assigned'],
            'developer' => [
                'summary' => 'DashboardController::index counts booked shifts over all shifts.',
                'flow' => ['app/Http/Controllers/DashboardController.php:11'],
                'queries' => ['shifts where status = booked'],
                'caching' => null,
                'gotchas' => [],
            ],
            'query_index' => 0,
            'confidence' => 'high',
        ], $overrides)]]);
    }

    public function respond(string $instructions, array $input, array $tools, ?string $forceTool = null): LlmResponse
    {
        $this->requests[] = compact('instructions', 'input', 'tools') + ['force' => $forceTool, 'effort' => $this->effort];
        Assert::assertNotEmpty($this->script, 'FakeLlm ran out of scripted steps');

        $step = array_shift($this->script);
        $calls = $step instanceof Closure ? $step($input, $forceTool) : $step;

        $items = [];
        $parsed = [];
        foreach ($calls as $i => [$name, $args]) {
            $id = 'call_'.count($this->requests).'_'.$i;
            $items[] = ['type' => 'function_call', 'call_id' => $id, 'name' => $name, 'arguments' => json_encode($args)];
            $parsed[] = ['id' => $id, 'name' => $name, 'arguments' => $args, 'raw' => json_encode($args)];
        }

        return new LlmResponse($items, $parsed, null, 100, 20);
    }

    /** Reasoning effort requested for subsequent calls (recorded per request). */
    public ?string $effort = 'low';

    public function withReasoningEffort(?string $effort): static
    {
        $this->effort = $effort; // same instance, so the scripted steps carry on

        return $this;
    }

    public function model(): string
    {
        return 'fake-model';
    }

    public function toolResult(string $callId, string $output): array
    {
        return ['type' => 'function_call_output', 'call_id' => $callId, 'output' => $output];
    }

    public function userMessage(string $text): array
    {
        return ['role' => 'user', 'content' => $text];
    }

    /** Tool outputs the agent sent back, in order. @return string[] */
    public function toolOutputs(): array
    {
        $last = end($this->requests);
        $out = [];
        foreach ($last ? $last['input'] : [] as $item) {
            if (($item['type'] ?? null) === 'function_call_output') {
                $out[] = $item['output'];
            }
        }

        return $out;
    }
}
