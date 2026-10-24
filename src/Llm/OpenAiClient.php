<?php

namespace Atoria\Alfred\Llm;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI Responses API, stateless (store=false). The caller keeps the whole
 * conversation and replays it each turn; reasoning items are carried as
 * encrypted content so nothing is retained on OpenAI's side.
 */
class OpenAiClient implements LlmClient
{
    public function __construct(protected array $config) {}

    public function withReasoningEffort(?string $effort): static
    {
        $clone = clone $this;
        $clone->config['reasoning_effort'] = $effort;

        return $clone;
    }

    public function model(): string
    {
        return (string) ($this->config['model'] ?? 'gpt-5-mini');
    }

    public function respond(string $instructions, array $input, array $tools, ?string $forceTool = null): LlmResponse
    {
        if (empty($this->config['api_key'])) {
            throw new LlmException('Alfred has no OpenAI API key. Set ALFRED_OPENAI_API_KEY (or OPENAI_API_KEY) in .env.');
        }

        $payload = [
            'model' => $this->model(),
            'instructions' => $instructions,
            'input' => $input,
            'tools' => array_map(fn (array $t) => [
                'type' => 'function',
                'name' => $t['name'],
                'description' => $t['description'],
                'parameters' => $t['parameters'],
                'strict' => $t['strict'] ?? false,
            ], $tools),
            'tool_choice' => $forceTool ? ['type' => 'function', 'name' => $forceTool] : 'required',
            'parallel_tool_calls' => true,
            'store' => false,
        ];

        if (! empty($this->config['reasoning_effort'])) {
            $payload['reasoning'] = ['effort' => $this->config['reasoning_effort']];
            $payload['include'] = ['reasoning.encrypted_content'];
        }

        $request = Http::withToken((string) $this->config['api_key'])
            ->acceptJson()
            ->timeout((int) ($this->config['timeout'] ?? 120))
            ->retry(2, 500, fn ($e) => $e instanceof ConnectionException || ($e instanceof RequestException && in_array($e->response->status(), [429, 500, 502, 503], true)), throw: false);

        if (! empty($this->config['organization'])) {
            $request = $request->withHeaders(['OpenAI-Organization' => $this->config['organization']]);
        }

        try {
            $response = $request->post(rtrim((string) ($this->config['base_url'] ?? 'https://api.openai.com/v1'), '/').'/responses', $payload);
        } catch (ConnectionException $e) {
            throw new LlmException('Could not reach OpenAI: '.$e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            $message = $response->json('error.message') ?? $response->body();
            throw new LlmException("OpenAI error ({$response->status()}): ".mb_substr((string) $message, 0, 500));
        }

        $items = (array) $response->json('output', []);

        // Cut off before the reply finished (the model's own output limit):
        // let the agent ask again, shorter.
        if ($response->json('status') === 'incomplete') {
            $reason = (string) $response->json('incomplete_details.reason', '');
            if ($reason === 'content_filter') {
                throw new LlmException('OpenAI stopped the reply (content filter).');
            }
            $complete = array_filter($items, fn ($i) => ($i['type'] ?? null) === 'function_call' && is_array(json_decode((string) ($i['arguments'] ?? ''), true)));
            if ($complete === []) {
                throw new LlmCutOff(
                    'The AI\'s reply was cut off before it finished ('.($reason ?: 'incomplete').'). Try again, or use a model with a larger output limit.',
                    (int) $response->json('usage.input_tokens', 0),
                    (int) $response->json('usage.output_tokens', 0),
                );
            }
        }
        $calls = [];
        $text = null;

        foreach ($items as $item) {
            $type = $item['type'] ?? null;

            if ($type === 'function_call') {
                $raw = (string) ($item['arguments'] ?? '{}');
                $args = json_decode($raw, true);
                $calls[] = [
                    'id' => (string) $item['call_id'],
                    'name' => (string) $item['name'],
                    'arguments' => is_array($args) ? $args : [],
                    'raw' => $raw,
                ];
            } elseif ($type === 'message') {
                foreach ((array) ($item['content'] ?? []) as $part) {
                    if (($part['type'] ?? null) === 'output_text') {
                        $text = trim(($text ?? '').' '.$part['text']);
                    }
                }
            }
        }

        return new LlmResponse(
            $items,
            $calls,
            $text,
            (int) $response->json('usage.input_tokens', 0),
            (int) $response->json('usage.output_tokens', 0),
        );
    }

    /** Conversation item carrying a tool's result back to the model. */
    public function toolResult(string $callId, string $output): array
    {
        return ['type' => 'function_call_output', 'call_id' => $callId, 'output' => $output];
    }

    public function userMessage(string $text): array
    {
        return ['role' => 'user', 'content' => $text];
    }
}
