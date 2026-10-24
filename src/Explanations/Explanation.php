<?php

namespace Atoria\Alfred\Explanations;

use Atoria\Alfred\Alfred;
use Atoria\Alfred\Security\PathGuard;
use Atoria\Alfred\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $key
 * @property string $title
 * @property string $summary
 * @property ?string $formula
 * @property array $steps
 * @property array $data_sources
 * @property array $caveats
 * @property array $technical_notes
 * @property string $confidence
 * @property ?string $query_sql
 * @property ?string $query_connection
 * @property ?string $query_source
 * @property array $files
 * @property string $status
 * @property bool $edited
 * @property ?string $verified_by
 * @property ?Carbon $verified_at
 * @property ?string $example
 * @property ?array $glossary
 * @property ?array $developer
 * @property ?string $route
 * @property ?string $component
 * @property ?string $label
 * @property ?int $format
 * @property ?string $model
 * @property ?int $input_tokens
 * @property ?int $output_tokens
 * @property ?string $owner set for a private explanation: the user key it belongs to
 * @property ?string $tenant
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class Explanation extends Model
{
    use BelongsToTenant;

    public const GENERATED = 'generated';

    public const VERIFIED = 'verified';

    /** Current shape: a business part plus a developer part. */
    public const FORMAT = 2;

    protected $guarded = ['id'];

    protected $casts = [
        'steps' => 'array',
        'data_sources' => 'array',
        'caveats' => 'array',
        'technical_notes' => 'array',
        'glossary' => 'array',
        'developer' => 'array',
        'format' => 'integer',
        'files' => 'array',
        'edited' => 'boolean',
        'verified_at' => 'datetime',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
    ];

    public function getTable(): string
    {
        return config('alfred.table', 'alfred_explanations');
    }

    public function getConnectionName(): ?string
    {
        return Alfred::connection() ?? parent::getConnectionName();
    }

    public function isOutdatedFormat(): bool
    {
        return (int) ($this->format ?? 1) < self::FORMAT;
    }

    public function isVerified(): bool
    {
        return $this->status === self::VERIFIED;
    }

    /** True when any file the explanation was built from has changed or disappeared since. */
    public function isStale(PathGuard $guard): bool
    {
        foreach ((array) $this->files as $relative => $hash) {
            $abs = $guard->basePath().DIRECTORY_SEPARATOR.$relative;
            if (! is_file($abs) || sha1_file($abs) !== $hash) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> the same files, hashed as they are now */
    public function currentFileHashes(PathGuard $guard): array
    {
        $now = [];
        foreach (array_keys((array) $this->files) as $relative) {
            $abs = $guard->basePath().DIRECTORY_SEPARATOR.$relative;
            if (is_file($abs)) {
                $now[$relative] = sha1_file($abs) ?: '';
            }
        }

        return $now;
    }
}
