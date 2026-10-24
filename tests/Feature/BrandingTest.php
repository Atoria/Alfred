<?php

namespace Atoria\Alfred\Tests\Feature;

use Atoria\Alfred\Alfred;
use Atoria\Alfred\Tests\TestCase;

class BrandingTest extends TestCase
{
    protected function pageConfig(): array
    {
        $html = $this->actingAs($this->user())->get('/dashboard')->assertOk()->getContent();
        preg_match('/data-alfred="([^"]+)"/', $html, $m);

        return json_decode(html_entity_decode($m[1]), true);
    }

    public function test_defaults_to_alfred_and_a_question_mark(): void
    {
        $config = $this->pageConfig();

        $this->assertSame('Alfred', $config['name']);
        $this->assertSame(['type' => 'text', 'text' => '?'], $config['icon']);
        $this->assertNull($config['color']);
    }

    public function test_name_icon_and_colour_come_from_config(): void
    {
        config([
            'alfred.branding.name' => 'Ask Ops',
            'alfred.branding.icon' => '/images/helper.png',
            'alfred.branding.color' => '#0f766e',
        ]);

        $config = $this->pageConfig();

        $this->assertSame('Ask Ops', $config['name']);
        $this->assertSame('image', $config['icon']['type']);
        $this->assertStringEndsWith('/images/helper.png', $config['icon']['src']);
        $this->assertSame('#0f766e', $config['color']);
    }

    public function test_icon_can_be_a_url_a_data_uri_or_short_text(): void
    {
        $alfred = app(Alfred::class);

        config(['alfred.branding.icon' => 'https://cdn.example.com/bot.svg']);
        $this->assertSame(['type' => 'image', 'src' => 'https://cdn.example.com/bot.svg'], $alfred->icon());

        config(['alfred.branding.icon' => 'data:image/png;base64,iVBORw0KGgo=']);
        $this->assertSame('image', $alfred->icon()['type']);

        config(['alfred.branding.icon' => 'logo.svg']);
        $this->assertSame('image', $alfred->icon()['type']);

        config(['alfred.branding.icon' => '🤖']);
        $this->assertSame(['type' => 'text', 'text' => '🤖'], $alfred->icon());

        config(['alfred.branding.icon' => '']);
        $this->assertSame(['type' => 'text', 'text' => '?'], $alfred->icon());
    }

    public function test_colour_rejects_anything_that_is_not_a_plain_css_colour(): void
    {
        $alfred = app(Alfred::class);

        foreach (['#fff', '#0f766e', 'rgb(15, 118, 110)', 'teal', 'oklch(60% 0.1 180)'] as $ok) {
            config(['alfred.branding.color' => $ok]);
            $this->assertSame($ok, $alfred->color(), $ok);
        }
        foreach (['red;}body{display:none', 'url(x)', '#fff}</style>'] as $bad) {
            config(['alfred.branding.color' => $bad]);
            $this->assertNull($alfred->color(), $bad);
        }
    }

    public function test_the_name_is_used_in_messages_people_see(): void
    {
        config(['alfred.branding.name' => 'Ask Ops']);
        $user = $this->user();
        $this->loadDashboard($user);

        $this->actingAs($user)->postJson('/_alfred/explain', ['token' => 'expired', 'element' => $this->element()])
            ->assertStatus(410)
            ->assertJson(['message' => 'This page has expired for Ask Ops. Reload the page and try again.']);
    }
}
