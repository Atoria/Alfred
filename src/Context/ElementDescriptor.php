<?php

namespace Atoria\Alfred\Context;

/** What the user pointed at, as reported by the overlay. */
class ElementDescriptor
{
    /**
     * @param  string[]  $labels  headings / aria-labels / titles around the element, nearest first
     * @param  string[]  $components  Livewire component names, innermost first
     * @param  array{framework?: ?string, page?: ?string, files?: string[], components?: string[]}  $frontend  Vue/React/Svelte/Inertia source as seen in the browser (unverified)
     */
    public function __construct(
        public ?string $explainKey,
        public string $kind,
        public string $tag,
        public array $labels,
        public ?string $column,
        public ?string $text,
        public array $components,
        public ?string $selector,
        public array $frontend = ['framework' => null, 'page' => null, 'files' => [], 'components' => []],
    ) {}

    public static function fromArray(array $data): self
    {
        $str = fn ($v, int $max = 300) => is_scalar($v) && trim((string) $v) !== '' ? mb_substr(trim((string) $v), 0, $max) : null;
        $list = fn ($v, int $n) => array_slice(array_values(array_filter(array_map(fn ($s) => $str($s), is_array($v) ? $v : []))), 0, $n);

        $kind = $str($data['kind'] ?? null, 20);

        return new self(
            $str($data['explain_key'] ?? null, 120),
            in_array($kind, ['chart', 'table-cell', 'table', 'stat', 'element'], true) ? $kind : 'element',
            preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($data['tag'] ?? 'div'))) ?: 'div',
            $list($data['labels'] ?? [], 8),
            $str($data['column'] ?? null, 200),
            $str($data['text'] ?? null, 500),
            array_values(array_filter($list($data['components'] ?? [], 6), fn ($n) => preg_match('/^[A-Za-z0-9._:\\\\-]+$/', $n) === 1)),
            $str($data['selector'] ?? null, 300),
            self::frontendFrom($data['frontend'] ?? null),
        );
    }

    /** Only shapes, never trusted as paths: FrontendSource checks every file against readable code. */
    protected static function frontendFrom(mixed $data): array
    {
        $data = is_array($data) ? $data : [];
        $framework = in_array($data['framework'] ?? null, ['vue', 'react', 'svelte'], true) ? $data['framework'] : null;
        $page = is_string($data['page'] ?? null) && preg_match('#^[A-Za-z0-9_./-]{1,120}$#', $data['page']) && ! str_contains($data['page'], '..') ? $data['page'] : null;

        $files = [];
        foreach (array_slice(is_array($data['files'] ?? null) ? $data['files'] : [], 0, 8) as $f) {
            if (is_string($f) && strlen($f) <= 400 && ! str_contains($f, "\0")) {
                $files[] = str_replace('\\', '/', $f);
            }
        }

        $names = [];
        foreach (array_slice(is_array($data['components'] ?? null) ? $data['components'] : [], 0, 8) as $n) {
            if (is_string($n) && preg_match('/^[A-Za-z0-9_$.-]{1,80}$/', $n)) {
                $names[] = $n;
            }
        }

        return ['framework' => $framework, 'page' => $page, 'files' => $files, 'components' => $names];
    }

    /**
     * The part of the element an explanation may depend on. Explanations are
     * cached and shown to other people, so the AI only ever sees what is also
     * in the cache key (ExplanationKey): nothing a user could add to their
     * own request (extra labels, a CSS path) can steer an explanation someone
     * else will read.
     *  - Developer-keyed elements (data-explain): the key, kind and tag only.
     *  - Others: kind, tag, column, the first three labels, components and
     *    the front-end source.
     */
    public function forExplanation(): self
    {
        if ($this->explainKey) {
            return new self($this->explainKey, $this->kind, $this->tag, [], null, null, [], null);
        }

        return new self(null, $this->kind, $this->tag, array_slice($this->labels, 0, 3), $this->column, $this->text, $this->components, null, $this->frontend);
    }

    public function innermostComponent(): ?string
    {
        return $this->components[0] ?? null;
    }
}
