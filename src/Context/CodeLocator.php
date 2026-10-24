<?php

namespace Atoria\Alfred\Context;

/**
 * Where an element lives in the code, from what Alfred recorded about the
 * page — no AI involved, so it's instant and free.
 */
class CodeLocator
{
    public function __construct(protected ComponentResolver $components) {}

    /**
     * @return array{route: ?string, action: ?string, controller_file: ?string, components: array<int, array{name: string, class: ?string, file: ?string}>, views: string[], code: string[]}
     */
    public function locate(PageContext $page, ElementDescriptor $element): array
    {
        $base = rtrim(str_replace('\\', '/', (string) (config('alfred.base_path') ?: base_path())), '/');
        $relative = function (?string $file) use ($base): ?string {
            if (! $file) {
                return null;
            }
            $file = str_replace('\\', '/', $file);
            if (! str_starts_with($file, $base.'/') || str_contains($file, '/vendor/')) {
                return null;
            }

            return substr($file, strlen($base) + 1);
        };

        // The controller (or Livewire page) that handled the request.
        $controllerFile = null;
        if ($page->action && $page->action !== 'Closure') {
            $class = explode('@', $page->action)[0];
            if (class_exists($class)) {
                $controllerFile = $relative($this->components->file($class));
            }
        }

        // Components around the element, innermost first.
        $components = [];
        foreach ($element->components as $name) {
            $class = $this->components->resolve($name, $page);
            $components[] = [
                'name' => $name,
                'class' => $class,
                'file' => $class ? $relative($this->components->file($class)) : null,
            ];
        }

        // Only the views that matter for this element: those named like one of
        // its components, else the page's own (first rendered) view. A ticket
        // with every partial and layout the page touched helps nobody.
        $appViews = [];
        foreach ($page->views as $name => $path) {
            if ($path && ! str_contains($path, 'vendor/') && ! str_contains($path, 'storage/framework')) {
                $appViews[(string) $name] = $path;
            }
        }
        $needles = [];
        foreach ($element->components as $name) {
            $short = \Illuminate\Support\Str::kebab(class_basename(str_replace(['.', '/'], '\\', (string) $name)));
            if (strlen($short) >= 3) {
                $needles[] = $short;
            }
        }
        $views = [];
        foreach ($appViews as $name => $path) {
            foreach ($needles as $needle) {
                if (str_contains(strtolower($name.' '.$path), $needle)) {
                    $views[] = $path;
                    break;
                }
            }
            if (count($views) >= 3) {
                break;
            }
        }
        if ($views === [] && $appViews !== []) {
            $views[] = reset($appViews);
        }

        // Lines of app code that loaded the element's own data: queries made by
        // its component (or, without one, the first few on the page), deduped.
        $inner = $element->innermostComponent();
        $queries = $page->queryList();
        $own = $inner ? array_filter($queries, fn ($q) => ($q['component'] ?? null) === $inner) : [];
        $code = [];
        foreach ($own ?: $queries as $q) {
            if (! empty($q['source']) && ! in_array($q['source'], $code, true)) {
                $code[] = $q['source'].(! empty($q['caller']) ? ' ('.$q['caller'].')' : '');
            }
            if (count($code) >= 3) {
                break;
            }
        }

        $front = (new FrontendSource(\Atoria\Alfred\Security\PathGuard::fromConfig()))->resolve($element);

        return [
            'frontend' => [
                'page' => $front['page'],
                'page_file' => $front['page_file'],
                'files' => $front['files'],
            ],
            'route' => $page->routeName ?: ($page->routeUri ? '/'.ltrim($page->routeUri, '/') : null),
            'action' => $page->action,
            'controller_file' => $controllerFile,
            'components' => $components,
            'views' => array_values(array_unique($views)),
            'code' => $code,
        ];
    }

    /** Human-readable lines for tickets. @return string[] */
    public static function lines(?array $location): array
    {
        if (! $location) {
            return [];
        }

        $lines = [];
        $flow = (array) ($location['explanation']['flow'] ?? []);
        if (! empty($location['explanation']['title'])) {
            $lines[] = 'Alfred explained it as: '.$location['explanation']['title'].' — '.($location['explanation']['summary'] ?? '');
            foreach ((array) ($location['explanation']['followups'] ?? []) as $t) {
                $lines[] = 'Asked: '.$t['question'].' — Answer: '.$t['answer'];
            }
        }
        foreach ((array) ($location['frontend']['files'] ?? []) as $file) {
            $lines[] = 'Front-end component: '.$file;
        }
        if (! empty($location['frontend']['page'])) {
            $lines[] = 'Inertia page: '.$location['frontend']['page'].(! empty($location['frontend']['page_file']) ? ' — '.$location['frontend']['page_file'] : '');
        }
        foreach ((array) ($location['components'] ?? []) as $c) {
            $lines[] = 'Component: '.($c['class'] ?? $c['name']).(! empty($c['file']) ? ' — '.$c['file'] : '');
        }
        if (! empty($location['route']) || ! empty($location['action'])) {
            $lines[] = 'Route: '.trim(($location['route'] ?? '').' → '.($location['action'] ?? ''), ' →').(! empty($location['controller_file']) ? ' — '.$location['controller_file'] : '');
        }
        foreach ((array) ($location['views'] ?? []) as $v) {
            $lines[] = 'View: '.$v;
        }
        if ($flow !== []) {
            // The explanation already traced the exact path to this value: that beats raw query sources.
            foreach (array_slice($flow, 0, 6) as $i => $step) {
                $lines[] = 'Code path '.($i + 1).': '.$step;
            }
        } else {
            foreach (array_slice((array) ($location['code'] ?? []), 0, 3) as $c) {
                $lines[] = 'Loads data at: '.$c;
            }
        }

        return $lines;
    }
}
