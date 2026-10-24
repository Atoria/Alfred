<?php

namespace Atoria\Alfred;

use Atoria\Alfred\Access\Guests;
use Atoria\Alfred\Context\RequestRecorder;
use Atoria\Alfred\Tenancy\Tenancy;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Throwable;

class Alfred
{
    public function enabled(): bool
    {
        return (bool) config('alfred.enabled');
    }

    public function canUse(?Authenticatable $user): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        return Gate::forUser($user)->allows((string) config('alfred.gates.use', 'useAlfred'))
            || $this->isGuest($user);
    }

    /** A signed-out visitor who has been let in (see config alfred.guests). */
    public function isGuest(?Authenticatable $user): bool
    {
        return $user === null && app(Guests::class)->allowed(app()->bound('request') ? request() : null);
    }

    /**
     * The signed-in user, checking each guard in config('alfred.guards') in
     * order (the default guard when none are listed).
     */
    public function user(?Request $request = null): ?Authenticatable
    {
        return $this->resolveUser($request)[0];
    }

    /** The guard the user signed in with, or null for guests. */
    public function guard(?Request $request = null): ?string
    {
        return $this->resolveUser($request)[1];
    }

    /** @return array{0: ?Authenticatable, 1: ?string} */
    protected function resolveUser(?Request $request): array
    {
        $request ??= request();
        $guards = array_values(array_filter((array) config('alfred.guards', [])));
        if ($guards === []) {
            $guards = [(string) config('auth.defaults.guard', 'web')];
        }

        foreach ($guards as $guard) {
            try {
                $user = $request->user($guard);
            } catch (Throwable) {
                $user = null; // guard not configured in this context
            }
            if ($user instanceof Authenticatable) {
                return [$user, $guard];
            }
        }

        return [null, null];
    }

    /**
     * Which connection Alfred's own tables live on: ALFRED_DB_CONNECTION,
     * else the tenancy package's central connection, else the default.
     */
    public static function connection(): ?string
    {
        $configured = config('alfred.connection');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return app(Tenancy::class)->centralConnection();
    }

    public function canVerify(?Authenticatable $user): bool
    {
        return $this->enabled() && Gate::forUser($user)->allows((string) config('alfred.gates.verify', 'verifyAlfred'));
    }

    /** "Report / request": turn a clicked element + a note into a ticket. */
    public function canReport(?Authenticatable $user): bool
    {
        return $this->enabled()
            && (bool) config('alfred.report.enabled', true)
            && (Gate::forUser($user)->allows((string) config('alfred.gates.report', 'reportAlfred'))
                || ($this->isGuest($user) && (bool) config('alfred.guests.report', true)));
    }

    /** May see and create tickets for changes / requests at all. */
    public function canTicket(?Authenticatable $user): bool
    {
        return $this->canEdit($user) || $this->canReport($user);
    }

    /** Editors see every change; people who can only report see their own requests. */
    public function canSeeChange(Request $request, \Atoria\Alfred\Changes\Change $change): bool
    {
        $user = $this->user($request);

        return $this->canUse($user) && (
            $this->canEdit($user)
            || ($this->canReport($user) && $change->user_key === $this->userKey($request))
        );
    }

    /**
     * May this person's new explanations be stored for everyone (in their
     * tenant)? Otherwise what they generate stays private to them. Guests
     * never contribute.
     */
    public function canContribute(?Authenticatable $user): bool
    {
        return $this->enabled() && $user !== null
            && Gate::forUser($user)->allows((string) config('alfred.gates.contribute', 'contributeAlfredExplanations'));
    }

    /** The "For developers" tab: code-level detail behind an explanation. */
    public function canViewCode(?Authenticatable $user): bool
    {
        return $this->enabled() && Gate::forUser($user)->allows((string) config('alfred.gates.code', 'viewAlfredCode'));
    }

    public function canViewRecords(?Authenticatable $user): bool
    {
        return $this->enabled()
            && (bool) config('alfred.drilldown.enabled', true)
            && Gate::forUser($user)->allows((string) config('alfred.gates.records', 'viewAlfredRecords'));
    }

    /** "Change this" is on, allowed in this environment, and the user passes the editAlfred gate. */
    public function canEdit(?Authenticatable $user): bool
    {
        return $this->editingAvailable()
            && Gate::forUser($user)->allows((string) config('alfred.gates.edit', 'editAlfred'));
    }

    public function editingAvailable(): bool
    {
        if (! $this->enabled() || ! config('alfred.edit.enabled')) {
            return false;
        }

        $environments = (array) config('alfred.edit.environments', ['local']);

        return in_array('*', $environments, true) || app()->environment($environments);
    }

    /** "write" (apply to files on this server) or "propose" (diff / patch only). */
    public function editMode(): string
    {
        $mode = config('alfred.edit.mode');
        if (! in_array($mode, ['write', 'propose'], true)) {
            $mode = app()->environment('local') ? 'write' : 'propose';
        }

        // All tenants run the same code: someone working in one tenant must not
        // rewrite it for every other tenant. Their changes are proposals (preview,
        // patch, ticket, pull request) unless you allow it, e.g. on your own machine.
        if ($mode === 'write' && ! config('alfred.edit.write_in_tenants', false) && app(Tenancy::class)->current() !== null) {
            return 'propose';
        }

        return $mode;
    }

    /** What the overlay needs to show the "Previewing…" bar. */
    protected function previewState(): ?array
    {
        $preview = app(\Atoria\Alfred\Preview\Preview::class);
        $change = $preview->active();

        if (! $change) {
            return null;
        }

        return [
            'id' => $change->id,
            'summary' => $change->summary,
            'skipped' => $preview->skipped(),
            'cookie' => \Atoria\Alfred\Preview\Preview::COOKIE,
        ];
    }

    /**
     * Who owns a page context or a request: the user, or the browser session
     * for guests. Scoped by guard and tenant, so user #5 of one panel or
     * tenant is never mistaken for user #5 of another. A user of the default
     * guard outside any tenant keeps the plain "u:<id>" form.
     */
    public function userKey(Request $request): string
    {
        [$user, $guard] = $this->resolveUser($request);
        $tenancy = app(Tenancy::class);
        $tenant = $tenancy->current();
        // In a multi-tenant app every key names its tenant ("-" = central), so
        // tenant user #5 and central user #5 can never be confused.
        $multiTenant = $tenancy->adapter() !== null;

        if ($user) {
            $id = (string) $user->getAuthIdentifier();
            if (! $multiTenant && $guard === (string) config('auth.defaults.guard', 'web')) {
                return 'u:'.$id;
            }

            return 'u:'.$guard.':'.($tenant ?? '-').':'.$id;
        }

        $scope = $multiTenant ? 't:'.($tenant ?? '-').'|' : '';

        return $request->hasSession()
            ? 's:'.sha1($scope.$request->session()->getId())
            : 'ip:'.sha1($scope.(string) $request->ip());
    }

    public function isOwnRequest(Request $request): bool
    {
        $prefix = trim((string) config('alfred.path', '_alfred'), '/');

        return $request->is($prefix, $prefix.'/*');
    }

    /** The overlay <script> tag for the current page, or nothing. */
    public function scriptTag(Request $request, ?string $token = null): HtmlString
    {
        $user = $this->user($request);
        $token ??= app(RequestRecorder::class)->context()?->token;

        if (! $token || ! $this->canUse($user)) {
            return new HtmlString('');
        }

        $prefix = '/'.trim((string) config('alfred.path', '_alfred'), '/');
        $script = dirname(__DIR__).'/resources/js/alfred.js';
        $version = self::fileVersion($script);

        $config = [
            'token' => $token,
            'endpoint' => url($prefix),
            'tenant' => $tenant = app(Tenancy::class)->clientToken($request),
            'guest' => $user === null,
            'csrf' => $request->hasSession() ? csrf_token() : null,
            'canVerify' => $this->canVerify($user),
            'canViewRecords' => $this->canViewRecords($user),
            'canEdit' => $this->canEdit($user),
            'canReport' => $this->canReport($user),
            'canViewCode' => $this->canViewCode($user),
            'followUps' => (bool) config('alfred.followups.enabled', true),
            'pullRequests' => $this->canEdit($user) && app(\Atoria\Alfred\PullRequests\Providers\GitProviderManager::class)->available() !== [],
            'editMode' => $this->editMode(),
            'screenshots' => (bool) config('alfred.screenshots.enabled', true),
            'preview' => $this->previewState(),
            'position' => config('alfred.position', 'bottom-right'),
            'name' => self::name(),
            'icon' => $this->icon(),
            'color' => $this->color(),
        ];

        return new HtmlString(sprintf(
            '<script src="%s" data-alfred="%s" defer></script>',
            e(url($prefix.'/alfred.js').'?v='.$version.($tenant ? '&'.Tenancy::PARAM.'='.rawurlencode($tenant) : '')),
            e((string) json_encode($config, JSON_UNESCAPED_SLASHES)),
        ));
    }

    /** The display name people see (ALFRED_NAME), "Alfred" by default. */
    /** A short content hash of one of Alfred's files, worked out once per process. */
    public static function fileVersion(string $path): string
    {
        static $versions = [];

        return $versions[$path] ??= is_file($path) ? substr(sha1_file($path) ?: '', 0, 10) : '1';
    }

    public static function name(): string
    {
        $name = trim((string) config('alfred.branding.name', 'Alfred'));

        return $name !== '' ? mb_substr($name, 0, 40) : 'Alfred';
    }

    /**
     * The button: ['type' => 'image', 'src' => …] or ['type' => 'text', 'text' => …].
     *
     * @return array{type: string, src?: string, text?: string}
     */
    public function icon(): array
    {
        $icon = trim((string) config('alfred.branding.icon', '?'));

        if ($icon === '') {
            return ['type' => 'text', 'text' => '?'];
        }
        if (preg_match('#^(https?://|//|data:image/)#i', $icon)) {
            return ['type' => 'image', 'src' => $icon];
        }
        if (str_starts_with($icon, '/') || preg_match('/\.(png|jpe?g|gif|svg|webp|avif|ico)(\?.*)?$/i', $icon)) {
            return ['type' => 'image', 'src' => asset(ltrim($icon, '/'))];
        }

        return ['type' => 'text', 'text' => mb_substr($icon, 0, 3)];
    }

    /** The accent colour, if it is a plain CSS colour value (no injection into the stylesheet). */
    public function color(): ?string
    {
        $color = trim((string) config('alfred.branding.color', ''));

        return $color !== '' && preg_match('/^(#[0-9a-f]{3,8}|(rgb|rgba|hsl|hsla|oklch)\([0-9.,%\s\/a-z-]+\)|[a-z]{3,20})$/i', $color) ? $color : null;
    }
}
