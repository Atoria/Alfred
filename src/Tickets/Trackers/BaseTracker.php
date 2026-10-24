<?php

namespace Atoria\Alfred\Tickets\Trackers;

use Atoria\Alfred\Tickets\TrackerException;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

abstract class BaseTracker implements Tracker
{
    /** Required config keys under alfred.trackers.{name}. */
    protected array $required = [];

    public function configured(): bool
    {
        foreach ($this->required as $key) {
            if (trim((string) $this->config($key)) === '') {
                return false;
            }
        }

        return true;
    }

    protected function config(string $key, mixed $default = null): mixed
    {
        return config("alfred.trackers.{$this->name()}.{$key}", $default);
    }

    /** Labels/tags for this tracker: its own list if set, otherwise the shared one. */
    protected function labels(): array
    {
        $own = $this->config('labels');
        $labels = is_array($own) && $own !== [] ? $own : (array) config('alfred.tickets.labels', []);

        return array_values(array_filter(array_map(fn ($l) => trim((string) $l), $labels), fn ($l) => $l !== ''));
    }

    /** Run an HTTP call, turning network errors and error responses into TrackerException. */
    protected function call(string $what, Closure $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            throw new TrackerException("Could not reach {$this->label()}: ".$e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            throw new TrackerException("{$this->label()} refused {$what} ({$response->status()}): ".$this->errorText($response));
        }

        return $response;
    }

    /** A short human-readable error from a failed response. */
    protected function errorText(Response $response): string
    {
        $json = $response->json();
        if (is_array($json)) {
            foreach (['message', 'error', 'err', 'errors', 'error_description'] as $key) {
                if (! empty($json[$key])) {
                    $value = $json[$key];

                    return is_string($value) ? $value : mb_substr((string) json_encode($value), 0, 300);
                }
            }
        }

        return mb_substr(trim($response->body()), 0, 300) ?: 'no details';
    }

    /** Upload each file with $upload; failures become warnings, never a failed ticket. */
    protected function uploadAll(array $files, Closure $upload): array
    {
        $warnings = [];
        foreach ($files as $file) {
            try {
                $upload($file);
            } catch (TrackerException $e) {
                report($e);
                $warnings[] = $e->getMessage();
            }
        }

        return $warnings;
    }
}
