<?php

namespace Atoria\Alfred\Tests\Feature;

use Atoria\Alfred\Changes\Change;
use Atoria\Alfred\Preview\ClassName;
use Atoria\Alfred\Preview\Preview;
use Atoria\Alfred\Preview\PreviewErrors;
use Atoria\Alfred\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;

class PreviewTest extends TestCase
{
    private string $sandbox;

    private string $class;

    private string $classPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir().'/alfred-preview-'.uniqid();
        File::copyDirectory(self::fixturePath(), $this->sandbox);
        config()->set('alfred.base_path', $this->sandbox);
        config()->set('alfred.edit.enabled', true);
        config()->set('alfred.edit.environments', ['testing']);
        config()->set('alfred.edit.mode', 'propose'); // the staging case: nothing may be written

        // A brand-new controller class per test (PHP can't unload classes between tests).
        $short = 'Board'.str_replace('.', '', uniqid('', true)).'Controller';
        $this->class = 'PreviewSandbox\\'.$short;
        $this->classPath = 'app/Http/Controllers/'.$short.'.php';
        File::ensureDirectoryExists($this->sandbox.'/app/Http/Controllers');
        file_put_contents($this->sandbox.'/'.$this->classPath, <<<PHP
<?php

namespace PreviewSandbox;

class {$short}
{
    public function show()
    {
        return '<html><body><td class="early">'.\$this->format('Gold plan').'</td></body></html>';
    }

    protected function format(string \$value): string
    {
        return \$value;
    }
}

PHP);
        $sandbox = $this->sandbox;
        spl_autoload_register(function ($class) use ($sandbox) {
            if (str_starts_with($class, 'PreviewSandbox\\')) {
                $file = $sandbox.'/app/Http/Controllers/'.substr($class, strlen('PreviewSandbox\\')).'.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        });

        // A closure, so the class is loaded when the request runs (like a real app,
        // where routes and Filament resources load after Alfred's early hook).
        $class = $this->class;
        Route::middleware('web')->get('/board', fn () => app($class)->show());
        Route::middleware('web')->get('/board-view', fn () => view('board'));
        View::addLocation($this->sandbox.'/resources/views');
        file_put_contents($this->sandbox.'/resources/views/board.blade.php', "<html><body><span class=\"label\">Monthly price</span></body></html>\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sandbox);
        File::deleteDirectory(storage_path('framework/alfred-preview'));
        parent::tearDown();
    }

    protected function proposal(string $path, string $search, string $replace, string $status = Change::PROPOSED): Change
    {
        $before = file_get_contents($this->sandbox.'/'.$path);
        $after = str_replace($search, $replace, $before);

        return Change::query()->create([
            'user_key' => 'u:1', 'environment' => 'staging', 'instruction' => 'make it in badges',
            'summary' => 'Show Monthly price as badges',
            'edits' => [['path' => $path, 'search' => $search, 'replace' => $replace]],
            'files' => [$path => ['before_sha1' => sha1($before), 'after_sha1' => sha1($after)]],
            'diff' => 'x', 'status' => $status,
        ]);
    }

    protected function previewToken($user, Change $change): string
    {
        // Previews run only in the session they were started in: send the session cookie
        // (JSON test requests leave cookies out unless asked; browsers send them).
        $cookie = $this->actingAs($user)->withCredentials()->postJson("/_alfred/changes/{$change->id}/preview")->assertOk()->getCookie(Preview::COOKIE, false);
        $this->assertTrue($cookie->isHttpOnly(), 'page scripts cannot read the preview cookie');

        return $cookie->getValue();
    }

    public function test_the_requester_sees_the_proposed_php_change_without_any_file_changing(): void
    {
        $manager = $this->user('verifier@example.com');
        $change = $this->proposal($this->classPath, 'return $value;', "return '<span class=\"badge\">'.\$value.'</span>';");
        $original = file_get_contents($this->sandbox.'/'.$this->classPath);

        $html = $this->actingAs($manager)->withUnencryptedCookie(Preview::COOKIE, $this->previewToken($manager, $change))
            ->get('/board')->assertOk()->getContent();

        $this->assertStringContainsString('<span class="badge">Gold plan</span>', $html);
        $this->assertSame($original, file_get_contents($this->sandbox.'/'.$this->classPath), 'the codebase is untouched');
        $this->assertSame(Change::PROPOSED, $change->fresh()->status);
    }

    public function test_blade_views_are_previewed(): void
    {
        $manager = $this->user('verifier@example.com');
        $change = $this->proposal('resources/views/board.blade.php', '<span class="label">', '<span class="label badge">');

        $html = $this->actingAs($manager)->withUnencryptedCookie(Preview::COOKIE, $this->previewToken($manager, $change))
            ->get('/board-view')->assertOk()->getContent();

        $this->assertStringContainsString('<span class="label badge">Monthly price</span>', $html);
        $this->assertStringContainsString('"preview":{"id":'.$change->id, html_entity_decode($html), 'the overlay knows to show the preview bar');
        $this->assertStringNotContainsString('badge', file_get_contents($this->sandbox.'/resources/views/board.blade.php'));
    }

    /** @return array{0: mixed, 1: string} the user and their preview cookie for a change that breaks /board-view */
    protected function brokenBladePreview(string $broken = '{{ alfred_test_function_that_does_not_exist() }}'): array
    {
        $manager = $this->user('verifier@example.com');
        $change = $this->proposal('resources/views/board.blade.php', '<span class="label">Monthly price</span>', $broken);

        return [$manager, $this->previewToken($manager, $change)];
    }

    public function test_a_preview_that_breaks_the_page_shows_a_way_out(): void
    {
        [$manager, $token] = $this->brokenBladePreview();

        $html = $this->actingAs($manager)->withUnencryptedCookie(Preview::COOKIE, $token)
            ->get('/board-view')->assertStatus(500)->assertHeader('X-Alfred-Preview-Failed', '1')->getContent();

        $this->assertStringContainsString('The change you are previewing broke this page', $html);
        $this->assertStringContainsString('Show Monthly price as badges', $html);
        $this->assertStringContainsString('alfred_test_function_that_does_not_exist', $html, 'says what broke');
        $this->assertStringContainsString('/_alfred/preview/stop?to=%2Fboard-view', $html);
        $this->assertStringContainsString('resources/views/board.blade.php', $html, 'says where (the previewed file)');

        // Remembered for "Refine": the agent gets the error with the next attempt.
        $change = Change::query()->latest('id')->first();
        $this->assertStringContainsString('alfred_test_function_that_does_not_exist', (string) PreviewErrors::get($change->id));
        $this->assertStringContainsString('click <em>Refine</em>', $html);
    }

    public function test_other_kinds_of_breakage_are_caught_too(): void
    {
        foreach ([
            '@php throw new \\RuntimeException("boom"); @endphp',  // an exception
            '@php intdiv(1, 0); @endphp',                          // an Error (DivisionByZeroError)
            '@php abort(503); @endphp',                            // a 5xx answer
            '@if(true)',                                           // a Blade syntax error
        ] as $broken) {
            File::deleteDirectory(Preview::root());
            [$manager, $token] = $this->brokenBladePreview($broken);

            $this->actingAs($manager)->withUnencryptedCookie(Preview::COOKIE, $token)
                ->get('/board-view')->assertHeader('X-Alfred-Preview-Failed', '1')->assertSee('Stop preview and reload', false);
        }
    }

    public function test_json_requests_get_a_message_with_the_way_out(): void
    {
        [$manager, $token] = $this->brokenBladePreview();

        $this->actingAs($manager)->withUnencryptedCookie(Preview::COOKIE, $token)->withCredentials()
            ->getJson('/board-view')->assertStatus(500)
            ->assertJson(['alfred_preview_failed' => true])
            ->assertJsonPath('stop_url', fn ($url) => str_contains($url, '/_alfred/preview/stop'));
    }

    public function test_ordinary_not_found_pages_are_not_blamed_on_the_preview(): void
    {
        [$manager, $token] = $this->brokenBladePreview();

        $this->actingAs($manager)->withUnencryptedCookie(Preview::COOKIE, $token)
            ->get('/no-such-page')->assertNotFound()->assertHeaderMissing('X-Alfred-Preview-Failed');
    }

    public function test_stop_clears_the_cookie_and_goes_back_within_the_site(): void
    {
        $this->get('/_alfred/preview/stop?to=%2Fboard-view')->assertRedirect('/board-view')->assertCookieExpired(Preview::COOKIE);
        $this->get('/_alfred/preview/stop?to=%2F%2Fevil.test')->assertRedirect('/');
        $this->get('/_alfred/preview/stop?to=https%3A%2F%2Fevil.test')->assertRedirect('/');
    }

    public function test_alfreds_own_endpoints_never_run_previewed_code(): void
    {
        [$manager, $token] = $this->brokenBladePreview();

        $this->actingAs($manager)->withUnencryptedCookie(Preview::COOKIE, $token)->withCredentials()
            ->getJson('/_alfred/changes')->assertOk();
        $this->assertNull(app(Preview::class)->active());
    }

    public function test_the_preview_folder_keeps_itself_out_of_git(): void
    {
        $manager = $this->user('verifier@example.com');
        $change = $this->proposal('resources/views/board.blade.php', '<span class="label">', '<span class="label badge">');
        $this->actingAs($manager)->withUnencryptedCookie(Preview::COOKIE, $this->previewToken($manager, $change))->get('/board-view')->assertOk();

        $this->assertSame("*\n!.gitignore\n", file_get_contents(Preview::root().'/.gitignore'));
    }

    public function test_copies_nobody_can_preview_any_more_are_removed(): void
    {
        $root = Preview::root();
        $active = $this->proposal('resources/views/board.blade.php', '<span class="label">', '<span class="label badge">');
        $applied = $this->proposal('resources/views/board.blade.php', '<span class="label">', '<b>', Change::APPLIED);
        $idle = $this->proposal('resources/views/board.blade.php', '<span class="label">', '<i>');

        foreach ([$active, $applied, $idle] as $c) {
            File::ensureDirectoryExists($root.'/'.$c->id.'-abcdefabcdef/resources/views');
        }
        File::ensureDirectoryExists($root.'/999999-abcdefabcdef');   // change deleted
        File::ensureDirectoryExists($root.'/not-ours');               // left alone
        touch($root.'/'.$idle->id.'-abcdefabcdef', time() - 8 * 86400); // nobody previewed it for 8 days

        $this->assertSame(3, Preview::prune());
        $this->assertDirectoryExists($root.'/'.$active->id.'-abcdefabcdef');
        $this->assertDirectoryDoesNotExist($root.'/'.$applied->id.'-abcdefabcdef');
        $this->assertDirectoryDoesNotExist($root.'/'.$idle->id.'-abcdefabcdef');
        $this->assertDirectoryDoesNotExist($root.'/999999-abcdefabcdef');
        $this->assertDirectoryExists($root.'/not-ours');

        $this->artisan('alfred:clear', ['--previews' => true])->expectsOutput('Removed 1 preview folder(s).')->assertSuccessful();
        $this->assertDirectoryDoesNotExist($root.'/'.$active->id.'-abcdefabcdef');
    }

    public function test_without_the_cookie_nothing_changes(): void
    {
        $this->proposal($this->classPath, 'return $value;', "return 'CHANGED';");

        $this->actingAs($this->user('verifier@example.com'))->get('/board')->assertSee('Gold plan', false)->assertDontSee('CHANGED');
    }

    public function test_a_class_that_is_already_loaded_is_reported_not_faked(): void
    {
        $manager = $this->user('verifier@example.com');
        $this->actingAs($manager)->get('/board'); // loads the original class into this PHP process
        $change = $this->proposal($this->classPath, 'return $value;', "return 'CHANGED';");

        $html = $this->actingAs($manager)->withUnencryptedCookie(Preview::COOKIE, $this->previewToken($manager, $change))->get('/board')->getContent();

        $this->assertStringNotContainsString('CHANGED', $html);
        $this->assertStringContainsString('already loaded', html_entity_decode($html));
    }

    public function test_forged_or_stale_previews_do_nothing(): void
    {
        $manager = $this->user('verifier@example.com');
        $change = $this->proposal($this->classPath, 'return $value;', "return 'CHANGED';");
        $token = $this->previewToken($manager, $change);

        $forged = preg_replace('/\.[0-9a-f]+$/', '.'.str_repeat('0', 40), $token);
        $this->actingAs($manager)->withUnencryptedCookie(Preview::COOKIE, $forged)->get('/board')->assertDontSee('CHANGED');

        $change->forceFill(['status' => Change::DISCARDED])->save();
        $this->actingAs($manager)->withUnencryptedCookie(Preview::COOKIE, $token)->get('/board')->assertDontSee('CHANGED');
    }

    public function test_previews_switch_themselves_off(): void
    {
        config()->set('alfred.edit.preview_minutes', 30);
        $manager = $this->user('verifier@example.com');
        $change = $this->proposal($this->classPath, 'return $value;', "return 'CHANGED';");
        $token = $this->previewToken($manager, $change);

        $this->travel(31)->minutes();
        $this->actingAs($manager)->withUnencryptedCookie(Preview::COOKIE, $token)->get('/board')->assertDontSee('CHANGED');
    }

    public function test_a_preview_is_only_for_the_person_it_was_issued_to(): void
    {
        $manager = $this->user('verifier@example.com');
        $change = $this->proposal($this->classPath, 'return $value;', "return 'CHANGED';");
        $token = $this->previewToken($manager, $change);

        // A colleague who got hold of the cookie (also allowed to edit): normal page, cookie removed.
        $colleague = $this->user('verifier2@example.com');
        Gate::define('editAlfred', fn ($u = null) => $u !== null);
        $res = $this->actingAs($colleague)->withUnencryptedCookie(Preview::COOKIE, $token)->get('/board');
        $res->assertRedirect('/board');
        $this->assertSame('', (string) $res->getCookie(Preview::COOKIE, false)?->getValue());
        $this->assertDontSee($res, 'CHANGED');
    }

    public function test_a_preview_runs_only_in_the_session_it_was_started_in(): void
    {
        $manager = $this->user('verifier@example.com');
        $change = $this->proposal($this->classPath, 'return $value;', "return 'CHANGED';");
        $token = $this->previewToken($manager, $change);

        // Another browser (or the same one after signing out: a new session) runs nothing previewed.
        $hit = false;
        Route::middleware('web')->get('/side-effect', function () use (&$hit) {
            $hit = str_contains(app($this->class)->show(), 'CHANGED');

            return 'ok';
        });
        $this->withCookie(config('session.cookie'), str_repeat('t', 40));
        $this->actingAs($this->user('someone@example.com'))->withUnencryptedCookie(Preview::COOKIE, $token)->get('/side-effect')->assertOk();
        $this->assertFalse($hit, 'previewed code never ran for the other session');
    }

    public function test_requests_without_a_session_do_not_end_the_preview(): void
    {
        // Like Livewire's script route: no "web" middleware, so no session and no user.
        Route::get('/asset.js', fn () => response('console.log(1)', 200, ['Content-Type' => 'application/javascript']));
        $manager = $this->user('verifier@example.com');
        $change = $this->proposal($this->classPath, 'return $value;', "return 'CHANGED';");
        $token = $this->previewToken($manager, $change);

        $res = $this->withUnencryptedCookie(Preview::COOKIE, $token)->get('/asset.js')->assertOk();
        $this->assertNull($res->getCookie(Preview::COOKIE, false), 'the preview cookie is kept');

        $this->actingAs($manager)->withUnencryptedCookie(Preview::COOKIE, $token)->get('/board')->assertOk()->assertSee('CHANGED', false);
    }

    public function test_a_preview_stops_when_its_owner_may_no_longer_edit(): void
    {
        $manager = $this->user('verifier@example.com');
        $change = $this->proposal($this->classPath, 'return $value;', "return 'CHANGED';");
        $token = $this->previewToken($manager, $change);

        Gate::define('editAlfred', fn ($u = null) => false);
        $this->actingAs($manager)->withUnencryptedCookie(Preview::COOKIE, $token)->get('/board')->assertRedirect('/board');
    }

    public function test_no_session_no_preview(): void
    {
        $manager = $this->user('verifier@example.com');
        $change = $this->proposal($this->classPath, 'return $value;', "return 'CHANGED';");
        $token = $this->previewToken($manager, $change);

        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->withUnencryptedCookie(Preview::COOKIE, $token)->get('/board')->assertOk()->assertDontSee('CHANGED');
    }

    private function assertDontSee($response, string $text): void
    {
        $this->assertStringNotContainsString($text, (string) $response->getContent());
    }

    public function test_a_file_edited_since_the_proposal_is_not_previewed(): void
    {
        $manager = $this->user('verifier@example.com');
        $change = $this->proposal('resources/views/board.blade.php', '<span class="label">', '<span class="label badge">');
        file_put_contents($this->sandbox.'/resources/views/board.blade.php', "<html><body><span class=\"label\">Monthly price!</span></body></html>\n");

        $html = $this->actingAs($manager)->withUnencryptedCookie(Preview::COOKIE, $this->previewToken($manager, $change))->get('/board-view')->getContent();

        $this->assertStringContainsString('Monthly price!', $html);
        $this->assertStringContainsString('has changed since the proposal', html_entity_decode($html));
    }

    public function test_preview_endpoint_rules(): void
    {
        $change = $this->proposal($this->classPath, 'return $value;', "return 'CHANGED';");

        $this->actingAs($this->user())->postJson("/_alfred/changes/{$change->id}/preview")->assertForbidden();

        $verifier = $this->user('verifier@example.com');
        $this->actingAs($verifier)->getJson("/_alfred/changes/{$change->id}")->assertJson(['can_preview' => true, 'can_apply' => false]);

        config()->set('alfred.edit.preview', false);
        $this->actingAs($verifier)->postJson("/_alfred/changes/{$change->id}/preview")->assertStatus(409);
        config()->set('alfred.edit.preview', true);

        $_SERVER['LARAVEL_OCTANE'] = 1;
        try {
            $this->actingAs($verifier)->postJson("/_alfred/changes/{$change->id}/preview")->assertStatus(409)->assertJsonPath('message', 'Preview is not available on long-running servers (Octane).');
        } finally {
            unset($_SERVER['LARAVEL_OCTANE']);
        }

        $change->forceFill(['status' => Change::DISCARDED])->save();
        $this->actingAs($verifier)->postJson("/_alfred/changes/{$change->id}/preview")->assertStatus(409);
    }

    public function test_class_name_detection(): void
    {
        $this->assertSame('App\\Filament\\Resources\\X\\XResource', ClassName::fromCode("<?php\nnamespace App\\Filament\\Resources\\X;\n\nuse Foo;\n\nfinal class XResource extends Resource { public function a() { return Foo::class; } }"));
        $this->assertSame('Plain', ClassName::fromCode("<?php\n\$x = new class {};\nclass Plain {}"));
        $this->assertNull(ClassName::fromCode("<?php\nreturn ['a' => 1];"));
    }
}
