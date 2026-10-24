<?php

namespace Atoria\Alfred\Changes;

/**
 * Minimal unified-diff writer (git-apply compatible) for one file.
 *
 * Edits are small and local, so: strip the common prefix/suffix lines, run
 * an LCS on what's left (capped — past the cap the middle is emitted as one
 * replace block, still a valid diff), then group into hunks with context.
 */
class DiffBuilder
{
    public function __construct(protected int $context = 3, protected int $lcsCap = 2000) {}

    public function file(string $path, string $before, string $after): string
    {
        if ($before === $after) {
            return '';
        }

        [$a, $aEol] = $this->lines($before);
        [$b, $bEol] = $this->lines($after);

        $ops = $this->ops($a, $b);
        $hunks = $this->hunks($ops, $a, $b, $aEol, $bEol);
        if ($hunks === '') {
            return '';
        }

        return "diff --git a/{$path} b/{$path}\n--- a/{$path}\n+++ b/{$path}\n".$hunks;
    }

    /** @param  array<string, array{0: string, 1: string}>  $files  path => [before, after] */
    public function files(array $files): string
    {
        $out = '';
        foreach ($files as $path => [$before, $after]) {
            $out .= $this->file($path, $before, $after);
        }

        return $out;
    }

    protected const NO_EOL = "\0alfred-no-eol";

    /**
     * Lines without terminators. A last line with no trailing newline gets a
     * marker so it compares unequal to the same text with a newline.
     *
     * @return array{0: string[], 1: bool}
     */
    protected function lines(string $text): array
    {
        if ($text === '') {
            return [[], true];
        }

        $endsWithNewline = str_ends_with($text, "\n");
        $lines = explode("\n", $endsWithNewline ? substr($text, 0, -1) : $text);
        if (! $endsWithNewline) {
            $lines[count($lines) - 1] .= self::NO_EOL;
        }

        return [$lines, $endsWithNewline];
    }

    /**
     * Edit script: list of [op, aIndex, bIndex] with op in ' ', '-', '+'.
     *
     * @return array<int, array{0: string, 1: ?int, 2: ?int}>
     */
    protected function ops(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        $start = 0;
        while ($start < $n && $start < $m && $a[$start] === $b[$start]) {
            $start++;
        }
        $endA = $n;
        $endB = $m;
        while ($endA > $start && $endB > $start && $a[$endA - 1] === $b[$endB - 1]) {
            $endA--;
            $endB--;
        }

        $ops = [];
        for ($i = 0; $i < $start; $i++) {
            $ops[] = [' ', $i, $i];
        }

        $midA = array_slice($a, $start, $endA - $start);
        $midB = array_slice($b, $start, $endB - $start);

        if (count($midA) * count($midB) > $this->lcsCap * $this->lcsCap) {
            foreach ($midA as $i => $_) {
                $ops[] = ['-', $start + $i, null];
            }
            foreach ($midB as $j => $_) {
                $ops[] = ['+', null, $start + $j];
            }
        } else {
            foreach ($this->lcs($midA, $midB) as [$op, $i, $j]) {
                $ops[] = [$op, $i === null ? null : $start + $i, $j === null ? null : $start + $j];
            }
        }

        for ($k = 0; $endA + $k < $n; $k++) {
            $ops[] = [' ', $endA + $k, $endB + $k];
        }

        return $ops;
    }

    /** @return array<int, array{0: string, 1: ?int, 2: ?int}> */
    protected function lcs(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        if ($n === 0 || $m === 0) {
            $ops = [];
            for ($i = 0; $i < $n; $i++) {
                $ops[] = ['-', $i, null];
            }
            for ($j = 0; $j < $m; $j++) {
                $ops[] = ['+', null, $j];
            }

            return $ops;
        }

        // Lengths table, row by row (ints in nested arrays — fine for small middles).
        $len = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $len[$i][$j] = $a[$i] === $b[$j] ? $len[$i + 1][$j + 1] + 1 : max($len[$i + 1][$j], $len[$i][$j + 1]);
            }
        }

        $ops = [];
        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $ops[] = [' ', $i++, $j++];
            } elseif ($len[$i + 1][$j] >= $len[$i][$j + 1]) {
                $ops[] = ['-', $i++, null];
            } else {
                $ops[] = ['+', null, $j++];
            }
        }
        while ($i < $n) {
            $ops[] = ['-', $i++, null];
        }
        while ($j < $m) {
            $ops[] = ['+', null, $j++];
        }

        return $ops;
    }

    protected function hunks(array $ops, array $a, array $b, bool $aEol, bool $bEol): string
    {
        $changed = [];
        foreach ($ops as $k => $op) {
            if ($op[0] !== ' ') {
                $changed[] = $k;
            }
        }
        if ($changed === []) {
            return '';
        }

        // Group change indices whose context windows touch.
        $groups = [];
        $current = [$changed[0], $changed[0]];
        foreach (array_slice($changed, 1) as $k) {
            if ($k - $current[1] <= 2 * $this->context + 1) {
                $current[1] = $k;
            } else {
                $groups[] = $current;
                $current = [$k, $k];
            }
        }
        $groups[] = $current;

        $out = '';

        foreach ($groups as [$from, $to]) {
            $from = max(0, $from - $this->context);
            $to = min(count($ops) - 1, $to + $this->context);

            $body = '';
            $aStart = $bStart = null;
            $aCount = $bCount = 0;
            $aBefore = $bBefore = 0; // lines of a/b before the hunk (for empty-side start numbers)

            for ($k = 0; $k < $from; $k++) {
                if ($ops[$k][1] !== null) {
                    $aBefore++;
                }
                if ($ops[$k][2] !== null) {
                    $bBefore++;
                }
            }

            for ($k = $from; $k <= $to; $k++) {
                [$op, $i, $j] = $ops[$k];
                if ($op === ' ' || $op === '-') {
                    $aStart ??= $i + 1;
                    $aCount++;
                }
                if ($op === ' ' || $op === '+') {
                    $bStart ??= $j + 1;
                    $bCount++;
                }

                $text = $op === '+' ? $b[$j] : $a[$i];
                if (str_ends_with($text, self::NO_EOL)) {
                    $body .= $op.substr($text, 0, -strlen(self::NO_EOL))."\n\\ No newline at end of file\n";
                } else {
                    $body .= $op.$text."\n";
                }
            }

            $aStart ??= $aBefore; // empty old side: number of the line before
            $bStart ??= $bBefore;

            $out .= sprintf("@@ -%s +%s @@\n", $this->range($aStart, $aCount), $this->range($bStart, $bCount)).$body;
        }

        return $out;
    }

    protected function range(int $start, int $count): string
    {
        return $count === 1 ? (string) $start : "{$start},{$count}";
    }
}
