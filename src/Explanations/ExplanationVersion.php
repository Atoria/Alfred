<?php

namespace Atoria\Alfred\Explanations;

use Atoria\Alfred\Alfred;
use Atoria\Alfred\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One saved state of an explanation: every generation, edit, verification,
 * kept follow-up or restore adds one, so verifiers can see how an
 * explanation changed and put an earlier one back.
 *
 * @property int $id
 * @property int $explanation_id
 * @property ?string $tenant
 * @property int $version
 * @property string $action
 * @property ?string $by
 * @property array $snapshot
 * @property ?Carbon $created_at
 */
class ExplanationVersion extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    /** The fields a version keeps (and a restore puts back). */
    public const FIELDS = ['title', 'summary', 'formula', 'example', 'steps', 'data_sources', 'caveats', 'glossary', 'developer', 'confidence'];

    protected $guarded = ['id'];

    protected $casts = ['snapshot' => 'array', 'version' => 'integer', 'explanation_id' => 'integer'];

    public function getTable(): string
    {
        return config('alfred.versions_table', 'alfred_explanation_versions');
    }

    public function getConnectionName(): ?string
    {
        return Alfred::connection() ?? parent::getConnectionName();
    }

    public static function record(Explanation $e, string $action, ?string $by): self
    {
        $next = (int) static::query()->where('explanation_id', $e->id)->max('version') + 1;

        $version = static::query()->create([
            'explanation_id' => $e->id,
            'tenant' => $e->tenant,
            'version' => $next,
            'action' => $action,
            'by' => $by,
            'snapshot' => array_intersect_key($e->only(self::FIELDS), array_flip(self::FIELDS)) + [
                'status' => $e->status,
                'model' => $e->model,
                'input_tokens' => (int) $e->input_tokens,
                'output_tokens' => (int) $e->output_tokens,
            ],
            'created_at' => now(),
        ]);

        // Keep the history bounded.
        $keep = max(1, (int) config('alfred.history.keep', 50));
        $cutoff = static::query()->where('explanation_id', $e->id)->orderByDesc('version')->skip($keep)->value('version');
        if ($cutoff !== null) {
            static::query()->where('explanation_id', $e->id)->where('version', '<=', $cutoff)->delete();
        }

        return $version;
    }
}
