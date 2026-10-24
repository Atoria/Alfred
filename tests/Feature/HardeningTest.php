<?php

namespace Atoria\Alfred\Tests\Feature;

use Atoria\Alfred\Access\Guests;
use Atoria\Alfred\Changes\Change;
use Atoria\Alfred\Changes\ChangeManager;
use Atoria\Alfred\Context\CodeLocator;
use Atoria\Alfred\Security\AccessDenied;
use Atoria\Alfred\Security\PathGuard;
use Atoria\Alfred\Support\SafeUrl;
use Atoria\Alfred\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

/** Limits, one-ticket-per-change, who sees code locations, and the smaller guards. */
class HardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('alfred.tickets.trackers', ['jira']);
        config()->set('alfred.trackers.jira', ['url' => 'https://acme.atlassian.net/', 'email' => 'bot@acme.test', 'token' => 't', 'project' => 'PROJ', 'issue_type' => 'Task']);
        Http::fake(['acme.atlassian.net/*' => Http::response(['id' => '1', 'key' => 'PROJ-1'], 201)]);
    }

    protected function report($user, array $extra = [])
    {
        return $this->actingAs($user)->postJson('/_alfred/requests', ['token' => $this->loadDashboard($user), 'element' => $this->element(), 'text' => 'The fill rate looks off.'] + $extra);
    }

    protected function ticket($user, int $id)
    {
        return $this->actingAs($user)->postJson("/_alfred/changes/{$id}/ticket", ['title' => 'Fill rate', 'description' => 'Looks off.']);
    }

    public function test_code_locations_are_shown_only_to_people_with_code_access(): void
    {
        $id = $this->report($this->user())->assertCreated()->assertJsonPath('location', null)->json('id');
        $this->assertNotNull(Change::query()->find($id)->location['route']);

        $this->report($this->user('verifier@example.com'))->assertCreated()->assertJsonPath('location.route', 'dashboard');
    }

    public function test_a_ticket_is_created_once_even_when_two_people_press_create(): void
    {
        $verifier = $this->user('verifier@example.com');
        $id = $this->report($verifier)->json('id');

        // Someone else is creating it right now.
        $lock = Cache::store()->getStore()->lock('alfred:ticket:'.$id, 60);
        $lock->get();
        $this->ticket($verifier, $id)->assertStatus(409)->assertJson(['message' => 'A ticket for this is being created right now.']);
        $lock->release();

        $this->ticket($verifier, $id)->assertCreated();
        $this->ticket($verifier, $id)->assertStatus(409);
        Http::assertSentCount(1);
    }

    public function test_ticket_creation_is_rate_limited(): void
    {
        $verifier = $this->user('verifier@example.com');
        foreach (range(1, 10) as $i) {
            $this->ticket($verifier, $this->report($verifier)->json('id'))->assertCreated();
        }
        $this->ticket($verifier, $this->report($verifier)->json('id'))->assertStatus(429)->assertHeader('Retry-After');
    }

    protected function asGuest(): void
    {
        config(['alfred.guests.enabled' => true, 'alfred.guests.access_key' => 'k']);
        $this->withCredentials()->withCookie(config('session.cookie'), str_repeat('g', 40))->withUnencryptedCookie(Guests::COOKIE, app(Guests::class)->cookieValue());
    }

    protected function guestReport()
    {
        preg_match('/data-alfred="([^"]+)"/', $this->get('/dashboard')->getContent(), $m);
        $token = json_decode(html_entity_decode($m[1]), true)['token'];

        return $this->postJson('/_alfred/requests', ['token' => $token, 'element' => $this->element(), 'text' => 'The fill rate looks off.']);
    }

    public function test_guests_send_tickets_only_when_allowed(): void
    {
        $this->asGuest();
        $id = $this->guestReport()->assertCreated()->json('id');

        $this->postJson("/_alfred/changes/{$id}/ticket", ['title' => 'x', 'description' => 'y'])->assertForbidden();
        Http::assertNothingSent();

        config(['alfred.guests.tickets' => true]);
        $this->postJson("/_alfred/changes/{$id}/ticket", ['title' => 'x', 'description' => 'y'])->assertCreated();
    }

    public function test_guest_reports_are_limited_per_ip_too(): void
    {
        $this->asGuest();
        foreach (range(1, 5) as $i) {
            $this->guestReport()->assertCreated();
        }
        // A fresh session doesn't mean a fresh budget.
        $this->withCookie(config('session.cookie'), str_repeat('h', 40));
        $this->guestReport()->assertStatus(429);
    }

    public function test_tickets_list_only_the_code_that_matters(): void
    {
        $location = [
            'route' => 'dashboard', 'action' => 'App\\Http\\Controllers\\DashboardController@index', 'controller_file' => 'app/Http/Controllers/DashboardController.php',
            'components' => [], 'views' => ['resources/views/dashboard.blade.php'],
            'code' => ['a.php:1', 'b.php:2', 'c.php:3', 'd.php:4'],
            'explanation' => ['title' => 'Fill rate', 'summary' => 'Booked ÷ all.', 'flow' => ['DashboardController::index (app/Http/Controllers/DashboardController.php:11)', 'Shift::booked scope'], 'followups' => []],
        ];
        $lines = CodeLocator::lines($location);
        $this->assertContains('Code path 1: DashboardController::index (app/Http/Controllers/DashboardController.php:11)', $lines);
        $this->assertEmpty(preg_grep('/^Loads data at/', $lines), "the explanation's traced path replaces raw query sources");

        unset($location['explanation']);
        $this->assertCount(3, preg_grep('/^Loads data at/', CodeLocator::lines($location)));
    }

    public function test_reports_keep_the_elements_own_views_and_queries(): void
    {
        $verifier = $this->user('verifier@example.com');
        $location = $this->report($verifier)->json('location');

        $this->assertSame(['resources/views/dashboard.blade.php'], $location['views']);
        $this->assertLessThanOrEqual(3, count($location['code']));
    }

    public function test_sensitive_query_parameters_are_not_recorded(): void
    {
        $this->assertSame('https://app.test/reset?page=2', SafeUrl::clean('https://app.test/reset?token=abc&page=2&signature=x&expires=1'));
        $this->assertSame('https://app.test/x', SafeUrl::clean('https://app.test/x?api_key=1&Password=2'));
        $this->assertSame('https://app.test/x?tab=a', SafeUrl::clean('https://app.test/x?tab=a'));
    }

    public function test_deny_patterns_ignore_case_and_editing_has_its_own(): void
    {
        $sandbox = sys_get_temp_dir().'/alfred-guard-'.uniqid();
        File::copyDirectory(self::fixturePath(), $sandbox);
        File::ensureDirectoryExists($sandbox.'/app/Providers');
        File::put($sandbox.'/app/Providers/AppServiceProvider.php', "<?php\n");
        File::put($sandbox.'/app/Models/PasswordHelper.php', "<?php\n");
        config(['alfred.base_path' => $sandbox]);

        try {
            $this->assertThrows(fn () => PathGuard::fromConfig()->file('app/Models/PasswordHelper.php'), AccessDenied::class);

            // Readable for explanations, never editable.
            PathGuard::fromConfig()->file('app/Providers/AppServiceProvider.php');
            $this->assertThrows(fn () => ChangeManager::editableGuard()->file('app/Providers/AppServiceProvider.php'), AccessDenied::class);
        } finally {
            File::deleteDirectory($sandbox);
        }
    }

    public function test_records_never_show_columns_a_model_hides(): void
    {
        $verifier = $this->user('verifier@example.com');
        $token = $this->loadDashboard($verifier);
        $this->llm->submit(['query_index' => 0]);
        $id = $this->actingAs($verifier)->postJson('/_alfred/explain', ['token' => $token, 'element' => $this->element()])->json('id');

        $sandbox = sys_get_temp_dir().'/alfred-hidden-'.uniqid();
        File::ensureDirectoryExists($sandbox.'/app/Models');
        $class = 'HiddenWard'.str_replace('.', '', uniqid('', true));
        File::put($sandbox.'/app/Models/'.$class.'.php', "<?php\nnamespace AlfredHiddenTest;\nclass {$class} extends \\Illuminate\\Database\\Eloquent\\Model { protected \$hidden = ['ward']; }\n");
        require $sandbox.'/app/Models/'.$class.'.php';
        config(['alfred.base_path' => $sandbox]);

        try {
            $rows = $this->actingAs($verifier)->postJson("/_alfred/explanations/{$id}/records", ['token' => $token])->assertOk()->json('rows');
            $this->assertSame('••••', $rows[0]['ward']);
        } finally {
            File::deleteDirectory($sandbox);
        }
    }

    public function test_someone_who_may_no_longer_use_alfred_is_not_recorded_further(): void
    {
        Gate::define('useAlfred', fn ($u = null) => false);
        $html = $this->actingAs($this->user())->get('/dashboard')->getContent();
        $this->assertStringNotContainsString('data-alfred', $html);
    }
}
