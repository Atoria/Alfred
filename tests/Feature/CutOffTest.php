<?php

namespace Atoria\Alfred\Tests\Feature;

use Atoria\Alfred\Llm\LlmClient;
use Atoria\Alfred\Llm\OpenAiClient;
use Atoria\Alfred\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/** Replies cut off before they finish are recovered from, whatever the provider. */
class CutOffTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(LlmClient::class, new OpenAiClient(['api_key' => 'sk-test', 'model' => 'gpt-5-mini', 'reasoning_effort' => 'low']));
    }

    protected function toolCall(string $name, string $arguments, string $id = 'c1'): array
    {
        return ['type' => 'function_call', 'id' => 'fc_'.$id, 'call_id' => $id, 'name' => $name, 'arguments' => $arguments];
    }

    protected function submit(): array
    {
        return $this->toolCall('submit_explanation', json_encode([
            'title' => 'Fill rate', 'summary' => 'Booked shifts as a share of all shifts.', 'formula' => null, 'steps' => [], 'data_sources' => [],
            'caveats' => [], 'example' => null, 'glossary' => [], 'query_index' => null, 'confidence' => 'high',
            'developer' => ['summary' => 'x', 'flow' => [], 'queries' => [], 'caching' => null, 'gotchas' => []],
        ]), 'c2');
    }

    protected function explain()
    {
        $user = $this->user();

        return $this->actingAs($user)->postJson('/_alfred/explain', ['token' => $this->loadDashboard($user), 'element' => $this->element()]);
    }

    public function test_a_reply_cut_off_at_the_output_limit_is_asked_again(): void
    {
        Http::fakeSequence('api.openai.com/*')
            ->push(['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens'], 'output' => [$this->toolCall('submit_explanation', '{"title":"Fi')], 'usage' => ['input_tokens' => 900, 'output_tokens' => 4000]])
            ->push(['status' => 'completed', 'output' => [$this->submit()], 'usage' => ['input_tokens' => 1000, 'output_tokens' => 300]]);

        $this->explain()->assertOk()->assertJson(['title' => 'Fill rate']);

        $requests = Http::recorded();
        $this->assertCount(2, $requests);
        $this->assertStringContainsString('Your last reply was cut off', json_encode($requests[1][0]['input']));
    }

    public function test_a_tool_call_with_broken_arguments_is_not_run_and_the_model_is_told(): void
    {
        Http::fakeSequence('api.openai.com/*')
            ->push(['status' => 'completed', 'output' => [$this->toolCall('read_file', '{"path":"app/Http/Contr')], 'usage' => ['input_tokens' => 900, 'output_tokens' => 50]])
            ->push(['status' => 'completed', 'output' => [$this->submit()], 'usage' => ['input_tokens' => 1000, 'output_tokens' => 300]]);

        $this->explain()->assertOk()->assertJson(['title' => 'Fill rate']);

        $second = json_encode(Http::recorded()[1][0]['input']);
        $this->assertStringContainsString('Your call to read_file was cut off or its arguments were not valid JSON, so it was not run.', $second);
    }

    public function test_cut_off_twice_ends_with_a_clear_message(): void
    {
        $cut = ['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens'], 'output' => [], 'usage' => ['input_tokens' => 900, 'output_tokens' => 4000]];
        Http::fakeSequence('api.openai.com/*')->push($cut)->push($cut);

        $verifier = $this->user('verifier@example.com');
        $this->actingAs($verifier)->postJson('/_alfred/explain', ['token' => $this->loadDashboard($verifier), 'element' => $this->element()])
            ->assertStatus(502)->assertJsonPath('message', fn ($m) => str_contains($m, 'cut off before it finished (max_output_tokens)'));
    }
}
