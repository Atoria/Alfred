<?php

namespace Atoria\Alfred\Preview;

use Atoria\Alfred\Changes\Change;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\View\FileViewFinder;
use Throwable;

/**
 * "Preview on this page": render a proposed change for ONE user, without
 * touching the code on disk.
 *
 * The user's browser carries a signed cookie naming the change. Early in each
 * of their requests (before the app's own service providers load classes)
 * Alfred writes the changed files to a private temp folder and:
 *   - PHP classes: registers a prepended autoloader so the changed class is
 *     loaded from the temp copy instead of the original;
 *   - Blade views under resources/views: puts the temp folder first in the
 *     view finder, so the changed view is rendered instead.
 * Everyone else's requests are untouched. Nothing is written to the codebase.
 *
 * Not possible (reported, not faked): CSS/JS that needs a build, files whose
 * class was already loaded before Alfred could step in, and long-running
 * servers (Octane / RoadRunner / Swoole), where a loaded class would leak to
 * other users — preview is disabled there.
 */
class Preview
{
    public const COOKIE = 'alfred_preview';

    protected ?Change $change = null;

    /** @var array<string, string> relative path => why it could not be previewed */
    protected array $skipped = [];

    /** spl_object_id of the request already handled (register() and the middleware see the same one). */
    protected ?int $handled = null;

    protected ?string $tokenUser = null;

    public function enabled(): bool
    {
        return (bool) config('alfred.enabled')
            && (bool) config('alfred.edit.enabled')
            && (bool) config('alfred.edit.preview', true)
            && ! self::longRunning();
    }

    public static function longRunning(): bool
    {
        return isset($_SERVER['LARAVEL_OCTANE']) || isset($_ENV['LARAVEL_OCTANE']) || class_exists('Laravel\\Octane\\Octane', false) && app()->bound('octane');
    }

    /**
     * Signed: change id, the user it was issued to, the session it was issued
     * in, and when it stops working. Signing out (a new session) ends it.
     */
    public function token(Change $change, string $userKey, string $sessionId): string
    {
        $expires = now()->getTimestamp() + 60 * max(1, (int) config('alfred.edit.preview_minutes', 120));
        $payload = $change->id.'.'.rtrim(strtr(base64_encode($userKey), '+/', '-_'), '=').'.'.$expires.'.'.self::sessionHash($sessionId);

        return $payload.'.'.$this->sign($payload);
    }

    /** @return array{0: int, 1: string, 2: string}|null [change id, user key, session hash], or null if invalid or expired */
    public function parse(?string $token): ?array
    {
        if (! is_string($token) || substr_count($token, '.') !== 4) {
            return null;
        }

        [$id, $user, $expires, $session, $sig] = explode('.', $token);
        if (! ctype_digit($id) || ! ctype_digit($expires) || ! hash_equals($this->sign($id.'.'.$user.'.'.$expires.'.'.$session), $sig)) {
            return null;
        }
        if ((int) $expires < now()->getTimestamp()) {
            return null; // previews switch themselves off
        }

        $userKey = base64_decode(strtr($user, '-_', '+/'), true);

        return $userKey === false || $userKey === '' ? null : [(int) $id, $userKey, $session];
    }

    protected static function sessionHash(string $sessionId): string
    {
        return substr(hash_hmac('sha256', $sessionId, 'alfred-preview'), 0, 24);
    }

    /**
     * The session id from the request's session cookie, read before the
     * session middleware runs (previewed classes must be in place before the
     * app loads them). Null if there is none or it can't be read.
     */
    protected function sessionId(Request $request): ?string
    {
        $name = (string) config('session.cookie');
        $raw = $request->cookies->get($name);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $decrypted = app('encrypter')->decrypt($raw, false);
            $id = \Illuminate\Cookie\CookieValuePrefix::validate($name, (string) $decrypted, array_filter([app('encrypter')->getKey(), ...(array) (method_exists(app('encrypter'), 'getPreviousKeys') ? app('encrypter')->getPreviousKeys() : [])]));
        } catch (Throwable) {
            $id = $raw; // the app doesn't encrypt its session cookie
        }

        return is_string($id) && preg_match('/^[A-Za-z0-9]{40}$/', $id) ? $id : null;
    }

    /** The cookie that carries a preview: HttpOnly (page scripts can't read or steal it), Secure on https, Lax. */
    public function cookie(\Illuminate\Http\Request $request, string $token): \Symfony\Component\HttpFoundation\Cookie
    {
        return new \Symfony\Component\HttpFoundation\Cookie(self::COOKIE, $token, now()->addMinutes(max(1, (int) config('alfred.edit.preview_minutes', 120))), '/', null, $request->isSecure(), true, false, \Symfony\Component\HttpFoundation\Cookie::SAMESITE_LAX);
    }

    public function forgetCookie(\Illuminate\Http\Request $request): \Symfony\Component\HttpFoundation\Cookie
    {
        return new \Symfony\Component\HttpFoundation\Cookie(self::COOKIE, '', 1, '/', null, $request->isSecure(), true, false, \Symfony\Component\HttpFoundation\Cookie::SAMESITE_LAX);
    }

    /**
     * Turn the preview on for this request if the cookie asks for it. Safe to
     * call more than once (provider register() in production, middleware as a
     * fallback); only the first call does anything.
     */
    public function activate(Request $request): void
    {
        if ($this->handled === spl_object_id($request)) {
            return;
        }
        $this->handled = spl_object_id($request);
        $this->change = null;
        $this->skipped = [];
        $this->tokenUser = null;

        if (! $this->enabled() || ! ($parsed = $this->parse($request->cookies->get(self::COOKIE)))) {
            return;
        }

        // Only in the session it was started in: a cookie carried to another
        // browser, or left behind after signing out, runs nothing.
        $sessionId = $this->sessionId($request);
        if ($sessionId === null || ! hash_equals($parsed[2], self::sessionHash($sessionId))) {
            return;
        }

        // Alfred's own endpoints never run previewed code, so "Stop preview"
        // and the overlay keep working even when the change breaks pages.
        $prefix = trim((string) config('alfred.path', '_alfred'), '/');
        if ($request->is($prefix, $prefix.'/*')) {
            return;
        }

        [$id, $userKey] = $parsed;

        try {
            // Plain query builder: during register() Eloquent has no connection resolver yet.
            $row = app('db')->connection(\Atoria\Alfred\Alfred::connection())
                ->table(config('alfred.changes_table', 'alfred_changes'))
                ->where('id', $id)
                ->first();
            $change = $row ? (new Change)->newFromBuilder((array) $row) : null;
        } catch (Throwable) {
            return; // e.g. migrations not run yet
        }

        if (! $change || ! $change->hasEdits() || in_array($change->status, [Change::DISCARDED, Change::FAILED], true)) {
            return;
        }
        // The change must belong to the tenant the preview was issued in (user keys name it).
        if (str_starts_with($userKey, 'u:') && (explode(':', $userKey)[2] ?? '-') !== ((string) $change->tenant ?: '-')) {
            return;
        }

        $this->tokenUser = $userKey;
        $this->change = $change;

        try {
            $this->install($change);
        } catch (Throwable $e) {
            report($e);
            $this->change = null;
        }
    }

    public function active(): ?Change
    {
        return $this->change;
    }

    /** The user the preview cookie was issued to (checked against the real user once auth has run). */
    public function tokenUser(): ?string
    {
        return $this->tokenUser;
    }

    /** @return array<string, string> */
    public function skipped(): array
    {
        return $this->skipped;
    }

    protected function install(Change $change): void
    {
        $base = rtrim((string) (config('alfred.base_path') ?: base_path()), '/');
        $root = self::root();
        $this->prepareRoot($root);
        $dir = $root.'/'.$change->id.'-'.substr(sha1(json_encode($change->edits)), 0, 12);

        // Recompute the changed files from the current code + the edits.
        $files = [];
        foreach ((array) $change->files as $path => $hashes) {
            $abs = $base.'/'.$path;
            $current = is_file($abs) ? (string) file_get_contents($abs) : null;

            if ($change->status === Change::APPLIED) {
                $this->skipped[$path] = 'already applied — the page shows it for everyone';

                continue;
            }
            if ($current === null || sha1($current) !== ($hashes['before_sha1'] ?? null)) {
                $this->skipped[$path] = 'the file has changed since the proposal';

                continue;
            }
            $files[$path] = $current;
        }

        // Exactly what "Apply" would write: same edits, same rules, same result.
        foreach (\Atoria\Alfred\Changes\EditApplier::byFile((array) $change->edits) as $path => $edits) {
            if (! isset($files[$path])) {
                continue;
            }
            try {
                $after = \Atoria\Alfred\Changes\EditApplier::apply($files[$path], $edits);
            } catch (\Atoria\Alfred\Changes\EditMismatch) {
                $after = null;
            }
            if ($after === null || (isset($change->files[$path]['after_sha1']) && sha1($after) !== $change->files[$path]['after_sha1'])) {
                $this->skipped[$path] = 'the edits no longer give the proposed result';
                unset($files[$path]);

                continue;
            }
            $files[$path] = $after;
        }

        if (is_dir($dir)) {
            @touch($dir); // in use: keep it out of the clean-up
        }

        foreach ($files as $path => $contents) {
            $target = $dir.'/'.$path;
            if (! is_file($target) || sha1_file($target) !== sha1($contents)) {
                File::ensureDirectoryExists(dirname($target));
                file_put_contents($target, $contents, LOCK_EX);
            }

            if (str_ends_with($path, '.blade.php')) {
                if (! str_starts_with($path, 'resources/views/')) {
                    $this->skipped[$path] = 'only views under resources/views can be previewed';

                    continue;
                }
                $views = $dir.'/resources/views';
                // 'view.finder' is a plain binding (new object per resolve): change the factory's own finder.
                $prepend = function ($factory) use ($views) {
                    $finder = $factory->getFinder();
                    if ($finder instanceof FileViewFinder && ! in_array($views, $finder->getPaths(), true)) {
                        $finder->prependLocation($views);
                        $finder->flush();
                    }
                };
                app()->resolved('view') ? $prepend(app('view')) : app()->afterResolving('view', $prepend);
            } elseif (str_ends_with($path, '.php')) {
                $class = ClassName::fromCode($contents);
                if (! $class) {
                    $this->skipped[$path] = 'not a class file';

                    continue;
                }
                if (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false)) {
                    $this->skipped[$path] = 'the class was already loaded before the preview could swap it';

                    continue;
                }
                spl_autoload_register(function (string $requested) use ($class, $target) {
                    if ($requested === $class) {
                        require $target;
                    }
                }, true, true);
            } else {
                $this->skipped[$path] = 'needs a front-end build to preview';
            }
        }
    }

    /** Where previewed copies of changed files are kept. */
    public static function root(): string
    {
        return storage_path('framework/alfred-preview');
    }

    /** Keep the folder out of git, and clear out old copies now and then. */
    protected function prepareRoot(string $root): void
    {
        File::ensureDirectoryExists($root);

        if (! is_file($root.'/.gitignore')) {
            @file_put_contents($root.'/.gitignore', "*\n!.gitignore\n", LOCK_EX);
        }

        $marker = $root.'/.pruned';
        if (! is_file($marker) || filemtime($marker) < time() - 3600) {
            @touch($marker);
            self::prune();
        }
    }

    /**
     * Remove copies nobody can preview any more: the change was applied,
     * discarded or deleted, or nobody has previewed it for a while.
     *
     * @return int how many folders were removed
     */
    public static function prune(?int $maxAgeDays = null): int
    {
        $root = self::root();
        if (! is_dir($root)) {
            return 0;
        }

        $maxAge = max(0, $maxAgeDays ?? (int) config('alfred.edit.preview_keep_days', 7)) * 86400;
        $removed = 0;

        foreach (File::directories($root) as $dir) {
            if (! preg_match('/^(\d+)-[0-9a-f]{12}$/', basename($dir), $m)) {
                continue; // not ours
            }

            $status = null;
            try {
                $status = Change::query()->whereKey((int) $m[1])->value('status');
            } catch (Throwable) {
                continue; // no database right now: try again later
            }

            $previewable = in_array($status, [Change::PROPOSED, Change::REVERTED], true);
            $old = $maxAge === 0 || filemtime($dir) < time() - $maxAge;

            if (! $previewable || $old) {
                File::deleteDirectory($dir);
                $removed++;
            }
        }

        return $removed;
    }

    protected function sign(string $payload): string
    {
        return substr(hash_hmac('sha256', 'alfred-preview|'.$payload, (string) config('app.key')), 0, 40);
    }
}
