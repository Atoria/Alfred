<?php

namespace Atoria\Alfred\Agent\Tools;

use Atoria\Alfred\Agent\AgentState;
use Atoria\Alfred\Security\AccessDenied;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

class DescribeModel implements Tool
{
    public function name(): string
    {
        return 'describe_model';
    }

    public function description(): string
    {
        return 'Describe an Eloquent model: its table, columns (schema only, no data), casts, relations, local scopes and source file.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'class' => ['type' => 'string', 'description' => 'Fully-qualified class, e.g. App\\Models\\Booking'],
            ],
            'required' => ['class'],
            'additionalProperties' => false,
        ];
    }

    public function strict(): bool
    {
        return true;
    }

    public function handle(array $arguments, AgentState $state): mixed
    {
        $class = ltrim((string) ($arguments['class'] ?? ''), '\\');

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return "{$class} is not an Eloquent model.";
        }

        $reflection = new ReflectionClass($class);
        $file = $reflection->getFileName();

        // Only describe models that live in readable application code.
        if (! $file || ! $state->guard->allows($file)) {
            throw new AccessDenied("{$class} is outside the readable code.");
        }
        $state->remember($file);

        /** @var Model $model */
        $model = $reflection->newInstanceWithoutConstructor();
        try {
            $model = new $class;
        } catch (Throwable) {
            // keep the bare instance
        }

        $relations = [];
        $scopes = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class && ! str_starts_with($method->getDeclaringClass()->getName(), 'App\\')) {
                continue;
            }
            $type = $method->getReturnType();
            if ($method->getNumberOfRequiredParameters() === 0 && $type instanceof ReflectionNamedType && is_a($type->getName(), Relation::class, true)) {
                $relations[$method->getName()] = class_basename($type->getName());
            }
            if (str_starts_with($method->getName(), 'scope') && strlen($method->getName()) > 5) {
                $scopes[] = lcfirst(substr($method->getName(), 5));
            } elseif (array_filter($method->getAttributes(), fn ($a) => str_ends_with($a->getName(), '\\Scope'))) {
                $scopes[] = $method->getName();
            }
        }

        $columns = null;
        try {
            $columns = array_map(
                fn (array $c) => $c['name'].' '.$c['type_name'].($c['nullable'] ? ' null' : ''),
                Schema::connection($model->getConnectionName())->getColumns($model->getTable()),
            );
        } catch (Throwable) {
            // schema not reachable — the file is still informative
        }

        return [
            'class' => $class,
            'file' => $state->guard->relative($file),
            'table' => $model->getTable(),
            'connection' => $model->getConnectionName(),
            'columns' => $columns,
            'casts' => $model->getCasts(),
            'relations' => $relations,
            'scopes' => $scopes,
            'soft_deletes' => in_array('Illuminate\\Database\\Eloquent\\SoftDeletes', class_uses_recursive($class), true),
            'global_scopes' => array_keys($model->getGlobalScopes()),
        ];
    }
}
