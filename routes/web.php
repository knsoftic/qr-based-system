<?php

use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\LogController;
use App\Http\Controllers\Admin\MerchantController;
use App\Http\Controllers\Admin\PaymentAccountController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SmsController;
use App\Http\Controllers\Admin\TransactionController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\Merchant\AuthController as MerchantAuthController;
use App\Http\Controllers\Merchant\PortalController;
use Illuminate\Support\Facades\Route;

// The public front door is the merchant login (signed-in merchants land on their dashboard).
Route::redirect('/', '/merchant/login');
Route::get('/docs', DocsController::class)->name('docs');

Route::middleware('throttle:checkout')->group(function () {
    Route::get('/checkout/{paymentId}', [CheckoutController::class, 'show'])->name('checkout.show');
    Route::get('/checkout/{paymentId}/status', [CheckoutController::class, 'status'])->name('checkout.status');
});

Route::prefix('merchant')->name('merchant.')->group(function () {
    Route::middleware('guest:merchant')->group(function () {
        Route::get('login', [MerchantAuthController::class, 'showLogin'])->name('login');
        Route::post('login', [MerchantAuthController::class, 'login'])->middleware('throttle:merchant-login')->name('login.attempt');
    });

    Route::middleware('merchant.portal')->group(function () {
        Route::post('logout', [MerchantAuthController::class, 'logout'])->name('logout');
        Route::get('/', [PortalController::class, 'dashboard'])->name('dashboard');
        Route::post('secret/reveal', [PortalController::class, 'revealSecret'])->middleware('throttle:merchant-sensitive')->name('secret.reveal');
        Route::post('secret/rotate', [PortalController::class, 'rotateSecret'])->middleware('throttle:merchant-sensitive')->name('secret.rotate');
        Route::get('test', [PortalController::class, 'testForm'])->name('test');
        Route::post('test', [PortalController::class, 'runTest'])->middleware('throttle:payin-test')->name('test.run');
        Route::get('verify', [PortalController::class, 'verifyForm'])->name('verify');
        Route::post('verify/credentials', [PortalController::class, 'verifyCredentials'])->middleware('throttle:merchant-sensitive')->name('verify.credentials');
        Route::post('verify/signature', [PortalController::class, 'verifySignature'])->middleware('throttle:merchant-sensitive')->name('verify.signature');
    });
});

Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('login', [AuthController::class, 'showLogin'])->name('login');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:admin-login')->name('login.attempt');
    });

    Route::middleware(['auth', 'admin.active'])->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/', DashboardController::class)->name('dashboard');

        Route::get('accounts', [PaymentAccountController::class, 'index'])->name('accounts.index');
        Route::get('accounts/create', [PaymentAccountController::class, 'create'])->name('accounts.create');
        Route::post('accounts', [PaymentAccountController::class, 'store'])->name('accounts.store');
        Route::get('accounts/{account}', [PaymentAccountController::class, 'show'])->name('accounts.show');
        Route::get('accounts/{account}/edit', [PaymentAccountController::class, 'edit'])->name('accounts.edit');
        Route::put('accounts/{account}', [PaymentAccountController::class, 'update'])->name('accounts.update');
        Route::post('accounts/{account}/qr', [PaymentAccountController::class, 'uploadQr'])->name('accounts.qr');
        Route::put('accounts/{account}/payload', [PaymentAccountController::class, 'updatePayload'])->name('accounts.payload');
        Route::post('accounts/{account}/toggle', [PaymentAccountController::class, 'toggle'])->name('accounts.toggle');

        Route::get('merchants', [MerchantController::class, 'index'])->name('merchants.index');
        Route::get('merchants/create', [MerchantController::class, 'create'])->name('merchants.create');
        Route::post('merchants', [MerchantController::class, 'store'])->name('merchants.store');
        Route::get('merchants/{merchant}', [MerchantController::class, 'show'])->name('merchants.show');
        Route::get('merchants/{merchant}/edit', [MerchantController::class, 'edit'])->name('merchants.edit');
        Route::put('merchants/{merchant}', [MerchantController::class, 'update'])->name('merchants.update');
        Route::post('merchants/{merchant}/rotate', [MerchantController::class, 'rotate'])->name('merchants.rotate');
        Route::post('merchants/{merchant}/toggle', [MerchantController::class, 'toggle'])->name('merchants.toggle');
        Route::post('merchants/{merchant}/portal-password', [MerchantController::class, 'resetPortalPassword'])->name('merchants.portal-password');
        Route::post('merchants/{merchant}/test-payin', [MerchantController::class, 'testPayIn'])->middleware('throttle:payin-test')->name('merchants.test');

        Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');
        Route::get('transactions/export', [TransactionController::class, 'export'])->name('transactions.export');
        Route::get('transactions/{payment}', [TransactionController::class, 'show'])->name('transactions.show');
        Route::post('transactions/{payment}/paid', [TransactionController::class, 'markPaid'])->name('transactions.paid');
        Route::post('transactions/{payment}/failed', [TransactionController::class, 'markFailed'])->name('transactions.failed');
        Route::post('transactions/{payment}/callback', [TransactionController::class, 'resendCallback'])->name('transactions.callback');

        Route::get('settings/{tab?}', [SettingsController::class, 'edit'])->name('settings.edit')->whereIn('tab', SettingsController::TABS);
        Route::put('settings/{tab}', [SettingsController::class, 'update'])->name('settings.update')->whereIn('tab', SettingsController::TABS);
        Route::post('settings/verification/key', [SettingsController::class, 'regenerateVerifierKey'])->name('settings.verifier-key');

        Route::get('reports', ReportController::class)->name('reports');

        Route::get('sms', [SmsController::class, 'index'])->name('sms.index');
        Route::get('sms/devices', [SmsController::class, 'devices'])->name('sms.devices');
        Route::post('sms/devices', [SmsController::class, 'storeDevice'])->name('sms.devices.store');
        Route::post('sms/devices/{device}/toggle', [SmsController::class, 'toggleDevice'])->name('sms.devices.toggle');
        Route::post('sms/devices/{device}/rotate', [SmsController::class, 'rotateDevice'])->name('sms.devices.rotate');
        Route::get('sms/app.apk', [SmsController::class, 'downloadApp'])->name('sms.app');

        Route::get('logs/audit', [LogController::class, 'audit'])->name('logs.audit');
        Route::get('logs/transactions', [LogController::class, 'events'])->name('logs.events');
        Route::get('logs/callbacks', [LogController::class, 'callbacks'])->name('logs.callbacks');

        Route::get('admins', [AdminUserController::class, 'index'])->name('admins.index');
        Route::post('admins', [AdminUserController::class, 'store'])->name('admins.store');
        Route::post('admins/{user}/toggle', [AdminUserController::class, 'toggle'])->name('admins.toggle');

        Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
    });
});
