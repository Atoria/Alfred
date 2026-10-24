<?php

namespace Atoria\Alfred\Security;

use Atoria\Alfred\Alfred;
use Atoria\Alfred\Events\SuspiciousRequestBlocked;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Runs RequestScreen on what a person typed; if refused, records it and answers 422. */
class Screening
{
    public function __construct(protected RequestScreen $screen, protected Alfred $alfred) {}

    public function refuse(Request $request, string $text, string $where): ?JsonResponse
    {
        $verdict = $this->screen->check($text);
        if ($verdict === null) {
            return null;
        }

        $this->record($request, $text, $where, $verdict);

        return response()->json([
            'message' => Alfred::name()." can't help with that: ".$verdict['reason'].'. '.Alfred::name().' only answers about this page in your own workspace, as you.',
            'blocked' => $verdict['category'],
        ], 422);
    }

    /** @param  array{category: string, reason: string, match: string}  $verdict */
    protected function record(Request $request, string $text, string $where, array $verdict): void
    {
        try {
            $user = $this->alfred->user($request);
            $event = SecurityEvent::query()->create([
                'user_key' => $this->alfred->userKey($request),
                'user_label' => $user ? (string) ($user->email ?? $user->getAuthIdentifier()) : 'A signed-out visitor',
                'category' => $verdict['category'],
                'where' => $where,
                'excerpt' => mb_substr($text, 0, 1000),
                'matched' => $verdict['match'],
                'route' => mb_substr((string) $request->headers->get('referer', ''), 0, 255) ?: null,
                'ip_hash' => hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')),
                'created_at' => now(),
            ]);

            SuspiciousRequestBlocked::dispatch($event);
        } catch (Throwable $e) {
            report($e); // recording must never turn a refusal into an error page

            return;
        }

        try {
            Log::channel(config('alfred.screening.log_channel'))->warning('Alfred refused a request: '.$verdict['category'], [
                'where' => $where,
                'user' => $event->user_label,
                'tenant' => $event->tenant,
                'matched' => $verdict['match'],
                'security_event' => $event->id,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
