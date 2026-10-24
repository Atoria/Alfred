<?php

namespace Atoria\Alfred\Tests;

use Atoria\Alfred\AlfredServiceProvider;
use Atoria\Alfred\Llm\LlmClient;
use Atoria\Alfred\Tests\Fixtures\App\Http\Controllers\DashboardController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected FakeLlm $llm;

    protected function getPackageProviders($app): array
    {
        return [AlfredServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('alfred.enabled', true);
        $app['config']->set('alfred.base_path', self::fixturePath());
        $app['config']->set('alfred.verifiers', ['verifier@example.com']);
        $app['config']->set('alfred.users', ['*@example.com']);
        $app['config']->set('alfred.openai.api_key', 'sk-test');
        $app['config']->set('alfred.async.enabled', false); // BackgroundTest covers the async path
    }

    public static function fixturePath(): string
    {
        return __DIR__.'/Fixtures/app';
    }

    protected function setUp(): void
    {
        parent::setUp();

        View::addNamespace('fixture', self::fixturePath().'/resources/views');

        // Browsers of signed-in people always carry a session cookie; Alfred
        // doesn't record visitors without one (unless guests are on).
        $this->withCookie((string) config('session.cookie'), str_repeat('s', 40));

        $this->llm = new FakeLlm;
        $this->app->instance(LlmClient::class, $this->llm);

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email');
            $t->string('password');
            $t->rememberToken();
            $t->timestamps();
        });
        Schema::create('shifts', function (Blueprint $t) {
            $t->id();
            $t->string('ward');
            $t->string('status');
            $t->string('secret_token')->nullable();
            $t->timestamps();
        });
        $this->artisan('migrate')->run();

        foreach ([['A', 'booked'], ['B', 'booked'], ['C', 'open'], ['D', 'cancelled']] as [$ward, $status]) {
            \DB::table('shifts')->insert(['ward' => $ward, 'status' => $status, 'secret_token' => 'tok-'.$ward]);
        }
    }

    protected function defineRoutes($router): void
    {
        Route::middleware('web')->group(function () {
            Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
            Route::get('/dashboard.json', [DashboardController::class, 'json'])->name('dashboard.json');
        });
    }

    protected function user(string $email = 'manager@example.com'): User
    {
        $user = new User;
        $user->forceFill(['name' => 'Test', 'email' => $email, 'password' => 'x']);
        $user->setTable('users');
        $user->save();

        return $user;
    }

    /** Load the dashboard as $user and return the page token Alfred injected. */
    protected function loadDashboard(User $user): string
    {
        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();
        preg_match('/data-alfred="([^"]+)"/', $html, $m);
        $this->assertNotEmpty($m, 'Alfred overlay was not injected');

        return json_decode(html_entity_decode($m[1]), true)['token'];
    }

    protected function element(array $overrides = []): array
    {
        return array_merge([
            'explain_key' => null,
            'kind' => 'stat',
            'tag' => 'p',
            'labels' => ['Fill rate'],
            'column' => null,
            'text' => '50%',
            'components' => [],
            'selector' => 'section > p',
        ], $overrides);
    }
}
