<?php

namespace Atoria\Alfred\Tests\Feature;

use Atoria\Alfred\AlfredServiceProvider;
use Atoria\Alfred\Explanations\Explanation;
use Atoria\Alfred\Llm\LarAgentClient;
use Atoria\Alfred\Llm\LlmClient;
use Atoria\Alfred\Llm\LlmCutOff;
use Atoria\Alfred\Llm\LlmException;
use Atoria\Alfred\Tests\Fixtures\LarAgent\RecordingChatDriver;
use Atoria\Alfred\Tests\Fixtures\LarAgent\RecordingClaudeDriver;
use Atoria\Alfred\Tests\Fixtures\LarAgent\RecordingResponsesDriver;
use Atoria\Alfred\Tests\Fixtures\LarAgent\ScriptedResponsesDriver;
use Atoria\Alfred\Tests\TestCase;
use Illuminate\Support\Facades\Exceptions;
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

    public function test_a_reply_cut_off_at_the_token_limit_is_retried_once_shorter(): void
    {
        $cutOff = fn () => [
            'last' => ['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens'], 'usage' => ['input_tokens' => 500, 'output_tokens' => 16000],
                'output' => [['type' => 'function_call', 'name' => 'submit_explanation', 'arguments' => '{"title":"Fill']]],
            'throw' => new \InvalidArgumentException('ToolCall arguments must be a valid JSON string. Error: Syntax error'),
        ];
        Exceptions::fake();

        $driver = (new RecordingResponsesDriver(['model' => 'gpt-5-mini']))->recording([$cutOff(), $this->submit()]);
        $this->useDriver($driver);
        $this->explain()->assertOk()->assertJson(['title' => 'Fill rate']);

        $retry = $driver->payloads->getArrayCopy()[1];
        $this->assertStringContainsString('Your last reply was cut off', json_encode($retry['input']));
        $this->assertSame(16020, Explanation::query()->first()->output_tokens, 'the lost reply still counts');
        Exceptions::assertReported(fn (LlmException $e) => str_contains($e->getMessage(), '"incomplete_reason":"max_output_tokens"') && str_contains($e->getMessage(), 'submit_explanation (14 chars)'));

        // Twice in a row: a clear message, not a JSON parse error.
        $driver = (new RecordingResponsesDriver(['model' => 'gpt-5-mini']))->recording([$cutOff(), $cutOff()]);
        $this->useDriver($driver);
        $verifier = $this->user('verifier@example.com');
        $this->actingAs($verifier)->postJson('/_alfred/explain', ['token' => $this->loadDashboard($verifier), 'element' => $this->element(['labels' => ['Other']])])
            ->assertStatus(502)->assertJsonPath('message', fn ($m) => str_contains($m, 'cut off at the token limit (16000 tokens). Raise ALFRED_LARAGENT_MAX_TOKENS'));
    }

    public function test_provider_errors_are_explained(): void
    {
        // Chat-style drivers report a cut-off as "finish reason: length": retried once, then explained.
        $driver = (new RecordingResponsesDriver(['model' => 'gpt-5-mini']))->recording([new \Exception('Unexpected finish reason: length'), new \Exception('Unexpected finish reason: length')]);
        $this->useDriver($driver);
        $this->explain()->assertStatus(502);
        $this->assertCount(2, $driver->payloads);

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
            $this->fail('expected an exception');
        } catch (LlmCutOff $e) {
            $this->assertStringContainsString('ALFRED_LARAGENT_MAX_TOKENS', $e->getMessage());
        }

        // Ordinary errors are not mistaken for cut-offs.
        $client = new LarAgentClient(['provider' => 'test', 'driver' => $driver->recording([new \Exception('Incorrect API key provided')])]);
        try {
            $client->respond('x', [], []);
            $this->fail('expected an exception');
        } catch (LlmException $e) {
            $this->assertNotInstanceOf(LlmCutOff::class, $e);
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

    protected function scripted(array $config, array $alfred = []): void
    {
        ScriptedResponsesDriver::$scripts = [];
        ScriptedResponsesDriver::$payloads = [];
        config(['laragent.providers' => $config, 'laragent.default_providers' => null, 'laragent.fallback_provider' => null]);
        config(['alfred.provider' => 'laragent']);
        $this->app->bind(LlmClient::class, fn () => new LarAgentClient($alfred + ['reasoning' => 'auto', 'reasoning_effort' => 'low']));
    }

    public function test_the_connection_comes_from_laragent_and_the_task_settings_from_alfred(): void
    {
        $this->scripted([
            'mine' => ['driver' => ScriptedResponsesDriver::class, 'api_key' => 'k', 'model' => 'gpt-4.1-mini',
                'default_max_completion_tokens' => 30000, 'default_temperature' => 1.3, 'default_top_p' => 0.5, 'parallel_tool_calls' => false],
        ], ['provider' => 'mine']);
        ScriptedResponsesDriver::$scripts['gpt-4.1-mini'] = [$this->submit()];

        $this->explain()->assertOk();
        $payload = ScriptedResponsesDriver::$payloads['gpt-4.1-mini'][0];
        $this->assertSame('gpt-4.1-mini', $payload['model'], "the provider's model");
        $this->assertSame(30000, $payload['max_output_tokens'], 'a provider limit above 16000 is kept');
        $this->assertFalse($payload['parallel_tool_calls'], 'what the provider supports');
        $this->assertArrayNotHasKey('temperature', $payload, "the app's agents' sampling is not Alfred's");
        $this->assertArrayNotHasKey('top_p', $payload);
    }

    public function test_a_low_provider_limit_never_cuts_alfred_short_but_alfreds_own_settings_win(): void
    {
        $stock = ['driver' => ScriptedResponsesDriver::class, 'api_key' => 'k', 'model' => 'gpt-5-mini', 'default_max_completion_tokens' => 10000, 'default_temperature' => 1];
        $this->scripted(['openai_responses' => $stock]);

        $this->assertSame(16000, (new LarAgentClient(['provider' => 'openai_responses']))->maxTokens(), "LarAgent's stock 10000 is too short for Alfred");
        $this->assertSame(40000, (new LarAgentClient(['provider' => 'openai_responses', 'max_tokens' => '40000']))->maxTokens(), 'ALFRED_LARAGENT_MAX_TOKENS wins');
        $this->assertSame(8000, (new LarAgentClient(['provider' => 'openai_responses', 'max_tokens' => '8000']))->maxTokens(), 'even when lower: it is explicit');
        $this->assertSame(16000, (new LarAgentClient(['provider' => 'nothing-set']))->maxTokens());

        $this->scripted(['openai_responses' => $stock], ['provider' => 'openai_responses', 'temperature' => '0.2']);
        ScriptedResponsesDriver::$scripts['gpt-5-mini'] = [$this->submit()];
        $this->explain()->assertOk();
        $this->assertSame(0.2, ScriptedResponsesDriver::$payloads['gpt-5-mini'][0]['temperature'], 'ALFRED_LARAGENT_TEMPERATURE is sent when set');
    }

    public function test_a_failing_provider_falls_back_to_the_next(): void
    {
        $this->scripted([
            'primary' => ['driver' => ScriptedResponsesDriver::class, 'api_key' => 'k', 'model' => 'model-a'],
            'backup' => ['driver' => ScriptedResponsesDriver::class, 'api_key' => 'k', 'model' => 'model-b'],
        ], ['provider' => 'primary,backup']);
        ScriptedResponsesDriver::$scripts['model-a'] = [new \Exception('503 Service Unavailable')];
        ScriptedResponsesDriver::$scripts['model-b'] = [$this->submit()];
        Exceptions::fake();

        $this->explain()->assertOk()->assertJson(['title' => 'Fill rate']);
        $this->assertSame('model-b', Explanation::query()->first()->model);
        Exceptions::assertReported(fn (LlmException $e) => str_contains($e->getMessage(), 'provider [primary] failed, trying [backup]'));
    }

    public function test_laragents_default_providers_and_overrides_are_followed(): void
    {
        $this->scripted([
            'default' => ['driver' => ScriptedResponsesDriver::class, 'api_key' => 'k', 'model' => 'model-a'],
            'gemini' => ['driver' => ScriptedResponsesDriver::class, 'api_key' => 'k', 'model' => 'model-b'],
        ]);
        config(['laragent.default_providers' => ['default', 'gemini' => ['model' => 'model-c']]]);

        $client = new LarAgentClient([]);
        $this->assertSame(['default', 'gemini'], array_column($client->providers(), 'name'));
        $this->assertSame('model-c', $client->providers()[1]['config']['model']);

        config(['laragent.default_providers' => null, 'laragent.fallback_provider' => 'gemini']);
        $this->assertSame(['default', 'gemini'], array_column((new LarAgentClient([]))->providers(), 'name'), "LarAgent's older fallback_provider");
    }

    public function test_a_cut_off_reply_does_not_switch_provider(): void
    {
        $this->scripted([
            'primary' => ['driver' => ScriptedResponsesDriver::class, 'api_key' => 'k', 'model' => 'model-a'],
            'backup' => ['driver' => ScriptedResponsesDriver::class, 'api_key' => 'k', 'model' => 'model-b'],
        ], ['provider' => 'primary,backup']);
        ScriptedResponsesDriver::$scripts['model-a'] = [new \Exception('Unexpected finish reason: length'), $this->submit()];

        $this->explain()->assertOk();
        $this->assertArrayNotHasKey('model-b', ScriptedResponsesDriver::$payloads);
    }

    public function test_doctor_checks_every_provider_without_printing_keys(): void
    {
        $this->scripted([
            'primary' => ['driver' => ScriptedResponsesDriver::class, 'api_key' => 'secret-key-123', 'model' => 'model-a', 'default_max_completion_tokens' => 20000],
            'broken' => ['driver' => ScriptedResponsesDriver::class, 'api_key' => null, 'model' => 'model-b'],
        ]);
        config(['alfred.laragent' => ['provider' => 'primary,broken,missing'], 'alfred.openai.api_key' => null]);

        $this->artisan('alfred:doctor')
            ->expectsOutputToContain('LarAgent [primary] model model-a, key set (14 characters), up to 20000 tokens per reply')
            ->expectsOutputToContain('LarAgent [broken] has no API key')
            ->expectsOutputToContain('LarAgent [missing] not in config/laragent.php')
            ->doesntExpectOutputToContain('secret-key-123')
            ->assertFailed();
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
