<?php

namespace Atoria\Alfred\Agent\Tools;

use Atoria\Alfred\Agent\AgentState;

class ListFiles implements Tool
{
    public function name(): string
    {
        return 'list_files';
    }

    public function description(): string
    {
        return 'List the files and folders directly inside a directory of the application. Use "" for the project root.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Directory relative to the project root, e.g. app/Models'],
            ],
            'required' => ['path'],
            'additionalProperties' => false,
        ];
    }

    public function strict(): bool
    {
        return true;
    }

    public function handle(array $arguments, AgentState $state): mixed
    {
        $guard = $state->guard;
        $dir = $guard->directory((string) ($arguments['path'] ?? ''));

        if ($dir === $guard->basePath()) {
            return ['readable_directories' => array_map(fn ($r) => $guard->relative($r).'/', $guard->roots())];
        }

        $entries = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $abs = $dir.DIRECTORY_SEPARATOR.$name;
            if (! $guard->allows($abs)) {
                continue;
            }
            if (is_dir($abs)) {
                $entries[] = $name.'/';
            } elseif ($guard->extensionAllowed($abs)) {
                $entries[] = $name;
            }
            if (count($entries) >= 500) {
                $entries[] = '… (truncated)';
                break;
            }
        }

        return ['directory' => $guard->relative($dir).'/', 'entries' => $entries];
    }
}
