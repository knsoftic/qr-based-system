<?php

use App\Enums\PaymentStatus;
use App\Jobs\SendPaymentCallback;
use App\Models\Payment;
use App\Models\User;
use App\Payments\CallbackDispatcher;
use App\Payments\PaymentService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('payments:expire', function (PaymentService $payments) {
    $this->info('Expired '.$payments->expireLapsed().' payment(s).');
})->purpose('Mark pending payments past their QR expiry as expired');

Artisan::command('payments:retry-callbacks', function (CallbackDispatcher $dispatcher) {
    $maxAttempts = (int) config('gateway.callback_max_attempts');
    $sent = 0;

    Payment::query()
        ->with('merchant')
        ->withCount('callbackDeliveries')
        ->where('status', '!=', PaymentStatus::Pending->value)
        ->whereNull('callback_sent_at')
        ->where('updated_at', '>=', now()->subDay())
        ->orderBy('id')
        ->each(function (Payment $payment) use ($dispatcher, $maxAttempts, &$sent) {
            if (! $dispatcher->callbackUrl($payment) || $payment->callback_deliveries_count >= $maxAttempts) {
                return;
            }

            // Exponential backoff: 2, 4, 8, 16 ... minutes after the last attempt.
            $last = $payment->callbackDeliveries()->latest('id')->first();
            if ($last && $last->created_at->gt(now()->subMinutes(2 ** $payment->callback_deliveries_count))) {
                return;
            }

            SendPaymentCallback::dispatchSync($payment->id);
            $sent++;
        });

    $this->info("Retried {$sent} callback(s).");
})->purpose('Retry failed merchant callbacks with backoff');

Artisan::command('gateway:create-admin {email} {--name=Administrator}', function (string $email) {
    $password = $this->secret('Password (min 10 characters)');

    if (strlen((string) $password) < 10) {
        $this->error('Password must be at least 10 characters.');

        return 1;
    }

    User::query()->updateOrCreate(
        ['email' => strtolower($email)],
        ['name' => $this->option('name'), 'password' => $password, 'is_active' => true],
    );

    $this->info("Admin {$email} is ready. Sign in at ".route('admin.login'));
})->purpose('Create or reset an admin user');

// onOneServer(): if schedule:run is triggered twice in the same minute (e.g. an
// aaPanel cron task and a crontab entry), each task still runs only once.
Schedule::command('payments:expire')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('payments:retry-callbacks')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
