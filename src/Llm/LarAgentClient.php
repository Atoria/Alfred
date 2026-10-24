<?php

namespace Atoria\Alfred\Llm;

use LarAgent\Core\Contracts\LlmDriver;
use LarAgent\Core\DTO\DriverConfig;
use LarAgent\Message;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\Messages\ToolResultMessage;
use LarAgent\PhantomTool;
use Throwable;

/**
 * Alfred on any model LarAgent supports (maestroerror/laragent): OpenAI,
 * Anthropic Claude, Google Gemini, Groq, Ollama, OpenRouter, or anything
 * OpenAI-compatible, using the providers in your config/laragent.php.
 *
 * Only LarAgent's driver layer is used. Alfred keeps its own agent loop,
 * so its safeguards (read before edit, path guard, edits rejected until
 * they match, step limit, trace) work the same on every provider. Alfred's
 * tools are registered as phantom tools: the model asks, Alfred runs them.
 *
 * Provider differences LarAgent leaves to the caller are handled here:
 *  - forcing the final tool (each API spells tool_choice differently);
 *  - reasoning effort (OpenAI Responses: reasoning.effort with encrypted
 *    replay; Chat Completions style: reasoning_effort, only for reasoning
 *    models; Claude and native Gemini: not used, see reasoningFor()).
 */
class LarAgentClient implements LlmClient
{
    /** Marks conversation items that wrap a LarAgent message. */
    public const KEY = '__laragent';

    /** @var array<string, string> tool call id => tool name (Gemini and Claude want the name with the result) */
    protected array $names = [];

    /** @param  array{provider?: string, model?: ?string, reasoning?: string, reasoning_effort?: ?string, max_tokens?: int, driver?: ?LlmDriver}  $config */
    public function __construct(protected array $config) {}

    public static function installed(): bool
    {
        return interface_exists(LlmDriver::class) && class_exists(PhantomTool::class);
    }

    public function withReasoningEffort(?string $effort): static
    {
        $clone = clone $this;
        $clone->config['reasoning_effort'] = $effort;

        return $clone;
    }

    /**
     * ALFRED_LARAGENT_MODEL, else the provider's model in config/laragent.php.
     * LarAgent's stock OpenAI providers set none: those use Alfred's usual
     * OpenAI model (ALFRED_OPENAI_MODEL, gpt-5-mini).
     */
    public function model(): string
    {
        $provider = $this->providerConfig();
        $model = ($this->config['model'] ?? null) ?: ($provider['model'] ?? null);
        if (! $model && str_contains((string) ($provider['driver'] ?? ''), '\\OpenAi\\') && ! str_contains((string) ($provider['driver'] ?? ''), 'Ollama')) {
            $model = config('alfred.openai.model', 'gpt-5-mini');
        }

        return (string) ($model ?: '');
    }

    public function provider(): string
    {
        return (string) ($this->config['provider'] ?? 'default') ?: 'default';
    }

    public function respond(string $instructions, array $input, array $tools, ?string $forceTool = null): LlmResponse
    {
        if (! self::installed()) {
            throw new LlmException('ALFRED_PROVIDER=laragent needs LarAgent: composer require maestroerror/laragent');
        }

        $driver = $this->driver();
        foreach ($tools as $tool) {
            $phantom = PhantomTool::create((string) $tool['name'], (string) $tool['description']);
            $parameters = (array) ($tool['parameters'] ?? []);
            $phantom->setProperties((array) ($parameters['properties'] ?? []));
            $phantom->setRequiredProps(array_values((array) ($parameters['required'] ?? [])));
            $driver->registerTool($phantom);
        }

        $messages = [Message::system($instructions)];
        foreach ($input as $item) {
            $messages[] = $item[self::KEY] ?? Message::user((string) ($item['content'] ?? ''));
        }

        try {
            $message = $driver->sendMessage($messages, $this->settings($driver, $forceTool));
        } catch (Throwable $e) {
            throw new LlmException($this->explain($e), 0, $e);
        }

        $calls = [];
        $text = null;
        if ($message instanceof ToolCallMessage) {
            foreach ($message->getToolCalls() as $call) {
                $raw = (string) $call->getArguments();
                $args = json_decode($raw !== '' ? $raw : '{}', true);
                $this->names[$call->getId()] = $call->getToolName();
                $calls[] = [
                    'id' => (string) $call->getId(),
                    'name' => (string) $call->getToolName(),
                    'arguments' => is_array($args) ? $args : [],
                    'raw' => $raw,
                ];
            }
        } else {
            $content = $message->getContent();
            $text = $content !== null ? trim((string) $content) : null;
        }

        $usage = $message->getUsage();

        return new LlmResponse(
            [[self::KEY => $message]],
            $calls,
            $text,
            (int) ($usage->promptTokens ?? 0), // @phpstan-ignore nullsafe.neverNull (older LarAgent versions leave usage empty)
            (int) ($usage->completionTokens ?? 0),
        );
    }

    public function toolResult(string $callId, string $output): array
    {
        return [self::KEY => new ToolResultMessage($output, $callId, $this->names[$callId] ?? '')];
    }

    public function userMessage(string $text): array
    {
        return [self::KEY => Message::user($text)];
    }

    /** The provider's settings from config/laragent.php. */
    protected function providerConfig(): array
    {
        $provider = config('laragent.providers.'.$this->provider());

        return is_array($provider) ? $provider : [];
    }

    protected function driver(): LlmDriver
    {
        if (($this->config['driver'] ?? null) instanceof LlmDriver) {
            return clone $this->config['driver']; // tests / a custom driver instance
        }

        $provider = $this->providerConfig();
        $class = $provider['driver'] ?? null;
        if (! $provider || ! is_string($class) || ! class_exists($class)) {
            throw new LlmException("LarAgent provider [{$this->provider()}] is not set up. Add it to config/laragent.php (providers) or set ALFRED_LARAGENT_PROVIDER to one that is.");
        }

        if ($this->model() === '') {
            throw new LlmException("LarAgent provider [{$this->provider()}] has no model. Set ALFRED_LARAGENT_MODEL (e.g. qwen3:14b for Ollama).");
        }

        return new $class(new DriverConfig(
            model: $this->model(),
            apiKey: $this->apiKey($provider),
            apiUrl: $provider['api_url'] ?? null,
            // Alfred's submissions (explanations, edits) are long; Claude's default 1024 would cut them off.
            maxCompletionTokens: max(1024, (int) ($this->config['max_tokens'] ?? 16000)),
        ));
    }

    /**
     * The provider's key. LarAgent's OpenAI providers read OPENAI_API_KEY;
     * apps set up for Alfred often only have ALFRED_OPENAI_API_KEY, so an
     * OpenAI provider without a key uses that one.
     */
    protected function apiKey(array $provider): ?string
    {
        $key = $provider['api_key'] ?? null;
        if (is_string($key) && $key !== '') {
            return $key;
        }

        $driver = (string) ($provider['driver'] ?? '');
        $openAi = in_array($driver, [\LarAgent\Drivers\OpenAi\OpenAiDriver::class, \LarAgent\Drivers\OpenAi\OpenAiResponsesDriver::class], true)
            || is_subclass_of($driver, \LarAgent\Drivers\OpenAi\OpenAiDriver::class)
            || is_subclass_of($driver, \LarAgent\Drivers\OpenAi\OpenAiResponsesDriver::class);

        return $openAi ? (config('alfred.openai.api_key') ?: null) : null;
    }

    /** Per-request settings: how to force the final tool and how to ask for reasoning, in this API's terms. */
    protected function settings(LlmDriver $driver, ?string $forceTool): array
    {
        $family = $this->family($driver);
        // Alfred's submissions (explanations, edits) are long; Claude's default 1024 would cut them off.
        $settings = ['maxCompletionTokens' => max(1024, (int) ($this->config['max_tokens'] ?? 16000))];
        $extras = [];

        // Every step must be a tool call, like Alfred's own client
        // ("required"): otherwise models narrate ("I'll look at the
        // controller…"), and each of those costs a round trip before Alfred
        // can steer them back. On the last step, force the final tool.
        // Only where LarAgent's driver still recognises the tool calls
        // (Responses: scans the output; Claude: stop_reason tool_use). The
        // Chat Completions drivers only do for a named tool, so they get
        // "auto" until the last step.
        if ($forceTool !== null) {
            match ($family) {
                'responses' => $settings['toolChoice'] = ['type' => 'function', 'name' => $forceTool],
                'chat' => $settings['toolChoice'] = ['type' => 'function', 'function' => ['name' => $forceTool]],
                'claude' => $extras['tool_choice'] = ['type' => 'tool', 'name' => $forceTool],
                'groq' => $extras['tool_choice'] = ['type' => 'function', 'function' => ['name' => $forceTool]],
                default => null, // native Gemini driver: no tool_choice; the loop's "call it now" message does the job
            };
        } else {
            match ($family) {
                'responses' => $settings['toolChoice'] = 'required',
                'claude' => $extras['tool_choice'] = ['type' => 'any'],
                default => null,
            };
        }

        if ($family === 'responses') {
            $extras['store'] = false; // nothing kept on OpenAI's side, like Alfred's own client
        }

        if (($effort = $this->reasoningFor($family)) !== null) {
            $extras['reasoning_effort'] = $effort;
        }

        return $settings + $extras;
    }

    /**
     * Reasoning effort, where the provider supports it in a tool loop:
     *  - OpenAI Responses: yes, with encrypted reasoning replayed between turns.
     *  - Chat Completions (OpenAI, OpenRouter, Gemini's OpenAI endpoint, Ollama)
     *    and Groq: "reasoning_effort", but only reasoning models accept it, so
     *    in "auto" it is sent only for those (o1/o3/o4, gpt-5, gpt-oss, Gemini
     *    2.5+, Qwen 3, DeepSeek R1).
     *  - Claude: never. Extended thinking can't be combined with forcing a
     *    tool, and LarAgent doesn't replay thinking blocks between tool turns,
     *    which Anthropic requires.
     *  - Native Gemini driver: it has no setting for it.
     * ALFRED_LARAGENT_REASONING: auto (default) | on | off.
     */
    protected function reasoningFor(string $family): ?string
    {
        $effort = $this->config['reasoning_effort'] ?? null;
        $mode = strtolower((string) ($this->config['reasoning'] ?? 'auto'));
        if (! is_string($effort) || $effort === '' || $mode === 'off' || in_array($family, ['claude', 'gemini'], true)) {
            return null;
        }
        if ($mode === 'on' || $family === 'responses') {
            return $effort;
        }

        return self::reasoningModel($this->model()) ? $effort : null;
    }

    public static function reasoningModel(string $model): bool
    {
        // "openai/gpt-5-mini" → gpt-5-mini, "qwen3:8b" (Ollama tag) → qwen3
        $name = strtolower(explode(':', basename(str_replace('\\', '/', $model)))[0]);

        return (bool) preg_match('/^(o[1-9]|gpt-5|gpt-oss|gemini-(2\.5|[3-9])|qwen-?3|qwq|deepseek-r1)/', $name);
    }

    protected function family(LlmDriver $driver): string
    {
        return match (true) {
            $driver instanceof \LarAgent\Drivers\OpenAi\OpenAiResponsesDriver => 'responses',
            $driver instanceof \LarAgent\Drivers\OpenAi\BaseOpenAiDriver => 'chat',
            $driver instanceof \LarAgent\Drivers\Anthropic\ClaudeDriver => 'claude',
            $driver instanceof \LarAgent\Drivers\Groq\GroqDriver => 'groq',
            $driver instanceof \LarAgent\Drivers\Gemini\GeminiDriver => 'gemini',
            default => 'other',
        };
    }

    protected function explain(Throwable $e): string
    {
        $message = $e->getMessage();
        $hint = match (true) {
            str_contains($message, 'max_tokens'), str_contains($message, 'finish reason: length') => ' The answer was cut off: raise ALFRED_LARAGENT_MAX_TOKENS.',
            str_contains($message, 'API key') => ' Set the API key for this provider in config/laragent.php / .env.',
            str_contains(strtolower($message), 'reasoning') => ' This model may not support reasoning: set ALFRED_LARAGENT_REASONING=off.',
            default => '',
        };

        return 'LarAgent ['.$this->provider().'] error: '.mb_substr($message, 0, 400).$hint;
    }
}
