<?php

namespace Atoria\Alfred\Tests\Feature;

use Atoria\Alfred\Access\Guests;
use Atoria\Alfred\Changes\Change;
use Atoria\Alfred\Tests\TestCase;
use Illuminate\Support\Facades\Log;

class GuestTest extends TestCase
{
    protected string $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = str_repeat('g', 40);
    }

    protected function enable(array $extra = []): void
    {
        config(array_merge(['alfred.guests.enabled' => true, 'alfred.guests.access_key' => 'open-sesame'], $extra));
    }

    protected function asGuest(bool $unlocked = true): static
    {
        $this->withCredentials()->withCookie(config('session.cookie'), $this->session);
        if ($unlocked) {
            $this->withUnencryptedCookie(Guests::COOKIE, app(Guests::class)->cookieValue());
        }

        return $this;
    }

    /** @return array<string, mixed>|null */
    protected function overlay(): ?array
    {
        $html = $this->get('/dashboard')->assertOk()->getContent();

        return preg_match('/data-alfred="([^"]+)"/', $html, $m) ? json_decode(html_entity_decode($m[1]), true) : null;
    }

    public function test_guests_are_off_by_default(): void
    {
        $this->assertNull($this->overlay());
        $this->get('/_alfred/access?key=anything')->assertNotFound();
    }

    public function test_a_browser_is_let_in_only_with_the_access_link(): void
    {
        $this->enable();

        $this->assertNull($this->asGuest(false)->overlay(), 'no cookie, no Alfred');

        $this->get('/_alfred/access?key=wrong')->assertForbidden();

        $this->get('/_alfred/access?key=open-sesame&to=/dashboard')
            ->assertRedirect('/dashboard')
            ->assertCookie(Guests::COOKIE, app(Guests::class)->cookieValue(), false);

        $config = $this->asGuest()->overlay();
        $this->assertNotNull($config);
        $this->assertTrue($config['guest']);
        $this->assertTrue($config['canReport']);
        $this->assertFalse($config['canVerify']);
        $this->assertFalse($config['canEdit']);
        $this->assertFalse($config['canViewCode']);
        $this->assertFalse($config['canViewRecords']);
    }

    public function test_the_access_link_only_redirects_within_the_site(): void
    {
        $this->enable();

        foreach (['//evil.test', 'https://evil.test', '/\\evil.test', 'evil'] as $to) {
            $this->get('/_alfred/access?key=open-sesame&to='.urlencode($to))->assertRedirect('/');
        }
    }

    public function test_changing_the_key_locks_every_browser_out(): void
    {
        $this->enable();
        $old = app(Guests::class)->cookieValue();

        config(['alfred.guests.access_key' => 'new-key']);

        $this->withCookie(config('session.cookie'), $this->session)->withUnencryptedCookie(Guests::COOKIE, $old);
        $this->assertNull($this->overlay());
    }

    public function test_guests_can_explain_and_report_but_not_verify_or_change(): void
    {
        $this->enable();
        $config = $this->asGuest()->overlay();
        $token = $config['token'];

        $this->llm->submit();
        $id = $this->asGuest()->postJson('/_alfred/explain', ['token' => $token, 'element' => $this->element()])
            ->assertOk()->assertJson(['title' => 'Fill rate'])
            ->assertJsonMissingPath('technical_notes')
            ->json('id');

        $this->asGuest()->postJson('/_alfred/requests', ['token' => $token, 'element' => $this->element(), 'text' => 'The login button is hard to find'])
            ->assertCreated();
        $this->assertSame('A signed-out visitor', Change::query()->latest('id')->value('requested_by'));

        $this->asGuest()->postJson("/_alfred/explanations/{$id}/verify")->assertForbidden();
        $this->asGuest()->postJson('/_alfred/changes', ['token' => $token, 'element' => $this->element(), 'instruction' => 'Make it red'])->assertForbidden();
    }

    public function test_another_browser_cannot_use_a_guests_page(): void
    {
        $this->enable();
        $token = $this->asGuest()->overlay()['token'];

        $this->session = str_repeat('h', 40);
        $this->llm->submit();
        $this->asGuest()->postJson('/_alfred/explain', ['token' => $token, 'element' => $this->element()])->assertStatus(410);
    }

    public function test_production_is_refused_unless_listed_explicitly(): void
    {
        $this->enable();
        $this->app['env'] = 'production';

        $this->assertNull($this->asGuest()->overlay());
        $this->get('/_alfred/access?key=open-sesame')->assertNotFound();

        $this->spyLog();
        config(['alfred.guests.environments' => ['staging', 'production']]);
        $this->assertNotNull($this->asGuest()->overlay());
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message) => str_contains($message, 'guest access is switched on in production'));
    }

    public function test_on_local_no_key_is_needed(): void
    {
        config(['alfred.guests.enabled' => true, 'alfred.guests.access_key' => null]);
        $this->app['env'] = 'local';

        $this->assertNotNull($this->asGuest(false)->overlay());
    }

    public function test_without_a_key_only_local_is_open(): void
    {
        config(['alfred.guests.enabled' => true, 'alfred.guests.access_key' => null]);
        $this->app['env'] = 'staging';

        $this->assertNull($this->asGuest(false)->overlay());
        $this->get('/_alfred/access?key=')->assertNotFound();
    }

    public function test_signed_in_users_who_are_not_listed_still_do_not_get_it(): void
    {
        $this->enable();

        $html = $this->actingAs($this->user('stranger@elsewhere.test'))->withUnencryptedCookie(Guests::COOKIE, app(Guests::class)->cookieValue())
            ->get('/dashboard')->getContent();
        $this->assertStringNotContainsString('data-alfred', $html);
    }

    public function test_guests_are_rate_limited_per_ip_not_just_per_session(): void
    {
        $this->enable(['alfred.guests.rate_limit' => 1]);

        $token = $this->asGuest()->overlay()['token'];
        $this->llm->submit();
        $this->asGuest()->postJson('/_alfred/explain', ['token' => $token, 'element' => $this->element()])->assertOk();

        // A fresh session from the same IP doesn't get a fresh budget.
        $this->session = str_repeat('k', 40);
        $token = $this->asGuest()->overlay()['token'];
        $this->asGuest()->postJson('/_alfred/explain', ['token' => $token, 'element' => $this->element(['labels' => ['Other']])])->assertStatus(429);
    }
}
