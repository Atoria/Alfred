<?php

namespace Atoria\Alfred\Tests\Feature;

use Atoria\Alfred\Context\ElementDescriptor;
use Atoria\Alfred\Context\FrontendSource;
use Atoria\Alfred\Security\PathGuard;
use Atoria\Alfred\Tests\TestCase;
use Illuminate\Support\Facades\File;

/** Inertia / Vue / React pages: the markup lives in resources/js, not Blade. */
class FrontendTest extends TestCase
{
    private string $sandbox;

    private const HERO = 'resources/js/components/landing/LandingHero.vue';

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = sys_get_temp_dir().'/alfred-front-'.uniqid();
        File::copyDirectory(self::fixturePath(), $this->sandbox);
        config()->set('alfred.base_path', $this->sandbox);
        config()->set('alfred.edit.enabled', true);
        config()->set('alfred.edit.environments', ['testing']);
        config()->set('alfred.edit.mode', 'write');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sandbox);
        parent::tearDown();
    }

    protected function heroElement(array $files): array
    {
        return $this->element([
            'kind' => 'element',
            'tag' => 'span',
            'labels' => ['Know what works.'],
            'selector' => 'div#app > section.hero > h1.heading > span.heading-accent',
            'frontend' => ['framework' => 'vue', 'page' => 'Welcome', 'files' => $files, 'components' => ['LandingHero', 'Welcome']],
        ]);
    }

    protected function resolve(array $files, ?string $page = 'Welcome'): array
    {
        $element = ElementDescriptor::fromArray($this->element(['frontend' => ['framework' => 'vue', 'page' => $page, 'files' => $files]]));

        return (new FrontendSource(PathGuard::fromConfig()))->resolve($element);
    }

    public function test_browser_paths_become_readable_project_files(): void
    {
        $r = $this->resolve([
            $this->sandbox.'/'.self::HERO,                                   // this machine
            '/var/www/html/resources/js/pages/Welcome.vue',                  // a container / another laptop
            '/var/www/html/resources/js/components/Missing.vue',             // not in this project
            '/var/www/html/resources/js/../../.env',                         // escape attempt
            '/home/me/app/node_modules/vue/dist/runtime.js',                 // not readable code
        ]);

        $this->assertSame([self::HERO, 'resources/js/pages/Welcome.vue'], $r['files']);
        $this->assertSame('vue', $r['framework']);
    }

    public function test_the_inertia_page_name_finds_its_component(): void
    {
        $r = $this->resolve([], 'Welcome');

        $this->assertSame('resources/js/pages/Welcome.vue', $r['page_file']);
        $this->assertSame([], $r['files']);
        $this->assertNull($this->resolve([], '../../.env')['page']);
    }

    public function test_frontend_folders_are_readable_even_with_an_old_published_config(): void
    {
        // A config published before 1.2 lists only PHP folders for reading.
        config()->set('alfred.paths', ['app', 'resources/views', 'routes']);

        $this->assertTrue(PathGuard::fromConfig()->allows($this->sandbox.'/'.self::HERO), 'edit paths are always readable');
    }

    public function test_the_agent_is_pointed_at_the_component_and_edits_it(): void
    {
        $verifier = $this->user('verifier@example.com');

        $this->llm
            ->then([['read_file', ['path' => self::HERO, 'start_line' => null, 'end_line' => null]]])
            ->then([['propose_edit', [
                'summary' => 'Make "Know what works." black',
                'notes' => null,
                'edits' => [['path' => self::HERO, 'search' => '.heading-accent { color: var(--brand); }', 'replace' => '.heading-accent { color: var(--text-1); }']],
            ]]]);

        $res = $this->actingAs($verifier)->postJson('/_alfred/changes', [
            'token' => $this->loadDashboard($verifier),
            'element' => $this->heroElement(['/Users/someone/www/tracer/'.self::HERO, '/Users/someone/www/tracer/resources/js/pages/Welcome.vue']),
            'instruction' => 'Make whole text here black please',
        ])->assertCreated()->assertJson([
            'status' => 'proposed',
            'files' => [self::HERO],
            'frontend_files' => [self::HERO],
            'needs_build' => true,
            'can_preview' => false, // preview can't run a Vite build
            'can_apply' => true,
        ]);

        $prompt = $this->llm->requests[0]['input'][0]['content'];
        $this->assertStringContainsString('## Front-end source (reported by the browser)', $prompt);
        $this->assertStringContainsString('Inertia page component: Welcome (resources/js/pages/Welcome.vue)', $prompt);
        $this->assertStringContainsString('- '.self::HERO, $prompt);
        $this->assertStringContainsString('resources/js/', $prompt, 'resources/js is readable');

        $instructions = $this->llm->requests[0]['instructions'];
        $this->assertStringContainsString('Never work around not finding the right file with global overrides', $instructions);
        $this->assertStringContainsString('A child element with its own class or colour is not affected by styling its parent', $instructions);

        // The tool read the real component (not refused as unreadable).
        $this->assertStringContainsString('heading-accent', $this->llm->toolOutputs()[0]);

        // Tickets and the overlay show where it lives.
        $location = \Atoria\Alfred\Changes\Change::find($res->json('id'))->location;
        $this->assertSame([self::HERO, 'resources/js/pages/Welcome.vue'], $location['frontend']['files']);

        // Applying writes the component.
        $this->actingAs($verifier)->postJson('/_alfred/changes/'.$res->json('id').'/apply')->assertOk();
        $this->assertStringContainsString('.heading-accent { color: var(--text-1); }', file_get_contents($this->sandbox.'/'.self::HERO));
    }

    public function test_css_files_can_be_read_and_changed(): void
    {
        File::ensureDirectoryExists($this->sandbox.'/resources/css');
        File::put($this->sandbox.'/resources/css/app.css', ".brand { color: purple; }\n");

        $this->assertSame($this->sandbox.'/resources/css/app.css', PathGuard::fromConfig()->file('resources/css/app.css'));
    }

    public function test_blade_and_php_changes_are_still_previewable(): void
    {
        $this->assertFalse(FrontendSource::frontendFile('resources/views/app.blade.php'));
        $this->assertFalse(FrontendSource::frontendFile('app/Http/Controllers/HomeController.php'));
        $this->assertTrue(FrontendSource::frontendFile('resources/js/pages/Welcome.vue'));
        $this->assertTrue(FrontendSource::frontendFile('resources/css/app.css'));
    }

    public function test_unknown_or_junk_frontend_data_is_ignored(): void
    {
        $d = ElementDescriptor::fromArray($this->element(['frontend' => ['framework' => 'angularjs<script>', 'page' => 'a b<c>', 'files' => 'nope', 'components' => ['Ok', 'not ok!', str_repeat('x', 200)]]]));

        $this->assertNull($d->frontend['framework']);
        $this->assertNull($d->frontend['page']);
        $this->assertSame([], $d->frontend['files']);
        $this->assertSame(['Ok'], $d->frontend['components']);
    }
}
