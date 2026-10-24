<?php

namespace Atoria\Alfred\Tickets;

use Atoria\Alfred\Changes\Change;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/** Screenshots taken in the browser and attached to a change ("before" / "after"). */
class Screenshots
{
    public const KINDS = ['before', 'after'];

    public function enabled(): bool
    {
        return (bool) config('alfred.screenshots.enabled', true);
    }

    public function disk(): Filesystem
    {
        return Storage::disk(config('alfred.screenshots.disk', 'local'));
    }

    /** Store a data: URL (PNG or JPEG) as the change's $kind screenshot. */
    public function store(Change $change, string $kind, string $dataUrl): string
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('Unknown screenshot kind.');
        }
        if (! preg_match('#^data:image/(png|jpeg);base64,([A-Za-z0-9+/=\r\n]+)$#', $dataUrl, $m)) {
            throw new InvalidArgumentException('Expected a PNG or JPEG data URL.');
        }

        $bytes = base64_decode($m[2], true);
        if ($bytes === false || $bytes === '') {
            throw new InvalidArgumentException('Invalid image data.');
        }
        if (strlen($bytes) > (int) config('alfred.screenshots.max_kb', 6000) * 1024) {
            throw new InvalidArgumentException('Screenshot is too large.');
        }

        $isPng = str_starts_with($bytes, "\x89PNG\r\n\x1a\n");
        $isJpeg = str_starts_with($bytes, "\xFF\xD8\xFF");
        if (! $isPng && ! $isJpeg) {
            throw new InvalidArgumentException('Not a PNG or JPEG image.');
        }

        $ext = $isPng ? 'png' : 'jpg';
        $path = "alfred/changes/{$change->id}/{$kind}.{$ext}";

        $shots = (array) $change->screenshots;
        if (isset($shots[$kind]) && $shots[$kind] !== $path) {
            $this->disk()->delete($shots[$kind]);
        }

        $this->disk()->put($path, $bytes);
        $shots[$kind] = $path;
        $change->forceFill(['screenshots' => $shots])->save();

        return $path;
    }

    /** @return array{0: string, 1: string, 2: string}|null [contents, mime, filename] */
    public function get(Change $change, string $kind): ?array
    {
        $path = ((array) $change->screenshots)[$kind] ?? null;
        if (! $path || ! $this->disk()->exists($path)) {
            return null;
        }

        $ext = pathinfo($path, PATHINFO_EXTENSION);

        return [
            (string) $this->disk()->get($path),
            $ext === 'png' ? 'image/png' : 'image/jpeg',
            "alfred-change-{$change->id}-{$kind}.{$ext}",
        ];
    }

    /** @return string[] kinds that exist */
    public function kinds(Change $change): array
    {
        return array_values(array_filter(self::KINDS, fn ($k) => isset(((array) $change->screenshots)[$k])));
    }
}
