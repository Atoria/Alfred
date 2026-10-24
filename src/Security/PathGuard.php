<?php

namespace Atoria\Alfred\Security;

use Illuminate\Support\Str;

/**
 * The only door between the agent and the filesystem.
 *
 * Every path the agent names is resolved here: it must sit under one of the
 * configured roots, must not match a deny pattern, must have an allowed
 * extension, and must still be inside the base path after symlinks resolve.
 */
class PathGuard
{
    /** @var string[]|null */
    protected ?array $resolvedRoots = null;

    /** @param  string[]  $roots  @param  string[]  $deny  @param  string[]  $extensions */
    public function __construct(
        protected string $basePath,
        protected array $roots,
        protected array $deny,
        protected array $extensions,
    ) {
        $this->basePath = rtrim(realpath($basePath) ?: $basePath, DIRECTORY_SEPARATOR);
    }

    public static function fromConfig(): self
    {
        return new self(
            config('alfred.base_path') ?: base_path(),
            // Anything editable must also be readable: edits are only accepted on files the agent read.
            array_values(array_unique(array_merge((array) config('alfred.paths', []), (array) config('alfred.edit.paths', [])))),
            (array) config('alfred.deny', []),
            (array) config('alfred.extensions', []),
        );
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    /** Absolute path of an allowed root directory that exists. @return string[] */
    public function roots(): array
    {
        if ($this->resolvedRoots !== null) {
            return $this->resolvedRoots;
        }

        $roots = [];
        foreach ($this->roots as $root) {
            $abs = realpath($this->basePath.DIRECTORY_SEPARATOR.trim($root, '/\\'));
            if ($abs !== false && is_dir($abs) && $this->insideBase($abs)) {
                $roots[] = $abs;
            }
        }

        return $this->resolvedRoots = $roots;
    }

    /** Resolve a file the agent wants to read. Returns the absolute path or throws. */
    public function file(string $path): string
    {
        $abs = $this->resolve($path);

        if (! is_file($abs)) {
            throw new AccessDenied("Not a file: {$path}");
        }

        if (! $this->extensionAllowed($abs)) {
            throw new AccessDenied("File type not readable: {$path}");
        }

        return $abs;
    }

    /** Resolve a directory the agent wants to list or search. */
    public function directory(?string $path): string
    {
        if ($path === null || $path === '' || $path === '.' || $path === '/') {
            return $this->basePath;
        }

        $abs = $this->resolve($path);

        if (! is_dir($abs)) {
            throw new AccessDenied("Not a directory: {$path}");
        }

        return $abs;
    }

    /** Whether an absolute path may be shown to / read by the agent. */
    public function allows(string $absolute): bool
    {
        $real = realpath($absolute);
        if ($real === false || ! $this->insideBase($real)) {
            return false;
        }

        $relative = $this->relative($real);

        return $this->underRoot($real) && ! $this->denied($relative);
    }

    public function extensionAllowed(string $path): bool
    {
        $name = strtolower(basename($path));
        foreach ($this->extensions as $ext) {
            if (str_ends_with($name, '.'.strtolower(ltrim($ext, '.')))) {
                return true;
            }
        }

        return false;
    }

    public function relative(string $absolute): string
    {
        $absolute = str_replace('\\', '/', $absolute);
        $base = str_replace('\\', '/', $this->basePath);

        return ltrim(Str::after($absolute, $base), '/');
    }

    protected function resolve(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));

        if ($path === '' || str_contains($path, "\0")) {
            throw new AccessDenied('Empty or invalid path.');
        }

        $candidate = str_starts_with($path, '/') && str_starts_with($path, str_replace('\\', '/', $this->basePath))
            ? $path
            : $this->basePath.'/'.ltrim($path, '/');

        $real = realpath($candidate);

        if ($real === false) {
            throw new AccessDenied("Path does not exist: {$path}");
        }

        if (! $this->insideBase($real)) {
            throw new AccessDenied("Outside the project: {$path}");
        }

        if (! $this->underRoot($real) && $real !== $this->basePath) {
            throw new AccessDenied("Not in a readable directory: {$path}. Readable: ".implode(', ', $this->roots));
        }

        if ($this->denied($this->relative($real))) {
            throw new AccessDenied("Access to {$path} is not allowed.");
        }

        return $real;
    }

    protected function insideBase(string $real): bool
    {
        return $real === $this->basePath || str_starts_with($real, $this->basePath.DIRECTORY_SEPARATOR);
    }

    protected function underRoot(string $real): bool
    {
        foreach ($this->roots() as $root) {
            if ($real === $root || str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    protected function denied(string $relative): bool
    {
        // Case-insensitive: "StripeSecret.php" is caught by "*secret*".
        $relative = strtolower(str_replace('\\', '/', $relative));
        $segments = explode('/', $relative);
        $name = end($segments);

        foreach ($this->deny as $pattern) {
            $pattern = strtolower(str_replace('\\', '/', $pattern));

            if (Str::is($pattern, $relative) || Str::is($pattern, $name)) {
                return true;
            }

            // "storage/*" must also deny "storage" itself and anything nested under any segment.
            foreach ($segments as $segment) {
                if (Str::is(rtrim($pattern, '/*'), $segment) && str_ends_with($pattern, '/*')) {
                    return true;
                }
            }
        }

        return false;
    }
}
