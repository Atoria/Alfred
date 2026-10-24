<?php

namespace Atoria\Alfred;

use Atoria\Alfred\Agent\Agent;
use Atoria\Alfred\Agent\PromptBuilder;
use Atoria\Alfred\Console\ClearCommand;
use Atoria\Alfred\Context\ContextStore;
use Atoria\Alfred\Context\RequestRecorder;
use Atoria\Alfred\Http\Middleware\RecordAndInject;
use Atoria\Alfred\Llm\LlmClient;
use Atoria\Alfred\Llm\OpenAiClient;
use Atoria\Alfred\Preview\Preview;
use Atoria\Alfred\Privacy\Redactor;
use Atoria\Alfred\Security\PathGuard;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Throwable;

class AlfredServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/alfred.php', 'alfred');

        $this->app->singleton(Alfred::class);
        $this->app->singleton(\Atoria\Alfred\Tickets\Trackers\TrackerManager::class);
        $this->app->singleton(\Atoria\Alfred\PullRequests\Providers\GitProviderManager::class);
        $this->app->singleton(ContextStore::class);
        $this->app->singleton(\Atoria\Alfred\Tenancy\Tenancy::class);
        $this->app->singleton(\Atoria\Alfred\Access\Guests::class);
        $this->app->scoped(RequestRecorder::class);

        // Built per use so runtime config changes (and tests) apply.
        $this->app->bind(PathGuard::class, fn () => PathGuard::fromConfig());
        $this->app->bind(Redactor::class, fn () => Redactor::fromConfig());
        $this->app->bind(LlmClient::class, function () {
            return match ($provider = config('alfred.provider', 'openai')) {
                'openai' => new OpenAiClient((array) config('alfred.openai', [])),
                'laragent' => new \Atoria\Alfred\Llm\LarAgentClient((array) config('alfred.laragent', [])),
                default => throw new InvalidArgumentException("Unknown Alfred provider [{$provider}]."),
            };
        });
        $this->app->singleton(Preview::class);
        $this->activatePreviewEarly();

        $this->app->bind(Agent::class, fn ($app) => new Agent(
            $app->make(LlmClient::class),
            $app->make(PromptBuilder::class),
            $app->make(PathGuard::class),
            $app->make(Redactor::class),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/alfred.php' => config_path('alfred.php')], 'alfred-config');
            $this->publishes([__DIR__.'/../resources/js/alfred.js' => public_path('vendor/alfred/alfred.js')], 'alfred-assets');
            $this->commands([ClearCommand::class, \Atoria\Alfred\Console\UsageCommand::class, \Atoria\Alfred\Console\DoctorCommand::class]);
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->defineGates();

        if (class_exists(\Illuminate\Foundation\Console\AboutCommand::class)) {
            \Illuminate\Foundation\Console\AboutCommand::add('Alfred', fn () => [
                'Version' => \Composer\InstalledVersions::isInstalled('atoria/alfred') ? (string) \Composer\InstalledVersions::getPrettyVersion('atoria/alfred') : 'dev',
                'Enabled' => config('alfred.enabled') ? '<fg=green;options=bold>ENABLED</>' : 'OFF',
                'AI provider' => (string) config('alfred.provider', 'openai'),
                'Changes' => config('alfred.edit.enabled') ? (string) config('alfred.edit.mode', 'propose') : 'off',
                'Guests' => config('alfred.guests.enabled') ? '<fg=yellow;options=bold>ON</>' : 'off',
            ]);
        }

        Blade::directive('alfred', fn () => '<?php echo app(\\Atoria\\Alfred\\Alfred::class)->scriptTag(request()); ?>');

        if (! config('alfred.enabled')) {
            return;
        }

        $this->loadRoutesFrom(__DIR__.'/../routes/alfred.php');

        if (class_exists(EncryptCookies::class) && method_exists(EncryptCookies::class, 'except')) { // @phpstan-ignore function.alreadyNarrowedType (apps may swap the class)
            EncryptCookies::except([\Atoria\Alfred\Access\Guests::COOKIE, Preview::COOKIE]); // signed by Alfred itself
        }

        $kernel = $this->app->make(Kernel::class);
        if (method_exists($kernel, 'pushMiddleware')) { // @phpstan-ignore function.alreadyNarrowedType (custom kernels)
            $kernel->pushMiddleware(RecordAndInject::class);
        }
        if (method_exists($kernel, 'prependToMiddlewarePriority')) { // @phpstan-ignore function.alreadyNarrowedType (custom kernels)
            $kernel->prependToMiddlewarePriority(\Atoria\Alfred\Http\Middleware\ResolveTenant::class);
        }

        $this->listen();
        $this->catchBrokenPreviews();
    }

    /**
     * Any error while someone previews a change (exception, PHP fatal error)
     * shows them a recovery page with "Stop preview" instead of an error page
     * with no way out. Covers errors anywhere after boot, including views,
     * middleware and Livewire requests.
     */
    protected function catchBrokenPreviews(): void
    {
        try {
            $handler = $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class);
        } catch (Throwable) {
            return;
        }
        if (! method_exists($handler, 'renderable')) { // @phpstan-ignore function.alreadyNarrowedType (custom handlers)
            return;
        }

        $handler->renderable(function (Throwable $e, $request) {
            $preview = $this->app->make(Preview::class);
            if (! $request instanceof Request || ! $preview->active() || $this->app->make(Alfred::class)->isOwnRequest($request)) {
                return null;
            }
            // Ordinary "not found" / "forbidden" / validation answers aren't the preview's fault.
            if ($e instanceof \Illuminate\Validation\ValidationException
                || $e instanceof \Illuminate\Auth\AuthenticationException
                || ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface && $e->getStatusCode() < 500)) {
                return null;
            }

            return \Atoria\Alfred\Preview\PreviewFailure::response($request, $preview, $e);
        });
    }

    /**
     * A user previewing a proposed change needs their modified classes in
     * place before the app's own providers (e.g. Filament panels) load them —
     * so this runs in register(), which for packages comes before the app's.
     */
    protected function activatePreviewEarly(): void
    {
        if (! config('alfred.enabled') || ! config('alfred.edit.enabled')) {
            return;
        }

        if (class_exists(EncryptCookies::class) && method_exists(EncryptCookies::class, 'except')) { // @phpstan-ignore function.alreadyNarrowedType (apps may swap the class)
            EncryptCookies::except(Preview::COOKIE); // signed by Alfred itself
        }

        if (! $this->app->bound('request')) {
            return;
        }

        $request = $this->app->make('request');
        if ($request instanceof Request && $request->cookies->has(Preview::COOKIE)) { // @phpstan-ignore instanceof.alwaysTrue (the binding can be replaced)
            $this->app->make(Preview::class)->activate($request);
        }
    }

    protected function defineGates(): void
    {
        $use = (string) config('alfred.gates.use', 'useAlfred');
        $verify = (string) config('alfred.gates.verify', 'verifyAlfred');
        $records = (string) config('alfred.gates.records', 'viewAlfredRecords');
        $edit = (string) config('alfred.gates.edit', 'editAlfred');
        $code = (string) config('alfred.gates.code', 'viewAlfredCode');
        $report = (string) config('alfred.gates.report', 'reportAlfred');
        $contribute = (string) config('alfred.gates.contribute', 'contributeAlfredExplanations');

        // Apps define their own in a service provider; those take precedence
        // because Gate::has() is checked lazily at call time below.
        $this->app->booted(function () use ($use, $verify, $records, $edit, $code, $report, $contribute) {
            if (! Gate::has($use)) {
                // Only people listed (or verifiers). Unlisted users never see Alfred.
                Gate::define($use, fn ($user = null) => self::emailListed($user, 'alfred.users') || self::emailListed($user, 'alfred.verifiers'));
            }
            if (! Gate::has($verify)) {
                Gate::define($verify, fn ($user = null) => self::emailListed($user, 'alfred.verifiers'));
            }
            if (! Gate::has($records)) {
                Gate::define($records, fn ($user = null) => Gate::forUser($user)->allows($verify));
            }
            if (! Gate::has($edit)) {
                Gate::define($edit, fn ($user = null) => Gate::forUser($user)->allows($verify));
            }
            if (! Gate::has($code)) {
                Gate::define($code, fn ($user = null) => Gate::forUser($user)->allows($verify));
            }
            if (! Gate::has($contribute)) {
                // Signed-in users who may use Alfred; guests never (checked in Alfred::canContribute too).
                Gate::define($contribute, fn ($user = null) => $user !== null && Gate::forUser($user)->allows($use));
            }
            if (! Gate::has($report)) {
                Gate::define($report, fn ($user = null) => Gate::forUser($user)->allows($use));
            }
        });
    }

    /** Is the user's email in the given config list? Entries may be exact or patterns like *@acme.com. */
    public static function emailListed(mixed $user, string $key): bool
    {
        $email = is_object($user) ? ($user->email ?? null) : null;
        if (! is_string($email) || $email === '') {
            return false;
        }
        $email = strtolower($email);

        foreach ((array) config($key, []) as $entry) {
            $entry = strtolower(trim((string) $entry));
            if ($entry !== '' && ($entry === $email || (str_contains($entry, '*') && \Illuminate\Support\Str::is($entry, $email)))) {
                return true;
            }
        }

        return false;
    }

    protected function listen(): void
    {
        $recorder = fn () => $this->app->make(RequestRecorder::class);

        Event::listen(QueryExecuted::class, function (QueryExecuted $event) use ($recorder) {
            $r = $recorder();
            if ($r->active()) {
                $r->recordQuery($event);
            }
        });

        // Once the user is known: someone who may not use Alfred isn't recorded any further.
        Event::listen(\Illuminate\Auth\Events\Authenticated::class, function ($event) use ($recorder) {
            $r = $recorder();
            if (! $r->active()) {
                return;
            }
            try {
                if (! $this->app->make(Alfred::class)->canUse($event->user)) {
                    $r->stop();
                }
            } catch (Throwable) {
                $r->stop();
            }
        });

        View::composer('*', function ($view) use ($recorder) {
            $r = $recorder();
            if ($r->active()) {
                $r->recordView($view->getName(), method_exists($view, 'getPath') ? $view->getPath() : null);
            }
        });

        // Livewire (v3 + v4): which components were on the page, and which one
        // was rendering when a query ran.
        if (function_exists('Livewire\\on')) {
            foreach (['mount', 'hydrate'] as $hook) {
                try {
                    \Livewire\on($hook, function ($component) use ($recorder) {
                        $r = $recorder();
                        if ($r->active() && is_object($component)) {
                            $r->recordComponent($component);
                        }
                    });
                } catch (Throwable) {
                    // unsupported Livewire version — component hints are optional
                }
            }
        }
    }
}
