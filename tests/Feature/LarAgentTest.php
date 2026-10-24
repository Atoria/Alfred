<?php

namespace Atoria\Alfred\Tests\Feature;

use Atoria\Alfred\AlfredServiceProvider;
use Atoria\Alfred\Explanations\Explanation;
use Atoria\Alfred\Llm\LarAgentClient;
use Atoria\Alfred\Llm\LlmClient;
use Atoria\Alfred\Llm\LlmException;
use Atoria\Alfred\Tests\Fixtures\LarAgent\RecordingChatDriver;
use Atoria\Alfred\Tests\Fixtures\LarAgent\RecordingClaudeDriver;
use Atoria\Alfred\Tests\Fixtures\LarAgent\RecordingResponsesDriver;
use Atoria\Alfred\Tests\TestCase;
use Illuminate\Testing\TestResponse;
use LarAgent\Drivers\Anthropic\ClaudeDriver;
use LarAgent\Drivers\OpenAi\OllamaDriver;
use LarAgent\Drivers\OpenAi\OpenAiResponsesDriver;
use LarAgent\Drivers\OpenAi\OpenRouter;
use LarAgent\Messages\ToolCallMessage;
use LarAgent\ToolCall;

/**
 * ALFRED_PROVIDER=laragent, run through LarAgent's real drivers (payloads
 * recorded instead of sent). Skipped when maestroerror/laragent isn't
 * installed (it needs PHP 8.3+).
 */
class LarAgentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! LarAgentClient::installed()) {
            $this->markTestSkipped('maestroerror/laragent is not installed.');
        }
        require_once dirname(__DIR__).'/Fixtures/LarAgent/RecordingDrivers.php';
    }

    protected function useDriver($driver, array $config = []): void
    {
        config(['alfred.provider' => 'laragent']);
        $this->app->bind(LlmClient::class, fn () => new LarAgentClient(array_merge(
            ['provider' => 'test', 'model' => 'gpt-5-mini', 'reasoning' => 'auto', 'reasoning_effort' => 'low', 'max_tokens' => 16000, 'driver' => $driver],
            $config,
        )));
    }

    protected function toolCall(string $id, string $tool, array $args): ToolCallMessage
    {
        $message = new ToolCallMessage([new ToolCall($id, $tool, json_encode($args))]);
        $message->setUsage(['prompt_tokens' => 100, 'completion_tokens' => 20]);

        return $message;
    }

    protected function submit(string $id = 'call_2'): ToolCallMessage
    {
        return $this->toolCall($id, 'submit_explanation', [
            'title' => 'Fill rate', 'summary' => 'Booked shifts as a share of all shifts.', 'formula' => 'Booked ÷ all × 100',
            'steps' => ['Count booked', 'Count all'], 'data_sources' => ['Shifts'], 'caveats' => [], 'example' => null,
            'glossary' => [], 'developer' => ['summary' => 'Counts in DashboardController.', 'flow' => [], 'queries' => [], 'caching' => null, 'gotchas' => []],
            'query_index' => null, 'confidence' => 'high',
        ]);
    }

    protected function explain(): TestResponse
    {
        $user = $this->user();

        return $this->actingAs($user)->postJson('/_alfred/explain', ['token' => $this->loadDashboard($user), 'element' => $this->element()]);
    }

    public function test_explains_through_the_openai_responses_driver(): void
    {
        $driver = (new RecordingResponsesDriver(['model' => 'gpt-5-mini']))->recording([
            $this->toolCall('call_1', 'read_file', ['path' => 'app/Http/Controllers/DashboardController.php', 'start_line' => null, 'end_line' => null]),
            $this->submit(),
        ]);
        $this->useDriver($driver);

        $this->explain()->assertOk()->assertJson(['title' => 'Fill rate', 'cached' => false]);

        [$first, $second] = $driver->payloads->getArrayCopy();

        // Alfred's prompt and tools, in Responses API form.
        $this->assertStringContainsString('How is this calculated?', $first['instructions']);
        $this->assertStringContainsString('# What the user selected', json_encode($first['input']));
        $this->assertContains('read_file', array_column($first['tools'], 'name'));
        $this->assertTrue($first['tools'][0]['strict']);

        // Same privacy and reasoning as Alfred's own OpenAI client.
        $this->assertFalse($first['store']);
        $this->assertSame(['effort' => 'low'], $first['reasoning']);
        $this->assertContains('reasoning.encrypted_content', $first['include']);
        $this->assertSame('required', $first['tool_choice'], 'every step is a tool call, like the built-in client (no narrating round trips)');

        // The tool ran in Alfred and its result went back with the call id.
        $outputs = array_values(array_filter($second['input'], fn ($i) => ($i['type'] ?? null) === 'function_call_output'));
        $this->assertSame('call_1', $outputs[0]['call_id']);
        $this->assertStringContainsString('$booked = Shift::query()->booked()->count();', $outputs[0]['output']);

        $e = Explanation::query()->first();
        $this->assertSame(200, $e->input_tokens);
        $this->assertSame(40, $e->output_tokens);
        $this->assertSame('gpt-5-mini', $e->model);
    }

    public function test_the_last_step_forces_the_answer_in_each_apis_own_terms(): void
    {
        config(['alfred.agent.max_steps' => 2]);
        $read = fn ($id) => $this->toolCall($id, 'read_file', ['path' => 'app/Http/Controllers/DashboardController.php', 'start_line' => null, 'end_line' => null]);

        // OpenAI Responses
        $driver = (new RecordingResponsesDriver(['model' => 'gpt-5-mini']))->recording([$read('c1'), $this->submit('c2')]);
        $this->useDriver($driver);
        $this->explain()->assertOk();
        $this->assertSame(['type' => 'function', 'name' => 'submit_explanation'], $driver->payloads[1]['tool_choice']);

        // Chat Completions (OpenAI, OpenRouter, Gemini's OpenAI endpoint, Ollama…)
        Explanation::query()->delete();
        $driver = (new RecordingChatDriver(['model' => 'gpt-4.1-mini']))->recording([$read('c1'), $this->submit('c2')]);
        $this->useDriver($driver, ['model' => 'gpt-4.1-mini']);
        $this->explain()->assertOk();
        $this->assertSame(['type' => 'function', 'function' => ['name' => 'submit_explanation']], $driver->payloads[1]['tool_choice']);

        // Anthropic Claude
        Explanation::query()->delete();
        $driver = (new RecordingClaudeDriver(['model' => 'claude-sonnet-4-5', 'apiKey' => 'test']))->recording([$read('c1'), $this->submit('c2')]);
        $this->useDriver($driver, ['model' => 'claude-sonnet-4-5']);
        $this->explain()->assertOk();
        $this->assertSame(['type' => 'tool', 'name' => 'submit_explanation'], $driver->payloads[1]['tool_choice']);
        $this->assertSame(['type' => 'any'], $driver->payloads[0]['tool_choice'], 'Claude must call some tool on every step');
    }

    public function test_reasoning_effort_goes_only_where_the_api_supports_it(): void
    {
        $reply = fn () => [$this->submit('c1')];

        // Chat Completions: only reasoning models accept reasoning_effort.
        foreach (['gpt-4.1-mini' => false, 'gpt-5-mini' => true, 'openai/o4-mini' => true, 'qwen3:8b' => true, 'llama-3.3-70b' => false] as $model => $sent) {
            Explanation::query()->delete();
            $driver = (new RecordingChatDriver(['model' => $model]))->recording($reply());
            $this->useDriver($driver, ['model' => $model]);
            $this->explain()->assertOk();
            $this->assertSame($sent, ($driver->payloads[0]['reasoning_effort'] ?? null) === 'low', $model);
        }

        // Claude: never (thinking can't be combined with forcing a tool, and isn't replayed between tool turns).
        Explanation::query()->delete();
        $driver = (new RecordingClaudeDriver(['model' => 'claude-sonnet-4-5', 'apiKey' => 'test']))->recording($reply());
        $this->useDriver($driver, ['model' => 'claude-sonnet-4-5', 'reasoning' => 'on']);
        $this->explain()->assertOk();
        $this->assertArrayNotHasKey('reasoning_effort', $driver->payloads[0]);
        $this->assertArrayNotHasKey('thinking', $driver->payloads[0]);
        $this->assertSame(16000, $driver->payloads[0]['max_tokens'] ?? null, 'long enough for an explanation (Claude defaults to 1024)');

        // off means off; on forces it for any chat model.
        Explanation::query()->delete();
        $driver = (new RecordingResponsesDriver(['model' => 'gpt-5-mini']))->recording($reply());
        $this->useDriver($driver, ['reasoning' => 'off']);
        $this->explain()->assertOk();
        $this->assertArrayNotHasKey('reasoning', $driver->payloads[0]);

        Explanation::query()->delete();
        $driver = (new RecordingChatDriver(['model' => 'my-custom-model']))->recording($reply());
        $this->useDriver($driver, ['model' => 'my-custom-model', 'reasoning' => 'on']);
        $this->explain()->assertOk();
        $this->assertSame('low', $driver->payloads[0]['reasoning_effort']);
    }

    public function test_changes_use_the_higher_edit_effort(): void
    {
        config(['alfred.edit.enabled' => true, 'alfred.edit.environments' => ['testing'], 'alfred.edit.mode' => 'propose', 'alfred.edit.reasoning_effort' => 'medium', 'alfred.laragent.reasoning_effort' => 'low']);
        $driver = (new RecordingResponsesDriver(['model' => 'gpt-5-mini']))->recording([
            $this->toolCall('c1', 'propose_edit', ['summary' => 'Nothing to change', 'notes' => 'Already fine.', 'edits' => []]),
        ]);
        $this->useDriver($driver);
        $verifier = $this->user('verifier@example.com');

        $this->actingAs($verifier)->postJson('/_alfred/changes', ['token' => $this->loadDashboard($verifier), 'element' => $this->element(), 'instruction' => 'move it left'])
            ->assertCreated();

        $this->assertSame(['effort' => 'medium'], $driver->payloads[0]['reasoning']);
    }

    public function test_tool_results_carry_the_tool_name_for_claude(): void
    {
        $driver = (new RecordingClaudeDriver(['model' => 'claude-sonnet-4-5', 'apiKey' => 'test']))->recording([
            $this->toolCall('toolu_1', 'read_file', ['path' => 'app/Http/Controllers/DashboardController.php', 'start_line' => null, 'end_line' => null]),
            $this->submit('toolu_2'),
        ]);
        $this->useDriver($driver, ['model' => 'claude-sonnet-4-5']);

        $this->explain()->assertOk();

        $second = $driver->payloads[1];
        $this->assertStringContainsString('How is this calculated?', is_string($second['system']) ? $second['system'] : json_encode($second['system']));
        $this->assertStringContainsString('"tool_use_id":"toolu_1"', json_encode($second['messages']));
    }

    public function test_provider_errors_are_explained(): void
    {
        $driver = (new RecordingResponsesDriver(['model' => 'gpt-5-mini']))->recording([new \Exception('Unexpected finish reason: length')]);
        $this->useDriver($driver);
        $this->explain()->assertStatus(502);

        $client = new LarAgentClient(['provider' => 'nope']);
        try {
            $client->respond('x', [], []);
            $this->fail('expected an exception');
        } catch (LlmException $e) {
            $this->assertStringContainsString('LarAgent provider [nope] is not set up', $e->getMessage());
        }

        $client = new LarAgentClient(['provider' => 'test', 'driver' => $driver->recording([new \Exception('Unexpected finish reason: length')])]);
        try {
            $client->respond('x', [], []);
        } catch (LlmException $e) {
            $this->assertStringContainsString('raise ALFRED_LARAGENT_MAX_TOKENS', $e->getMessage());
        }
    }

    public function test_the_provider_comes_from_laragents_own_config(): void
    {
        config([
            'alfred.provider' => 'laragent',
            'alfred.laragent' => ['provider' => 'openai_responses', 'model' => null, 'reasoning' => 'auto', 'reasoning_effort' => 'low', 'max_tokens' => 16000],
            'laragent.providers.openai_responses' => ['driver' => RecordingResponsesDriver::class, 'model' => 'gpt-5-nano', 'api_key' => null],
        ]);
        $this->app->forgetInstance(LlmClient::class);
        $this->app->offsetUnset(LlmClient::class);
        (new AlfredServiceProvider($this->app))->register();

        $client = $this->app->make(LlmClient::class);
        $this->assertInstanceOf(LarAgentClient::class, $client);
        $this->assertSame('gpt-5-nano', $client->model());
        $this->assertSame('openai_responses', $client->provider());
    }

    public function test_a_model_is_always_known(): void
    {
        config(['alfred.openai.model' => 'gpt-5-mini', 'laragent.providers' => [
            'openai_responses' => ['driver' => OpenAiResponsesDriver::class, 'api_key' => null],
            'ollama' => ['driver' => OllamaDriver::class, 'api_key' => 'ollama'],
            'claude' => ['driver' => ClaudeDriver::class, 'model' => 'claude-sonnet-4-5'],
        ]]);

        $this->assertSame('gpt-5-mini', (new LarAgentClient(['provider' => 'openai_responses']))->model(), 'stock OpenAI providers have no model');
        $this->assertSame('claude-sonnet-4-5', (new LarAgentClient(['provider' => 'claude']))->model());
        $this->assertSame('qwen3:14b', (new LarAgentClient(['provider' => 'ollama', 'model' => 'qwen3:14b']))->model());

        try {
            (new LarAgentClient(['provider' => 'ollama']))->respond('x', [], []);
            $this->fail('expected an exception');
        } catch (LlmException $e) {
            $this->assertStringContainsString('has no model. Set ALFRED_LARAGENT_MODEL', $e->getMessage());
        }
    }

    public function test_openai_providers_fall_back_to_alfreds_openai_key(): void
    {
        config([
            'alfred.openai.api_key' => 'sk-alfred',
            'laragent.providers.openai_responses' => ['driver' => RecordingResponsesDriver::class, 'api_key' => null],
            'laragent.providers.openrouter' => ['driver' => OpenRouter::class, 'api_key' => null, 'model' => 'x'],
            'laragent.providers.own' => ['driver' => RecordingResponsesDriver::class, 'api_key' => 'sk-own'],
        ]);
        $key = fn (string $provider) => (fn () => $this->apiKey($this->providerConfig()))->call(new LarAgentClient(['provider' => $provider]));

        $this->assertSame('sk-alfred', $key('openai_responses'), 'OPENAI_API_KEY not set: use ALFRED_OPENAI_API_KEY');
        $this->assertSame('sk-own', $key('own'), "the provider's own key wins");
        $this->assertNull($key('openrouter'), 'never sent to a non-OpenAI service');
    }

    public function test_reasoning_model_detection(): void
    {
        foreach (['o3', 'o4-mini', 'gpt-5', 'gpt-5-mini', 'openai/gpt-oss-120b', 'gemini-2.5-flash', 'gemini-3-pro', 'qwen3:14b', 'deepseek-r1:8b'] as $m) {
            $this->assertTrue(LarAgentClient::reasoningModel($m), $m);
        }
        foreach (['gpt-4o', 'gpt-4.1-mini', 'llama-3.3-70b-versatile', 'gemini-2.0-flash', 'claude-sonnet-4-5', 'mistral-large'] as $m) {
            $this->assertFalse(LarAgentClient::reasoningModel($m), $m);
        }
    }
}
