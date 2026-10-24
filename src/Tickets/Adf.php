<?php

namespace Atoria\Alfred\Tickets;

/** Tiny builder for Atlassian Document Format (Jira Cloud REST v3 descriptions). */
class Adf
{
    public static function doc(array $blocks): array
    {
        return ['type' => 'doc', 'version' => 1, 'content' => array_values(array_filter($blocks))];
    }

    public static function text(string $text, ?string $href = null, bool $strong = false): ?array
    {
        if ($text === '') {
            return null;
        }

        $node = ['type' => 'text', 'text' => $text];
        $marks = [];
        if ($href) {
            $marks[] = ['type' => 'link', 'attrs' => ['href' => $href]];
        }
        if ($strong) {
            $marks[] = ['type' => 'strong'];
        }
        if ($marks) {
            $node['marks'] = $marks;
        }

        return $node;
    }

    /** @param  array<int, array|null|string>  $inline */
    public static function paragraph(array $inline): ?array
    {
        $content = array_values(array_filter(array_map(fn ($n) => is_string($n) ? self::text($n) : $n, $inline)));

        return $content ? ['type' => 'paragraph', 'content' => $content] : null;
    }

    /** Plain multi-paragraph text (blank lines separate paragraphs, single newlines become breaks). @return array[] */
    public static function paragraphs(string $text): array
    {
        $out = [];
        foreach (preg_split("/\n\s*\n/", trim($text)) ?: [] as $para) {
            $lines = explode("\n", trim($para));
            $inline = [];
            foreach ($lines as $i => $line) {
                if ($i > 0) {
                    $inline[] = ['type' => 'hardBreak'];
                }
                $inline[] = self::text($line);
            }
            if ($p = self::paragraph($inline)) {
                $out[] = $p;
            }
        }

        return $out;
    }

    public static function heading(string $text, int $level = 3): array
    {
        return ['type' => 'heading', 'attrs' => ['level' => $level], 'content' => [self::text($text)]];
    }

    /** @param  array<int, array<int, array|null|string>>  $items  each item is a list of inline nodes/strings */
    public static function bullets(array $items): ?array
    {
        $list = [];
        foreach ($items as $inline) {
            if ($p = self::paragraph($inline)) {
                $list[] = ['type' => 'listItem', 'content' => [$p]];
            }
        }

        return $list ? ['type' => 'bulletList', 'content' => $list] : null;
    }

    public static function code(string $code, string $language = 'diff'): ?array
    {
        return $code === '' ? null : ['type' => 'codeBlock', 'attrs' => ['language' => $language], 'content' => [self::text($code)]];
    }

    public static function rule(): array
    {
        return ['type' => 'rule'];
    }
}
