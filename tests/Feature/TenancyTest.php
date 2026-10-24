<?php

namespace Atoria\Alfred\Tests\Feature;

use Atoria\Alfred\Alfred;
use Atoria\Alfred\Explanations\Explanation;
use Atoria\Alfred\Tenancy\Tenancy;
use Atoria\Alfred\Tests\Fixtures\Tenancy\FakeTenancy;
use Atoria\Alfred\Tests\TestCase;

class TenancyTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('alfred.tenancy.driver', FakeTenancy::class);
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

    /** Load the dashboard inside $tenant (as the app's own tenancy middleware would), then leave it. */
    protected function pageIn(string $tenant, $user): array
    {
        FakeTenancy::$current = $tenant;
        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();
        FakeTenancy::$current = null; // the next request starts central, like a real one

        preg_match('/data-alfred="([^"]+)"/', $html, $m);
        $this->assertNotEmpty($m, 'overlay not injected');

        return [json_decode(html_entity_decode($m[1]), true), $html];
    }

    public function test_the_page_carries_a_signed_tenant_reference(): void
    {
        [$config, $html] = $this->pageIn('acme', $this->user());

        $this->assertNotEmpty($config['tenant']);
        $this->assertStringContainsString('alfred.js?v=', $html);
        $this->assertStringContainsString('alfred_tenant='.rawurlencode($config['tenant']), html_entity_decode($html));
    }

    public function test_central_pages_have_no_tenant(): void
    {
        $token = $this->loadDashboard($this->user());
        $this->assertNotEmpty($token);

        $html = $this->get('/dashboard')->getContent();
        preg_match('/data-alfred="([^"]+)"/', $html, $m);
        $this->assertNull(json_decode(html_entity_decode($m[1]), true)['tenant']);
    }

    public function test_alfred_requests_run_in_the_pages_tenant(): void
    {
        $user = $this->user();
        [$config] = $this->pageIn('acme', $user);

        $this->llm->submit();
        $this->actingAs($user)->withHeader(Tenancy::HEADER, $config['tenant'])
            ->postJson('/_alfred/explain', ['token' => $config['token'], 'element' => $this->element()])
            ->assertOk();

        $this->assertSame(['acme'], FakeTenancy::$initialized);
    }

    public function test_works_with_a_tenancy_cache_that_cannot_tag(): void
    {
        $this->app->singleton('cache', fn ($app) => new \Atoria\Alfred\Tests\Fixtures\Tenancy\TaggingCacheManager($app));
        \Illuminate\Support\Facades\Cache::clearResolvedInstances();

        $user = $this->user();
        [$config] = $this->pageIn('acme', $user);

        $this->llm->submit();
        $this->actingAs($user)->withHeader(Tenancy::HEADER, $config['tenant'])
            ->postJson('/_alfred/explain', ['token' => $config['token'], 'element' => $this->element()])
            ->assertOk();
    }

    public function test_without_the_reference_the_tenant_page_is_not_found(): void
    {
        $user = $this->user();
        [$config] = $this->pageIn('acme', $user);

        $this->llm->submit();
        $this->actingAs($user)->postJson('/_alfred/explain', ['token' => $config['token'], 'element' => $this->element()])
            ->assertStatus(410); // user key is tenant-scoped; central request can't use it
    }

    public function test_a_tampered_reference_is_refused(): void
    {
        $user = $this->user();
        [$config] = $this->pageIn('acme', $user);

        [, $expires, $signature] = explode('.', $config['tenant']);
        $forged = rtrim(strtr(base64_encode('globex'), '+/', '-_'), '=').'.'.$expires.'.'.$signature;

        $this->actingAs($user)->withHeader(Tenancy::HEADER, $forged)
            ->postJson('/_alfred/explain', ['token' => $config['token'], 'element' => $this->element()])
            ->assertForbidden();

        $longer = explode('.', $config['tenant']);
        $longer[1] = (string) ((int) $longer[1] + 86400);
        $this->actingAs($user)->withHeader(Tenancy::HEADER, implode('.', $longer))
            ->postJson('/_alfred/explain', ['token' => $config['token'], 'element' => $this->element()])
            ->assertForbidden();

        $this->assertSame([], FakeTenancy::$initialized);
    }

    public function test_a_reference_from_another_domain_is_refused(): void
    {
        $user = $this->user();
        [$config] = $this->pageIn('acme', $user);

        $this->actingAs($user)->withHeader(Tenancy::HEADER, $config['tenant'])
            ->postJson('http://globex.test/_alfred/explain', ['token' => $config['token'], 'element' => $this->element()])
            ->assertForbidden();
    }

    public function test_a_mismatch_with_the_tenant_the_app_already_chose_is_refused(): void
    {
        $user = $this->user();
        [$config] = $this->pageIn('acme', $user);

        FakeTenancy::$current = 'globex';
        $this->actingAs($user)->withHeader(Tenancy::HEADER, $config['tenant'])
            ->postJson('/_alfred/explain', ['token' => $config['token'], 'element' => $this->element()])
            ->assertForbidden();
    }

    public function test_links_work_with_the_query_parameter(): void
    {
        $user = $this->user();
        [$config] = $this->pageIn('acme', $user);

        $this->actingAs($user)->get('/_alfred/alfred.js?alfred_tenant='.rawurlencode($config['tenant']))->assertOk();
        $this->assertSame(['acme'], FakeTenancy::$initialized);
    }

    public function test_each_tenant_gets_its_own_explanations_by_default(): void
    {
        $user = $this->user();

        foreach (['acme', 'globex'] as $tenant) {
            [$config] = $this->pageIn($tenant, $user);
            $this->llm->submit(['title' => 'Fill rate for '.$tenant]);
            $this->actingAs($user)->withHeader(Tenancy::HEADER, $config['tenant'])
                ->postJson('/_alfred/explain', ['token' => $config['token'], 'element' => $this->element()])
                ->assertOk()->assertJson(['title' => 'Fill rate for '.$tenant, 'cached' => false]);
            FakeTenancy::$current = null;
        }

        $this->assertSame(['acme', 'globex'], Explanation::withoutGlobalScopes()->orderBy('id')->pluck('tenant')->all());
        $this->assertCount(2, $this->llm->requests);
    }

    public function test_explanation_keys_always_differ_per_tenant(): void
    {
        $key = function (?string $tenant) {
            FakeTenancy::$current = $tenant;
            $k = \Atoria\Alfred\Explanations\ExplanationKey::for(new \Atoria\Alfred\Context\PageContext('t', null, '/'), \Atoria\Alfred\Context\ElementDescriptor::fromArray($this->element()));
            FakeTenancy::$current = null;

            return $k;
        };

        $this->assertCount(3, array_unique([$key(null), $key('acme'), $key('globex')]));
    }

    public function test_tables_go_on_the_central_connection(): void
    {
        $this->assertSame('testing', Alfred::connection());
        $this->assertSame('testing', (new Explanation)->getConnectionName());

        config(['alfred.connection' => 'other']);
        $this->assertSame('other', Alfred::connection(), 'ALFRED_DB_CONNECTION wins');
    }

    public function test_tenant_users_are_scoped_in_their_key(): void
    {
        $user = $this->user();
        FakeTenancy::$current = 'acme';

        $request = \Illuminate\Http\Request::create('/');
        $request->setUserResolver(fn () => $user);

        $this->assertSame('u:web:acme:'.$user->id, app(Alfred::class)->userKey($request));
    }

    public function test_migrations_can_run_twice(): void
    {
        foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') as $file) {
            (require $file)->up();
        }

        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('alfred_changes', 'pr_provider'));
    }
}
