<?php

namespace App\Payments;

use App\Jobs\SendPaymentCallback;
use App\Models\Payment;

class CallbackDispatcher
{
    /**
     * Send the merchant callback after the HTTP response is flushed, so it
     * works without a queue worker. Failed deliveries are retried by the
     * scheduled `payments:retry-callbacks` command.
     */
    public function dispatch(Payment $payment): void
    {
        if (! $this->callbackUrl($payment)) {
            return;
        }

        SendPaymentCallback::dispatch($payment->id)->afterResponse();
    }

    public function callbackUrl(Payment $payment): ?string
    {
        return $payment->callback_url ?: $payment->merchant?->callback_url;
    }
}
