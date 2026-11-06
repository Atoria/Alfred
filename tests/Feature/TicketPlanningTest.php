<?php

namespace Atoria\Alfred\Tests\Feature;

use Atoria\Alfred\Changes\Change;
use Atoria\Alfred\Tests\TestCase;
use Atoria\Alfred\Tickets\Adf;
use Atoria\Alfred\Tickets\Ticket;
use Atoria\Alfred\Tickets\Trackers\Tracker;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/** A custom tracker that does not implement Plannable. */
class PlainTracker implements Tracker
{
    public function name(): string
    {
        return 'plain';
    }

    public function label(): string
    {
        return 'Plain';
    }

    public function configured(): bool
    {
        return true;
    }

    public function target(): ?string
    {
        return null;
    }

    public function create(Ticket $ticket): array
    {
        return ['key' => 'P-1', 'url' => 'https://plain.test/P-1'];
    }
}

/** AI-written ticket name/body, and the "Assign to" / "Sprint" choices. */
class TicketPlanningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config()->set('alfred.edit.enabled', true);
        config()->set('alfred.edit.environments', ['testing']);
        config()->set('alfred.openai.api_key', 'sk-test');
    }

    protected function request(array $attributes = []): Change
    {
        return Change::query()->create(array_merge([
            'user_key' => 'u:999',
            'requested_by' => 'verifier@example.com',
            'environment' => 'staging',
            'route' => 'dashboard',
            'url' => 'http://app.test/dashboard',
            'element' => ['kind' => 'stat', 'tag' => 'p', 'labels' => ['Revenue'], 'column' => null],
            'location' => ['explanation' => ['title' => 'Revenue', 'summary' => 'Sum of all order totals this month.', 'flow' => [], 'followups' => []]],
            'instruction' => 'this should not count cancelled orders',
            'summary' => 'this should not count cancelled orders',
            'edits' => [],
            'files' => [],
            'status' => Change::REQUESTED,
        ], $attributes));
    }

    protected function jira(): void
    {
        config()->set('alfred.tickets.trackers', ['jira']);
        config()->set('alfred.trackers.jira', ['url' => 'https://acme.atlassian.net', 'email' => 'bot@acme.test', 'token' => 't', 'project' => 'PROJ', 'issue_type' => 'Task', 'labels' => []]);
    }

    protected function fakeJira(): void
    {
        Http::fake([
            'acme.atlassian.net/rest/api/3/user/assignable/search*' => Http::response([
                ['accountId' => 'acc-2', 'displayName' => 'Nino', 'accountType' => 'atlassian', 'active' => true],
                ['accountId' => 'acc-1', 'displayName' => 'Giorgi', 'accountType' => 'atlassian', 'active' => true],
                ['accountId' => 'bot', 'displayName' => 'Automation', 'accountType' => 'app', 'active' => true],
            ]),
            'acme.atlassian.net/rest/agile/1.0/board?*' => Http::response(['values' => [['id' => 7, 'type' => 'scrum']]]),
            'acme.atlassian.net/rest/agile/1.0/board/7/sprint*' => Http::response(['values' => [
                ['id' => 12, 'name' => 'Sprint 12', 'state' => 'future', 'startDate' => '2026-11-16'],
                ['id' => 11, 'name' => 'Sprint 11', 'state' => 'active', 'startDate' => '2026-11-02'],
            ]]),
            'acme.atlassian.net/rest/agile/1.0/sprint/*' => Http::response(null, 204),
            'acme.atlassian.net/rest/api/3/issue' => Http::response(['key' => 'PROJ-5'], 201),
        ]);
    }

    public function test_the_ai_writes_the_ticket_name_and_body_and_keeps_the_requesters_words(): void
    {
        $c = $this->request();
        $this->llm->then([['write_ticket', [
            'title' => 'Exclude cancelled orders from the Revenue total on Dashboard',
            'body' => "The Revenue stat counts cancelled orders, so it overstates revenue.\n\n### Expected result\nRevenue leaves out cancelled orders.\n\n### Acceptance criteria\n- Cancelled orders are not counted\n- Other statuses are unchanged",
        ]]]);

        $this->actingAs($this->user('verifier@example.com'))->getJson("/_alfred/changes/{$c->id}/ticket")->assertOk()->assertJson(['ai_draft' => true]);

        $res = $this->actingAs($this->user('verifier@example.com'))->postJson("/_alfred/changes/{$c->id}/ticket/write")->assertOk()
            ->assertJson(['title' => 'Exclude cancelled orders from the Revenue total on Dashboard']);

        $this->assertStringContainsString('### Acceptance criteria', $res->json('description'));
        $this->assertStringEndsWith("### Original request\n> this should not count cancelled orders\n— verifier@example.com", $res->json('description'));

        $sent = $this->llm->requests[0];
        $this->assertSame('write_ticket', $sent['force']);
        $this->assertStringContainsString('"""this should not count cancelled orders"""', $sent['input'][0]['content']);
        $this->assertStringContainsString('Sum of all order totals this month.', $sent['input'][0]['content']);
        $this->assertStringContainsString('Page: /dashboard', $sent['input'][0]['content']);
    }

    public function test_without_an_ai_key_or_when_turned_off_there_is_no_ai_draft(): void
    {
        $c = $this->request();
        config()->set('alfred.tickets.ai_draft', false);
        $this->actingAs($this->user('verifier@example.com'))->getJson("/_alfred/changes/{$c->id}/ticket")->assertJson(['ai_draft' => false]);
        $this->actingAs($this->user('verifier@example.com'))->postJson("/_alfred/changes/{$c->id}/ticket/write")->assertNotFound();

        config()->set('alfred.tickets.ai_draft', true);
        config()->set('alfred.openai.api_key', '');
        $this->actingAs($this->user('verifier@example.com'))->getJson("/_alfred/changes/{$c->id}/ticket")->assertJson(['ai_draft' => false]);
    }

    public function test_an_empty_ai_reply_is_an_error_and_others_cannot_ask(): void
    {
        $c = $this->request();
        $this->llm->then([]);

        $this->actingAs($this->user('verifier@example.com'))->postJson("/_alfred/changes/{$c->id}/ticket/write")->assertStatus(502);
        $this->actingAs($this->user())->postJson("/_alfred/changes/{$c->id}/ticket/write")->assertForbidden();
    }

    public function test_jira_offers_people_and_sprints_then_assigns_and_moves_the_issue(): void
    {
        $this->jira();
        $this->fakeJira();
        $c = $this->request();

        $this->actingAs($this->user('verifier@example.com'))->getJson("/_alfred/changes/{$c->id}/ticket/planning?tracker=jira")->assertOk()
            ->assertJson([
                'supported' => true,
                'destination_label' => 'Sprint',
                'assignees' => [['id' => 'acc-1', 'name' => 'Giorgi'], ['id' => 'acc-2', 'name' => 'Nino']],
                'destinations' => [
                    ['id' => '', 'name' => 'Backlog', 'default' => true],
                    ['id' => '11', 'name' => 'Sprint 11', 'hint' => 'active'],
                    ['id' => '12', 'name' => 'Sprint 12', 'hint' => 'future'],
                ],
            ])
            ->assertJsonCount(2, 'assignees');

        $this->actingAs($this->user('verifier@example.com'))->postJson("/_alfred/changes/{$c->id}/ticket", [
            'title' => 'Exclude cancelled orders', 'description' => "Intro\n\n### Expected result\n- No cancelled orders",
            'assignee' => 'acc-1', 'destination' => '11',
        ])->assertCreated()->assertJson(['key' => 'PROJ-5', 'warnings' => []]);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/rest/api/3/issue')
            && $r['fields']['assignee'] === ['accountId' => 'acc-1']
            && $r['fields']['description']['content'][1] === ['type' => 'heading', 'attrs' => ['level' => 3], 'content' => [['type' => 'text', 'text' => 'Expected result']]]
            && $r['fields']['description']['content'][2]['type'] === 'bulletList');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/rest/agile/1.0/sprint/11/issue') && $r['issues'] === ['PROJ-5']);
    }

    public function test_backlog_and_unassigned_send_nothing_extra(): void
    {
        $this->jira();
        $this->fakeJira();
        $c = $this->request();

        $this->actingAs($this->user('verifier@example.com'))->postJson("/_alfred/changes/{$c->id}/ticket", [
            'title' => 'x title', 'description' => 'y body', 'assignee' => '', 'destination' => '',
        ])->assertCreated();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/rest/api/3/issue') && ! isset($r['fields']['assignee']));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/sprint/'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'assignable'));
    }

    public function test_a_choice_the_tracker_did_not_offer_is_refused(): void
    {
        $this->jira();
        $this->fakeJira();
        $c = $this->request();

        $this->actingAs($this->user('verifier@example.com'))->postJson("/_alfred/changes/{$c->id}/ticket", [
            'title' => 'x title', 'description' => 'y body', 'assignee' => 'bot',
        ])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'sprint'));
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/rest/api/3/issue'));
    }

    public function test_a_failed_sprint_move_is_a_warning(): void
    {
        $this->jira();
        Http::fake([
            'acme.atlassian.net/rest/api/3/user/assignable/search*' => Http::response([]),
            'acme.atlassian.net/rest/agile/1.0/board?*' => Http::response(['values' => [['id' => 7]]]),
            'acme.atlassian.net/rest/agile/1.0/board/7/sprint*' => Http::response(['values' => [['id' => 11, 'name' => 'Sprint 11', 'state' => 'active']]]),
            'acme.atlassian.net/rest/agile/1.0/sprint/*' => Http::response(['errorMessages' => ['closed']], 400),
            'acme.atlassian.net/rest/api/3/issue' => Http::response(['key' => 'PROJ-6'], 201),
        ]);
        $c = $this->request();

        $this->actingAs($this->user('verifier@example.com'))->postJson("/_alfred/changes/{$c->id}/ticket", ['title' => 'x title', 'description' => 'y body', 'destination' => '11'])
            ->assertCreated()->assertJsonPath('warnings.0', fn ($w) => str_contains($w, 'PROJ-6 was created in the backlog'));
    }

    public function test_tracker_errors_leave_the_form_usable(): void
    {
        $this->jira();
        Http::fake(['*' => Http::response(['errorMessages' => ['nope']], 401)]);
        $c = $this->request();

        $this->actingAs($this->user('verifier@example.com'))->getJson("/_alfred/changes/{$c->id}/ticket/planning?tracker=jira")->assertOk()
            ->assertJson(['assignees' => [], 'destinations' => []])
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Could not load people and sprints'));
    }

    public function test_trello_offers_board_members_and_lists(): void
    {
        config()->set('alfred.tickets.trackers', ['trello']);
        config()->set('alfred.trackers.trello', ['key' => 'k', 'token' => 't', 'list_id' => 'L1', 'list_name' => 'Inbox']);
        Http::fake([
            'api.trello.com/1/lists/L1/board*' => Http::response(['id' => 'B1']),
            'api.trello.com/1/boards/B1/members*' => Http::response([['id' => 'm1', 'fullName' => 'Nino', 'username' => 'nino']]),
            'api.trello.com/1/boards/B1/lists*' => Http::response([['id' => 'L1', 'name' => 'Inbox'], ['id' => 'L2', 'name' => 'Sprint 4']]),
            'api.trello.com/1/cards*' => Http::response(['id' => 'C1', 'idShort' => 3, 'shortUrl' => 'https://trello.com/c/x']),
        ]);
        $c = $this->request();

        $this->actingAs($this->user('verifier@example.com'))->getJson("/_alfred/changes/{$c->id}/ticket/planning?tracker=trello")->assertOk()
            ->assertJson(['destination_label' => 'List', 'assignees' => [['id' => 'm1', 'name' => 'Nino']], 'destinations' => [['id' => 'L1', 'name' => 'Inbox', 'default' => true], ['id' => 'L2', 'name' => 'Sprint 4']]]);

        $this->actingAs($this->user('verifier@example.com'))->postJson("/_alfred/changes/{$c->id}/ticket", ['title' => 'x title', 'description' => 'y body', 'assignee' => 'm1', 'destination' => 'L2'])->assertCreated();
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/1/cards?') && $r['idList'] === 'L2' && $r['idMembers'] === 'm1');
    }

    public function test_github_assigns_and_sets_the_milestone(): void
    {
        config()->set('alfred.tickets.trackers', ['github']);
        config()->set('alfred.trackers.github', ['token' => 'ghp', 'repo' => 'acme/app', 'api_url' => 'https://api.github.com']);
        Http::fake([
            'api.github.com/repos/acme/app/assignees*' => Http::response([['login' => 'nino']]),
            'api.github.com/repos/acme/app/milestones*' => Http::response([['number' => 3, 'title' => 'v2.1', 'due_on' => '2026-12-01T00:00:00Z']]),
            'api.github.com/repos/acme/app/issues' => Http::response(['number' => 9, 'html_url' => 'https://github.com/acme/app/issues/9'], 201),
        ]);
        $c = $this->request();

        $this->actingAs($this->user('verifier@example.com'))->getJson("/_alfred/changes/{$c->id}/ticket/planning?tracker=github")
            ->assertJson(['destination_label' => 'Milestone', 'destinations' => [['id' => '', 'name' => 'No milestone'], ['id' => '3', 'name' => 'v2.1', 'hint' => 'due 2026-12-01']]]);
        $this->actingAs($this->user('verifier@example.com'))->postJson("/_alfred/changes/{$c->id}/ticket", ['title' => 'x title', 'description' => 'y body', 'assignee' => 'nino', 'destination' => '3'])->assertCreated();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/issues') && $r['assignees'] === ['nino'] && $r['milestone'] === 3);
    }

    public function test_trackers_without_planning_ignore_nothing_and_refuse_choices(): void
    {
        config()->set('alfred.tickets.trackers', ['plain']);
        config()->set('alfred.tickets.custom', ['plain' => PlainTracker::class]);
        $c = $this->request();

        $this->actingAs($this->user('verifier@example.com'))->getJson("/_alfred/changes/{$c->id}/ticket")->assertJsonPath('trackers.0.planning', false);
        $this->actingAs($this->user('verifier@example.com'))->getJson("/_alfred/changes/{$c->id}/ticket/planning?tracker=plain")->assertJson(['supported' => false]);
        $this->actingAs($this->user('verifier@example.com'))->postJson("/_alfred/changes/{$c->id}/ticket", ['title' => 'x title', 'description' => 'y body', 'assignee' => 'someone'])->assertStatus(422);
        $this->actingAs($this->user('verifier@example.com'))->postJson("/_alfred/changes/{$c->id}/ticket", ['title' => 'x title', 'description' => 'y body'])->assertCreated();
    }

    public function test_adf_reads_light_markdown(): void
    {
        $doc = Adf::markdown("Intro line\n\n### Expected\n- one\n- two\n\n1. first\n\n> quoted\n> more\n\nplain");

        $this->assertSame(['paragraph', 'heading', 'bulletList', 'orderedList', 'blockquote', 'paragraph'], array_column($doc, 'type'));
        $this->assertCount(2, $doc[2]['content']);
        $this->assertCount(2, $doc[4]['content']);
        // Plain text stays exactly as before.
        $this->assertSame(Adf::paragraphs("a\nb\n\nc"), Adf::markdown("a\nb\n\nc"));
    }
}
