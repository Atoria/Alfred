<?php

namespace Atoria\Alfred\Changes;

/**
 * The one way Alfred turns a file plus its search/replace edits into the new
 * file — for preview, apply and pull requests alike, so what someone previews
 * is exactly what gets applied or opened as a PR.
 */
class EditApplier
{
    /**
     * @param  array<int, array{search: string, replace: string}>  $edits  in order, for one file
     * @param  bool  $forgiving  when the exact text isn't found once, place it with the TextMatcher (base branch moved on)
     *
     * @throws EditMismatch when an edit doesn't match exactly once
     */
    public static function apply(string $content, array $edits, bool $forgiving = false): string
    {
        foreach (array_values($edits) as $i => $edit) {
            $search = (string) $edit['search'];
            $replace = (string) $edit['replace'];

            if ($search !== '' && substr_count($content, $search) === 1) {
                $content = self::replaceOnce($content, $search, $replace);

                continue;
            }
            if (! $forgiving) {
                throw new EditMismatch($i, 'the text to replace is no longer in the file exactly once');
            }
            try {
                $placed = (new TextMatcher)->locate($content, $search, $replace);
            } catch (MatchProblem $e) {
                throw new EditMismatch($i, $e->getMessage());
            }
            $content = self::replaceOnce($content, $placed['search'], $placed['replace']);
        }

        return $content;
    }

    /**
     * The edits of a change grouped per file, in order.
     *
     * @return array<string, array<int, array{search: string, replace: string}>>
     */
    public static function byFile(array $edits): array
    {
        $grouped = [];
        foreach ($edits as $edit) {
            $grouped[(string) $edit['path']][] = ['search' => (string) $edit['search'], 'replace' => (string) $edit['replace']];
        }

        return $grouped;
    }

    protected static function replaceOnce(string $content, string $search, string $replace): string
    {
        $pos = strpos($content, $search);

        return $pos === false ? $content : substr_replace($content, $replace, $pos, strlen($search));
    }
}
