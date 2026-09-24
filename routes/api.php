<?php

use App\Http\Controllers\UssdController;
use Illuminate\Support\Facades\Route;

// Set this as the USSD callback URL in the Africa's Talking dashboard.
Route::post('/ussd', UssdController::class)->name('ussd');
