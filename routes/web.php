<?php

use App\Http\Controllers\Admin\AgentController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CoordinatorController;
use App\Http\Controllers\Admin\CorrectionController;
use App\Http\Controllers\Admin\MissingController;
use App\Http\Controllers\Admin\OverviewController;
use App\Http\Controllers\Admin\SystemController;
use App\Http\Middleware\AuthenticateAdmin;
use App\Http\Middleware\EnsureDatabaseReady;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// Admin console (enabled by ADMIN_PASSWORD).
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('login.attempt');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::middleware(AuthenticateAdmin::class)->group(function () {
        Route::get('/', OverviewController::class)->name('overview');

        Route::post('/system/migrate', [SystemController::class, 'migrate'])->name('system.migrate');
        Route::post('/system/test-email', [SystemController::class, 'testEmail'])->name('system.test-email');

        Route::middleware(EnsureDatabaseReady::class)->group(function () {
            Route::post('/system/polling-units', [SystemController::class, 'importPollingUnits'])->name('system.polling-units');
            Route::post('/system/summary', [SystemController::class, 'sendSummary'])->name('system.summary');

            Route::get('/agents', [AgentController::class, 'index'])->name('agents.index');
            Route::post('/agents', [AgentController::class, 'store'])->name('agents.store');
            Route::post('/agents/import', [AgentController::class, 'import'])->name('agents.import');
            Route::post('/agents/{agent}/pin', [AgentController::class, 'resetPin'])->name('agents.pin');
            Route::delete('/agents/{agent}', [AgentController::class, 'destroy'])->name('agents.destroy');

            Route::get('/coordinators', [CoordinatorController::class, 'index'])->name('coordinators.index');
            Route::post('/coordinators', [CoordinatorController::class, 'store'])->name('coordinators.store');
            Route::delete('/coordinators/{coordinator}', [CoordinatorController::class, 'destroy'])->name('coordinators.destroy');

            Route::get('/corrections', [CorrectionController::class, 'index'])->name('corrections.index');
            Route::post('/corrections/{result:reference}/approve', [CorrectionController::class, 'approve'])->name('corrections.approve');
            Route::post('/corrections/{result:reference}/reject', [CorrectionController::class, 'reject'])->name('corrections.reject');

            Route::get('/missing', MissingController::class)->name('missing');
        });
    });
});
