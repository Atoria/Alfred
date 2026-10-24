<?php

namespace Atoria\Alfred\Privacy;

/**
 * Decides what about the page and its queries may be sent to the LLM.
 *
 * code_only: labels only (digits masked), SQL templates, no element text, no bindings.
 * redacted:  + element text with emails / phones / numbers masked.
 * full:      + raw element text and query bindings.
 */
class Redactor
{
    public const CODE_ONLY = 'code_only';

    public const REDACTED = 'redacted';

    public const FULL = 'full';

    public function __construct(protected string $mode = self::CODE_ONLY)
    {
        if (! in_array($mode, [self::CODE_ONLY, self::REDACTED, self::FULL], true)) {
            $this->mode = self::CODE_ONLY;
        }
    }

    public static function fromConfig(): self
    {
        return new self((string) config('alfred.privacy', self::CODE_ONLY));
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function sendsBindings(): bool
    {
        return $this->mode === self::FULL;
    }

    /** Labels are what the element is called (heading, column, aria-label) — never its value. */
    public function label(?string $label): ?string
    {
        if ($label === null) {
            return null;
        }

        $label = $this->squash($label);

        if ($this->mode === self::FULL) {
            return $label;
        }

        return $this->maskDigits($this->maskPersonal($label));
    }

    /** The element's own visible text (a cell value, a stat value...). */
    public function text(?string $text): ?string
    {
        if ($text === null || $this->mode === self::CODE_ONLY) {
            return null;
        }

        $text = $this->squash($text);

        return $this->mode === self::FULL ? $text : $this->maskDigits($this->maskPersonal($text));
    }

    /** @param  array<int, mixed>  $bindings */
    public function bindings(array $bindings): ?array
    {
        return $this->sendsBindings() ? array_map(fn ($b) => is_scalar($b) || $b === null ? $b : (string) json_encode($b), $bindings) : null;
    }

    public function maskDigits(string $value): string
    {
        return preg_replace('/\d/u', '#', $value) ?? $value;
    }

    public function maskPersonal(string $value): string
    {
        $value = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '[email]', $value) ?? $value;
        $value = preg_replace('/(?:\+?\d[\d\s().\-]{7,}\d)/u', '[phone]', $value) ?? $value;

        return $value;
    }

    protected function squash(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return mb_substr($value, 0, 300);
    }
}
