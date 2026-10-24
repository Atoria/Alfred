<?php

namespace Atoria\Alfred\Context;

use Atoria\Alfred\Security\PathGuard;

/**
 * Turns what the browser reported about the clicked element's Vue / React /
 * Svelte / Inertia source into files the agent can actually open.
 *
 * Paths from the browser are untrusted and often from another machine (a
 * Docker container, a teammate's laptop), so each one is matched by its
 * project-relative tail and kept only if that file exists in readable code.
 */
class FrontendSource
{
    public const EXTENSIONS = ['vue', 'jsx', 'tsx', 'js', 'ts', 'mjs', 'svelte', 'css', 'scss', 'sass', 'less'];

    public function __construct(protected PathGuard $guard) {}

    /**
     * @return array{framework: ?string, page: ?string, page_file: ?string, files: string[], components: string[]}
     */
    public function resolve(ElementDescriptor $element): array
    {
        $f = $element->frontend;

        $files = [];
        foreach ($f['files'] ?? [] as $reported) {
            if (($rel = $this->match($reported)) !== null && ! in_array($rel, $files, true)) {
                $files[] = $rel;
            }
        }

        $pageFile = ! empty($f['page']) ? $this->pageFile($f['page']) : null;

        return [
            'framework' => $f['framework'] ?? null,
            'page' => $f['page'] ?? null,
            'page_file' => $pageFile,
            'files' => $files,
            'components' => array_values($f['components'] ?? []),
        ];
    }

    public function known(ElementDescriptor $element): bool
    {
        $r = $this->resolve($element);

        return $r['files'] !== [] || $r['page_file'] !== null || $r['components'] !== [] || $r['page'] !== null;
    }

    /** A browser-reported path → readable project-relative path, or null. */
    protected function match(string $reported): ?string
    {
        $path = ltrim(str_replace('\\', '/', $reported), '/');
        $path = preg_replace('#^(file|webpack|vite)://+#', '', $path) ?? $path;

        $base = rtrim(str_replace('\\', '/', $this->guard->basePath()), '/');
        $candidates = [];
        if (str_starts_with('/'.$path, $base.'/')) {
            $candidates[] = substr('/'.$path, strlen($base) + 1);
        }

        // Same project on another machine: cut at a readable root (e.g. "resources/js/").
        foreach ($this->guard->roots() as $root) {
            $rel = trim($this->guard->relative($root), '/').'/';
            $at = strpos('/'.$path, '/'.$rel);
            if ($rel !== '/' && $at !== false) {
                $candidates[] = substr('/'.$path, $at + 1);
            }
        }

        foreach ($candidates as $rel) {
            if (str_contains($rel, '..') || ! $this->frontendFile($rel)) {
                continue;
            }
            $abs = $base.'/'.$rel;
            if (is_file($abs) && $this->guard->allows($abs)) {
                return $rel;
            }
        }

        return null;
    }

    /** Inertia's page name ("Welcome", "Users/Index") → its component file. */
    protected function pageFile(string $page): ?string
    {
        $base = rtrim(str_replace('\\', '/', $this->guard->basePath()), '/');
        foreach (['resources/js/Pages', 'resources/js/pages', 'resources/ts/Pages', 'resources/ts/pages'] as $dir) {
            foreach (['vue', 'tsx', 'jsx', 'svelte', 'ts', 'js'] as $ext) {
                $rel = $dir.'/'.$page.'.'.$ext;
                if (is_file($base.'/'.$rel) && $this->guard->allows($base.'/'.$rel)) {
                    return $rel;
                }
            }
        }

        return null;
    }

    public static function frontendFile(string $path): bool
    {
        if (str_ends_with($path, '.blade.php')) {
            return false;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, self::EXTENSIONS, true);
    }
}
