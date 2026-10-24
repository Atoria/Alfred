<?php

namespace Atoria\Alfred\Tests\Feature;

use Atoria\Alfred\Changes\Change;
use Atoria\Alfred\Context\CodeLocator;
use Atoria\Alfred\Explanations\Explanation;
use Atoria\Alfred\Tests\TestCase;

/** Follow-up questions under an explanation, and carrying on to a change or ticket from it. */
class FollowUpTest extends TestCase
{
    protected function explained($user): array
    {
        $token = $this->loadDashboard($user);
        $this->llm->submit();
        $id = $this->actingAs($user)->postJson('/_alfred/explain', ['token' => $token, 'element' => $this->element()])->assertOk()->json('id');

        return [$token, $id];
    }

    protected function answer(string $answer, ?string $developer = 'DashboardController::index counts status = booked.', string $confidence = 'high'): static
    {
        $this->llm->then([['submit_answer', ['answer' => $answer, 'developer' => $developer, 'confidence' => $confidence]]]);

        return $this;
    }

    protected function ask($user, string $token, int $id, string $question, ?string $thread = null)
    {
        return $this->actingAs($user)->postJson("/_alfred/explanations/{$id}/ask", [
            'token' => $token, 'element' => $this->element(), 'question' => $question, 'thread' => $thread,
        ]);
    }

    public function test_a_follow_up_is_answered_from_the_explanation(): void
    {
        $user = $this->user();
        [$token, $id] = $this->explained($user);
        $this->answer('Yes. Cancelled shifts are counted in the total, so they lower the rate.');

        $res = $this->ask($user, $token, $id, 'Does this include cancelled shifts?')->assertOk()
            ->assertJsonPath('turns.0.question', 'Does this include cancelled shifts?')
            ->assertJsonPath('turns.0.answer', 'Yes. Cancelled shifts are counted in the total, so they lower the rate.')
            ->assertJsonPath('turns.0.developer', null); // not a verifier: no code notes
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{32}$/', $res->json('thread'));

        $request = end($this->llm->requests);
        $prompt = $request['input'][0]['content'];
        $this->assertStringContainsString('# The explanation they read', $prompt);
        $this->assertStringContainsString('Booked shifts as a share of all shifts.', $prompt);
        $this->assertStringContainsString('DashboardController.php:11', $prompt, 'starts from the developer flow');
        $this->assertStringContainsString("# New question\nDoes this include cancelled shifts?", $prompt);
        $this->assertStringContainsString('follow-up question', $request['instructions']);
        $this->assertContains('submit_answer', array_column($request['tools'], 'name'));
        $this->assertContains('read_file', array_column($request['tools'], 'name'), 'can still check the code');
    }

    public function test_the_thread_continues_and_verifiers_see_the_developer_note(): void
    {
        $verifier = $this->user('verifier@example.com');
        [$token, $id] = $this->explained($verifier);

        $this->answer('Yes, they are included.');
        $thread = $this->ask($verifier, $token, $id, 'Are cancelled shifts included?')->json('thread');

        $this->answer('Monday to Sunday, in UK time.', null);
        $this->ask($verifier, $token, $id, 'Which week is "this week"?', $thread)->assertOk()
            ->assertJsonPath('thread', $thread)
            ->assertJsonCount(2, 'turns')
            ->assertJsonPath('turns.0.developer', 'DashboardController::index counts status = booked.');

        $prompt = end($this->llm->requests)['input'][0]['content'];
        $this->assertStringContainsString("# Earlier follow-ups\nQ: Are cancelled shifts included?\nA: Yes, they are included.", $prompt);
    }

    public function test_code_in_the_answer_is_sent_back(): void
    {
        $user = $this->user();
        [$token, $id] = $this->explained($user);
        $this->answer('Shift::booked() filters on status.')->answer('Only shifts someone is booked on are counted.');

        $this->ask($user, $token, $id, 'What counts as booked?')->assertOk()
            ->assertJsonPath('turns.0.answer', 'Only shifts someone is booked on are counted.');
        $this->assertStringContainsString('Not accepted', end($this->llm->requests)['input'][count(end($this->llm->requests)['input']) - 1]['output']);
    }

    public function test_threads_are_private(): void
    {
        $user = $this->user();
        [$token, $id] = $this->explained($user);
        $this->answer('Yes.');
        $thread = $this->ask($user, $token, $id, 'Is this live?')->json('thread');

        $other = $this->user('other@example.com');
        $this->answer('No.');
        $this->ask($other, $this->loadDashboard($other), $id, 'And this?', $thread)->assertStatus(410);
    }

    public function test_a_verifier_can_keep_an_answer_on_the_explanation(): void
    {
        $verifier = $this->user('verifier@example.com');
        [$token, $id] = $this->explained($verifier);
        $this->answer('Yes, cancelled shifts lower the rate.');
        $thread = $this->ask($verifier, $token, $id, 'Do cancelled shifts count?')->json('thread');

        $this->actingAs($verifier)->postJson("/_alfred/explanations/{$id}/keep", ['thread' => $thread, 'turn' => 0])->assertOk()
            ->assertJsonPath('edited', true);
        $this->assertContains('Do cancelled shifts count? Yes, cancelled shifts lower the rate.', Explanation::find($id)->caveats);

        // Twice doesn't add it twice.
        $this->actingAs($verifier)->postJson("/_alfred/explanations/{$id}/keep", ['thread' => $thread, 'turn' => 0])->assertOk();
        $this->assertCount(1, array_filter(Explanation::find($id)->caveats, fn ($c) => str_starts_with($c, 'Do cancelled')));

        $user = $this->user();
        $this->actingAs($user)->postJson("/_alfred/explanations/{$id}/keep", ['thread' => $thread, 'turn' => 0])->assertForbidden();
    }

    public function test_limits(): void
    {
        config(['alfred.followups.max_turns' => 1]);
        $user = $this->user();
        [$token, $id] = $this->explained($user);
        $this->answer('Yes.');
        $thread = $this->ask($user, $token, $id, 'First question?')->json('thread');
        $this->ask($user, $token, $id, 'Second question?', $thread)->assertStatus(429);

        config(['alfred.followups.enabled' => false]);
        $this->ask($user, $token, $id, 'Third question?')->assertNotFound();
    }

    public function test_a_change_started_from_the_explanation_starts_from_what_alfred_found(): void
    {
        config(['alfred.edit.enabled' => true, 'alfred.edit.environments' => ['testing'], 'alfred.edit.mode' => 'propose']);
        $verifier = $this->user('verifier@example.com');
        [$token, $id] = $this->explained($verifier);
        $this->answer('Yes, they are counted.');
        $thread = $this->ask($verifier, $token, $id, 'Are cancelled shifts counted?')->json('thread');

        $this->llm->then([['propose_edit', ['summary' => 'Nothing to change', 'notes' => 'n', 'edits' => []]]]);
        $change = $this->actingAs($verifier)->postJson('/_alfred/changes', [
            'token' => $token, 'element' => $this->element(), 'instruction' => 'Leave cancelled shifts out of the total',
            'explanation' => $id, 'thread' => $thread,
        ])->assertCreated()->json('id');

        $prompt = end($this->llm->requests)['input'][0]['content'];
        $this->assertStringContainsString('# What Alfred already found about this element (its explanation)', $prompt);
        $this->assertStringContainsString('Flow (start here):', $prompt);
        $this->assertStringContainsString('Q: Are cancelled shifts counted? A: Yes, they are counted.', $prompt);

        $location = Change::find($change)->location;
        $this->assertSame('Fill rate', $location['explanation']['title']);
        $this->assertArrayNotHasKey('developer', $location['explanation'], 'tickets get business words only');
        $this->assertContains('Asked: Are cancelled shifts counted? — Answer: Yes, they are counted.', CodeLocator::lines($location));
    }

    public function test_a_report_from_the_explanation_carries_it_into_the_ticket(): void
    {
        $user = $this->user();
        [$token, $id] = $this->explained($user);

        $change = $this->actingAs($user)->postJson('/_alfred/requests', [
            'token' => $token, 'element' => $this->element(), 'text' => 'This should leave out cancelled shifts', 'explanation' => $id,
        ])->assertCreated()->json('id');

        $lines = CodeLocator::lines(Change::find($change)->location);
        $this->assertContains('Alfred explained it as: Fill rate — Booked shifts as a share of all shifts.', $lines);
    }

    public function test_someone_elses_thread_is_not_carried_along(): void
    {
        $user = $this->user();
        [$token, $id] = $this->explained($user);
        $this->answer('Secret answer.');
        $thread = $this->ask($user, $token, $id, 'My question?')->json('thread');

        $other = $this->user('other@example.com');
        $change = $this->actingAs($other)->postJson('/_alfred/requests', [
            'token' => $this->loadDashboard($other), 'element' => $this->element(), 'text' => 'Please fix', 'explanation' => $id, 'thread' => $thread,
        ])->assertCreated()->json('id');

        $this->assertSame([], Change::find($change)->location['explanation']['followups']);
    }

    public function test_follow_ups_run_in_the_background_when_async(): void
    {
        config(['alfred.async.enabled' => true]);
        $user = $this->user();
        $token = $this->loadDashboard($user);
        $this->llm->submit();
        $task = $this->actingAs($user)->postJson('/_alfred/explain', ['token' => $token, 'element' => $this->element()])->json('task');
        $id = $this->actingAs($user)->getJson('/_alfred/tasks/'.$task)->json('id');

        $this->answer('Yes.');
        $task = $this->ask($user, $token, $id, 'Is it live?')->assertStatus(202)->json('task');
        $this->actingAs($user)->getJson('/_alfred/tasks/'.$task)->assertOk()->assertJsonPath('turns.0.answer', 'Yes.');
    }
}
