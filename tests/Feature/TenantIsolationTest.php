<?php

namespace Atoria\Alfred\Tests\Feature;

use Atoria\Alfred\Changes\Change;
use Atoria\Alfred\Explanations\Explanation;
use Atoria\Alfred\Tenancy\Tenancy;
use Atoria\Alfred\Tests\Fixtures\Tenancy\FakeTenancy;
use Atoria\Alfred\Tests\TestCase;

/** One tenant must never see, use or affect anything of another tenant's. */
class TenantIsolationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('alfred.tenancy.driver', FakeTenancy::class);
        $app['config']->set('alfred.edit.enabled', true);
        $app['config']->set('alfred.edit.environments', ['testing']);
        $app['config']->set('alfred.edit.mode', 'propose');
    }

    protected function setUp(): void
    {
        FakeTenancy::reset();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        FakeTenancy::reset();
        parent::tearDown();
    }

    /** @return array{0: array, 1: string} page config + tenant header, for $tenant */
    protected function page(string $tenant, $user): array
    {
        $this->flushHeaders(); // test requests keep headers between calls; browsers don't
        FakeTenancy::$current = $tenant;
        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();
        FakeTenancy::$current = null;
        preg_match('/data-alfred="([^"]+)"/', $html, $m);
        $config = json_decode(html_entity_decode($m[1]), true);

        return [$config, $config['tenant']];
    }

    protected function as($user, string $header)
    {
        return $this->actingAs($user)->withHeader(Tenancy::HEADER, $header);
    }

    /** acme's verifier explains, asks a follow-up, proposes a change and reports. */
    protected function acmeData($verifier): array
    {
        [$c, $h] = $this->page('acme', $verifier);
        $this->llm->submit(['title' => 'ACME secret metric']);
        $exp = $this->as($verifier, $h)->postJson('/_alfred/explain', ['token' => $c['token'], 'element' => $this->element()])->assertOk()->json('id');
        FakeTenancy::$current = null;

        $this->llm->then([['submit_answer', ['answer' => 'ACME-only answer.', 'developer' => null, 'confidence' => 'high']]]);
        $thread = $this->as($verifier, $h)->postJson("/_alfred/explanations/{$exp}/ask", ['token' => $c['token'], 'element' => $this->element(), 'question' => 'What about ACME?'])->assertOk()->json('thread');
        FakeTenancy::$current = null;

        $this->llm->then([['propose_edit', ['summary' => 'ACME change', 'notes' => 'n', 'edits' => []]]]);
        $change = $this->as($verifier, $h)->postJson('/_alfred/changes', ['token' => $c['token'], 'element' => $this->element(), 'instruction' => 'ACME private request'])->assertCreated()->json('id');
        FakeTenancy::$current = null;

        return compact('exp', 'thread', 'change');
    }

    public function test_records_carry_their_tenant(): void
    {
        $acme = $this->acmeData($this->user('verifier@example.com'));

        $this->assertSame('acme', Explanation::withoutGlobalScopes()->find($acme['exp'])->tenant);
        $this->assertSame('acme', Change::withoutGlobalScopes()->find($acme['change'])->tenant);
    }

    public function test_another_tenant_sees_and_reaches_nothing(): void
    {
        $verifier = $this->user('verifier@example.com');
        $acme = $this->acmeData($verifier);
        [$g, $gh] = $this->page('globex', $verifier);

        // Change list, change by id, its patch, ticket draft, refine, apply, preview.
        $list = $this->as($verifier, $gh)->getJson('/_alfred/changes')->assertOk()->json('changes');
        $this->assertSame([], $list);
        foreach (['', '/patch', '/ticket', '/pull-request'] as $suffix) {
            $this->as($verifier, $gh)->getJson("/_alfred/changes/{$acme['change']}{$suffix}")->assertNotFound();
        }
        foreach (['apply', 'revert', 'discard', 'preview'] as $action) {
            $this->as($verifier, $gh)->postJson("/_alfred/changes/{$acme['change']}/{$action}")->assertNotFound();
        }
        $this->as($verifier, $gh)->postJson("/_alfred/changes/{$acme['change']}/refine", ['token' => $g['token'], 'feedback' => 'show me'])->assertNotFound();

        // Explanation by id: verify, edit, keep, records, ask.
        $this->as($verifier, $gh)->postJson("/_alfred/explanations/{$acme['exp']}/verify")->assertNotFound();
        $this->as($verifier, $gh)->putJson("/_alfred/explanations/{$acme['exp']}", ['title' => 'x', 'summary' => 'y'])->assertNotFound();
        $this->as($verifier, $gh)->postJson("/_alfred/explanations/{$acme['exp']}/keep", ['thread' => $acme['thread'], 'turn' => 0])->assertNotFound();
        $this->as($verifier, $gh)->postJson("/_alfred/explanations/{$acme['exp']}/records", ['token' => $g['token']])->assertNotFound();
        $this->as($verifier, $gh)->postJson("/_alfred/explanations/{$acme['exp']}/ask", ['token' => $g['token'], 'element' => $this->element(), 'question' => 'What does ACME have?'])->assertNotFound();

        // Smuggling acme's explanation/thread into globex's change or report.
        $this->llm->then([['propose_edit', ['summary' => 'g', 'notes' => 'n', 'edits' => []]]]);
        $this->as($verifier, $gh)->postJson('/_alfred/changes', ['token' => $g['token'], 'element' => $this->element(), 'instruction' => 'xxx', 'explanation' => $acme['exp'], 'thread' => $acme['thread']])->assertCreated();
        $this->assertStringNotContainsString('ACME', end($this->llm->requests)['input'][0]['content']);
        $report = $this->as($verifier, $gh)->postJson('/_alfred/requests', ['token' => $g['token'], 'element' => $this->element(), 'text' => 'xxx', 'explanation' => $acme['exp']])->assertCreated()->json();
        $this->assertStringNotContainsString('ACME', json_encode($report));

        // Globex explaining the same element gets its own explanation, never acme's.
        $this->llm->submit(['title' => 'Globex metric']);
        $this->as($verifier, $gh)->postJson('/_alfred/explain', ['token' => $g['token'], 'element' => $this->element()])
            ->assertOk()->assertJson(['title' => 'Globex metric', 'cached' => false]);
    }

    public function test_central_pages_see_no_tenant_records(): void
    {
        $verifier = $this->user('verifier@example.com');
        $acme = $this->acmeData($verifier);

        $this->flushHeaders();
        $this->actingAs($verifier)->get('/dashboard');
        $this->assertSame([], $this->actingAs($verifier)->getJson('/_alfred/changes')->assertOk()->json('changes'));
        $this->actingAs($verifier)->getJson("/_alfred/changes/{$acme['change']}")->assertNotFound();
    }

    public function test_one_tenants_page_token_and_thread_cant_be_used_from_another(): void
    {
        $verifier = $this->user('verifier@example.com');
        [$a, $ah] = $this->page('acme', $verifier);
        [$g, $gh] = $this->page('globex', $verifier);

        // acme's page token with globex's tenant reference: the page isn't this user's here.
        $this->llm->submit();
        $this->as($verifier, $gh)->postJson('/_alfred/explain', ['token' => $a['token'], 'element' => $this->element()])->assertStatus(410);
    }

    public function test_on_a_tenant_domain_requests_must_name_their_tenant(): void
    {
        FakeTenancy::$tenantDomain = true;
        $this->flushHeaders();
        $user = $this->user();

        $this->actingAs($user)->postJson('/_alfred/explain', ['token' => 'x', 'element' => $this->element()])
            ->assertForbidden()->assertJsonPath('message', 'This request is missing its workspace. Reload the page and try again.');

        // Plain links stay usable.
        $this->get('/_alfred/preview/stop')->assertRedirect('/');
    }

    public function test_user_keys_name_the_tenant_even_on_central_pages(): void
    {
        $user = $this->user();
        $request = \Illuminate\Http\Request::create('/');
        $request->setUserResolver(fn () => $user);

        $this->assertSame('u:web:-:'.$user->id, app(\Atoria\Alfred\Alfred::class)->userKey($request));
        FakeTenancy::$current = 'acme';
        $this->assertSame('u:web:acme:'.$user->id, app(\Atoria\Alfred\Alfred::class)->userKey($request));
    }

    public function test_one_tenant_cant_use_up_anothers_budget_or_rewrite_its_code(): void
    {
        config(['alfred.usage.daily_tokens' => 1000, 'alfred.edit.mode' => 'write']);
        \Atoria\Alfred\Usage\Usage::query()->create(['day' => now()->toDateString(), 'tenant' => 'acme', 'user_key' => 'u:web:acme:1', 'kind' => 'explanation', 'model' => 'm', 'requests' => 50, 'input_tokens' => 900, 'output_tokens' => 200]);

        $verifier = $this->user('verifier@example.com');
        [$g, $gh] = $this->page('globex', $verifier);
        $this->assertSame('propose', $g['editMode'], 'write mode on a tenant page would change every tenant\'s code');
        $this->llm->submit();
        $this->as($verifier, $gh)->postJson('/_alfred/explain', ['token' => $g['token'], 'element' => $this->element()])->assertOk();

        [$a, $ah] = $this->page('acme', $verifier);
        $this->llm->submit();
        $this->as($verifier, $ah)->postJson('/_alfred/explain', ['token' => $a['token'], 'element' => $this->element(['labels' => ['Other']])])->assertStatus(429);
    }
}
