<?php

use App\Http\Controllers\Api\CorrectionController;
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

// Coordinator API (Authorization: Bearer {ELECTION_API_TOKEN}).
Route::middleware(AuthenticateApiToken::class)->group(function () {
    Route::get('/corrections', [CorrectionController::class, 'index']);
    Route::post('/corrections/{reference}/approve', [CorrectionController::class, 'approve']);
    Route::post('/corrections/{reference}/reject', [CorrectionController::class, 'reject']);

    Route::get('/reports/summary', [ReportController::class, 'summary']);
    Route::get('/reports/missing', [ReportController::class, 'missing']);
});
