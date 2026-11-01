<?php

use App\Enums\PaymentStatus;
use App\Jobs\SendPaymentCallback;
use App\Models\AccountMonthlyStat;
use App\Models\Payment;
use App\Models\PaymentAccount;
use App\Models\User;
use App\Payments\CallbackDispatcher;
use App\Payments\PaymentService;
use App\Telegram\TelegramClient;
use Illuminate\Support\Carbon;
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

Artisan::command('gateway:create-admin {email} {--name=Administrator}
    {--password-env= : Read the password from this environment variable instead of prompting (for deploy scripts)}
    {--if-none : Do nothing (exit code 3) when an admin already exists}', function (string $email) {
    if ($this->option('if-none') && User::query()->exists()) {
        $this->info('An admin already exists; nothing changed.');

        return 3;
    }

    $password = $this->option('password-env')
        ? (string) getenv($this->option('password-env'))
        : (string) $this->secret('Password (min 10 characters)');

    if (strlen($password) < 10) {
        $this->error('Password must be at least 10 characters.');

        return 1;
    }

    User::query()->updateOrCreate(
        ['email' => strtolower($email)],
        ['name' => $this->option('name'), 'password' => $password, 'is_active' => true],
    );

    $this->info("Admin {$email} is ready. Sign in at ".route('admin.login'));
})->purpose('Create or reset an admin user');

Artisan::command('accounts:close-month {--month= : YYYY-MM to snapshot (default: last month)}', function () {
    $when = $this->option('month')
        ? Carbon::createFromFormat('Y-m', $this->option('month'))->startOfMonth()
        : now()->subMonthNoOverflow()->startOfMonth();
    [$from, $to] = [$when->copy(), $when->copy()->endOfMonth()];
    $saved = 0;

    PaymentAccount::withTrashed()->orderBy('id')->each(function ($account) use ($from, $to, $when, &$saved) {
        $row = $account->payments()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('COUNT(*) attempts')
            ->selectRaw("SUM(status='paid') verified")
            ->selectRaw("SUM(status='expired') expired")
            ->selectRaw("SUM(status='failed') failed")
            ->selectRaw("SUM(CASE WHEN status='paid' THEN amount ELSE 0 END) received")
            ->selectRaw("SUM(CASE WHEN status='paid' THEN fee_amount ELSE 0 END) fees")
            ->selectRaw("SUM(CASE WHEN status='paid' THEN net_amount ELSE 0 END) net")
            ->first();

        if (! $row->attempts) {
            return;
        }

        AccountMonthlyStat::query()->updateOrCreate(
            ['payment_account_id' => $account->id, 'year' => $when->year, 'month' => $when->month],
            [
                'attempts' => (int) $row->attempts, 'verified' => (int) $row->verified,
                'expired' => (int) $row->expired, 'failed' => (int) $row->failed,
                'received' => (float) $row->received, 'fees' => (float) $row->fees, 'net' => (float) $row->net,
            ],
        );
        $saved++;
    });

    $this->info("Snapshotted {$saved} account(s) for {$when->format('M Y')}. Limits reset automatically for the new month.");
})->purpose('Snapshot last month per-account totals (history); monthly limits reset on the 1st');

Artisan::command('telegram:set-webhook {--delete : Remove the webhook instead of registering it}', function (TelegramClient $client) {
    if (! $client->configured()) {
        $this->error('TELEGRAM_BOT_TOKEN is not set in .env.');

        return 1;
    }

    if ($this->option('delete')) {
        $result = $client->deleteWebhook();
        $this->line(($result['ok'] ?? false) ? 'Webhook removed.' : 'Failed: '.($result['description'] ?? 'unknown error.'));

        return ($result['ok'] ?? false) ? 0 : 1;
    }

    $secret = (string) config('services.telegram.webhook_secret');
    if ($secret === '') {
        $this->error('TELEGRAM_WEBHOOK_SECRET is not set in .env (use any long random string).');

        return 1;
    }

    $url = url('/api/telegram/webhook/'.$secret);
    $result = $client->setWebhook($url, $secret);

    if ($result['ok'] ?? false) {
        $this->info('Webhook registered: '.$url);

        return 0;
    }

    $this->error('Telegram rejected the webhook: '.($result['description'] ?? 'unknown error.'));

    return 1;
})->purpose('Register (or remove with --delete) the Telegram bot webhook');

Artisan::command('telegram:test {chat : Telegram chat id to message}', function (string $chat, TelegramClient $client) {
    if (! $client->configured()) {
        $this->error('TELEGRAM_BOT_TOKEN is not set in .env.');

        return 1;
    }

    $sent = $client->sendMessage($chat, '✅ Test message from '.config('app.name').'. Telegram is working.');
    $this->line($sent ? 'Sent.' : 'Failed to send (check the token and chat id).');

    return $sent ? 0 : 1;
})->purpose('Send a test Telegram message to a chat id');

// onOneServer(): if schedule:run is triggered twice in the same minute (e.g. an
// aaPanel cron task and a crontab entry), each task still runs only once.
Schedule::command('payments:expire')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('payments:retry-callbacks')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
// 1st of each month, just after midnight (Asia/Karachi): snapshot last month.
Schedule::command('accounts:close-month')->monthlyOn(1, '00:05')->timezone('Asia/Karachi')->onOneServer();
