<?php

namespace Atoria\Alfred\Agent;

use Atoria\Alfred\Agent\Tools\DescribeModel;
use Atoria\Alfred\Agent\Tools\ListFiles;
use Atoria\Alfred\Agent\Tools\ListRoutes;
use Atoria\Alfred\Agent\Tools\ReadFile;
use Atoria\Alfred\Agent\Tools\SearchCode;
use Atoria\Alfred\Agent\Tools\SubmitExplanation;
use Atoria\Alfred\Agent\Tools\Tool;
use Atoria\Alfred\Context\ElementDescriptor;
use Atoria\Alfred\Context\PageContext;
use Atoria\Alfred\Llm\LlmClient;
use Atoria\Alfred\Llm\LlmException;
use Atoria\Alfred\Privacy\Redactor;
use Atoria\Alfred\Security\AccessDenied;
use Atoria\Alfred\Security\PathGuard;
use Atoria\Alfred\Security\SecretScrubber;
use Atoria\Alfred\Usage\Usage;
use Throwable;

/**
 * The tool-calling loop: give the model the page context, let it read code
 * with read-only tools, stop when it calls the task's terminal tool
 * (submit_explanation for explanations, propose_edit for UI changes).
 */
class Agent
{
    /** @var array<string, Tool> extra tools registered by the app */
    protected array $extraTools = [];

    public function __construct(
        protected LlmClient $llm,
        protected PromptBuilder $prompts,
        protected PathGuard $guard,
        protected Redactor $redactor,
    ) {}

    public function llm(): LlmClient
    {
        return $this->llm;
    }

    /** The same agent talking to a different (e.g. differently configured) client. */
    public function using(LlmClient $llm): static
    {
        $clone = clone $this;
        $clone->llm = $llm;

        return $clone;
    }

    public function addTool(Tool $tool): static
    {
        $this->extraTools[$tool->name()] = $tool;

        return $this;
    }

    /** @return Tool[] the read-only investigation tools */
    public function readTools(): array
    {
        return [new SearchCode, new ReadFile, new ListFiles, new ListRoutes, new DescribeModel, ...array_values($this->extraTools)];
    }

    public function run(PageContext $page, ElementDescriptor $element): AgentResult
    {
        $state = new AgentState($this->guard, $page, $this->redactor);

        return $this->loop(
            $state,
            $this->prompts->instructions(),
            $this->prompts->initialMessage($page, $element, $this->redactor, $this->guard),
            [...$this->readTools(), new SubmitExplanation],
            SubmitExplanation::NAME,
            (int) config('alfred.agent.max_steps', 14),
        );
    }

    /**
     * @param  Tool[]  $tools
     */
    public function loop(AgentState $state, string $instructions, string $message, array $tools, string $terminal, int $maxSteps): AgentResult
    {
        /** @var array<string, Tool> $byName */
        $byName = [];
        foreach ($tools as $tool) {
            $byName[$tool->name()] = $tool;
        }

        $definitions = array_map(fn (Tool $t) => [
            'name' => $t->name(),
            'description' => $t->description(),
            'parameters' => $t->parameters(),
            'strict' => $t->strict(),
        ], array_values($byName));

        $input = [$this->llm->userMessage($message)];
        $maxSteps = max(2, $maxSteps);
        $inputTokens = 0;
        $outputTokens = 0;
        $steps = 0;
        $forcedAttempts = 0;

        while ($state->submission === null) {
            $steps++;

            $force = $steps >= $maxSteps ? $terminal : null;
            if ($force) {
                // The terminal tool may reject a bad submission (e.g. an edit
                // whose search text doesn't match); allow a couple of retries.
                if (++$forcedAttempts > 3) {
                    throw new LlmException('The agent did not produce a usable result.');
                }
                $input[] = $this->llm->userMessage("Step limit reached. Call {$terminal} now with what you have.");
            }

            $response = $this->llm->respond($instructions, $input, $definitions, $force);
            $inputTokens += $response->inputTokens;
            $outputTokens += $response->outputTokens;
            Usage::record(self::usageKind($terminal), $this->llm->model(), $response->inputTokens, $response->outputTokens);

            foreach ($response->items as $item) {
                $input[] = $item;
            }

            if ($response->text !== null && $response->text !== '') {
                $state->record($steps, '(message)', '', $response->text);
            }

            if ($response->calls === []) {
                $input[] = $this->llm->userMessage("Use the tools to investigate, and finish by calling {$terminal}.");

                continue;
            }

            foreach ($response->calls as $call) {
                $output = $this->invoke($byName, $call['name'], $call['arguments'], $state);
                $state->record($steps, $call['name'], (string) json_encode($call['arguments'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $output);
                $input[] = $this->llm->toolResult($call['id'], $output);
            }
        }

        return new AgentResult(
            $state->submission,
            $state->files,
            $this->llm->model(),
            $inputTokens,
            $outputTokens,
            $steps,
        );
    }

    protected static function usageKind(string $terminal): string
    {
        return match ($terminal) {
            'submit_explanation' => 'explanation',
            'propose_edit' => 'change',
            'submit_answer' => 'follow-up',
            default => $terminal,
        };
    }

    /** @param  array<string, Tool>  $tools */
    protected function invoke(array $tools, string $name, array $arguments, AgentState $state): string
    {
        $tool = $tools[$name] ?? null;

        if (! $tool) {
            return "Unknown tool {$name}.";
        }

        try {
            $result = $tool->handle($arguments, $state);
        } catch (AccessDenied $e) {
            return 'Denied: '.$e->getMessage();
        } catch (Throwable $e) {
            report($e);

            return 'Tool error: '.class_basename($e).': '.mb_substr($e->getMessage(), 0, 300);
        }

        // Secrets hard-coded in ordinary files never leave the server.
        return SecretScrubber::scrub(is_string($result) ? $result : (string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }
}
