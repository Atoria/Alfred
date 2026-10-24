<?php

use Atoria\Alfred\Http\Controllers\AccessController;
use Atoria\Alfred\Http\Controllers\AssetController;
use Atoria\Alfred\Http\Controllers\ChangeController;
use Atoria\Alfred\Http\Controllers\ExplainController;
use Atoria\Alfred\Http\Controllers\PullRequestController;
use Atoria\Alfred\Http\Controllers\TaskController;
use Atoria\Alfred\Http\Controllers\TicketController;
use Atoria\Alfred\Http\Middleware\ResolveTenant;
use Illuminate\Support\Facades\Route;

Route::group([
    'prefix' => config('alfred.path', '_alfred'),
    // ResolveTenant first: the tenant has to be known before the session and auth run.
    'middleware' => array_merge([ResolveTenant::class], (array) config('alfred.middleware', ['web'])),
    'as' => 'alfred.',
], function () {
    Route::get('alfred.js', [AssetController::class, 'script'])->name('script');

    // Way out of a preview that broke the page (works without the overlay).
    Route::get('preview/stop', [ChangeController::class, 'stopPreview'])->name('preview.stop');

    // A fresh CSRF token for the overlay when the session's one changed (419).
    Route::get('csrf', [AssetController::class, 'csrf'])->name('csrf');

    // Long AI work answers with a task id; the overlay checks back here.
    Route::get('tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');

    // Private access link for signed-out visitors (alfred.guests).
    Route::get('access', [AccessController::class, 'unlock'])->middleware('throttle:10,1')->name('access');
    Route::get('access/forget', [AccessController::class, 'forget'])->name('access.forget');

    // Explain (cache-first). With force=true (verifiers only) it regenerates.
    Route::post('explain', [ExplainController::class, 'explain'])->name('explain');
    Route::post('explanations/{explanation}/records', [ExplainController::class, 'records'])->name('records');
    Route::post('explanations/{explanation}/verify', [ExplainController::class, 'verify'])->name('verify');
    Route::put('explanations/{explanation}', [ExplainController::class, 'update'])->name('update');
    Route::post('explanations/{explanation}/ask', [ExplainController::class, 'ask'])->name('ask');
    Route::post('explanations/{explanation}/keep', [ExplainController::class, 'keep'])->name('keep');
    Route::get('explanations/{explanation}/history', [ExplainController::class, 'history'])->name('history');
    Route::post('explanations/{explanation}/history/{version}/restore', [ExplainController::class, 'restore'])->whereNumber('version')->name('restore');

    // "Change this" (alfred.edit.*)
    Route::get('changes', [ChangeController::class, 'index'])->name('changes.index');
    Route::post('changes', [ChangeController::class, 'store'])->name('changes.store');
    Route::post('requests', [ChangeController::class, 'report'])->name('requests.store');
    Route::get('changes/{change}', [ChangeController::class, 'show'])->name('changes.show');
    Route::post('changes/{change}/apply', [ChangeController::class, 'apply'])->name('changes.apply');
    Route::post('changes/{change}/revert', [ChangeController::class, 'revert'])->name('changes.revert');
    Route::post('changes/{change}/discard', [ChangeController::class, 'discard'])->name('changes.discard');
    Route::post('changes/{change}/refine', [ChangeController::class, 'refine'])->name('changes.refine');
    Route::get('changes/{change}/patch', [ChangeController::class, 'patch'])->name('changes.patch');
    Route::post('changes/{change}/preview', [ChangeController::class, 'preview'])->name('changes.preview');

    // Screenshots + Jira tickets for a change
    Route::post('changes/{change}/screenshots', [TicketController::class, 'storeScreenshot'])->name('changes.screenshots.store');
    Route::get('changes/{change}/screenshots/{kind}', [TicketController::class, 'showScreenshot'])->whereIn('kind', ['before', 'after'])->name('changes.screenshots.show');
    Route::get('changes/{change}/ticket', [TicketController::class, 'draft'])->name('changes.ticket.draft');
    Route::post('changes/{change}/ticket', [TicketController::class, 'create'])->name('changes.ticket.create');

    // Pull requests for a change
    Route::get('changes/{change}/pull-request', [PullRequestController::class, 'draft'])->name('changes.pull-request.draft');
    Route::post('changes/{change}/pull-request', [PullRequestController::class, 'store'])->name('changes.pull-request.store');

    Route::get('vendor/html-to-image.js', [AssetController::class, 'screenshotLibrary'])->name('screenshot-library');
});
