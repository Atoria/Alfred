<?php

namespace Atoria\Alfred\Http\Controllers;

use Atoria\Alfred\Alfred;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

class AssetController extends Controller
{
    public function script(Request $request, Alfred $alfred): Response
    {
        abort_unless($alfred->canUse($alfred->user($request)), 404);

        return $this->serve($request, dirname(__DIR__, 3).'/resources/js/alfred.js');
    }

    /** The current session's CSRF token, so the overlay can recover from "419 page expired" without a reload. */
    public function csrf(Request $request, Alfred $alfred): JsonResponse
    {
        abort_unless($request->hasSession() && $alfred->canUse($alfred->user($request)), 403);

        return response()->json(['csrf' => csrf_token()])->header('Cache-Control', 'no-store');
    }

    /** Bundled html-to-image (MIT), loaded on demand for screenshots. */
    public function screenshotLibrary(Request $request, Alfred $alfred): Response
    {
        abort_unless($alfred->canUse($alfred->user($request)) && $alfred->canTicket($alfred->user($request)), 404);

        return $this->serve($request, dirname(__DIR__, 3).'/resources/js/vendor/html-to-image.js');
    }

    protected function serve(Request $request, string $path): Response
    {
        $hash = Alfred::fileVersion($path);
        $etag = '"'.$hash.'"';
        // A URL carrying the current version never changes: browsers keep it until the file does.
        $cache = $request->query('v') === $hash ? 'private, max-age=31536000, immutable' : 'private, max-age=3600';

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304)->header('ETag', $etag)->header('Cache-Control', $cache);
        }

        return response((string) file_get_contents($path), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => $cache,
            'ETag' => $etag,
        ]);
    }
}
