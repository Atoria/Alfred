<?php

namespace Atoria\Alfred\Tests\Feature;

use Atoria\Alfred\Context\ContextStore;
use Atoria\Alfred\Tests\TestCase;

class RecordingTest extends TestCase
{
    public function test_injects_overlay_for_signed_in_users(): void
    {
        $token = $this->loadDashboard($this->user());

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $token);
    }

    public function test_guests_get_nothing(): void
    {
        $html = $this->get('/dashboard')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-alfred', $html);
    }

    public function test_disabled_means_nothing_is_injected(): void
    {
        config()->set('alfred.enabled', false);

        $html = $this->actingAs($this->user())->get('/dashboard')->getContent();

        $this->assertStringNotContainsString('data-alfred', $html);
    }

    public function test_json_responses_are_left_alone(): void
    {
        $this->actingAs($this->user())->get('/dashboard.json')->assertExactJson(['booked' => 2]);
    }

    public function test_records_route_views_and_queries_with_their_source_line(): void
    {
        $user = $this->user();
        $page = app(ContextStore::class)->get($this->loadDashboard($user));

        $this->assertSame('u:'.$user->id, $page->userId);
        $this->assertSame('dashboard', $page->routeName);
        $this->assertStringContainsString('DashboardController@index', $page->action);
        $this->assertArrayHasKey('fixture::dashboard', $page->views);

        $queries = $page->queryList();
        $this->assertCount(2, $queries);
        $this->assertStringContainsString('count(*)', $queries[0]['sql']);
        $this->assertSame(['booked'], $queries[0]['bindings']);
        $this->assertSame('app/Http/Controllers/DashboardController.php:11', $queries[0]['source']);
        $this->assertSame('app/Http/Controllers/DashboardController.php:12', $queries[1]['source']);
    }

    public function test_livewire_round_trips_join_the_page_context(): void
    {
        $user = $this->user();
        $token = $this->loadDashboard($user);

        $this->actingAs($user)
            ->withHeaders(['X-Livewire' => '1', 'X-Alfred-Token' => $token])
            ->getJson('/dashboard.json')
            ->assertOk();

        $queries = app(ContextStore::class)->get($token)->queryList();
        $this->assertCount(3, $queries, 'the follow-up query joins the page context');
        $this->assertSame('app/Http/Controllers/DashboardController.php:21', $queries[2]['source']);

        // Repeating the same round-trip merges into the same entry.
        $this->actingAs($user)->withHeaders(['X-Livewire' => '1', 'X-Alfred-Token' => $token])->getJson('/dashboard.json');
        $queries = app(ContextStore::class)->get($token)->queryList();
        $this->assertCount(3, $queries);
        $this->assertSame(2, $queries[2]['count']);
    }

    public function test_another_user_cannot_write_into_my_page_context(): void
    {
        $token = $this->loadDashboard($this->user());
        $this->actingAs($this->user('intruder@example.com'))
            ->withHeaders(['X-Livewire' => '1', 'X-Alfred-Token' => $token])
            ->getJson('/dashboard.json');

        $this->assertCount(2, app(ContextStore::class)->get($token)->queryList());
    }

    public function test_custom_gate_wins(): void
    {
        \Gate::define('useAlfred', fn ($user = null) => $user?->email === 'boss@example.com');

        $html = $this->actingAs($this->user())->get('/dashboard')->getContent();
        $this->assertStringNotContainsString('data-alfred', $html);

        $this->loadDashboard($this->user('boss@example.com'));
    }

    public function test_script_is_served_only_to_allowed_users(): void
    {
        $this->get('/_alfred/alfred.js')->assertNotFound();
        $this->actingAs($this->user())->get('/_alfred/alfred.js')->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=utf-8');
    }

    protected function probe(): void
    {
        \Illuminate\Support\Facades\Route::middleware('web')->get('/probe', function () {
            if (request()->has('as')) {
                event(new \Illuminate\Auth\Events\Authenticated('web', \Illuminate\Foundation\Auth\User::query()->findOrFail(request('as'))));
            }

            return app(\Atoria\Alfred\Context\RequestRecorder::class)->active() ? 'recording' : 'not recording';
        });
    }

    public function test_visitors_who_cannot_be_signed_in_are_not_recorded(): void
    {
        $this->probe();
        $this->get('/probe')->assertSeeText('recording')->assertDontSee('not recording');

        $this->defaultCookies = [];
        $this->get('/probe')->assertSee('not recording');

        // A "remember me" cookie may sign them in.
        $this->withCookie('remember_web_abc', 'x')->get('/probe')->assertSeeText('recording')->assertDontSee('not recording');
    }

    public function test_recording_stops_once_the_user_turns_out_not_to_use_alfred(): void
    {
        $this->probe();
        \Illuminate\Support\Facades\Gate::define('useAlfred', fn ($u = null) => $u?->email === 'manager@example.com');

        $this->get('/probe?as='.$this->user()->id)->assertSeeText('recording')->assertDontSee('not recording');
        $this->get('/probe?as='.$this->user('outsider@example.com')->id)->assertSee('not recording');
    }
}
