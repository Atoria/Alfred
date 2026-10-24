<?php

namespace Atoria\Alfred\Tests\Feature;

use Atoria\Alfred\Alfred;
use Atoria\Alfred\Tests\TestCase;
use Illuminate\Http\Request;

class GuardsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('auth.guards.merchant', ['driver' => 'session', 'provider' => 'users']);
    }

    protected function asMerchant(string $email = 'shop@example.com')
    {
        $user = $this->user($email);
        // Sign in on the second guard only (no shouldUse, so "web" stays the default).
        $this->app['auth']->guard('merchant')->setUser($user);

        return $user;
    }

    public function test_users_of_other_guards_are_not_seen_by_default(): void
    {
        $this->asMerchant();

        $this->assertStringNotContainsString('data-alfred', $this->get('/dashboard')->getContent());
    }

    public function test_listed_guards_are_checked_in_order(): void
    {
        config(['alfred.guards' => ['web', 'merchant']]);
        $user = $this->asMerchant();

        $html = $this->get('/dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('data-alfred', $html);

        $alfred = app(Alfred::class);
        $request = Request::create('/');
        $request->setUserResolver(fn ($guard = null) => $guard === 'merchant' ? $user : null);

        $this->assertSame('merchant', $alfred->guard($request));
        $this->assertSame('u:merchant:-:'.$user->id, $alfred->userKey($request), 'scoped so merchant #1 is not web user #1');
    }

    public function test_default_guard_users_keep_their_plain_key(): void
    {
        config(['alfred.guards' => ['web', 'merchant']]);
        $user = $this->user();

        $request = Request::create('/');
        $request->setUserResolver(fn ($guard = null) => $guard === 'web' ? $user : null);

        $this->assertSame('u:'.$user->id, app(Alfred::class)->userKey($request));
    }

    public function test_an_unknown_guard_is_skipped_not_fatal(): void
    {
        config(['alfred.guards' => ['nope', 'web']]);

        $this->assertNotEmpty($this->loadDashboard($this->user()));
    }

    public function test_a_merchant_can_explain_end_to_end(): void
    {
        config(['alfred.guards' => ['web', 'merchant']]);
        $this->asMerchant();

        $html = $this->get('/dashboard')->getContent();
        preg_match('/data-alfred="([^"]+)"/', $html, $m);
        $token = json_decode(html_entity_decode($m[1]), true)['token'];

        $this->llm->submit();
        $this->postJson('/_alfred/explain', ['token' => $token, 'element' => $this->element()])->assertOk();
    }
}
