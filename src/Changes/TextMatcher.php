<?php

namespace Atoria\Alfred\Changes;

/**
 * Finds where a search/replace edit belongs in a file, forgivingly.
 *
 * Models are good at knowing WHAT to change and bad at reproducing
 * indentation byte-for-byte. So:
 *
 *  1. exact match — used as is;
 *  2. otherwise line-by-line with surrounding whitespace ignored (and CRLF
 *     vs LF ignored) — if that finds exactly one place, the edit is
 *     rewritten to the file's real text, and the replacement is re-indented
 *     by the same amount and given the file's line endings;
 *  3. otherwise the error names the closest lines in the file (with line
 *     numbers), so the next attempt can copy them.
 *
 * The result is always an exact, unique search string, so applying it
 * later is a plain str_replace.
 */
class TextMatcher
{
    /**
     * @return array{search: string, replace: string, fuzzy: bool}
     *
     * @throws MatchProblem
     */
    public function locate(string $content, string $search, string $replace): array
    {
        $exact = substr_count($content, $search);
        if ($exact === 1) {
            return ['search' => $search, 'replace' => $replace, 'fuzzy' => false];
        }

        $eol = str_contains($content, "\r\n") ? "\r\n" : "\n";
        $fileLines = explode("\n", str_replace("\r\n", "\n", $content));
        $needle = $this->trimBlankEdges(explode("\n", str_replace("\r\n", "\n", $search)));

        if ($exact > 1) {
            throw new MatchProblem("the search text occurs {$exact} times (".$this->exactLines($content, $search).'); include a neighbouring line so it is unique.');
        }

        if ($needle === []) {
            throw new MatchProblem('the search text is empty.');
        }

        $matches = $this->lineMatches($fileLines, $needle);

        if (count($matches) === 1) {
            $start = $matches[0];
            $found = array_slice($fileLines, $start, count($needle));
            $actual = implode($eol, $found);

            if (substr_count($content, $actual) !== 1) {
                throw new MatchProblem('the search text matches lines '.($start + 1).'–'.($start + count($needle)).' only loosely and that text is not unique; copy the exact lines.');
            }

            return [
                'search' => $actual,
                'replace' => $this->reindent($replace, $needle, $found, $eol),
                'fuzzy' => true,
            ];
        }

        if (count($matches) > 1) {
            $where = implode(', ', array_map(fn ($i) => 'line '.($i + 1), $matches));
            throw new MatchProblem("the search text matches {$where}; include a neighbouring line so it is unique.");
        }

        // Copied read_file's "   94  " line-number prefixes? Strip them and try once more.
        $prefixed = '/^\s*\d+  /';
        $nonBlank = array_filter($needle, fn ($l) => trim($l) !== '');
        if ($nonBlank !== [] && count(array_filter($nonBlank, fn ($l) => preg_match($prefixed, $l))) === count($nonBlank)) {
            $strip = fn (string $text) => implode("\n", array_map(fn ($l) => preg_replace($prefixed, '', $l, 1), explode("\n", str_replace("\r\n", "\n", $text))));

            return $this->locate($content, $strip($search), $strip($replace));
        }

        throw new MatchProblem('the search text was not found.'.$this->closest($fileLines, $needle));
    }

    /** @return int[] 0-based start lines where every needle line equals the file line, ignoring surrounding whitespace */
    protected function lineMatches(array $fileLines, array $needle): array
    {
        $n = count($needle);
        $trimmedNeedle = array_map('trim', $needle);
        $trimmedFile = array_map('trim', $fileLines);
        $matches = [];

        for ($i = 0; $i + $n <= count($fileLines); $i++) {
            if ($trimmedFile[$i] !== $trimmedNeedle[0]) {
                continue;
            }
            $ok = true;
            for ($k = 1; $k < $n; $k++) {
                if ($trimmedFile[$i + $k] !== $trimmedNeedle[$k]) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $matches[] = $i;
            }
        }

        return $matches;
    }

    /**
     * Swap indentation strings the model got wrong for the file's own, e.g.
     * 8 spaces → 2 tabs. Each search line's indent maps to the file line's
     * indent; replacement lines take the mapping of the longest indent they
     * start with. Null when the mapping is inconsistent or doesn't cover
     * every replacement line (the character-count shift is used instead).
     *
     * @return string[]|null
     */
    protected function mapIndents(array $replaceLines, array $needle, array $found): ?array
    {
        $map = [];
        foreach ($needle as $k => $line) {
            if (trim($line) === '') {
                continue;
            }
            $from = $this->indent($line);
            $to = $this->indent($found[$k]);
            if (isset($map[$from]) && $map[$from] !== $to) {
                return null;
            }
            $map[$from] = $to;
        }
        if ($map === [] || ! array_filter($map, fn ($to, $from) => $to !== $from && trim($from.$to) === '' && str_contains($from.$to, "\t") && str_contains($from.$to, ' '), ARRAY_FILTER_USE_BOTH)) {
            return null; // same kind of indentation on both sides: the count-based shift handles it
        }

        uksort($map, fn ($a, $b) => strlen($b) <=> strlen($a));
        $out = [];
        foreach ($replaceLines as $line) {
            if (trim($line) === '') {
                $out[] = $line;

                continue;
            }
            $indent = $this->indent($line);
            $hit = null;
            foreach ($map as $from => $to) {
                if (str_starts_with($indent, (string) $from)) {
                    $hit = [(string) $from, $to];

                    break;
                }
            }
            if ($hit === null) {
                return null;
            }
            $out[] = $hit[1].substr($line, strlen($hit[0]));
        }

        return $out;
    }

    /**
     * Shift the replacement's indentation by however much the model's search
     * text was off from the file (when the offset is consistent).
     */
    protected function reindent(string $replace, array $needle, array $found, string $eol): string
    {
        $replaceLines = explode("\n", str_replace("\r\n", "\n", $replace));

        if (($mapped = $this->mapIndents($replaceLines, $needle, $found)) !== null) {
            return implode($eol, $mapped);
        }

        $delta = null;

        foreach ($needle as $k => $line) {
            if (trim($line) === '') {
                continue;
            }
            $d = strlen($this->indent($found[$k])) - strlen($this->indent($line));
            if ($delta === null) {
                $delta = $d;
            } elseif ($delta !== $d) {
                $delta = null; // inconsistent: leave the replacement as written

                break;
            }
        }

        $unit = $this->indentChar($found);
        if ($delta) {
            $replaceLines = array_map(function ($line) use ($delta, $unit) {
                if (trim($line) === '') {
                    return $line;
                }
                if ($delta > 0) {
                    return str_repeat($unit, $delta).$line;
                }
                $strip = min(-$delta, strlen($this->indent($line)));

                return substr($line, $strip);
            }, $replaceLines);
        }

        return implode($eol, $replaceLines);
    }

    protected function closest(array $fileLines, array $needle): string
    {
        $first = trim($needle[0]);
        $best = null;
        $bestScore = 0.0;

        foreach ($fileLines as $i => $line) {
            $t = trim($line);
            if ($t === '') {
                continue;
            }
            similar_text($first, $t, $pct);
            if ($pct > $bestScore) {
                $bestScore = $pct;
                $best = $i;
            }
        }

        if ($best === null || $bestScore < 50) {
            return ' Read the file again and copy the lines exactly.';
        }

        $from = max(0, $best - 2);
        $to = min(count($fileLines) - 1, $best + count($needle) + 1);
        $snippet = [];
        for ($i = $from; $i <= $to; $i++) {
            $snippet[] = sprintf('%5d  %s', $i + 1, $fileLines[$i]);
        }

        return ' The closest lines in the file are (line numbers are NOT part of the text):'."\n".implode("\n", $snippet);
    }

    protected function exactLines(string $content, string $search): string
    {
        $lines = [];
        $offset = 0;
        while (($pos = strpos($content, $search, $offset)) !== false && count($lines) < 5) {
            $lines[] = 'line '.(substr_count($content, "\n", 0, $pos) + 1);
            $offset = $pos + 1;
        }

        return implode(', ', $lines);
    }

    protected function trimBlankEdges(array $lines): array
    {
        while ($lines !== [] && trim($lines[0]) === '') {
            array_shift($lines);
        }
        while ($lines !== [] && trim(end($lines)) === '') {
            array_pop($lines);
        }

        return array_values($lines);
    }

    protected function indent(string $line): string
    {
        return substr($line, 0, strlen($line) - strlen(ltrim($line, " \t")));
    }

    protected function indentChar(array $lines): string
    {
        foreach ($lines as $line) {
            $indent = $this->indent($line);
            if ($indent !== '') {
                return $indent[0];
            }
        }

        return ' ';
    }
}
