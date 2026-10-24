<?php

namespace Atoria\Alfred\Tests\Feature;

use Atoria\Alfred\Llm\LlmException;
use Atoria\Alfred\Llm\OpenAiClient;
use Atoria\Alfred\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class OpenAiClientTest extends TestCase
{
    public function test_sends_a_stateless_responses_request_and_parses_tool_calls(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'output' => [
                ['type' => 'reasoning', 'id' => 'rs_1', 'encrypted_content' => 'xxx', 'summary' => []],
                ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'read_file', 'arguments' => '{"path":"app/X.php","start_line":null,"end_line":null}'],
            ],
            'usage' => ['input_tokens' => 1200, 'output_tokens' => 80],
        ])]);

        $client = new OpenAiClient(['api_key' => 'sk-test', 'model' => 'gpt-5-mini', 'reasoning_effort' => 'low']);
        $res = $client->respond('be helpful', [$client->userMessage('hi')], [[
            'name' => 'read_file', 'description' => 'd', 'parameters' => ['type' => 'object'], 'strict' => true,
        ]]);

        $this->assertSame('read_file', $res->calls[0]['name']);
        $this->assertSame('app/X.php', $res->calls[0]['arguments']['path']);
        $this->assertSame(1200, $res->inputTokens);
        $this->assertCount(2, $res->items, 'reasoning item is carried for the next turn');

        Http::assertSent(function (Request $r) {
            return $r->url() === 'https://api.openai.com/v1/responses'
                && $r->hasHeader('Authorization', 'Bearer sk-test')
                && $r['store'] === false
                && $r['include'] === ['reasoning.encrypted_content']
                && $r['tool_choice'] === 'required'
                && $r['tools'][0]['type'] === 'function'
                && $r['tools'][0]['strict'] === true;
        });
    }

    public function test_forced_tool_and_errors(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid model']], 400)]);
        $client = new OpenAiClient(['api_key' => 'sk-test', 'model' => 'nope', 'base_url' => 'https://gateway.example/v1']);

        try {
            $client->respond('x', [], [], 'submit_explanation');
            $this->fail('expected exception');
        } catch (LlmException $e) {
            $this->assertStringContainsString('Invalid model', $e->getMessage());
        }

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://gateway.example/v1/responses')
            && $r['tool_choice'] === ['type' => 'function', 'name' => 'submit_explanation']
            && ! isset($r['reasoning']));
    }

    public function test_missing_key_is_a_clear_error(): void
    {
        $this->expectExceptionMessage('ALFRED_OPENAI_API_KEY');
        (new OpenAiClient(['api_key' => null]))->respond('x', [], []);
    }
}
