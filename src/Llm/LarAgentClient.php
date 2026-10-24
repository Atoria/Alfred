<?php

namespace Atoria\Alfred\Llm;

use LarAgent\Core\Contracts\LlmDriver;
use LarAgent\Core\DTO\DriverConfig;
use LarAgent\Drivers\Anthropic\ClaudeDriver;
use LarAgent\Drivers\Gemini\GeminiDriver;
use LarAgent\Drivers\Groq\GroqDriver;
use LarAgent\Drivers\OpenAi\BaseOpenAiDriver;
use LarAgent\Drivers\OpenAi\OpenAiDriver;
use LarAgent\Drivers\OpenAi\OpenAiResponsesDriver;
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

    /** Alfred's explanations and edits need room; a provider may set more, never less. */
    public const DEFAULT_MAX_TOKENS = 16000;

    /** @var array<string, string> tool call id => tool name (Gemini and Claude want the name with the result) */
    protected array $names = [];

    /** @var array<int, array{name: string, config: array<string, mixed>}>|null */
    protected ?array $providers = null;

    /** Index into providers(): moves on when a provider fails. */
    protected int $current = 0;

    /** @param  array{provider?: string|array<int|string, mixed>|null, model?: ?string, reasoning?: string, reasoning_effort?: ?string, max_tokens?: int|string|null, temperature?: float|string|null, driver?: ?LlmDriver}  $config */
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
     * The model of the provider in use: ALFRED_LARAGENT_MODEL for the first
     * provider, else the provider's own (or its override in
     * default_providers). LarAgent's stock OpenAI providers set none: those
     * use Alfred's usual OpenAI model (ALFRED_OPENAI_MODEL, gpt-5-mini).
     */
    public function model(): string
    {
        $provider = $this->providerConfig();
        $model = ($this->current === 0 ? ($this->config['model'] ?? null) : null) ?: ($provider['model'] ?? null);
        $driver = (string) ($provider['driver'] ?? config('laragent.default_driver'));
        if (! $model && str_contains($driver, '\\OpenAi\\') && ! str_contains($driver, 'Ollama')) {
            $model = config('alfred.openai.model', 'gpt-5-mini');
        }

        return (string) ($model ?: '');
    }

    /** The provider in use (its name in config/laragent.php). */
    public function provider(): string
    {
        return $this->providers()[$this->current]['name'] ?? 'default';
    }

    /**
     * The providers to use, in order, with their settings: the same sources
     * and precedence as LarAgent's own agents.
     *
     * @return array<int, array{name: string, config: array<string, mixed>}>
     */
    public function providers(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        $source = $this->config['provider'] ?? null;
        if (is_string($source) && str_contains($source, ',')) {
            $source = array_values(array_filter(array_map('trim', explode(',', $source))));
        }
        if ($source === null || $source === '' || $source === []) {
            $source = config('laragent.default_providers') ?: 'default';
        }

        $list = [];
        foreach ((array) $source as $key => $value) {
            [$name, $overrides] = is_int($key) ? [(string) $value, []] : [(string) $key, (array) $value];
            $list[] = ['name' => $name, 'config' => $overrides + (array) config('laragent.providers.'.$name, [])];
        }
        // LarAgent's older single fallback setting.
        $fallback = config('laragent.fallback_provider');
        if (is_string($source) && is_string($fallback) && $fallback !== '' && $fallback !== $source) {
            $list[] = ['name' => $fallback, 'config' => (array) config('laragent.providers.'.$fallback, [])];
        }

        return $this->providers = $list;
    }

    public function respond(string $instructions, array $input, array $tools, ?string $forceTool = null): LlmResponse
    {
        if (! self::installed()) {
            throw new LlmException('ALFRED_PROVIDER=laragent needs LarAgent: composer require maestroerror/laragent');
        }

        // Like LarAgent's own agents: the first provider, then the fallbacks
        // in order (ALFRED_LARAGENT_PROVIDER=openai_responses,claude, or
        // laragent.default_providers / fallback_provider). A provider that
        // fails moves on to the next one for the rest of this run; a reply
        // that was merely cut off is the agent's to retry, not a reason to switch.
        while (true) {
            try {
                return $this->attempt($instructions, $input, $tools, $forceTool);
            } catch (LlmCutOff $e) {
                throw $e;
            } catch (LlmException $e) {
                if ($this->current + 1 >= count($this->providers())) {
                    throw $e;
                }
                report(new LlmException('Alfred: LarAgent provider ['.$this->provider().'] failed, trying ['.$this->providers()[$this->current + 1]['name'].']: '.$e->getMessage(), 0, $e));
                $this->current++;
            }
        }
    }

    protected function attempt(string $instructions, array $input, array $tools, ?string $forceTool): LlmResponse
    {
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
            // A reply cut off at the token limit, in whichever way this driver
            // reports it (an unparsable tool call, "finish reason: length",
            // max_tokens): the agent can recover by asking again, shorter.
            if (self::cutOff($e->getMessage())) {
                $last = $this->lastResponse($driver);
                $usage = (array) ($last['usage'] ?? []);
                $in = (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0);
                $out = (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0);
                report(new LlmException('Alfred: LarAgent ['.$this->provider().'] reply was cut off or malformed: '.json_encode([
                    'error' => mb_substr($e->getMessage(), 0, 200),
                    'status' => $last['status'] ?? ($last['choices'][0]['finish_reason'] ?? ($last['stop_reason'] ?? null)),
                    'incomplete_reason' => $last['incomplete_details']['reason'] ?? null,
                    'output_tokens' => $out ?: null,
                    'reasoning_tokens' => $usage['output_tokens_details']['reasoning_tokens'] ?? ($usage['completion_tokens_details']['reasoning_tokens'] ?? null),
                    'max_tokens' => $this->maxTokens(),
                    'calls' => array_map(fn ($o) => ($o['name'] ?? '?').' ('.strlen((string) ($o['arguments'] ?? '')).' chars)', array_values(array_filter((array) ($last['output'] ?? []), fn ($o) => ($o['type'] ?? '') === 'function_call'))),
                ])));

                throw new LlmCutOff('The AI\'s reply was cut off at the token limit ('.$this->maxTokens().' tokens). Raise ALFRED_LARAGENT_MAX_TOKENS, or use ALFRED_PROVIDER=openai, which sets no such limit.', $in, $out, $e);
            }

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

    /**
     * Each provider as Alfred would use it, for `alfred:doctor`. Never the key itself.
     *
     * @return array<int, array{name: string, problem: ?string, model: string, key_length: ?int, max_tokens: int}>
     */
    public function describe(): array
    {
        $out = [];
        foreach (array_keys($this->providers()) as $i) {
            $probe = clone $this;
            $probe->current = $i;
            $provider = $probe->providerConfig();
            $driver = $provider['driver'] ?? config('laragent.default_driver');
            $needsKey = ! is_string($driver) || ! str_contains($driver, 'Ollama');
            $key = $probe->apiKey($provider);
            $problem = match (true) {
                $provider === [] => 'not in config/laragent.php (providers)',
                ! is_string($driver) || ! class_exists($driver) => 'its driver class does not exist',
                $probe->model() === '' => 'has no model (ALFRED_LARAGENT_MODEL)',
                $needsKey && ! $key => 'has no API key',
                default => null,
            };
            $out[] = ['name' => $probe->provider(), 'problem' => $problem, 'model' => $probe->model(), 'key_length' => $key ? strlen($key) : null, 'max_tokens' => $probe->maxTokens()];
        }

        return $out;
    }

    /** The provider's settings from config/laragent.php. */
    protected function providerConfig(): array
    {
        return $this->providers()[$this->current]['config'] ?? [];
    }

    /**
     * Longest reply per step. ALFRED_LARAGENT_MAX_TOKENS when set; otherwise
     * the provider's default_max_completion_tokens or 16000, whichever is
     * larger. Alfred's explanations and edits are long: a provider tuned for
     * short chat replies (LarAgent's stock 10000) must not cut them off,
     * while a provider set higher keeps its higher limit.
     */
    public function maxTokens(): int
    {
        $explicit = $this->config['max_tokens'] ?? null;
        if ($explicit !== null && $explicit !== '' && (int) $explicit > 0) {
            return max(1024, (int) $explicit);
        }

        return max(self::DEFAULT_MAX_TOKENS, (int) ($this->providerConfig()['default_max_completion_tokens'] ?? 0));
    }

    /**
     * Settings sent with every request besides the connection.
     *
     * Sampling (temperature and the like) is the task's business, not the
     * provider's: an app's own agents may run a chatbot at a creative
     * temperature, which would make Alfred's code reading worse. So Alfred
     * sends none (the model's own default) unless ALFRED_LARAGENT_TEMPERATURE
     * is set. parallel_tool_calls describes what the provider supports and is
     * taken from config/laragent.php.
     *
     * @return array<string, mixed>
     */
    protected function sampling(): array
    {
        $settings = [];
        $temperature = $this->config['temperature'] ?? null;
        if ($temperature !== null && $temperature !== '') {
            $settings['temperature'] = (float) $temperature;
        }

        $provider = $this->providerConfig();
        if (isset($provider['parallel_tool_calls'])) {
            $settings['parallelToolCalls'] = (bool) $provider['parallel_tool_calls'];
        }

        return $settings;
    }

    protected function driver(): LlmDriver
    {
        if (($this->config['driver'] ?? null) instanceof LlmDriver) {
            return clone $this->config['driver']; // tests / a custom driver instance
        }

        $provider = $this->providerConfig();
        $class = $provider['driver'] ?? config('laragent.default_driver'); // as LarAgent's agents
        if (! $provider || ! is_string($class) || ! class_exists($class)) {
            throw new LlmException("LarAgent provider [{$this->provider()}] is not set up. Add it to config/laragent.php (providers) or set ALFRED_LARAGENT_PROVIDER to one that is.");
        }

        if ($this->model() === '') {
            throw new LlmException("LarAgent provider [{$this->provider()}] has no model. Set ALFRED_LARAGENT_MODEL (e.g. qwen3:14b for Ollama).");
        }

        return new $class(DriverConfig::fromArray([
            'model' => $this->model(),
            'apiKey' => $this->apiKey($provider),
            'apiUrl' => $provider['api_url'] ?? null,
            'maxCompletionTokens' => $this->maxTokens(),
        ] + $this->sampling()));
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

        $driver = (string) ($provider['driver'] ?? config('laragent.default_driver'));
        $openAi = in_array($driver, [OpenAiDriver::class, OpenAiResponsesDriver::class], true)
            || is_subclass_of($driver, OpenAiDriver::class)
            || is_subclass_of($driver, OpenAiResponsesDriver::class);

        return $openAi ? (config('alfred.openai.api_key') ?: null) : null;
    }

    /** Per-request settings: how to force the final tool and how to ask for reasoning, in this API's terms. */
    protected function settings(LlmDriver $driver, ?string $forceTool): array
    {
        $family = $this->family($driver);
        $settings = ['maxCompletionTokens' => $this->maxTokens()];
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
            $driver instanceof OpenAiResponsesDriver => 'responses',
            $driver instanceof BaseOpenAiDriver => 'chat',
            $driver instanceof ClaudeDriver => 'claude',
            $driver instanceof GroqDriver => 'groq',
            $driver instanceof GeminiDriver => 'gemini',
            default => 'other',
        };
    }

    /** Does this driver error mean the reply was cut off before it finished? */
    public static function cutOff(string $message): bool
    {
        $m = strtolower($message);

        return str_contains($m, 'toolcall arguments must be a valid json')
            || str_contains($m, 'finish reason: length')
            || str_contains($m, 'max_output_tokens')
            || preg_match('/(finish|stop)[ _]reason:? *(length|max_tokens)/', $m) === 1
            || str_contains($m, 'maximum output tokens');
    }

    /** The driver's last raw response, if it kept one (LarAgent's Responses driver does). @return array<string, mixed> */
    protected function lastResponse(object $driver): array
    {
        try {
            if (! property_exists($driver, 'lastResponse')) {
                return [];
            }
            $property = new \ReflectionProperty($driver, 'lastResponse');
            $value = $property->getValue($driver);

            return is_object($value) && method_exists($value, 'toArray') ? (array) $value->toArray() : (is_array($value) ? $value : []);
        } catch (Throwable) {
            return [];
        }
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
