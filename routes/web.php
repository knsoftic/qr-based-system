<?php

use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CheckoutController as AdminCheckoutController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\LogController;
use App\Http\Controllers\Admin\MerchantController;
use App\Http\Controllers\Admin\PaymentAccountController;
use App\Http\Controllers\Admin\PayoutController;
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
    Route::post('/checkout/{paymentId}/phone', [CheckoutController::class, 'savePhone'])->name('checkout.phone');
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
        Route::get('payouts', [PortalController::class, 'payouts'])->name('payouts');
        Route::post('payouts', [PortalController::class, 'requestPayout'])->middleware('throttle:merchant-sensitive')->name('payouts.request');
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

        Route::get('accounts', [PaymentAccountController::class, 'index'])->middleware('perm:accounts.view')->name('accounts.index');
        Route::post('accounts/channels/maintenance', [PaymentAccountController::class, 'channelMaintenance'])->middleware('perm:accounts.manage')->name('accounts.channels.maintenance');
        Route::get('accounts/create', [PaymentAccountController::class, 'create'])->middleware('perm:accounts.create')->name('accounts.create');
        Route::post('accounts', [PaymentAccountController::class, 'store'])->middleware('perm:accounts.create')->name('accounts.store');
        Route::get('accounts/{account}', [PaymentAccountController::class, 'show'])->middleware('perm:accounts.view')->name('accounts.show');
        Route::get('accounts/{account}/edit', [PaymentAccountController::class, 'edit'])->middleware('perm:accounts.manage')->name('accounts.edit');
        Route::put('accounts/{account}', [PaymentAccountController::class, 'update'])->middleware('perm:accounts.manage')->name('accounts.update');
        Route::post('accounts/{account}/qr', [PaymentAccountController::class, 'uploadQr'])->middleware('perm:accounts.create')->name('accounts.qr');
        Route::put('accounts/{account}/payload', [PaymentAccountController::class, 'updatePayload'])->middleware('perm:accounts.manage')->name('accounts.payload');
        Route::post('accounts/{account}/toggle', [PaymentAccountController::class, 'toggle'])->middleware('perm:accounts.manage')->name('accounts.toggle');
        Route::post('accounts/{account}/maintenance', [PaymentAccountController::class, 'maintenance'])->middleware('perm:accounts.manage')->name('accounts.maintenance');
        Route::delete('accounts/{account}', [PaymentAccountController::class, 'destroy'])->middleware('perm:accounts.delete')->name('accounts.destroy');

        Route::get('merchants', [MerchantController::class, 'index'])->middleware('perm:merchants.view')->name('merchants.index');
        Route::get('merchants/create', [MerchantController::class, 'create'])->middleware('perm:merchants.manage')->name('merchants.create');
        Route::post('merchants', [MerchantController::class, 'store'])->middleware('perm:merchants.manage')->name('merchants.store');
        Route::get('merchants/{merchant}', [MerchantController::class, 'show'])->middleware('perm:merchants.view')->name('merchants.show');
        Route::get('merchants/{merchant}/edit', [MerchantController::class, 'edit'])->middleware('perm:merchants.manage')->name('merchants.edit');
        Route::put('merchants/{merchant}', [MerchantController::class, 'update'])->middleware('perm:merchants.manage')->name('merchants.update');
        Route::post('merchants/{merchant}/rotate', [MerchantController::class, 'rotate'])->middleware('perm:merchants.manage')->name('merchants.rotate');
        Route::post('merchants/{merchant}/toggle', [MerchantController::class, 'toggle'])->middleware('perm:merchants.manage')->name('merchants.toggle');
        Route::post('merchants/{merchant}/portal-password', [MerchantController::class, 'resetPortalPassword'])->middleware('perm:merchants.manage')->name('merchants.portal-password');
        Route::post('merchants/{merchant}/test-payin', [MerchantController::class, 'testPayIn'])->middleware(['throttle:payin-test', 'perm:merchants.manage'])->name('merchants.test');

        Route::get('transactions', [TransactionController::class, 'index'])->middleware('perm:transactions.view')->name('transactions.index');
        Route::get('transactions/export', [TransactionController::class, 'export'])->middleware('perm:transactions.view')->name('transactions.export');
        Route::get('transactions/{payment}', [TransactionController::class, 'show'])->middleware('perm:transactions.view')->name('transactions.show');
        Route::post('transactions/{payment}/paid', [TransactionController::class, 'markPaid'])->middleware('perm:transactions.manage')->name('transactions.paid');
        Route::post('transactions/{payment}/failed', [TransactionController::class, 'markFailed'])->middleware('perm:transactions.manage')->name('transactions.failed');
        Route::post('transactions/{payment}/callback', [TransactionController::class, 'resendCallback'])->middleware('perm:callbacks.resend')->name('transactions.callback');

        Route::get('settings/{tab?}', [SettingsController::class, 'edit'])->middleware('perm:settings.manage')->name('settings.edit')->whereIn('tab', SettingsController::TABS);
        Route::put('settings/{tab}', [SettingsController::class, 'update'])->middleware('perm:settings.manage')->name('settings.update')->whereIn('tab', SettingsController::TABS);
        Route::post('settings/verification/key', [SettingsController::class, 'regenerateVerifierKey'])->middleware('perm:settings.manage')->name('settings.verifier-key');

        Route::get('reports', ReportController::class)->middleware('perm:reports.view')->name('reports');

        Route::get('checkout', [AdminCheckoutController::class, 'edit'])->middleware('perm:checkout.manage')->name('checkout.edit');
        Route::post('checkout', [AdminCheckoutController::class, 'update'])->middleware('perm:checkout.manage')->name('checkout.update');

        Route::get('payouts', [PayoutController::class, 'index'])->middleware('perm:payouts.view')->name('payouts.index');
        Route::post('payouts/{payout}/paid', [PayoutController::class, 'markPaid'])->middleware('perm:payouts.manage')->name('payouts.paid');
        Route::post('payouts/{payout}/reject', [PayoutController::class, 'reject'])->middleware('perm:payouts.manage')->name('payouts.reject');

        Route::get('sms', [SmsController::class, 'index'])->middleware('perm:sms.view')->name('sms.index');
        Route::get('sms/devices', [SmsController::class, 'devices'])->middleware('perm:sms.view')->name('sms.devices');
        Route::post('sms/devices', [SmsController::class, 'storeDevice'])->middleware('perm:sms.manage')->name('sms.devices.store');
        Route::post('sms/devices/{device}/toggle', [SmsController::class, 'toggleDevice'])->middleware('perm:sms.manage')->name('sms.devices.toggle');
        Route::post('sms/devices/{device}/rotate', [SmsController::class, 'rotateDevice'])->middleware('perm:sms.manage')->name('sms.devices.rotate');
        Route::post('sms/settings', [SmsController::class, 'saveSettings'])->middleware('perm:sms.manage')->name('sms.settings');
        Route::get('sms/app.apk', [SmsController::class, 'downloadApp'])->middleware('perm:sms.view')->name('sms.app');

        Route::get('logs/audit', [LogController::class, 'audit'])->middleware('perm:logs.view')->name('logs.audit');
        Route::get('logs/transactions', [LogController::class, 'events'])->middleware('perm:logs.view')->name('logs.events');
        Route::get('logs/callbacks', [LogController::class, 'callbacks'])->middleware('perm:logs.view')->name('logs.callbacks');

        Route::middleware('perm:staff.manage')->group(function () {
            Route::get('admins', [AdminUserController::class, 'index'])->name('admins.index');
            Route::get('admins/create', [AdminUserController::class, 'create'])->name('admins.create');
            Route::post('admins', [AdminUserController::class, 'store'])->name('admins.store');
            Route::get('admins/{user}/edit', [AdminUserController::class, 'edit'])->name('admins.edit');
            Route::put('admins/{user}', [AdminUserController::class, 'update'])->name('admins.update');
            Route::post('admins/{user}/toggle', [AdminUserController::class, 'toggle'])->name('admins.toggle');
        });

        Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
    });
});
