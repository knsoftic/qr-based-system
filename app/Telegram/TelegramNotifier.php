<?php

namespace App\Telegram;

use App\Enums\PaymentStatus;
use App\Jobs\SendTelegramMessage;
use App\Models\CallbackDelivery;
use App\Models\Merchant;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\User;

/**
 * Turns gateway events into Telegram messages. Every method is best-effort:
 * if nothing is linked, or Telegram is not configured, it simply does nothing.
 * It must never throw into the payment / callback / payout flow.
 */
class TelegramNotifier
{
    public function __construct(private readonly TelegramClient $client) {}

    /** A payment reached a final status — notify the merchant on approval. */
    public function paymentStatusChanged(Payment $payment): void
    {
        if ($payment->status !== PaymentStatus::Paid) {
            return;
        }

        $merchant = $payment->merchant;
        if (! $merchant?->isTelegramLinked()) {
            return;
        }

        $text = "✅ <b>Payment received</b>\n"
            .e($merchant->telegramName())."\n\n"
            .'Amount: <b>'.Format::money($payment->amount, $payment->currency)."</b>\n"
            .'Order: <code>'.e($payment->order_id)."</code>\n"
            .'Txn: <code>'.e($payment->transaction_id)."</code>\n"
            .'Paid: '.Format::dt($payment->paid_at);

        $this->send($merchant->telegram_chat_id, $text, defer: true);
    }

    /** A merchant callback failed — notify once, on the first failed attempt. */
    public function callbackFailed(Payment $payment, CallbackDelivery $delivery): void
    {
        if ($delivery->success || $delivery->attempt > 1) {
            return;
        }

        $merchant = $payment->merchant;
        if (! $merchant?->isTelegramLinked()) {
            return;
        }

        $detail = $delivery->error ?: ('HTTP '.$delivery->response_code);

        $text = "⚠️ <b>Callback failed</b>\n"
            .e($merchant->telegramName())."\n\n"
            .'Order: <code>'.e($payment->order_id)."</code>\n"
            .'Txn: <code>'.e($payment->transaction_id)."</code>\n"
            .'Reason: '.e($detail)."\n"
            .'We will keep retrying automatically.';

        // Fired from inside SendPaymentCallback, which already runs after the
        // response, so send directly rather than deferring again.
        $this->send($merchant->telegram_chat_id, $text, defer: false);
    }

    /** A withdrawal was marked paid or rejected by an admin. */
    public function payoutSettled(Payout $payout): void
    {
        $merchant = $payout->merchant;
        if (! $merchant?->isTelegramLinked()) {
            return;
        }

        if ($payout->status === 'paid') {
            $text = "✅ <b>Withdrawal paid</b>\n";
        } elseif ($payout->status === 'rejected') {
            $text = "❌ <b>Withdrawal rejected</b>\n";
        } else {
            return;
        }

        $text .= e($merchant->telegramName())."\n\n"
            .'Ref: <code>'.e($payout->payout_ref)."</code>\n"
            .'Amount: <b>'.Format::money($payout->amount, $payout->currency)."</b>\n"
            .'Method: '.e($payout->methodLabel());

        if ($payout->status === 'rejected') {
            $text .= "\nThe amount has returned to your available balance.";
        }

        $this->send($merchant->telegram_chat_id, $text, defer: true);
    }

    /**
     * A payment channel went into / out of maintenance — broadcast to all
     * linked merchants and staff.
     */
    public function channelMaintenance(string $channel, bool $on): void
    {
        $text = $on
            ? "🛠️ <b>Channel maintenance</b>\nChannel \"".e($channel).'" is now in maintenance. Payments via this method are paused.'
            : "✅ <b>Channel resumed</b>\nChannel \"".e($channel).'" is back online.';

        foreach ([Merchant::class, User::class] as $class) {
            $class::query()
                ->whereNotNull('telegram_chat_id')
                ->select(['telegram_chat_id'])
                ->chunk(200, function ($owners) use ($text) {
                    foreach ($owners as $owner) {
                        $this->send((string) $owner->telegram_chat_id, $text, defer: true);
                    }
                });
        }
    }

    /**
     * Deferred sends (web-request events) go through an after-response job so
     * the user is not kept waiting; direct sends are for already-background code.
     */
    private function send(string $chatId, string $text, bool $defer): void
    {
        if ($chatId === '' || ! $this->client->configured()) {
            return;
        }

        try {
            if ($defer) {
                SendTelegramMessage::dispatch($chatId, $text)->afterResponse();
            } else {
                $this->client->sendMessage($chatId, $text);
            }
        } catch (\Throwable) {
            // Best-effort: a Telegram problem never breaks the main flow.
        }
    }
}
