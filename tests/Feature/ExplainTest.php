<?php

namespace Atoria\Alfred\Tests\Feature;

use Atoria\Alfred\Explanations\Explanation;
use Atoria\Alfred\Tests\TestCase;

class ExplainTest extends TestCase
{
    private string $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = file_get_contents(self::fixturePath().'/app/Models/Shift.php');
    }

    protected function tearDown(): void
    {
        // restore the fixture if a test touched it
        file_put_contents(self::fixturePath().'/app/Models/Shift.php', $this->original);
        parent::tearDown();
    }

    public function test_explains_by_reading_code_then_caches(): void
    {
        $user = $this->user();
        $token = $this->loadDashboard($user);

        $this->llm
            ->then([['read_file', ['path' => 'app/Http/Controllers/DashboardController.php', 'start_line' => null, 'end_line' => null]]])
            ->submit();

        $first = $this->actingAs($user)->postJson('/_alfred/explain', ['token' => $token, 'element' => $this->element()])
            ->assertOk()
            ->assertJson(['title' => 'Fill rate', 'formula' => 'Booked shifts ÷ all shifts × 100', 'cached' => false, 'verified' => false])
            ->assertJsonMissingPath('technical_notes'); // not a verifier

        // The agent saw the controller source, with line numbers.
        $this->assertStringContainsString('11          $booked = Shift::query()->booked()->count();', $this->llm->toolOutputs()[0]);

        $e = Explanation::find($first->json('id'));
        $this->assertArrayHasKey('app/Http/Controllers/DashboardController.php', $e->files);
        $this->assertStringContainsString('count(*)', $e->query_sql);

        // Same thing, another user, another value: served from cache, no LLM call.
        $other = $this->user('other@example.com');
        $this->actingAs($other)->postJson('/_alfred/explain', ['token' => $this->loadDashboard($other), 'element' => $this->element(['text' => '75%'])])
            ->assertOk()->assertJson(['id' => $e->id, 'cached' => true]);
        $this->assertCount(2, $this->llm->requests);
    }

    public function test_code_only_privacy_keeps_page_values_and_bindings_out_of_the_prompt(): void
    {
        $user = $this->user();
        $token = $this->loadDashboard($user);
        $this->llm->submit();

        $this->actingAs($user)->postJson('/_alfred/explain', ['token' => $token, 'element' => $this->element(['text' => 'Jane Doe 50%'])])->assertOk();

        $prompt = $this->llm->requests[0]['input'][0]['content'];
        $this->assertStringContainsString('"Fill rate"', $prompt);
        $this->assertStringContainsString('DashboardController.php:11', $prompt);
        $this->assertStringNotContainsString('Jane', $prompt);
        $this->assertStringNotContainsString('bindings=', $prompt);
    }

    public function test_agent_cannot_read_secrets(): void
    {
        $user = $this->user();
        $token = $this->loadDashboard($user);

        $this->llm
            ->then([
                ['read_file', ['path' => '.env', 'start_line' => null, 'end_line' => null]],
                ['read_file', ['path' => '../../../../.env', 'start_line' => null, 'end_line' => null]],
                ['search_code', ['query' => 'sk-should-never-be-read', 'path' => null, 'regex' => false]],
            ])
            ->submit();

        $this->actingAs($user)->postJson('/_alfred/explain', ['token' => $token, 'element' => $this->element()])->assertOk();

        [$env, $traversal, $search] = $this->llm->toolOutputs();
        $this->assertStringStartsWith('Denied:', $env);
        $this->assertStringStartsWith('Denied:', $traversal);
        $this->assertStringStartsWith('No matches', $search);
    }

    public function test_changed_code_regenerates_unverified_explanations(): void
    {
        $user = $this->user();
        $token = $this->loadDashboard($user);
        $this->llm->then([['read_file', ['path' => 'app/Models/Shift.php', 'start_line' => null, 'end_line' => null]]])->submit();
        $this->actingAs($user)->postJson('/_alfred/explain', ['token' => $token, 'element' => $this->element()])->assertOk();

        file_put_contents(self::fixturePath().'/app/Models/Shift.php', "\n// touched", FILE_APPEND);

        $this->llm->submit(['title' => 'Fill rate (new)']);
        $this->actingAs($user)->postJson('/_alfred/explain', ['token' => $token, 'element' => $this->element()])
            ->assertOk()->assertJson(['title' => 'Fill rate (new)', 'cached' => false]);
    }

    public function test_verified_explanations_are_kept_and_flagged_when_code_changes(): void
    {
        $verifier = $this->user('verifier@example.com');
        $token = $this->loadDashboard($verifier);
        $this->llm->then([['read_file', ['path' => 'app/Models/Shift.php', 'start_line' => null, 'end_line' => null]]])->submit();

        $id = $this->actingAs($verifier)->postJson('/_alfred/explain', ['token' => $token, 'element' => $this->element()])
            ->assertJsonPath('technical_notes.0', 'app/Http/Controllers/DashboardController.php:11')
            ->json('id');

        $this->actingAs($verifier)->postJson("/_alfred/explanations/{$id}/verify")->assertOk()->assertJson(['verified' => true, 'verified_by' => 'verifier@example.com']);

        file_put_contents(self::fixturePath().'/app/Models/Shift.php', "\n// touched", FILE_APPEND);

        $this->actingAs($verifier)->postJson('/_alfred/explain', ['token' => $token, 'element' => $this->element()])
            ->assertOk()->assertJson(['id' => $id, 'verified' => true, 'stale' => true, 'cached' => true]);
        $this->assertCount(2, $this->llm->requests, 'no regeneration');
    }

    public function test_only_verifiers_can_verify_edit_or_regenerate(): void
    {
        $user = $this->user();
        $token = $this->loadDashboard($user);
        $this->llm->submit();
        $id = $this->actingAs($user)->postJson('/_alfred/explain', ['token' => $token, 'element' => $this->element()])->json('id');

        $this->actingAs($user)->postJson("/_alfred/explanations/{$id}/verify")->assertForbidden();
        $this->actingAs($user)->putJson("/_alfred/explanations/{$id}", ['summary' => 'x'])->assertForbidden();
        $this->actingAs($user)->postJson('/_alfred/explain', ['token' => $token, 'element' => $this->element(), 'force' => true])->assertForbidden();
    }

    public function test_verifier_edits_become_verified(): void
    {
        $verifier = $this->user('verifier@example.com');
        $token = $this->loadDashboard($verifier);
        $this->llm->submit();
        $id = $this->actingAs($verifier)->postJson('/_alfred/explain', ['token' => $token, 'element' => $this->element()])->json('id');

        $this->actingAs($verifier)->putJson("/_alfred/explanations/{$id}", ['summary' => 'Share of shifts that are booked.', 'steps' => ['Count', 'Divide']])
            ->assertOk()->assertJson(['summary' => 'Share of shifts that are booked.', 'verified' => true, 'edited' => true, 'steps' => ['Count', 'Divide']]);
    }

    public function test_someone_elses_or_expired_page_token_is_rejected(): void
    {
        $token = $this->loadDashboard($this->user());

        $this->actingAs($this->user('other@example.com'))->postJson('/_alfred/explain', ['token' => $token, 'element' => $this->element()])->assertStatus(410);
        $this->actingAs($this->user('third@example.com'))->postJson('/_alfred/explain', ['token' => str_repeat('z', 40), 'element' => $this->element()])->assertStatus(410);
    }

    public function test_generation_is_rate_limited_per_user(): void
    {
        config()->set('alfred.rate_limit', 1);
        $user = $this->user();
        $token = $this->loadDashboard($user);
        $this->llm->submit();

        $this->actingAs($user)->postJson('/_alfred/explain', ['token' => $token, 'element' => $this->element()])->assertOk();
        $this->actingAs($user)->postJson('/_alfred/explain', ['token' => $token, 'element' => $this->element(['labels' => ['Something else']])])->assertStatus(429);
    }

    public function test_step_limit_forces_a_submission(): void
    {
        config()->set('alfred.agent.max_steps', 2);
        $user = $this->user();
        $token = $this->loadDashboard($user);
        $this->llm->then([['list_files', ['path' => 'app']]])->submit(['confidence' => 'low']);

        $this->actingAs($user)->postJson('/_alfred/explain', ['token' => $token, 'element' => $this->element()])->assertOk()->assertJson(['confidence' => 'low']);
        $this->assertSame('submit_explanation', $this->llm->requests[1]['force']);
    }
}
