<?php

namespace Atoria\Alfred\Http\Controllers;

use Atoria\Alfred\Access\Guests;
use Atoria\Alfred\Alfred;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * The private access link for signed-out visitors:
 *   /_alfred/access?key=<ALFRED_ACCESS_KEY>[&to=/login]
 * Opening it once leaves a signed cookie in that browser.
 */
class AccessController extends Controller
{
    public function __construct(protected Guests $guests) {}

    public function unlock(Request $request): Response
    {
        abort_unless($this->guests->enabled() && $this->guests->environmentAllowed() && $this->guests->key() !== '', 404);

        $key = $request->query('key');
        if (! is_string($key) || ! $this->guests->check($key)) {
            return response($this->page('That access link is not valid.', 'Ask whoever sent it for a fresh one.'), 403);
        }

        return redirect()->to($this->destination($request))->withCookie($this->guests->cookie($request));
    }

    public function forget(Request $request): Response
    {
        return redirect()->to($this->destination($request))->withCookie($this->guests->forgetCookie($request));
    }

    /** Only paths on this site, so the link can't be used to bounce people elsewhere. */
    protected function destination(Request $request): string
    {
        $to = $request->query('to');
        if (! is_string($to) || $to === '' || ! str_starts_with($to, '/') || str_starts_with($to, '//') || str_contains($to, '\\')) {
            return '/';
        }

        return $to;
    }

    protected function page(string $title, string $text): string
    {
        $name = e(Alfred::name());

        return '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$name.'</title>'
            .'<body style="font:15px/1.5 system-ui,sans-serif;max-width:28rem;margin:15vh auto;padding:0 1rem;color:#1f2937">'
            .'<h1 style="font-size:1.25rem">'.e($title).'</h1><p>'.e($text).'</p></body>';
    }
}
