<?php

namespace Atoria\Alfred\Tests\Feature;

use Atoria\Alfred\Changes\Change;
use Atoria\Alfred\Tests\TestCase;
use Atoria\Alfred\Tickets\Ticket;
use Atoria\Alfred\Tickets\Trackers\Tracker;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class FakeYouTrack implements Tracker
{
    public static ?Ticket $last = null;

    public function name(): string
    {
        return 'youtrack';
    }

    public function label(): string
    {
        return 'YouTrack';
    }

    public function configured(): bool
    {
        return true;
    }

    public function target(): ?string
    {
        return 'APP';
    }

    public function create(Ticket $ticket): array
    {
        self::$last = $ticket;

        return ['key' => 'APP-1', 'url' => 'https://yt.test/issue/APP-1', 'warnings' => []];
    }
}

class TrackersTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config()->set('alfred.edit.enabled', true);
        config()->set('alfred.edit.environments', ['testing']);
        config()->set('alfred.tickets.labels', ['alfred', 'ui change']);
    }

    /** A change with a "before" screenshot, ready to send. */
    protected function change(): Change
    {
        $c = Change::query()->create([
            'user_key' => 'u:999',
            'requested_by' => 'verifier@example.com',
            'environment' => 'staging',
            'url' => 'http://app.test/orders',
            'element' => ['kind' => 'stat', 'tag' => 'p', 'labels' => ['Orders']],
            'instruction' => 'make the total bold',
            'summary' => 'Bold the orders total',
            'edits' => [['path' => 'app/X.php', 'search' => 'a', 'replace' => 'b']],
            'files' => ['app/X.php' => ['before_sha1' => 'x', 'after_sha1' => 'y']],
            'diff' => "diff --git a/app/X.php b/app/X.php\n--- a/app/X.php\n+++ b/app/X.php\n@@ -1 +1 @@\n-a\n+b\n",
            'status' => Change::PROPOSED,
        ]);
        $this->actingAs($this->user('verifier@example.com'))
            ->postJson("/_alfred/changes/{$c->id}/screenshots", ['kind' => 'before', 'image' => 'data:image/png;base64,'.self::PNG])->assertOk();

        return $c;
    }

    protected function send(Change $c, ?string $tracker = null)
    {
        return $this->actingAs($this->user('verifier@example.com'))->postJson("/_alfred/changes/{$c->id}/ticket", array_filter([
            'tracker' => $tracker,
            'title' => 'Bold the orders total',
            'description' => 'Ops asked for it.',
            'screenshots' => ['before'],
        ]));
    }

    protected function use(string $name, array $settings): void
    {
        config()->set('alfred.tickets.trackers', [$name]);
        config()->set("alfred.trackers.{$name}", $settings);
    }

    public function test_github_creates_an_issue_and_links_the_screenshots(): void
    {
        $this->use('github', ['token' => 'ghp_x', 'repo' => 'acme/app', 'api_url' => 'https://api.github.com', 'labels' => []]);
        Http::fake(['api.github.com/repos/acme/app/issues' => Http::response(['number' => 12, 'html_url' => 'https://github.com/acme/app/issues/12'], 201)]);
        $c = $this->change();

        $this->send($c)->assertCreated()->assertJson(['provider' => 'github', 'label' => 'GitHub', 'key' => '#12', 'url' => 'https://github.com/acme/app/issues/12'])
            ->assertJsonPath('warnings.0', fn ($w) => str_contains($w, 'links to the screenshots'));

        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer ghp_x')
            && $r['title'] === 'Bold the orders total'
            && $r['labels'] === ['alfred', 'ui change']
            && str_contains($r['body'], 'Ops asked for it.')
            && str_contains($r['body'], '```diff')
            && str_contains($r['body'], "/_alfred/changes/{$c->id}/screenshots/before")
            && str_contains($r['body'], "Created with Alfred from change #{$c->id}"));
        $this->assertSame(['github', '#12'], [$c->fresh()->ticket_provider, $c->fresh()->ticket_key]);
    }

    public function test_gitlab_uploads_screenshots_and_embeds_them(): void
    {
        $this->use('gitlab', ['url' => 'https://gitlab.acme.test', 'token' => 'glpat', 'project' => 'acme/app', 'labels' => ['bug']]);
        Http::fake([
            'gitlab.acme.test/api/v4/projects/acme%2Fapp/uploads' => Http::response(['markdown' => '![before](/uploads/abc/before.png)'], 201),
            'gitlab.acme.test/api/v4/projects/acme%2Fapp/issues' => Http::response(['iid' => 5, 'web_url' => 'https://gitlab.acme.test/acme/app/-/issues/5'], 201),
        ]);

        $this->send($this->change())->assertCreated()->assertJson(['key' => '#5', 'warnings' => []]);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/issues')
            && $r->hasHeader('PRIVATE-TOKEN', 'glpat')
            && $r['labels'] === 'bug'
            && str_contains($r['description'], '![before](/uploads/abc/before.png)'));
    }

    public function test_linear_resolves_the_team_key_uploads_and_creates(): void
    {
        $this->use('linear', ['api_key' => 'lin_api_x', 'team' => 'eng']);
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), 'uploads.linear.test')) {
                return Http::response('', 200);
            }
            $q = (string) data_get($r->data(), 'query');

            return match (true) {
                str_contains($q, 'teams(') => Http::response(['data' => ['teams' => ['nodes' => [['id' => 'team-uuid']]]]]),
                str_contains($q, 'fileUpload') => Http::response(['data' => ['fileUpload' => ['success' => true, 'uploadFile' => [
                    'uploadUrl' => 'https://uploads.linear.test/put', 'assetUrl' => 'https://uploads.linear.test/before.png', 'headers' => [['key' => 'x-amz-acl', 'value' => 'private']],
                ]]]]),
                str_contains($q, 'issueCreate') => Http::response(['data' => ['issueCreate' => ['success' => true, 'issue' => ['identifier' => 'ENG-9', 'url' => 'https://linear.app/acme/issue/ENG-9']]]]),
            };
        });

        $this->send($this->change())->assertCreated()->assertJson(['key' => 'ENG-9', 'url' => 'https://linear.app/acme/issue/ENG-9']);

        Http::assertSent(fn (Request $r) => data_get($r->data(), 'variables.key') === 'ENG');
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->hasHeader('x-amz-acl', 'private') && $r->hasHeader('Content-Type', 'image/png'));
        Http::assertSent(fn (Request $r) => str_contains((string) data_get($r->data(), 'query'), 'issueCreate')
            && $r->hasHeader('Authorization', 'lin_api_x')
            && data_get($r->data(), 'variables.input.teamId') === 'team-uuid'
            && str_contains((string) data_get($r->data(), 'variables.input.description'), '![before](https://uploads.linear.test/before.png)'));
    }

    public function test_linear_reports_graphql_errors(): void
    {
        $this->use('linear', ['api_key' => 'k', 'team' => '0b4c9d1e-1111-2222-3333-444455556666']);
        Http::fake(['*' => Http::response(['errors' => [['message' => 'Authentication required']]], 200)]);

        $this->send($this->change())->assertStatus(502)->assertJsonPath('message', fn ($m) => str_contains($m, 'Authentication required'));
    }

    public function test_trello_creates_a_card_and_attaches(): void
    {
        $this->use('trello', ['key' => 'k1', 'token' => 't1', 'list_id' => 'L1', 'list_name' => 'Inbox', 'label_ids' => ['lab1']]);
        Http::fake([
            'api.trello.com/1/cards/C1/attachments*' => Http::response(['id' => 'a'], 200),
            'api.trello.com/1/cards*' => Http::response(['id' => 'C1', 'idShort' => 31, 'shortUrl' => 'https://trello.com/c/abc'], 200),
        ]);

        $this->send($this->change())->assertCreated()->assertJson(['key' => '#31', 'url' => 'https://trello.com/c/abc', 'warnings' => []]);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/1/cards?') && str_contains($r->url(), 'key=k1') && $r['idList'] === 'L1' && $r['idLabels'] === 'lab1' && str_contains($r['desc'], 'Ops asked for it.'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/cards/C1/attachments'));
    }

    public function test_clickup_creates_a_task_with_markdown_and_tags(): void
    {
        $this->use('clickup', ['token' => 'pk_1', 'list_id' => '900', 'labels' => []]);
        Http::fake([
            'api.clickup.com/api/v2/list/900/task' => Http::response(['id' => 'abc1', 'url' => 'https://app.clickup.com/t/abc1'], 200),
            'api.clickup.com/api/v2/task/abc1/attachment' => Http::response(['id' => 'f'], 200),
        ]);

        $this->send($this->change())->assertCreated()->assertJson(['key' => '#abc1', 'url' => 'https://app.clickup.com/t/abc1']);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/task') && $r->hasHeader('Authorization', 'pk_1') && $r['tags'] === ['alfred', 'ui change'] && str_contains($r['markdown_description'], '```diff'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/task/abc1/attachment'));
    }

    public function test_asana_creates_a_task_and_attaches(): void
    {
        $this->use('asana', ['token' => 'pat', 'project' => '1201']);
        Http::fake([
            'app.asana.com/api/1.0/tasks' => Http::response(['data' => ['gid' => '777', 'permalink_url' => 'https://app.asana.com/0/1201/777']], 201),
            'app.asana.com/api/1.0/attachments' => Http::response(['data' => ['gid' => '8']], 200),
        ]);

        $this->send($this->change())->assertCreated()->assertJson(['key' => '#777', 'url' => 'https://app.asana.com/0/1201/777']);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/tasks') && $r->hasHeader('Authorization', 'Bearer pat') && $r['data']['projects'] === ['1201']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/attachments'));
    }

    public function test_a_failed_upload_is_a_warning_not_a_failed_ticket(): void
    {
        $this->use('asana', ['token' => 'pat', 'project' => '1201']);
        Http::fake([
            'app.asana.com/api/1.0/tasks' => Http::response(['data' => ['gid' => '777', 'permalink_url' => 'https://app.asana.com/0/1201/777']], 201),
            'app.asana.com/api/1.0/attachments' => Http::response(['errors' => [['message' => 'too big']]], 413),
        ]);
        $c = $this->change();

        $this->send($c)->assertCreated()->assertJsonPath('warnings.0', fn ($w) => str_contains($w, 'Asana refused the attachment'));
        $this->assertSame('#777', $c->fresh()->ticket_key);
    }

    public function test_several_trackers_people_pick_one_and_only_configured_ones_are_offered(): void
    {
        config()->set('alfred.tickets.trackers', ['jira', 'github', 'trello']);
        config()->set('alfred.trackers.github', ['token' => 'ghp', 'repo' => 'acme/app', 'api_url' => 'https://api.github.com']);
        config()->set('alfred.trackers.trello', ['key' => 'k', 'token' => 't', 'list_id' => 'L', 'list_name' => 'Inbox']);
        // jira listed but not configured → hidden
        Http::fake([
            'api.trello.com/1/cards/C1/attachments*' => Http::response([], 200),
            'api.trello.com/*' => Http::response(['id' => 'C1', 'idShort' => 2, 'shortUrl' => 'https://trello.com/c/x'], 200),
        ]);
        $c = $this->change();

        $this->actingAs($this->user('verifier@example.com'))->getJson("/_alfred/changes/{$c->id}/ticket")->assertOk()
            ->assertJsonPath('trackers', [['name' => 'github', 'label' => 'GitHub', 'target' => 'acme/app'], ['name' => 'trello', 'label' => 'Trello', 'target' => 'Inbox']]);

        $this->send($c, 'jira')->assertStatus(422);
        $this->send($c, 'trello')->assertCreated()->assertJson(['provider' => 'trello']);
        $this->send($c, 'github')->assertStatus(409)->assertJson(['provider' => 'trello', 'key' => '#2']);
    }

    public function test_custom_trackers_can_be_registered(): void
    {
        config()->set('alfred.tickets.custom', ['youtrack' => FakeYouTrack::class]);
        config()->set('alfred.tickets.trackers', ['youtrack']);
        $c = $this->change();

        $this->send($c)->assertCreated()->assertJson(['provider' => 'youtrack', 'label' => 'YouTrack', 'key' => 'APP-1']);
        $this->assertSame('Bold the orders total', FakeYouTrack::$last->title);
        $this->assertSame('before', FakeYouTrack::$last->images()[0]['kind']);
    }

    public function test_no_tracker_still_lets_people_copy_the_text(): void
    {
        config()->set('alfred.tickets.trackers', []);
        $c = $this->change();

        $this->actingAs($this->user('verifier@example.com'))->getJson("/_alfred/changes/{$c->id}/ticket")->assertOk()->assertJson(['trackers' => []]);
        $this->send($c)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'ALFRED_TICKETS'));
    }

    public function test_old_jira_settings_still_work(): void
    {
        config()->set('alfred.tickets.trackers', []);
        config()->set('alfred.trackers.jira.enabled', true);
        config()->set('alfred.trackers.jira.url', 'https://acme.atlassian.net');
        config()->set('alfred.trackers.jira.email', 'bot@acme.test');
        config()->set('alfred.trackers.jira.token', 't');
        config()->set('alfred.trackers.jira.project', 'PROJ');
        Http::fake(['acme.atlassian.net/rest/api/3/issue' => Http::response(['key' => 'PROJ-1'], 201), '*' => Http::response([], 200)]);

        $this->send($this->change())->assertCreated()->assertJson(['provider' => 'jira', 'key' => 'PROJ-1']);
    }

    public function test_the_upgrade_keeps_existing_jira_tickets(): void
    {
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_27_000000_add_ticket_tracker_to_alfred_changes.php';
        $c = $this->change();
        $migration->down();
        \DB::table('alfred_changes')->where('id', $c->id)->update(['jira_key' => 'PROJ-3', 'jira_url' => 'https://acme.atlassian.net/browse/PROJ-3']);

        $migration->up();

        $fresh = $c->fresh();
        $this->assertSame(['jira', 'PROJ-3', 'https://acme.atlassian.net/browse/PROJ-3'], [$fresh->ticket_provider, $fresh->ticket_key, $fresh->ticket_url]);
    }
}
