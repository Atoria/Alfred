<?php

namespace Atoria\Alfred\Tests\Feature;

use Atoria\Alfred\Tenancy\Tenancy;
use Atoria\Alfred\Tests\Fixtures\Tenancy\FakeTenancy;
use Atoria\Alfred\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/** Long AI work runs after the response, so proxies (30–100 s) can't cut it off with a 504. */
class BackgroundTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('alfred.async.enabled', true);
    }

    protected function tearDown(): void
    {
        FakeTenancy::reset();
        parent::tearDown();
    }

    public function test_a_new_explanation_answers_at_once_and_is_fetched_when_ready(): void
    {
        $user = $this->user();
        $token = $this->loadDashboard($user);
        $this->llm->submit();

        $task = $this->actingAs($user)->postJson('/_alfred/explain', ['token' => $token, 'element' => $this->element()])
            ->assertStatus(202)->assertJsonStructure(['task'])->json('task');

        // The work ran after the response was sent.
        $this->assertCount(1, $this->llm->requests);

        $this->actingAs($user)->getJson('/_alfred/tasks/'.$task)
            ->assertOk()->assertJson(['title' => 'Fill rate', 'cached' => false]);
    }

    public function test_cached_explanations_skip_the_task(): void
    {
        $user = $this->user();
        $token = $this->loadDashboard($user);
        $this->llm->submit();
        $task = $this->actingAs($user)->postJson('/_alfred/explain', ['token' => $token, 'element' => $this->element()])->json('task');
        $this->actingAs($user)->getJson('/_alfred/tasks/'.$task)->assertOk();

        $this->actingAs($user)->postJson('/_alfred/explain', ['token' => $token, 'element' => $this->element()])
            ->assertOk()->assertJson(['cached' => true]);
    }

    public function test_a_change_runs_in_the_background_too(): void
    {
        config(['alfred.edit.enabled' => true, 'alfred.edit.environments' => ['testing'], 'alfred.edit.mode' => 'propose']);
        $verifier = $this->user('verifier@example.com');
        $this->llm->then([['propose_edit', ['summary' => 'Nothing to change', 'notes' => 'Already left-aligned.', 'edits' => []]]]);

        $task = $this->actingAs($verifier)->postJson('/_alfred/changes', [
            'token' => $this->loadDashboard($verifier), 'element' => $this->element(), 'instruction' => 'move it left',
        ])->assertStatus(202)->json('task');

        $this->actingAs($verifier)->getJson('/_alfred/tasks/'.$task)
            ->assertStatus(201)->assertJson(['summary' => 'Nothing to change', 'status' => 'proposed']);
    }

    public function test_only_the_person_who_asked_can_read_the_result(): void
    {
        $user = $this->user();
        $this->llm->submit();
        $task = $this->actingAs($user)->postJson('/_alfred/explain', ['token' => $this->loadDashboard($user), 'element' => $this->element()])->json('task');

        $this->actingAs($this->user('other@example.com'))->getJson('/_alfred/tasks/'.$task)->assertStatus(410);
        $this->actingAs($user)->getJson('/_alfred/tasks/not-a-task')->assertStatus(410);
    }

    public function test_errors_in_the_work_come_back_as_a_message(): void
    {
        $user = $this->user();
        $this->llm->then(function () {
            throw new RuntimeException('boom');
        });

        $task = $this->actingAs($user)->postJson('/_alfred/explain', ['token' => $this->loadDashboard($user), 'element' => $this->element()])->json('task');

        $this->actingAs($user)->getJson('/_alfred/tasks/'.$task)
            ->assertStatus(500)->assertJson(['message' => 'Alfred ran into a problem. Please try again.']);
    }

    public function test_a_task_that_never_finishes_is_reported_instead_of_waiting_forever(): void
    {
        $user = $this->user();
        $this->loadDashboard($user);
        $id = str_repeat('a', 40);
        Cache::put('alfred:task:'.$id, ['status' => 'running', 'owner' => 'u:'.$user->id, 'started' => time() - 3600], 600);

        $this->actingAs($user)->getJson('/_alfred/tasks/'.$id)->assertStatus(504)->assertJsonPath('message', fn ($m) => str_contains($m, 'took too long'));

        Cache::put('alfred:task:'.$id, ['status' => 'running', 'owner' => 'u:'.$user->id, 'started' => time()], 600);
        $this->actingAs($user)->getJson('/_alfred/tasks/'.$id)->assertStatus(202)->assertJson(['status' => 'running']);
    }

    public function test_the_work_runs_in_the_pages_tenant(): void
    {
        config(['alfred.tenancy.driver' => FakeTenancy::class]);
        app()->forgetInstance(Tenancy::class);
        $user = $this->user();

        FakeTenancy::$current = 'acme';
        $html = $this->actingAs($user)->get('/dashboard')->getContent();
        FakeTenancy::$current = null;
        preg_match('/data-alfred="([^"]+)"/', $html, $m);
        $config = json_decode(html_entity_decode($m[1]), true);

        $seen = null;
        $this->llm->then(function () use (&$seen) {
            $seen = FakeTenancy::$current;

            return [['submit_explanation', ['title' => 'Fill rate', 'summary' => 'x', 'formula' => null, 'steps' => [], 'data_sources' => [], 'caveats' => [], 'example' => null, 'glossary' => [], 'developer' => ['summary' => 'x', 'flow' => [], 'queries' => [], 'caching' => null, 'gotchas' => []], 'query_index' => null, 'confidence' => 'high']]];
        });

        $task = $this->actingAs($user)->withHeader(Tenancy::HEADER, $config['tenant'])
            ->postJson('/_alfred/explain', ['token' => $config['token'], 'element' => $this->element()])->assertStatus(202)->json('task');

        $this->assertSame('acme', $seen, 'background work re-entered the tenant after the middleware left it');
        $this->assertNull(FakeTenancy::$current, 'and left it again');

        $this->actingAs($user)->withHeader(Tenancy::HEADER, $config['tenant'])->getJson('/_alfred/tasks/'.$task)->assertOk();
    }

    public function test_one_person_can_only_have_a_few_tasks_running(): void
    {
        config(['alfred.async.max_running' => 1]);
        $user = $this->user();
        $key = 'alfred:running:'.sha1(app(\Atoria\Alfred\Alfred::class)->userKey(tap(\Illuminate\Http\Request::create('/'))->setUserResolver(fn () => $user)));
        Cache::put($key, ['other-task' => time()], 600);

        $token = $this->loadDashboard($user);
        $this->llm->submit();
        $this->actingAs($user)->postJson('/_alfred/explain', ['token' => $token, 'element' => $this->element()])
            ->assertStatus(429)->assertHeader('Retry-After');
        $this->assertCount(0, $this->llm->requests);

        // A slot left by a task that died long ago doesn't count.
        Cache::put($key, ['other-task' => time() - 3600], 600);
        $this->actingAs($user)->postJson('/_alfred/explain', ['token' => $token, 'element' => $this->element()])->assertStatus(202);
        $this->assertSame([], Cache::get($key), 'the slot is freed when the work ends');
    }
}
