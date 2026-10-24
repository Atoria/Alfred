<?php

namespace Atoria\Alfred\Context;

use Illuminate\Support\Str;
use Throwable;

/** Turns a Livewire component name (from the DOM) into its PHP class. */
class ComponentResolver
{
    public function resolve(string $name, PageContext $page): ?string
    {
        if (isset($page->components[$name]) && class_exists($page->components[$name])) {
            return $page->components[$name];
        }

        // Livewire's own resolver (v3: ComponentRegistry, v4: Factory).
        try {
            foreach (['Livewire\\Factory\\Factory', 'Livewire\\Mechanisms\\ComponentRegistry'] as $service) {
                if (class_exists($service) && app()->bound($service)) {
                    $resolver = app($service);
                    foreach (['resolveComponentClass', 'getClass'] as $method) {
                        if (method_exists($resolver, $method)) {
                            $class = $resolver->{$method}($name);
                            if (is_string($class) && class_exists($class)) {
                                return $class;
                            }
                        }
                    }
                }
            }
        } catch (Throwable) {
            // fall through to the naming convention
        }

        // Convention: "app.filament.widgets.stats-overview" => App\Filament\Widgets\StatsOverview
        $guess = collect(explode('.', $name))->map(fn ($s) => Str::studly($s))->implode('\\');
        foreach ([$guess, 'App\\Livewire\\'.$guess, 'App\\Http\\Livewire\\'.$guess] as $candidate) {
            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function file(string $class): ?string
    {
        try {
            return (new \ReflectionClass($class))->getFileName() ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}
