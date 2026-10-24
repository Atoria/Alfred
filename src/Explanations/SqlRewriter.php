<?php

namespace Atoria\Alfred\Explanations;

use InvalidArgumentException;

/**
 * Turns the query behind a value into a query for the rows behind it.
 *
 *   select count(*) as aggregate from bookings where status = ?
 *   => select * from bookings where status = ? limit 100
 *
 * Only plain SELECTs are accepted. The select list is swapped for "*" when it
 * is an ungrouped aggregate; placeholders inside the removed select list are
 * dropped from the bindings so the rest still line up.
 */
class SqlRewriter
{
    protected const AGGREGATES = '/\b(count|sum|avg|min|max|group_concat|string_agg|array_agg|json_agg|stddev|variance)\s*\(/i';

    /**
     * @param  array<int, mixed>  $bindings
     * @return array{sql: string, bindings: array<int, mixed>, aggregate: bool}
     */
    public function toRows(string $sql, array $bindings, int $limit): array
    {
        $sql = trim($sql);
        $this->assertSafe($sql);

        $from = $this->findTopLevel($sql, 'from');
        if ($from === null) {
            throw new InvalidArgumentException('Query has no FROM clause.');
        }

        $selectList = substr($sql, 6, $from - 6); // after "select"
        $rest = substr($sql, $from);

        $grouped = $this->findTopLevel($rest, 'group by') !== null;
        $aggregate = ! $grouped && preg_match(self::AGGREGATES, $this->stripQuoted($selectList)) === 1;

        if ($aggregate) {
            $dropped = $this->countPlaceholders($selectList);
            $bindings = array_slice($bindings, $dropped);
            $selectList = preg_match('/^\s*distinct\b/i', $selectList) ? ' distinct * ' : ' * ';
        }

        // Drop the query's own LIMIT/OFFSET (literal numbers in Laravel's grammar) and apply ours.
        $rest = preg_replace('/\s+limit\s+\d+(\s*,\s*\d+)?(\s+offset\s+\d+)?\s*$/i', '', $rest) ?? $rest;
        $rest = preg_replace('/\s+offset\s+\d+\s*$/i', '', $rest) ?? $rest;

        return [
            'sql' => 'select'.$selectList.$rest.' limit '.max(1, $limit),
            'bindings' => $bindings,
            'aggregate' => $aggregate,
        ];
    }

    public function assertSafe(string $sql): void
    {
        $plain = $this->stripQuoted($sql);

        if (! preg_match('/^\s*select\b/i', $plain)) {
            throw new InvalidArgumentException('Only SELECT queries can be drilled into.');
        }
        if (str_contains($plain, ';')) {
            throw new InvalidArgumentException('Multiple statements are not allowed.');
        }
        if (preg_match('/\b(into|insert|update|delete|drop|alter|create|truncate|grant|revoke|call|exec|execute|copy|load_file|outfile|dumpfile|pg_sleep|sleep|benchmark)\b|\bfor\s+(update|share)\b|\block\s+in\s+share\s+mode\b/i', $plain)) {
            throw new InvalidArgumentException('Query contains a disallowed keyword.');
        }
    }

    /** Position of a keyword at parenthesis depth 0, outside quotes. */
    public function findTopLevel(string $sql, string $keyword): ?int
    {
        $plain = $this->stripQuoted($sql);
        $depth = 0;
        $len = strlen($plain);
        $pattern = '/\G'.str_replace(' ', '\s+', preg_quote($keyword, '/')).'\b/i';

        for ($i = 0; $i < $len; $i++) {
            $c = $plain[$i];
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
            } elseif ($depth === 0 && ($i === 0 || ! ctype_alnum($plain[$i - 1]) && $plain[$i - 1] !== '_') && preg_match($pattern, $plain, $m, 0, $i)) {
                return $i;
            }
        }

        return null;
    }

    /** Replace quoted strings and identifiers with same-length filler so positions stay valid. */
    public function stripQuoted(string $sql): string
    {
        return preg_replace_callback(
            '/\'(?:[^\'\\\\]|\\\\.|\'\')*\'|"(?:[^"\\\\]|\\\\.)*"|`[^`]*`/s',
            fn ($m) => str_repeat('x', strlen($m[0])),
            $sql,
        ) ?? $sql;
    }

    protected function countPlaceholders(string $fragment): int
    {
        return substr_count($this->stripQuoted($fragment), '?');
    }
}
