<?php

use App\Http\Controllers\Admin\AgentCardController;
use App\Http\Controllers\Admin\AgentController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CoordinatorController;
use App\Http\Controllers\Admin\CorrectionController;
use App\Http\Controllers\Admin\IncidentController;
use App\Http\Controllers\Admin\MissingController;
use App\Http\Controllers\Admin\OverviewController;
use App\Http\Controllers\Admin\PollingUnitController;
use App\Http\Controllers\Admin\ResultController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SystemController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Middleware\AuthenticateAdmin;
use App\Http\Middleware\EnsureDatabaseReady;
use App\Http\Middleware\RequireAdminRole;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('login.attempt');
    Route::post('/setup', [AuthController::class, 'setup'])->middleware('throttle:5,1')->name('setup');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::middleware(AuthenticateAdmin::class)->group(function () {
        Route::get('/', OverviewController::class)->name('overview');
        Route::post('/account/password', [UserController::class, 'changeOwnPassword'])->name('account.password');

        // Admins only: set-up actions that also work before the database is ready.
        Route::middleware(RequireAdminRole::class)->group(function () {
            Route::post('/system/migrate', [SystemController::class, 'migrate'])->name('system.migrate');
            Route::post('/system/test-email', [SystemController::class, 'testEmail'])->name('system.test-email');
        });

        Route::middleware(EnsureDatabaseReady::class)->group(function () {
            // Everyone with an account: data, exports and correction review.
            Route::get('/results', [ResultController::class, 'index'])->name('results.index');
            Route::get('/results/export', [ResultController::class, 'export'])->name('results.export');
            Route::get('/results/export-collation', [ResultController::class, 'exportBreakdown'])->name('results.export-breakdown');
            Route::get('/results/{result:reference}', [ResultController::class, 'show'])->name('results.show');

            Route::get('/incidents', [IncidentController::class, 'index'])->name('incidents.index');
            Route::get('/incidents/export', [IncidentController::class, 'export'])->name('incidents.export');

            Route::get('/polling-units', [PollingUnitController::class, 'index'])->name('polling-units.index');
            Route::get('/polling-units/export', [PollingUnitController::class, 'export'])->name('polling-units.export');

            Route::get('/agents', [AgentController::class, 'index'])->name('agents.index');
            Route::get('/agents/export', [AgentController::class, 'export'])->name('agents.export');
            Route::get('/agents/cards', [AgentCardController::class, 'index'])->name('agents.cards');

            Route::get('/coordinators', [CoordinatorController::class, 'index'])->name('coordinators.index');

            Route::get('/corrections', [CorrectionController::class, 'index'])->name('corrections.index');
            Route::get('/corrections/export', [CorrectionController::class, 'export'])->name('corrections.export');
            Route::post('/corrections/{result:reference}/approve', [CorrectionController::class, 'approve'])->name('corrections.approve');
            Route::post('/corrections/{result:reference}/reject', [CorrectionController::class, 'reject'])->name('corrections.reject');

            Route::get('/missing', MissingController::class)->name('missing');

            // Admins only.
            Route::middleware(RequireAdminRole::class)->group(function () {
                Route::post('/system/polling-units', [SystemController::class, 'importPollingUnits'])->name('system.polling-units');
                Route::post('/system/summary', [SystemController::class, 'sendSummary'])->name('system.summary');
                Route::post('/system/jobs/run', [SystemController::class, 'runJobs'])->name('system.jobs.run');
                Route::post('/system/jobs/retry', [SystemController::class, 'retryFailedJobs'])->name('system.jobs.retry');

                Route::post('/agents', [AgentController::class, 'store'])->name('agents.store');
                Route::post('/agents/import', [AgentController::class, 'import'])->name('agents.import');
                Route::post('/agents/cards', [AgentCardController::class, 'withNewPins'])->name('agents.cards.pins');
                Route::post('/agents/{agent}/pin', [AgentController::class, 'resetPin'])->name('agents.pin');
                Route::delete('/agents/{agent}', [AgentController::class, 'destroy'])->name('agents.destroy');

                Route::post('/coordinators', [CoordinatorController::class, 'store'])->name('coordinators.store');
                Route::delete('/coordinators/{coordinator}', [CoordinatorController::class, 'destroy'])->name('coordinators.destroy');

                Route::get('/users', [UserController::class, 'index'])->name('users.index');
                Route::post('/users', [UserController::class, 'store'])->name('users.store');
                Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.update');
                Route::post('/users/{user}/password', [UserController::class, 'resetPassword'])->name('users.password');
                Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

                Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');
                Route::get('/audit/export', [AuditController::class, 'export'])->name('audit.export');

                Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
                Route::post('/settings/rehearsal', [SettingsController::class, 'rehearsal'])->name('settings.rehearsal');
                Route::post('/settings/clear-test-data', [SettingsController::class, 'clearTestData'])->name('settings.clear');
            });
        });
    });
});
