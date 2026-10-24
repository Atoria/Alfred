<?php

namespace Atoria\Alfred\Agent\Tools;

use Atoria\Alfred\Agent\AgentState;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class SearchCode implements Tool
{
    public function name(): string
    {
        return 'search_code';
    }

    public function description(): string
    {
        return 'Search the application source for text (case-insensitive). Good first moves: the label the user clicked ("Conversion rate"), a translation key, a column name, a class or method name. Set regex=true for a PCRE pattern.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Text or regex to find'],
                'path' => ['type' => ['string', 'null'], 'description' => 'Limit to this directory (relative), or null for all readable code'],
                'regex' => ['type' => 'boolean', 'description' => 'Treat query as a regular expression'],
            ],
            'required' => ['query', 'path', 'regex'],
            'additionalProperties' => false,
        ];
    }

    public function strict(): bool
    {
        return true;
    }

    public function handle(array $arguments, AgentState $state): mixed
    {
        $query = (string) ($arguments['query'] ?? '');
        if (trim($query) === '') {
            return 'Empty query.';
        }

        $regex = (bool) ($arguments['regex'] ?? false);
        $pattern = $regex ? '~'.str_replace('~', '\~', $query).'~iu' : null;
        if ($pattern !== null && @preg_match($pattern, '') === false) {
            return 'Invalid regular expression.';
        }

        $guard = $state->guard;
        $dirs = ($arguments['path'] ?? null) ? [$guard->directory((string) $arguments['path'])] : $guard->roots();
        $max = (int) config('alfred.agent.max_search_results', 40);
        $results = [];
        $scanned = 0;
        $needle = mb_strtolower($query);

        foreach ($dirs as $dir) {
            $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                fn (SplFileInfo $f) => $guard->allows($f->getPathname()),
            ));

            foreach ($iterator as $file) {
                /** @var SplFileInfo $file */
                if (! $file->isFile() || $file->getSize() > 1_000_000 || ! $guard->extensionAllowed($file->getPathname())) {
                    continue;
                }
                if (++$scanned > 20000) {
                    break 2;
                }

                $handle = @fopen($file->getPathname(), 'r');
                if (! $handle) {
                    continue;
                }
                $n = 0;
                while (($line = fgets($handle)) !== false) {
                    $n++;
                    $hit = $pattern ? preg_match($pattern, $line) === 1 : str_contains(mb_strtolower($line), $needle);
                    if ($hit) {
                        $results[] = $guard->relative($file->getPathname()).':'.$n.': '.mb_substr(trim($line), 0, 200);
                        if (count($results) >= $max) {
                            fclose($handle);
                            break 3;
                        }
                    }
                }
                fclose($handle);
            }
        }

        if ($results === []) {
            return "No matches for \"{$query}\".";
        }

        return implode("\n", $results).(count($results) >= $max ? "\n… more results truncated; narrow with path or a more specific query." : '');
    }
}
