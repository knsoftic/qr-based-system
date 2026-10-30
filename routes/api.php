<?php

use App\Http\Controllers\Api\BalanceController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PayoutController;
use App\Http\Controllers\Api\SmsController;
use App\Http\Controllers\Api\VerificationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::middleware(['throttle:merchant-api', 'merchant.auth'])->group(function () {
        Route::post('payments', [PaymentController::class, 'store']);
        Route::get('payments/{paymentId}', [PaymentController::class, 'show']);

        Route::get('balance', [BalanceController::class, 'show']);
        Route::post('payouts', [PayoutController::class, 'store']);
        Route::get('payouts', [PayoutController::class, 'index']);
        Route::get('payouts/{ref}', [PayoutController::class, 'show']);
    });

    Route::post('verifications', [VerificationController::class, 'store'])
        ->middleware(['throttle:verifier-api', 'verifier.auth']);

    // Android SMS forwarder app.
    Route::middleware(['throttle:sms-device', 'sms.device'])->group(function () {
        Route::post('sms', [SmsController::class, 'store']);
        Route::post('sms/ping', [SmsController::class, 'ping']);
    });
});
