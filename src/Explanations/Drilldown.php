<?php

namespace Atoria\Alfred\Explanations;

use Atoria\Alfred\Alfred;
use Atoria\Alfred\Context\PageContext;
use Atoria\Alfred\Preview\ClassName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * "Show the records behind this": re-runs the explanation's query with the
 * bindings THIS user's page used, as rows. Runs locally; nothing goes to the LLM.
 */
class Drilldown
{
    public function __construct(protected SqlRewriter $rewriter) {}

    /**
     * @return array{columns: string[], rows: array<int, array<string, mixed>>, truncated: bool, aggregate: bool, sql: string}
     */
    public function run(Explanation $explanation, PageContext $page): array
    {
        if (! $explanation->query_sql) {
            throw new InvalidArgumentException('This explanation is not linked to a query.');
        }

        $query = $page->findQuery($explanation->query_sql, $explanation->query_connection, $explanation->query_source);
        if (! $query) {
            throw new InvalidArgumentException('This page did not run the query behind this value. Open '.Alfred::name().' on the page where the value is shown.');
        }

        $limit = max(1, (int) config('alfred.drilldown.max_rows', 100));
        $rewritten = $this->rewriter->toRows($query['sql'], (array) $query['bindings'], $limit + 1);

        $connection = DB::connection($query['connection']);
        $rows = [];

        // Read-only on every database that supports it, and always rolled back.
        $driver = $connection->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $connection->statement('SET TRANSACTION READ ONLY'); // applies to the next transaction
        }
        $connection->beginTransaction();
        try {
            if ($driver === 'pgsql') {
                $connection->statement('SET TRANSACTION READ ONLY');
            }
            if ($driver === 'sqlite') {
                $connection->statement('PRAGMA query_only = ON');
            }
            $rows = $connection->select($rewritten['sql'], $rewritten['bindings']);
        } finally {
            try {
                $connection->rollBack();
            } catch (Throwable) {
                // nothing to roll back
            }
            if ($driver === 'sqlite') {
                $connection->statement('PRAGMA query_only = OFF');
            }
        }

        $truncated = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $hidden = array_merge(
            array_map('strtolower', (array) config('alfred.drilldown.hidden_columns', [])),
            $this->modelHiddenColumns(), // what the app's own models hide ($hidden)
        );

        $out = [];
        $columns = [];
        foreach ($rows as $row) {
            $item = [];
            foreach ((array) $row as $column => $value) {
                $columns[$column] = true;
                $item[$column] = $this->isHidden((string) $column, $hidden) ? '••••' : $this->present($value);
            }
            $out[] = $item;
        }

        return [
            'columns' => array_keys($columns),
            'rows' => $out,
            'truncated' => $truncated,
            'aggregate' => $rewritten['aggregate'],
            'sql' => $rewritten['sql'],
        ];
    }

    /**
     * Columns the app's Eloquent models hide from serialisation ($hidden),
     * found by scanning alfred.drilldown.models (default app/Models).
     *
     * @return string[]
     */
    protected function modelHiddenColumns(): array
    {
        static $cache = [];
        $base = (string) (config('alfred.base_path') ?: base_path());
        $dirs = (array) config('alfred.drilldown.models', ['app/Models']);
        $cacheKey = $base.'|'.implode(',', $dirs);
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $hidden = [];
        foreach ($dirs as $dir) {
            $path = rtrim($base, '/').'/'.trim($dir, '/');
            if (! is_dir($path)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $class = ClassName::fromCode((string) file_get_contents($file->getPathname()));
                try {
                    if ($class && class_exists($class) && is_subclass_of($class, Model::class) && ! (new \ReflectionClass($class))->isAbstract()) {
                        foreach ((new $class)->getHidden() as $column) {
                            $hidden[] = strtolower((string) $column);
                        }
                    }
                } catch (Throwable) {
                    // a model that can't be instantiated without arguments: skip it
                }
            }
        }

        return $cache[$cacheKey] = array_values(array_unique($hidden));
    }

    protected function isHidden(string $column, array $hidden): bool
    {
        $c = strtolower($column);
        if (in_array($c, $hidden, true)) {
            return true;
        }

        return (bool) preg_match('/(password|secret|token|api_key|private_key)/', $c);
    }

    protected function present(mixed $value): mixed
    {
        if ($value === null || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        $value = (string) $value;
        if (! mb_check_encoding($value, 'UTF-8')) {
            return '[binary]';
        }

        return mb_strlen($value) > 200 ? mb_substr($value, 0, 200).'…' : $value;
    }
}
