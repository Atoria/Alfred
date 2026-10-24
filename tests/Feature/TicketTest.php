<?php

namespace Atoria\Alfred\Tests\Feature;

use Atoria\Alfred\Changes\Change;
use Atoria\Alfred\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class TicketTest extends TestCase
{
    // 1×1 PNG
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config()->set('alfred.edit.enabled', true);
        config()->set('alfred.edit.environments', ['testing']);
        config()->set('alfred.edit.mode', 'write');
    }

    protected function change(array $attributes = []): Change
    {
        return Change::query()->create(array_merge([
            'user_key' => 'u:999', // someone else's change
            'requested_by' => 'verifier@example.com',
            'environment' => 'staging',
            'route' => 'plan-prices.index',
            'url' => 'http://app.test/plan-prices',
            'element' => ['kind' => 'table-cell', 'tag' => 'td', 'labels' => ['Plan Prices'], 'column' => 'Monthly price'],
            'instruction' => 'make it in badges please',
            'summary' => 'Show Monthly price as badges',
            'notes' => 'Uses Filament ->badge(), no CSS rebuild needed.',
            'edits' => [['path' => 'app/X.php', 'search' => 'a', 'replace' => 'b']],
            'files' => ['app/X.php' => ['before_sha1' => 'x', 'after_sha1' => 'y']],
            'diff' => "diff --git a/app/X.php b/app/X.php\n--- a/app/X.php\n+++ b/app/X.php\n@@ -1 +1,2 @@\n ->label('Monthly price')\n+->badge()\n",
            'status' => Change::PROPOSED,
        ], $attributes));
    }

    protected function jiraConfigured(): void
    {
        config()->set('alfred.tickets.trackers', ['jira']);
        config()->set('alfred.trackers.jira', [
            'url' => 'https://acme.atlassian.net/',
            'email' => 'bot@acme.test',
            'token' => 'jira-token',
            'project' => 'PROJ',
            'issue_type' => 'Task',
            'labels' => ['alfred', 'ui change'],
        ]);
    }

    public function test_screenshots_are_validated_stored_and_served_to_editors_only(): void
    {
        $verifier = $this->user('verifier@example.com');
        $c = $this->change();

        $this->actingAs($verifier)->postJson("/_alfred/changes/{$c->id}/screenshots", ['kind' => 'before', 'image' => 'data:image/png;base64,'.self::PNG])
            ->assertOk()->assertJson(['screenshots' => ['before']]);
        Storage::disk('local')->assertExists("alfred/changes/{$c->id}/before.png");

        $this->actingAs($verifier)->get("/_alfred/changes/{$c->id}/screenshots/before")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs($verifier)->get("/_alfred/changes/{$c->id}/screenshots/before?download=1")
            ->assertHeader('Content-Disposition', 'attachment; filename="alfred-change-'.$c->id.'-before.png"');

        // not an image / wrong kind / not allowed
        $this->actingAs($verifier)->postJson("/_alfred/changes/{$c->id}/screenshots", ['kind' => 'before', 'image' => 'data:image/png;base64,'.base64_encode('<?php echo 1;')])->assertStatus(422);
        $this->actingAs($verifier)->postJson("/_alfred/changes/{$c->id}/screenshots", ['kind' => 'during', 'image' => 'data:image/png;base64,'.self::PNG])->assertStatus(422);
        $this->actingAs($verifier)->postJson("/_alfred/changes/{$c->id}/screenshots", ['kind' => 'after', 'image' => 'data:text/html;base64,PGgxPg=='])->assertStatus(422);
        $this->actingAs($this->user())->get("/_alfred/changes/{$c->id}/screenshots/before")->assertForbidden();
    }

    public function test_draft_describes_the_request_and_works_without_jira(): void
    {
        $c = $this->change();

        $res = $this->actingAs($this->user('verifier@example.com'))->getJson("/_alfred/changes/{$c->id}/ticket")->assertOk()
            ->assertJson(['title' => 'Show Monthly price as badges', 'trackers' => [], 'ticket' => ['key' => null]]);

        $this->assertStringContainsString('verifier@example.com asked for a UI change on /plan-prices', $res->json('description'));
        $this->assertStringContainsString('“make it in badges please”', $res->json('description'));
        $this->assertStringContainsString('It has not been applied yet.', $res->json('description'));
        $this->assertStringContainsString("```diff\ndiff --git a/app/X.php", $res->json('details_markdown'));
        $this->assertStringContainsString('- Environment: staging', $res->json('details_markdown'));

        $this->actingAs($this->user('verifier@example.com'))->postJson("/_alfred/changes/{$c->id}/ticket", ['title' => 'x', 'description' => 'y'])->assertStatus(422);
    }

    public function test_applied_changes_say_so(): void
    {
        $c = $this->change(['status' => Change::APPLIED, 'applied_by' => 'verifier@example.com', 'applied_at' => now()]);

        $this->actingAs($this->user('verifier@example.com'))->getJson("/_alfred/changes/{$c->id}/ticket")
            ->assertJsonPath('description', fn ($d) => str_contains($d, 'already been applied on staging by verifier@example.com'));
    }

    public function test_creates_the_jira_issue_with_screenshots_once(): void
    {
        $this->jiraConfigured();
        Http::fake([
            'acme.atlassian.net/rest/api/3/issue' => Http::response(['id' => '10001', 'key' => 'PROJ-42'], 201),
            'acme.atlassian.net/rest/api/3/issue/PROJ-42/attachments' => Http::response([['id' => '1']], 200),
        ]);
        $verifier = $this->user('verifier@example.com');
        $c = $this->change();
        $this->actingAs($verifier)->postJson("/_alfred/changes/{$c->id}/screenshots", ['kind' => 'before', 'image' => 'data:image/png;base64,'.self::PNG]);
        $this->actingAs($verifier)->postJson("/_alfred/changes/{$c->id}/screenshots", ['kind' => 'after', 'image' => 'data:image/png;base64,'.self::PNG]);

        $this->actingAs($verifier)->postJson("/_alfred/changes/{$c->id}/ticket", [
            'title' => 'Show Monthly price as badges',
            'description' => "Someone asked for badges.\n\nSecond paragraph.",
            'include_diff' => true,
            'screenshots' => ['before'],
        ])->assertCreated()->assertJson(['provider' => 'jira', 'label' => 'Jira', 'key' => 'PROJ-42', 'url' => 'https://acme.atlassian.net/browse/PROJ-42', 'warnings' => []]);

        Http::assertSent(function (Request $r) {
            if (! str_ends_with($r->url(), '/rest/api/3/issue')) {
                return false;
            }
            $f = $r['fields'];
            $json = json_encode($f['description']);

            return $r->hasHeader('Authorization', 'Basic '.base64_encode('bot@acme.test:jira-token'))
                && $f['project'] === ['key' => 'PROJ']
                && $f['issuetype'] === ['name' => 'Task']
                && $f['summary'] === 'Show Monthly price as badges'
                && $f['labels'] === ['alfred', 'ui-change']
                && $f['description']['type'] === 'doc'
                && str_contains($json, '"type":"codeBlock","attrs":{"language":"diff"}')
                && str_contains($json, 'Second paragraph.')
                && str_contains($json, 'Screenshots attached: before.')
                && str_contains($json, '"href":"http:\/\/app.test\/plan-prices"');
        });
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/issue/PROJ-42/attachments') && $r->hasHeader('X-Atlassian-Token', 'no-check'));
        Http::assertSentCount(2); // the issue + one attachment ("after" was not ticked)

        $this->assertSame('PROJ-42', $c->fresh()->ticket_key);
        $this->assertSame('jira', $c->fresh()->ticket_provider);
        $this->actingAs($verifier)->getJson("/_alfred/changes/{$c->id}")->assertJson(['ticket' => ['provider' => 'jira', 'label' => 'Jira', 'key' => 'PROJ-42']]);

        // never twice
        $this->actingAs($verifier)->postJson("/_alfred/changes/{$c->id}/ticket", ['title' => 'x', 'description' => 'y'])
            ->assertStatus(409)->assertJson(['key' => 'PROJ-42']);
    }

    public function test_jira_errors_are_reported_back(): void
    {
        $this->jiraConfigured();
        Http::fake(['*' => Http::response(['errorMessages' => [], 'errors' => ['issuetype' => 'Specify a valid issue type']], 400)]);
        $c = $this->change();

        $this->actingAs($this->user('verifier@example.com'))->postJson("/_alfred/changes/{$c->id}/ticket", ['title' => 'x', 'description' => 'y'])
            ->assertStatus(502)->assertJsonPath('message', 'Jira refused the ticket (400): issuetype: Specify a valid issue type');
        $this->assertNull($c->fresh()->ticket_key);
    }

    public function test_ticket_endpoints_need_edit_rights(): void
    {
        $c = $this->change();

        $this->actingAs($this->user())->getJson("/_alfred/changes/{$c->id}/ticket")->assertForbidden();
        $this->actingAs($this->user('x@example.com'))->postJson("/_alfred/changes/{$c->id}/ticket", ['title' => 'x', 'description' => 'y'])->assertForbidden();
    }
}
