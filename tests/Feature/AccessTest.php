<?php

namespace Atoria\Alfred\Tests\Feature;

use Atoria\Alfred\Tests\TestCase;

class AccessTest extends TestCase
{
    protected function sees(string $email): bool
    {
        $html = $this->actingAs($this->user($email))->get('/dashboard')->assertOk()->getContent();

        return str_contains($html, 'data-alfred');
    }

    public function test_unlisted_users_do_not_see_alfred_at_all(): void
    {
        config(['alfred.users' => ['ana@acme.test'], 'alfred.verifiers' => ['lead@acme.test']]);

        $this->assertFalse($this->sees('stranger@acme.test'));
        $this->actingAs($this->user('other@acme.test'))->postJson('/_alfred/requests', ['token' => 'x', 'element' => $this->element(), 'text' => 'hi'])->assertForbidden();
    }

    public function test_listed_users_and_verifiers_see_it(): void
    {
        config(['alfred.users' => ['Ana@Acme.test'], 'alfred.verifiers' => ['lead@acme.test']]);

        $this->assertTrue($this->sees('ana@acme.test'));
        $this->assertTrue($this->sees('lead@acme.test'));
    }

    public function test_patterns_allow_a_whole_domain(): void
    {
        config(['alfred.users' => ['*@acme.test'], 'alfred.verifiers' => []]);

        $this->assertTrue($this->sees('anyone@acme.test'));
        $this->assertFalse($this->sees('anyone@acme.test.evil.com'));
        $this->assertFalse($this->sees('someone@other.test'));
    }

    public function test_nobody_sees_it_when_no_one_is_listed(): void
    {
        config(['alfred.users' => [], 'alfred.verifiers' => []]);

        $this->assertFalse($this->sees('ana@acme.test'));
    }

    public function test_verifier_patterns_work_too(): void
    {
        config(['alfred.users' => [], 'alfred.verifiers' => ['*@acme.test']]);

        $html = $this->actingAs($this->user('dev@acme.test'))->get('/dashboard')->getContent();
        preg_match('/data-alfred="([^"]+)"/', $html, $m);
        $this->assertTrue(json_decode(html_entity_decode($m[1]), true)['canVerify']);
    }

    public function test_the_overlay_can_get_a_fresh_csrf_token(): void
    {
        config(['alfred.users' => ['ana@acme.test']]);

        $res = $this->actingAs($this->user('ana@acme.test'))->getJson('/_alfred/csrf')->assertOk();
        $this->assertSame(session()->token(), $res->json('csrf'));
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));

        $this->actingAs($this->user('stranger@acme.test'))->getJson('/_alfred/csrf')->assertForbidden();
    }
}
