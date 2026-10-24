<?php

namespace Atoria\Alfred\PullRequests\Providers;

use Atoria\Alfred\PullRequests\PullRequestException;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

abstract class BaseGitProvider implements GitProvider
{
    /** Required config keys under alfred.git.{name}. */
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

    public function noun(): string
    {
        return 'pull request';
    }

    protected function config(string $key, mixed $default = null): mixed
    {
        return config("alfred.git.{$this->name()}.{$key}", $default);
    }

    /** Run an HTTP call, turning network errors and error responses into PullRequestException. */
    protected function call(string $what, Closure $request, array $okStatuses = []): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            throw new PullRequestException("Could not reach {$this->label()}: ".$e->getMessage(), 0, $e);
        }

        if ($response->failed() && ! in_array($response->status(), $okStatuses, true)) {
            $hint = in_array($response->status(), [401, 403], true) ? ' Check that the token can write to the repository and open '.$this->noun().'s.' : '';
            throw new PullRequestException("{$this->label()} refused {$what} ({$response->status()}): ".$this->errorText($response).$hint);
        }

        return $response;
    }

    protected function errorText(Response $response): string
    {
        $json = $response->json();
        if (is_array($json)) {
            foreach (['message', 'error', 'errors'] as $key) {
                if (! empty($json[$key])) {
                    $v = $json[$key];
                    if (is_array($v) && isset($v['message'])) {
                        return (string) $v['message'];
                    }

                    return is_string($v) ? $v : mb_substr((string) json_encode($v), 0, 300);
                }
            }
        }

        return mb_substr(trim($response->body()), 0, 300) ?: 'no details';
    }

    protected function missingFile(string $path): PullRequestException
    {
        return new PullRequestException("{$path} is not in the repository's base branch. If your app lives in a subfolder of the repository, set ALFRED_PR_PATH_PREFIX.");
    }
}
