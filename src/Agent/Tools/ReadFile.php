<?php

namespace Atoria\Alfred\Agent\Tools;

use Atoria\Alfred\Agent\AgentState;

class ReadFile implements Tool
{
    public function name(): string
    {
        return 'read_file';
    }

    public function description(): string
    {
        return 'Read a source file from the application, with line numbers. Use start_line/end_line for long files (max ~400 lines per call).';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Path relative to the project root, e.g. app/Filament/Widgets/StatsOverview.php'],
                'start_line' => ['type' => ['integer', 'null'], 'description' => 'First line (1-based), or null for the start'],
                'end_line' => ['type' => ['integer', 'null'], 'description' => 'Last line, or null'],
            ],
            'required' => ['path', 'start_line', 'end_line'],
            'additionalProperties' => false,
        ];
    }

    public function strict(): bool
    {
        return true;
    }

    public function handle(array $arguments, AgentState $state): mixed
    {
        $abs = $state->guard->file((string) ($arguments['path'] ?? ''));
        $state->remember($abs);

        $lines = file($abs, FILE_IGNORE_NEW_LINES) ?: [];
        $total = count($lines);
        $start = max(1, (int) ($arguments['start_line'] ?? 1));
        $end = min($total, (int) ($arguments['end_line'] ?? $start + 399));
        $end = min($end, $start + 399);

        $max = (int) config('alfred.agent.max_file_bytes', 60000);
        $out = [];
        $bytes = 0;

        for ($i = $start; $i <= $end; $i++) {
            $line = sprintf('%5d  %s', $i, $lines[$i - 1] ?? '');
            $bytes += strlen($line) + 1;
            if ($bytes > $max) {
                $out[] = "… truncated at line {$i}; call again with start_line={$i}";
                break;
            }
            $out[] = $line;
        }

        $header = $state->guard->relative($abs)." (lines {$start}-".min($end, $i - 1)." of {$total})";

        return $header."\n".implode("\n", $out);
    }
}
