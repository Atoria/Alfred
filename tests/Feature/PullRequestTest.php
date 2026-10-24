<?php

namespace Atoria\Alfred\Tests\Feature;

use Atoria\Alfred\Changes\Change;
use Atoria\Alfred\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class PullRequestTest extends TestCase
{
    private const FILE = "<?php\n\nclass Totals\n{\n    public function label(): string\n    {\n        return 'Total';\n    }\n}\n";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config()->set('alfred.edit.enabled', true);
        config()->set('alfred.edit.environments', ['testing']);
        config()->set('alfred.edit.mode', 'propose');
    }

    protected function change(array $attributes = []): Change
    {
        return Change::query()->create(array_merge([
            'user_key' => 'u:999',
            'requested_by' => 'lead@example.com',
            'environment' => 'staging',
            'url' => 'http://app.test/orders',
            'element' => ['kind' => 'stat', 'tag' => 'p', 'labels' => ['Total']],
            'instruction' => 'make the total bold',
            'summary' => 'Bold the orders total',
            'notes' => 'Wraps the label in <b>.',
            'edits' => [['path' => 'app/Totals.php', 'search' => "        return 'Total';", 'replace' => "        return '<b>Total</b>';"]],
            'files' => ['app/Totals.php' => ['before_sha1' => 'x', 'after_sha1' => 'y']],
            'diff' => "diff --git a/app/Totals.php b/app/Totals.php\n",
            'status' => Change::PROPOSED,
        ], $attributes));
    }

    protected function github(array $extra = []): void
    {
        config()->set('alfred.pull_requests.providers', ['github']);
        config()->set('alfred.git.github', ['token' => 'ghp', 'repo' => 'acme/app', 'api_url' => 'https://api.github.com']);
        Http::fake(array_merge([
            'api.github.com/repos/acme/app/git/ref/heads/main' => Http::response(['object' => ['sha' => 'base-sha']]),
            'api.github.com/repos/acme/app/git/commits/base-sha' => Http::response(['tree' => ['sha' => 'base-tree']]),
            'api.github.com/repos/acme/app/contents/*' => Http::response(self::FILE),
            'api.github.com/repos/acme/app/git/trees' => Http::response(['sha' => 'new-tree'], 201),
            'api.github.com/repos/acme/app/git/commits' => Http::response(['sha' => 'new-commit'], 201),
            'api.github.com/repos/acme/app/git/refs' => Http::response(['ref' => 'x'], 201),
            'api.github.com/repos/acme/app/pulls' => Http::response(['number' => 7, 'html_url' => 'https://github.com/acme/app/pull/7'], 201),
            'api.github.com/repos/acme/app/issues/7/labels' => Http::response([], 200),
            'api.github.com/repos/acme/app' => Http::response(['default_branch' => 'main']),
        ], $extra));
    }

    protected function open(Change $c, array $data = [])
    {
        return $this->actingAs($this->user('verifier@example.com'))->postJson("/_alfred/changes/{$c->id}/pull-request", $data);
    }

    public function test_github_one_commit_on_a_new_branch_and_a_draft_pull_request(): void
    {
        $this->github();
        config()->set('alfred.pull_requests.labels', ['alfred']);
        $c = $this->change();

        $this->open($c)->assertCreated()->assertJson([
            'provider' => 'github', 'label' => 'GitHub', 'key' => '#7', 'url' => 'https://github.com/acme/app/pull/7',
            'branch' => "alfred/{$c->id}-bold-the-orders-total", 'warnings' => [],
        ]);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/contents/app/Totals.php?ref=main') && $r->hasHeader('Accept', 'application/vnd.github.raw+json'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/git/trees')
            && $r['base_tree'] === 'base-tree'
            && $r['tree'][0]['path'] === 'app/Totals.php'
            && $r['tree'][0]['content'] === str_replace("return 'Total';", "return '<b>Total</b>';", self::FILE));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/git/commits') && $r->method() === 'POST' && $r['parents'] === ['base-sha'] && str_starts_with($r['message'], "Bold the orders total\n\nRequested by lead@example.com"));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/git/refs') && $r['ref'] === "refs/heads/alfred/{$c->id}-bold-the-orders-total" && $r['sha'] === 'new-commit');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/pulls')
            && $r['draft'] === true && $r['base'] === 'main' && $r['title'] === 'Bold the orders total'
            && str_contains($r['body'], '> make the total bold')
            && str_contains($r['body'], '`app/Totals.php`')
            && str_contains($r['body'], 'review it before merging'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/issues/7/labels') && $r['labels'] === ['alfred']);

        $fresh = $c->fresh();
        $this->assertSame(['github', '#7'], [$fresh->pr_provider, $fresh->pr_key]);
        $this->actingAs($this->user('verifier@example.com'))->getJson("/_alfred/changes/{$c->id}")->assertJsonPath('pull_request.key', '#7');

        // never twice
        $this->open($c)->assertStatus(409)->assertJson(['key' => '#7']);
    }

    public function test_falls_back_when_the_repo_cannot_have_draft_pull_requests(): void
    {
        config()->set('alfred.pull_requests.base', 'develop');
        $this->github([
            'api.github.com/repos/acme/app/git/ref/heads/develop' => Http::response(['object' => ['sha' => 'base-sha']]),
            'api.github.com/repos/acme/app/pulls' => Http::sequence()
                ->push(['message' => 'Validation Failed', 'errors' => [['message' => 'Draft pull requests are not supported in this repository.']]], 422)
                ->push(['number' => 8, 'html_url' => 'https://github.com/acme/app/pull/8'], 201),
        ]);

        $this->open($this->change())->assertCreated()->assertJson(['key' => '#8'])
            ->assertJsonPath('warnings.0', fn ($w) => str_contains($w, 'normal one'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/pulls') && $r['draft'] === false && $r['base'] === 'develop');
    }

    public function test_the_edits_follow_the_base_branch_even_if_indentation_moved(): void
    {
        $moved = str_replace("        return 'Total';", "\t\treturn 'Total';", self::FILE);
        $this->github(['api.github.com/repos/acme/app/contents/*' => Http::response($moved)]);

        $this->open($this->change())->assertCreated();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/git/trees') && str_contains($r['tree'][0]['content'], "\t\treturn '<b>Total</b>';"));
    }

    public function test_explains_when_the_base_branch_no_longer_fits(): void
    {
        $this->github(['api.github.com/repos/acme/app/contents/*' => Http::response("<?php\n\nreturn 'Grand total';\n")]);
        $c = $this->change();

        $this->open($c)->assertStatus(502)->assertJsonPath('message', fn ($m) => str_contains($m, 'app/Totals.php on the base branch no longer matches'));
        $this->assertNull($c->fresh()->pr_key);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/git/trees'));
    }

    public function test_a_missing_file_points_at_the_path_prefix(): void
    {
        config()->set('alfred.pull_requests.path_prefix', 'backend');
        $this->github(['api.github.com/repos/acme/app/contents/*' => Http::response(['message' => 'Not Found'], 404)]);

        $this->open($this->change())->assertStatus(502)->assertJsonPath('message', fn ($m) => str_contains($m, 'backend/app/Totals.php is not in the repository') && str_contains($m, 'ALFRED_PR_PATH_PREFIX'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/contents/backend/app/Totals.php'));
    }

    public function test_gitlab_commit_and_draft_merge_request(): void
    {
        config()->set('alfred.pull_requests.providers', ['gitlab']);
        config()->set('alfred.git.gitlab', ['url' => 'https://gitlab.acme.test', 'token' => 'glpat', 'project' => 'acme/app']);
        Http::fake([
            'gitlab.acme.test/api/v4/projects/acme%2Fapp/repository/files/*' => Http::response(self::FILE),
            'gitlab.acme.test/api/v4/projects/acme%2Fapp/repository/commits' => Http::response(['id' => 'c1'], 201),
            'gitlab.acme.test/api/v4/projects/acme%2Fapp/merge_requests' => Http::response(['iid' => 3, 'web_url' => 'https://gitlab.acme.test/acme/app/-/merge_requests/3'], 201),
            'gitlab.acme.test/api/v4/projects/acme%2Fapp' => Http::response(['default_branch' => 'master']),
        ]);

        $this->open($this->change())->assertCreated()->assertJson(['label' => 'GitLab', 'noun' => 'merge request', 'key' => '!3']);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'repository/files/app%2FTotals.php/raw') && str_contains($r->url(), 'ref=master'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/repository/commits')
            && $r['start_branch'] === 'master' && $r['force'] === true
            && $r['actions'][0]['action'] === 'update' && str_contains($r['actions'][0]['content'], '<b>Total</b>'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/merge_requests') && $r['title'] === 'Draft: Bold the orders total' && $r['target_branch'] === 'master');
    }

    public function test_bitbucket_commit_and_pull_request(): void
    {
        config()->set('alfred.pull_requests.providers', ['bitbucket']);
        config()->set('alfred.git.bitbucket', ['repo' => 'acme/app', 'token' => 'bbtok', 'email' => null]);
        Http::fake([
            'api.bitbucket.org/2.0/repositories/acme/app/refs/branches/main' => Http::response(['target' => ['hash' => 'h1']]),
            'api.bitbucket.org/2.0/repositories/acme/app/src/h1/*' => Http::response(self::FILE),
            'api.bitbucket.org/2.0/repositories/acme/app/src' => Http::response('', 201),
            'api.bitbucket.org/2.0/repositories/acme/app/pullrequests' => Http::response(['id' => 4, 'links' => ['html' => ['href' => 'https://bitbucket.org/acme/app/pull-requests/4']]], 201),
            'api.bitbucket.org/2.0/repositories/acme/app' => Http::response(['mainbranch' => ['name' => 'main']]),
        ]);

        $this->open($this->change())->assertCreated()->assertJson(['label' => 'Bitbucket', 'key' => '#4']);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/src') && $r->isMultipart() && $r->hasHeader('Authorization', 'Bearer bbtok')
            && collect($r->data())->contains(fn ($part) => $part['name'] === 'app/Totals.php' && str_contains($part['contents'], '<b>Total</b>'))
            && collect($r->data())->contains(fn ($part) => $part['name'] === 'parents' && $part['contents'] === 'h1'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/pullrequests') && $r['destination']['branch']['name'] === 'main' && $r['draft'] === true);
    }

    public function test_ticket_and_pull_request_together_are_linked(): void
    {
        config()->set('alfred.tickets.trackers', ['jira']);
        config()->set('alfred.trackers.jira', ['url' => 'https://acme.atlassian.net', 'email' => 'bot@acme.test', 'token' => 't', 'project' => 'PROJ']);
        $this->github(['acme.atlassian.net/rest/api/3/issue' => Http::response(['key' => 'PROJ-42'], 201)]);
        $c = $this->change();

        $this->actingAs($this->user('verifier@example.com'))->getJson("/_alfred/changes/{$c->id}/ticket")
            ->assertJsonPath('pull_requests.0.name', 'github');

        $this->actingAs($this->user('verifier@example.com'))->postJson("/_alfred/changes/{$c->id}/ticket", [
            'title' => 'Bold the orders total', 'description' => 'Please.', 'pull_request' => true,
        ])->assertCreated()->assertJson(['key' => 'PROJ-42', 'pull_request' => ['provider' => 'github', 'key' => '#7']]);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/pulls')
            && $r['title'] === 'PROJ-42: Bold the orders total'
            && $r['head'] === "alfred/proj-42-{$c->id}-bold-the-orders-total"
            && str_contains($r['body'], '**Ticket:** [PROJ-42](https://acme.atlassian.net/browse/PROJ-42)'));
    }

    public function test_same_platform_issue_is_closed_by_the_pull_request(): void
    {
        config()->set('alfred.tickets.trackers', ['github']);
        config()->set('alfred.trackers.github', ['token' => 'ghp', 'repo' => 'acme/app', 'api_url' => 'https://api.github.com']);
        $this->github(['api.github.com/repos/acme/app/issues' => Http::response(['number' => 12, 'html_url' => 'https://github.com/acme/app/issues/12'], 201)]);
        $c = $this->change();

        $this->actingAs($this->user('verifier@example.com'))->postJson("/_alfred/changes/{$c->id}/ticket", [
            'title' => 'Bold', 'description' => 'Please.', 'pull_request' => true,
        ])->assertCreated();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/pulls') && str_contains($r['body'], 'Closes #12') && $r['title'] === 'Bold the orders total');
    }

    public function test_a_failed_pull_request_never_loses_the_ticket(): void
    {
        config()->set('alfred.tickets.trackers', ['jira']);
        config()->set('alfred.trackers.jira', ['url' => 'https://acme.atlassian.net', 'email' => 'bot@acme.test', 'token' => 't', 'project' => 'PROJ']);
        $this->github([
            'acme.atlassian.net/rest/api/3/issue' => Http::response(['key' => 'PROJ-43'], 201),
            'api.github.com/repos/acme/app' => Http::response(['message' => 'Bad credentials'], 401),
        ]);
        $c = $this->change();

        $this->actingAs($this->user('verifier@example.com'))->postJson("/_alfred/changes/{$c->id}/ticket", [
            'title' => 'x', 'description' => 'y', 'pull_request' => true,
        ])->assertCreated()->assertJson(['key' => 'PROJ-43', 'pull_request' => ['key' => null]])
            ->assertJsonPath('warnings.0', fn ($w) => str_contains($w, 'pull request was not') && str_contains($w, 'Bad credentials'));
    }

    public function test_only_editors_and_only_changes_with_code(): void
    {
        $this->github();
        $c = $this->change();

        $this->actingAs($this->user('someone@example.com'))->postJson("/_alfred/changes/{$c->id}/pull-request")->assertForbidden();

        $request = $this->change(['status' => Change::REQUESTED, 'edits' => [], 'files' => []]);
        $this->open($request)->assertStatus(502)->assertJsonPath('message', fn ($m) => str_contains($m, 'no code edits'));
        $this->actingAs($this->user('verifier@example.com'))->getJson("/_alfred/changes/{$request->id}/pull-request")->assertJson(['can_open' => false]);
    }

    public function test_not_set_up(): void
    {
        config()->set('alfred.pull_requests.providers', []);

        $this->open($this->change())->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'ALFRED_PULL_REQUESTS'));
    }
}
