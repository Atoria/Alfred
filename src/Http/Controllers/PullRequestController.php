<?php

namespace Atoria\Alfred\Http\Controllers;

use Atoria\Alfred\Alfred;
use Atoria\Alfred\Changes\Change;
use Atoria\Alfred\PullRequests\PullRequestBuilder;
use Atoria\Alfred\PullRequests\PullRequestException;
use Atoria\Alfred\PullRequests\PullRequestOpener;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Opening a pull request (GitHub, GitLab, Bitbucket, …) with a change's edits. */
class PullRequestController extends Controller
{
    public function __construct(protected Alfred $alfred, protected PullRequestOpener $opener) {}

    public function draft(Request $request, Change $change, PullRequestBuilder $builder): JsonResponse
    {
        $this->authorizeChange($request, $change);

        return response()->json([
            'title' => $builder->defaultTitle($change),
            'branch' => $builder->branch($change),
            'providers' => $this->opener->choices(),
            'can_open' => $this->opener->eligible($change),
            'pull_request' => $this->opener->info($change),
        ]);
    }

    public function store(Request $request, Change $change): JsonResponse
    {
        $this->authorizeChange($request, $change);
        $data = $request->validate([
            'provider' => ['sometimes', 'nullable', 'string', 'max:40'],
            'title' => ['sometimes', 'nullable', 'string', 'max:250'],
        ]);

        if ($change->pr_key) {
            return response()->json(['message' => "A pull request already exists: {$change->pr_key}."] + $this->opener->info($change), 409);
        }

        try {
            $result = $this->opener->open($change, $data['provider'] ?? null, $data['title'] ?? null, $this->who($request));
        } catch (PullRequestException $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], $this->opener->choices() === [] ? 422 : 502);
        }

        return response()->json($result, 201);
    }

    protected function authorizeChange(Request $request, Change $change): void
    {
        // Pull requests carry code, so only people who may change the UI can open them.
        abort_unless($this->alfred->canEdit($this->alfred->user($request)), 403);
    }

    protected function who(Request $request): ?string
    {
        $user = $this->alfred->user($request);

        return $user ? (string) ($user->email ?? $user->getAuthIdentifier()) : null;
    }
}
