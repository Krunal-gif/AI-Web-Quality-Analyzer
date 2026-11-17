<?php

use App\Http\Controllers\AiController;
use App\Http\Controllers\AnalysisController;
use Illuminate\Support\Facades\Route;

/*
| JSON API for the React app. It lives in the web middleware group (not routes/api.php) on
| purpose: this local tool can read and write project files, so every POST is protected by
| Laravel's CSRF token and a random website can't drive it from the user's browser.
*/
Route::prefix('api')->group(function () {
    Route::get('analyses', [AnalysisController::class, 'index']);
    Route::post('analyses', [AnalysisController::class, 'store']);
    Route::delete('analyses', [AnalysisController::class, 'destroyAll']);
    Route::get('analyses/{id}', [AnalysisController::class, 'show']);
    Route::delete('analyses/{id}', [AnalysisController::class, 'destroy']);
    Route::patch('analyses/{id}/issues/{issue}', [AnalysisController::class, 'updateIssue']);
    Route::post('analyses/{id}/issues/{issue}/write', [AnalysisController::class, 'write']);
    Route::post('analyses/{id}/bulk', [AnalysisController::class, 'bulk']);
    Route::post('analyses/{id}/apply', [AnalysisController::class, 'apply']);
    Route::post('analyses/{id}/ai', [AiController::class, 'review']);
    Route::post('analyses/{id}/ai/cancel', [AiController::class, 'cancel']);
    Route::get('ai/status', [AiController::class, 'status']);
    Route::post('ai/settings', [AiController::class, 'settings']);
});

Route::get('analyses/{id}/report', [AnalysisController::class, 'report'])->name('report');
Route::get('analyses/{id}/report.json', [AnalysisController::class, 'reportJson']);
Route::get('analyses/{id}/report.html', [AnalysisController::class, 'reportHtml']);
Route::get('analyses/{id}/changes.patch', [AnalysisController::class, 'patch']);

// React single-page app (deep links like /a/{id} reopen a saved review; /history lists saved analyses)
Route::view('/', 'app');
Route::view('/history', 'app');
Route::view('/a/{id}', 'app')->where('id', '[\w.-]+');
