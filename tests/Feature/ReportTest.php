<?php

namespace Atoria\Alfred\Tests\Feature;

use Atoria\Alfred\Changes\Change;
use Atoria\Alfred\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class ReportTest extends TestCase
{
    protected function report($user, string $text = "The fill rate should leave out cancelled shifts.\nAsked by ops.")
    {
        return $this->actingAs($user)->postJson('/_alfred/requests', [
            'token' => $this->loadDashboard($user),
            'element' => $this->element(),
            'text' => $text,
        ]);
    }

    public function test_anyone_using_alfred_can_report_and_gets_the_code_location_without_ai(): void
    {
        $manager = $this->user();

        $res = $this->report($manager)->assertCreated()->assertJson([
            'kind' => 'request',
            'status' => 'requested',
            'summary' => 'The fill rate should leave out cancelled shifts.',
            'has_edits' => false,
            'can_apply' => false,
            'can_preview' => false,
            'requested_by' => 'manager@example.com',
        ]);

        $this->assertSame([], $this->llm->requests, 'no AI involved');
        // Someone without code access isn't shown where it lives in the code; the ticket carries it.
        $this->assertNull($res->json('location'));
        $location = \Atoria\Alfred\Changes\Change::query()->findOrFail($res->json('id'))->location;
        $this->assertSame('dashboard', $location['route']);
        $this->assertStringContainsString('DashboardController@index', $location['action']);
        $this->assertSame('app/Http/Controllers/DashboardController.php', $location['controller_file']);
        $this->assertContains('resources/views/dashboard.blade.php', $location['views']);
        $this->assertStringStartsWith('app/Http/Controllers/DashboardController.php:11', $location['code'][0]);
    }

    public function test_the_ticket_says_who_reported_what_where_and_where_it_lives_in_the_code(): void
    {
        $manager = $this->user();
        $id = $this->report($manager)->json('id');

        $draft = $this->actingAs($manager)->getJson("/_alfred/changes/{$id}/ticket")->assertOk();

        $this->assertSame('The fill rate should leave out cancelled shifts.', $draft->json('title'));
        $this->assertStringContainsString('manager@example.com reported on /dashboard (Fill rate):', $draft->json('description'));
        $this->assertStringContainsString('“The fill rate should leave out cancelled shifts.', $draft->json('description'));
        $md = $draft->json('details_markdown');
        $this->assertStringContainsString('### Where in the code', $md);
        $this->assertStringContainsString('- Route: dashboard → ', $md);
        $this->assertStringContainsString('— app/Http/Controllers/DashboardController.php', $md);
        $this->assertStringContainsString('- View: resources/views/dashboard.blade.php', $md);
        $this->assertStringContainsString('- Loads data at: app/Http/Controllers/DashboardController.php:11', $md);
        $this->assertStringContainsString('Request (no change proposed)', $md);
        $this->assertStringNotContainsString('```diff', $md);
    }

    public function test_creates_the_jira_issue_with_the_location(): void
    {
        config()->set('alfred.jira', ['enabled' => true, 'url' => 'https://acme.atlassian.net', 'email' => 'bot@acme.test', 'token' => 't', 'project' => 'PROJ', 'issue_type' => 'Task', 'labels' => [], 'include_diff' => true]);
        Http::fake(['acme.atlassian.net/rest/api/3/issue' => Http::response(['key' => 'PROJ-7'], 201)]);
        $manager = $this->user();
        $id = $this->report($manager)->json('id');

        $this->actingAs($manager)->postJson("/_alfred/changes/{$id}/ticket", ['title' => 'Leave out cancelled shifts', 'description' => 'From ops.'])
            ->assertCreated()->assertJson(['key' => 'PROJ-7']);

        Http::assertSent(fn (Request $r) => str_contains(json_encode($r['fields']['description']), 'Where in the code')
            && str_contains(json_encode($r['fields']['description']), 'DashboardController.php'));
    }

    public function test_reporters_only_see_their_own_requests_and_editors_see_all(): void
    {
        $manager = $this->user();
        $id = $this->report($manager)->json('id');

        $this->actingAs($this->user('other@example.com'))->getJson("/_alfred/changes/{$id}")->assertForbidden();
        $this->actingAs($this->user('other2@example.com'))->getJson("/_alfred/changes/{$id}/ticket")->assertForbidden();
        $this->actingAs($manager)->getJson("/_alfred/changes/{$id}")->assertOk();

        config()->set('alfred.edit.enabled', true);
        config()->set('alfred.edit.environments', ['testing']);
        $this->actingAs($this->user('verifier@example.com'))->getJson("/_alfred/changes/{$id}/ticket")->assertOk();
    }

    public function test_can_be_turned_off_or_restricted(): void
    {
        $manager = $this->user();
        $this->assertStringContainsString('&quot;canReport&quot;:true', $this->actingAs($manager)->get('/dashboard')->getContent());

        config()->set('alfred.report.enabled', false);
        $this->report($manager)->assertForbidden();
        $this->assertStringContainsString('&quot;canReport&quot;:false', $this->actingAs($manager)->get('/dashboard')->getContent());

        config()->set('alfred.report.enabled', true);
        \Gate::define('reportAlfred', fn ($user = null) => $user?->email === 'lead@example.com');
        $this->report($manager)->assertForbidden();
        $this->report($this->user('lead@example.com'))->assertCreated();
    }

    public function test_reported_requests_cannot_be_applied_or_previewed(): void
    {
        config()->set('alfred.edit.enabled', true);
        config()->set('alfred.edit.environments', ['testing']);
        config()->set('alfred.edit.mode', 'write');
        $verifier = $this->user('verifier@example.com');
        $id = $this->report($verifier)->json('id');

        $this->actingAs($verifier)->postJson("/_alfred/changes/{$id}/apply")->assertStatus(409);
        $this->actingAs($verifier)->postJson("/_alfred/changes/{$id}/preview")->assertStatus(409);
        $this->assertSame(Change::REQUESTED, Change::find($id)->status);
    }
}
