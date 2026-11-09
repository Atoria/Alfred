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

    /**
     * Light Markdown as written in ticket descriptions: "### Heading" lines,
     * "- item" / "* item" / "1. item" lists and "> quote" lines; everything
     * else is plain paragraphs (as paragraphs()). @return array[]
     */
    public static function markdown(string $text): array
    {
        $out = [];
        /** @var list<string> $para */
        $para = [];
        /** @var array{ordered: bool, items: list<string>}|null $list */
        $list = null;
        $flushPara = function () use (&$para, &$out) {
            if ($para !== []) {
                array_push($out, ...self::paragraphs(implode("\n", $para)));
                $para = [];
            }
        };
        $flushList = function () use (&$list, &$out) {
            if ($list !== null) {
                $items = array_values(array_filter(array_map(fn ($t) => self::paragraph([self::inline($t)]), $list['items'])));
                if ($items) {
                    $out[] = ['type' => $list['ordered'] ? 'orderedList' : 'bulletList', 'content' => array_map(fn ($p) => ['type' => 'listItem', 'content' => [$p]], $items)];
                }
                $list = null;
            }
        };

        foreach (preg_split("/\r?\n/", trim($text)) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                $flushPara();
                $flushList();
            } elseif (preg_match('/^(#{1,6})\s+(.+)$/', $trimmed, $m)) {
                $flushPara();
                $flushList();
                $out[] = self::heading(trim($m[2], " #"), max(2, min(4, strlen($m[1]))));
            } elseif (preg_match('/^(?:[-*•]|(\d+)[.)])\s+(.+)$/u', $trimmed, $m)) {
                $flushPara();
                $ordered = $m[1] !== '';
                if ($list !== null && $list['ordered'] !== $ordered) {
                    $flushList();
                }
                $list ??= ['ordered' => $ordered, 'items' => []];
                $list['items'][] = $m[2];
            } elseif (preg_match('/^>\s?(.*)$/', $trimmed, $m)) {
                $flushPara();
                $flushList();
                if ($p = self::paragraph([self::inline($m[1])])) {
                    $last = end($out);
                    if ($last && $last['type'] === 'blockquote') {
                        $out[count($out) - 1]['content'][] = $p;
                    } else {
                        $out[] = ['type' => 'blockquote', 'content' => [$p]];
                    }
                }
            } else {
                $flushList();
                $para[] = $line;
            }
        }
        $flushPara();
        $flushList();

        return $out;
    }

    /** Text without Markdown emphasis markers (**bold**, `code`). */
    protected static function inline(string $text): ?array
    {
        return self::text(trim(preg_replace(['/\*\*(.+?)\*\*/u', '/`([^`]+)`/u'], '$1', $text) ?? $text));
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
