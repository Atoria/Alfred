<?php

namespace Atoria\Alfred\Agent\Tools;

use Atoria\Alfred\Agent\AgentState;
use Illuminate\Support\Facades\Route;

class ListRoutes implements Tool
{
    public function name(): string
    {
        return 'list_routes';
    }

    public function description(): string
    {
        return 'List registered HTTP routes (method, URI, name, action), optionally filtered by a substring of the URI, name or action.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'filter' => ['type' => ['string', 'null'], 'description' => 'Substring to match, or null'],
            ],
            'required' => ['filter'],
            'additionalProperties' => false,
        ];
    }

    public function strict(): bool
    {
        return true;
    }

    public function handle(array $arguments, AgentState $state): mixed
    {
        $filter = mb_strtolower((string) ($arguments['filter'] ?? ''));
        $rows = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $line = implode(' ', [
                implode('|', array_diff($route->methods(), ['HEAD'])),
                '/'.ltrim($route->uri(), '/'),
                $route->getName() ? 'name='.$route->getName() : '',
                $route->getActionName(),
            ]);

            if (str_starts_with($route->uri(), trim((string) config('alfred.path', '_alfred'), '/'))) {
                continue;
            }
            if ($filter === '' || str_contains(mb_strtolower($line), $filter)) {
                $rows[] = $line;
            }
            if (count($rows) >= 80) {
                $rows[] = '… truncated; use a filter';
                break;
            }
        }

        return $rows === [] ? 'No routes match.' : implode("\n", $rows);
    }
}
