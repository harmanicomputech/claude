<?php

use App\Http\Controllers\Api\AgentAccountController;
use App\Http\Controllers\Api\CorrectionController;
use App\Http\Controllers\Api\DataController;
use App\Http\Controllers\Api\FieldController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\UssdController;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\VerifyUssdRequest;
use Illuminate\Support\Facades\Route;

// Africa's Talking USSD callback. With USSD_CALLBACK_SECRET set, register
// https://your-domain/api/ussd/{secret} as the callback URL.
Route::post('/ussd/{secret?}', UssdController::class)
    ->middleware(VerifyUssdRequest::class)
    ->name('ussd');

// Coordinator and web-app API (Authorization: Bearer {ELECTION_API_TOKEN}).
Route::middleware(AuthenticateApiToken::class)->group(function () {
    Route::get('/corrections', [CorrectionController::class, 'index']);
    Route::post('/corrections/{reference}/approve', [CorrectionController::class, 'approve']);
    Route::post('/corrections/{reference}/reject', [CorrectionController::class, 'reject']);

    // Read API for the Election Shield web app (see docs/WEB-APP-HANDOFF.md).
    Route::get('/results', [DataController::class, 'results']);
    Route::get('/results/{reference}', [DataController::class, 'result']);
    Route::get('/incidents', [DataController::class, 'incidents']);
    Route::get('/presences', [DataController::class, 'presences']);
    Route::get('/materials', [DataController::class, 'materials']);
    Route::get('/polling-units', [DataController::class, 'pollingUnits']);
    Route::get('/agents', [DataController::class, 'agents']);

    // Agent accounts and agents' submissions from the web app (channel "web").
    Route::post('/agents', [AgentAccountController::class, 'store']);
    Route::post('/agents/reset-pin', [AgentAccountController::class, 'resetPin']);
    Route::post('/agents/verify-pin', [AgentAccountController::class, 'verifyPin'])->middleware('throttle:30,1');
    Route::post('/field/results', [FieldController::class, 'result']);
    Route::post('/field/incidents', [FieldController::class, 'incident']);
    Route::post('/field/presence', [FieldController::class, 'presence']);
    Route::post('/field/materials', [FieldController::class, 'materials']);

    Route::get('/reports/summary', [ReportController::class, 'summary']);
    Route::get('/reports/missing', [ReportController::class, 'missing']);
});
