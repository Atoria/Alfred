<?php

namespace Atoria\Alfred\Explanations;

class ExplanationPresenter
{
    public static function toArray(Explanation $e, bool $cached, bool $stale, bool $canVerify, bool $canViewRecords, ?bool $canViewCode = null): array
    {
        $canViewCode ??= $canVerify;

        $data = [
            'id' => $e->id,
            'title' => $e->title,
            'summary' => $e->summary,
            'formula' => $e->formula,
            'steps' => array_values((array) $e->steps),
            'data_sources' => array_values((array) $e->data_sources),
            'caveats' => array_values((array) $e->caveats),
            'example' => $e->example,
            'glossary' => array_values((array) $e->glossary),
            'confidence' => $e->confidence,
            'verified' => $e->isVerified(),
            'verified_at' => $e->verified_at?->toIso8601String(),
            'edited' => (bool) $e->edited,
            'stale' => $stale,
            'cached' => $cached,
            'generated_at' => $e->updated_at?->toIso8601String(),
            'has_records' => $canViewRecords && $e->query_sql !== null,
            'can_verify' => $canVerify,
            'private' => $e->owner !== null, // only its author sees it (they may not create shared explanations)
        ];

        if ($canViewCode) {
            $dev = (array) $e->developer;
            $data['developer'] = [
                'summary' => $dev['summary'] ?? null,
                // older explanations only have technical notes
                'flow' => array_values((array) ($dev['flow'] ?? $e->technical_notes ?? [])),
                'queries' => array_values((array) ($dev['queries'] ?? [])),
                'caching' => $dev['caching'] ?? null,
                'gotchas' => array_values((array) ($dev['gotchas'] ?? [])),
            ];
        }

        if ($canVerify) {
            $data['technical_notes'] = array_values((array) $e->technical_notes);
            $data['query'] = $e->query_sql ? ['sql' => $e->query_sql, 'source' => $e->query_source, 'connection' => $e->query_connection] : null;
            $data['model'] = $e->model;
            $data['tokens'] = ['input' => (int) $e->input_tokens, 'output' => (int) $e->output_tokens];
            $data['verified_by'] = $e->verified_by;
        }

        return $data;
    }
}
