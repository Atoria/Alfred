<?php

namespace Atoria\Alfred\Http\Controllers;

use Atoria\Alfred\Alfred;
use Atoria\Alfred\Tasks\Background;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class TaskController extends Controller
{
    public function show(Request $request, string $task, Alfred $alfred, Background $background): JsonResponse
    {
        abort_unless($alfred->canUse($alfred->user($request)), 403);

        return $background->result($request, $task);
    }
}
