<?php

namespace Atoria\Alfred\Tests\Feature;

use Atoria\Alfred\Changes\Change;
use Atoria\Alfred\Events\ChangeApplied;
use Atoria\Alfred\Events\ChangeProposed;
use Atoria\Alfred\Events\ExplanationGenerated;
use Atoria\Alfred\Events\ExplanationVerified;
use Atoria\Alfred\Events\FollowUpAnswered;
use Atoria\Alfred\Security\SecurityEvent;
use Atoria\Alfred\Tests\TestCase;
use Atoria\Alfred\Usage\Usage;
use Illuminate\Support\Facades\Event;

/** What the AI costs, budgets that cap it, events apps can hook into, and housekeeping. */
class UsageTest extends TestCase
{
    protected function explain($user)
    {
        $this->llm->submit();

        return $this->actingAs($user)->postJson('/_alfred/explain', ['token' => $this->loadDashboard($user), 'element' => $this->element()]);
    }

    public function test_every_ai_call_is_counted_per_day_person_and_kind(): void
    {
        $user = $this->user();
        $this->explain($user)->assertOk();

        $row = Usage::query()->sole();
        $this->assertSame('explanation', $row->kind);
        $this->assertSame('u:'.$user->id, $row->user_key);
        $this->assertSame(1, $row->requests);
        $this->assertSame(100, $row->input_tokens); // what the fake model reports
        $this->assertSame(20, $row->output_tokens);
        $this->assertSame(now()->toDateString(), $row->day);

        $this->artisan('alfred:usage')->expectsOutputToContain('explanation')->assertSuccessful();
        $this->artisan('alfred:usage', ['--by' => 'nonsense'])->assertFailed();
    }

    public function test_a_spent_daily_budget_stops_new_ai_work_but_not_cached_answers(): void
    {
        config(['alfred.usage.daily_tokens' => 100]);
        $user = $this->user();
        $this->explain($user)->assertOk(); // 120 tokens: over budget now

        $this->explain($user)->assertOk()->assertJson(['cached' => true]);
        $this->llm->submit();
        $this->actingAs($user)->postJson('/_alfred/explain', ['token' => $this->loadDashboard($user), 'element' => $this->element(['labels' => ['Other']])])
            ->assertStatus(429)->assertJsonPath('message', fn ($m) => str_contains($m, 'budget for today has been used up'));
    }

    public function test_a_per_person_budget_leaves_others_alone(): void
    {
        config(['alfred.usage.daily_tokens_per_user' => 100]);
        $this->explain($this->user())->assertOk();

        $this->llm->submit();
        $other = $this->user('other@example.com');
        $this->actingAs($other)->postJson('/_alfred/explain', ['token' => $this->loadDashboard($other), 'element' => $this->element(['labels' => ['Other']])])->assertOk();
    }

    public function test_apps_can_listen_to_what_happens(): void
    {
        Event::fake([ExplanationGenerated::class, ExplanationVerified::class, FollowUpAnswered::class, ChangeProposed::class, ChangeApplied::class]);
        $verifier = $this->user('verifier@example.com');
        $id = $this->explain($verifier)->json('id');
        $this->actingAs($verifier)->postJson("/_alfred/explanations/{$id}/verify")->assertOk();
        $this->llm->then([['submit_answer', ['answer' => 'Yes.', 'developer' => null, 'confidence' => 'high']]]);
        $this->actingAs($verifier)->postJson("/_alfred/explanations/{$id}/ask", ['token' => $this->loadDashboard($verifier), 'element' => $this->element(), 'question' => 'Does it include cancelled shifts?'])->assertOk();

        Event::assertDispatched(ExplanationGenerated::class, fn ($e) => $e->explanation->id === $id);
        Event::assertDispatched(ExplanationVerified::class, fn ($e) => $e->by === 'verifier@example.com');
        Event::assertDispatched(FollowUpAnswered::class, fn ($e) => $e->question === 'Does it include cancelled shifts?' && $e->answer === 'Yes.');
    }

    public function test_old_changes_and_security_events_can_be_pruned(): void
    {
        config(['alfred.retention.changes_days' => 30]);
        $make = fn (string $status, int $daysAgo) => tap(Change::query()->create(['user_key' => 'u:1', 'environment' => 'testing', 'instruction' => 'x', 'summary' => 'x', 'edits' => [], 'files' => [], 'status' => $status]))
            ->forceFill(['created_at' => now()->subDays($daysAgo)])->save();
        $make(Change::PROPOSED, 40);
        $make(Change::APPLIED, 40);
        $make(Change::PROPOSED, 5);
        SecurityEvent::query()->create(['user_key' => 'u:1', 'category' => 'secrets', 'where' => 'follow-up', 'excerpt' => 'x', 'created_at' => now()->subDays(400)]);

        $this->artisan('model:prune', ['--model' => [Change::class, SecurityEvent::class]])->assertSuccessful();

        $this->assertSame([Change::APPLIED, Change::PROPOSED], Change::query()->orderBy('id')->pluck('status')->all(), 'applied changes are kept by default');
        $this->assertSame(0, SecurityEvent::query()->count());
    }

    public function test_doctor_reports_problems_without_printing_secrets(): void
    {
        config(['alfred.openai.api_key' => 'sk-test-secret-value-123', 'alfred.edit.enabled' => true, 'alfred.edit.mode' => 'propose']);
        $this->artisan('alfred:doctor')
            ->expectsOutputToContain('key set (24 characters)')
            ->doesntExpectOutputToContain('sk-test-secret')
            ->assertSuccessful();

        config(['alfred.openai.api_key' => null]);
        $this->artisan('alfred:doctor')->expectsOutputToContain('OpenAI key missing')->assertFailed();
    }
}
