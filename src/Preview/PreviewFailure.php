<?php

namespace Atoria\Alfred\Preview;

use Atoria\Alfred\Alfred;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * A previewed change can break the page (a missing icon, a typo in Blade, a
 * fatal error…). Then the overlay never loads, so its "Stop" button is gone.
 * Instead of the error page, the person previewing gets this page: what went
 * wrong, and a one-click way out. Everyone else is unaffected anyway.
 */
class PreviewFailure
{
    public static function response(Request $request, Preview $preview, ?Throwable $e = null, ?int $status = null): Response
    {
        $change = $preview->active();
        $prefix = '/'.trim((string) config('alfred.path', '_alfred'), '/');
        $back = $request->getRequestUri() ?: '/';
        $stop = url($prefix.'/preview/stop').'?to='.rawurlencode($back);
        $name = Alfred::name();
        $status = $status ?? 500;

        $message = $e ? class_basename($e).': '.$e->getMessage() : 'The page answered with error '.$status.'.';
        $where = $e ? self::where($e) : null;

        if ($change) {
            PreviewErrors::put((int) $change->id, $message.($where ? ' '.$where : '').' (on '.$request->path().')');
        }

        // Livewire shows error responses in a modal, so it gets the page (with a link that leaves the modal).
        if ($request->expectsJson() && ! $request->hasHeader('X-Livewire')) {
            return response()->json([
                'message' => 'The change you are previewing broke this request: '.$message.' Stop the preview to get the page back.',
                'alfred_preview_failed' => true,
                'stop_url' => $stop,
            ], 500)->header('X-Alfred-Preview-Failed', '1');
        }

        $summary = e($change?->summary ?: 'a proposed change');
        $html = '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>Preview failed · '.e($name).'</title>'
            .'<body style="margin:0;font:15px/1.55 ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f5f5f7;color:#1d1d1f">'
            .'<main style="max-width:40rem;margin:12vh auto;padding:0 20px">'
            .'<div style="background:#fff;border:1px solid #e4e4e8;border-radius:14px;padding:28px;box-shadow:0 12px 40px rgba(0,0,0,.08)">'
            .'<p style="margin:0 0 6px;font-size:13px;color:#a15c00;font-weight:600">'.e($name).' preview</p>'
            .'<h1 style="margin:0 0 10px;font-size:22px;line-height:1.3">The change you are previewing broke this page</h1>'
            .'<p style="margin:0 0 16px;color:#4b4b55">You are previewing <strong>'.$summary.'</strong>'.($change ? ' (change #'.$change->id.')' : '').'. Only you see this. The code and everyone else are unaffected.</p>'
            .'<pre style="white-space:pre-wrap;word-break:break-word;background:#fff4e0;color:#7a4500;border-radius:8px;padding:12px;font:12.5px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace;margin:0 0 20px">'.e($message).($where ? "\n".e($where) : '').'</pre>'
            .'<a href="'.e($stop).'" target="_top" style="display:inline-block;background:#3b5bdb;color:#fff;text-decoration:none;font-weight:600;padding:10px 18px;border-radius:9px">Stop preview and reload</a>'
            .'<p style="margin:16px 0 0;font-size:13px;color:#6b6b73">Then open the change from '.e($name).' (Changes) and click <em>Refine</em>: '.e($name).' already knows about this error and can fix it.</p>'
            .'</div></main></body></html>';

        return response($html, $status >= 500 ? $status : 500)->header('X-Alfred-Preview-Failed', '1');
    }

    /** The first frame in a previewed (temporary) file or the app, for a hint where it broke. */
    protected static function where(Throwable $e): ?string
    {
        $root = str_replace('\\', '/', Preview::root());
        $base = str_replace('\\', '/', (string) (config('alfred.base_path') ?: base_path()));

        foreach (array_merge([['file' => $e->getFile(), 'line' => $e->getLine()]], $e->getTrace()) as $frame) {
            $file = str_replace('\\', '/', (string) ($frame['file'] ?? ''));
            if ($file === '' || str_contains($file, '/vendor/')) {
                continue;
            }
            if (str_starts_with($file, $root.'/')) {
                // storage/framework/alfred-preview/{id}-{hash}/resources/views/x.blade.php → resources/views/x.blade.php
                $file = preg_replace('#^[^/]+/#', '', substr($file, strlen($root) + 1)).' (previewed copy)';
            } elseif (str_starts_with($file, $base.'/')) {
                $file = substr($file, strlen($base) + 1);
            }

            return 'at '.$file.(isset($frame['line']) ? ':'.$frame['line'] : '');
        }

        return null;
    }
}
