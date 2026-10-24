<?php

namespace Atoria\Alfred\Http\Middleware;

use Atoria\Alfred\Access\Guests;
use Atoria\Alfred\Alfred;
use Atoria\Alfred\Context\ContextStore;
use Atoria\Alfred\Context\RequestRecorder;
use Atoria\Alfred\Preview\Preview;
use Atoria\Alfred\Preview\PreviewFailure;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Global middleware. Records page views and their Livewire follow-ups, then
 * — once the user is known — keeps the recording and injects the overlay
 * for users who pass the "useAlfred" gate.
 */
class RecordAndInject
{
    public function __construct(
        protected Alfred $alfred,
        protected RequestRecorder $recorder,
        protected Preview $preview,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->alfred->enabled() || $this->alfred->isOwnRequest($request)) {
            return $next($request);
        }

        // Normally already done in the service provider; this covers apps that
        // build the request later (tests, custom kernels).
        $this->preview->activate($request);

        $isLivewire = $request->hasHeader('X-Livewire');
        $token = $request->header('X-Alfred-Token');

        if ($isLivewire && ContextStore::validToken($token)) {
            $this->recorder->continue($token, $request);
        } elseif (! $isLivewire && $request->isMethod('GET') && ! $request->expectsJson() && $this->mayBeSomeone($request)) {
            $this->recorder->start($request);
        }

        $response = $next($request);

        // A preview only for the person it was issued to, while they may still edit.
        // Requests without a session (asset routes such as Livewire's script)
        // can't tell who is asking; the preview token is already bound to this
        // browser's session, so those are left alone. Anyone else carrying the
        // cookie gets the normal page (pages) or just loses the cookie (other requests).
        if ($this->preview->active() && $request->hasSession() && ! $this->previewAllowed($request)) {
            report(new \RuntimeException('Alfred preview cookie used by someone it was not issued to; preview stopped.'));
            $this->recorder->stop();
            $forget = $this->preview->forgetCookie($request);

            if ($request->isMethod('GET') && ! $request->expectsJson()) {
                return redirect()->to('/'.ltrim($request->path(), '/'))->withCookie($forget);
            }
            $response->headers->setCookie($forget);

            return $response;
        }

        // A previewed change that made the page fail without an exception (e.g. a 500 response).
        if ($this->preview->active() && $response->getStatusCode() >= 500 && ! $response->headers->has('X-Alfred-Preview-Failed')) {
            $this->recorder->stop();

            return PreviewFailure::response($request, $this->preview, null, $response->getStatusCode());
        }

        if (! $this->recorder->active()) {
            return $response;
        }

        try {
            if (! $this->alfred->canUse($this->alfred->user($request))) {
                return $response;
            }

            $this->recorder->captureRoute($request);

            if ($this->recorder->isContinuation()) {
                $this->recorder->persist($this->alfred->userKey($request));

                return $response;
            }

            if (! $this->isInjectable($request, $response)) {
                return $response;
            }

            $token = $this->recorder->persist($this->alfred->userKey($request));

            if ($token && config('alfred.inject', true) && ! $this->excepted($request)) {
                $this->inject($request, $response, $token);
            }
        } catch (Throwable $e) {
            report($e); // Alfred must never break the host page
        } finally {
            $this->recorder->stop();
        }

        return $response;
    }

    /**
     * A visitor with no session and no "remember me" cookie can't be signed in,
     * so unless guests may use Alfred there's nothing worth recording.
     */
    protected function mayBeSomeone(Request $request): bool
    {
        if (app(Guests::class)->enabled()) {
            return true;
        }
        if ($request->cookies->has((string) config('session.cookie')) || $request->bearerToken() !== null) {
            return true;
        }
        foreach (array_keys($request->cookies->all()) as $name) {
            if (str_starts_with((string) $name, 'remember_')) {
                return true;
            }
        }

        return false;
    }

    protected function previewAllowed(Request $request): bool
    {
        try {
            $user = $this->alfred->user($request);

            return $this->preview->tokenUser() === $this->alfred->userKey($request) && $this->alfred->canEdit($user);
        } catch (Throwable) {
            return false;
        }
    }

    protected function isInjectable(Request $request, Response $response): bool
    {
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return false;
        }
        if ($response->isRedirection() || $response->getStatusCode() >= 400) {
            return false;
        }

        $type = (string) $response->headers->get('Content-Type', 'text/html');

        return $type === '' || str_contains($type, 'text/html');
    }

    protected function excepted(Request $request): bool
    {
        foreach ((array) config('alfred.except', []) as $pattern) {
            if (Str::is(trim($pattern, '/'), trim($request->path(), '/'))) {
                return true;
            }
        }

        return false;
    }

    protected function inject(Request $request, Response $response, string $token): void
    {
        $content = $response->getContent();

        if (! is_string($content) || str_contains($content, 'data-alfred=')) {
            return; // already placed with @alfred, or not a string body
        }

        $pos = strripos($content, '</body>');
        if ($pos === false) {
            return;
        }

        $tag = (string) $this->alfred->scriptTag($request, $token);
        $response->setContent(substr($content, 0, $pos).$tag.substr($content, $pos));
        $response->headers->remove('Content-Length');
    }
}
