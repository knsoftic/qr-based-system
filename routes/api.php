<?php

use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\VerificationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::middleware(['throttle:merchant-api', 'merchant.auth'])->group(function () {
        Route::post('payments', [PaymentController::class, 'store']);
        Route::get('payments/{paymentId}', [PaymentController::class, 'show']);
    });

    Route::post('verifications', [VerificationController::class, 'store'])
        ->middleware(['throttle:verifier-api', 'verifier.auth']);
});
