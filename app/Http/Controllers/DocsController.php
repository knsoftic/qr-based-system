<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Models\Payout;
use App\Support\ApiErrors;
use App\Support\Settings;
use Illuminate\View\View;

/**
 * Public merchant integration documentation. Every variable value below is
 * read from code (enums, model constants), config or admin settings, so the
 * docs stay correct when the implementation changes — no manual doc edits.
 */
class DocsController extends Controller
{
    public function __invoke(Settings $settings): View
    {
        return view('docs.index', [
            'baseUrl' => rtrim(url('/api/v1'), '/'),
            'currency' => $settings->get('payin.currency'),
            'expiryMinutes' => (int) round($settings->get('payin.expiry_seconds') / 60),
            'signatureTolerance' => (int) config('gateway.signature_tolerance'),
            'rateLimit' => (int) config('gateway.api_rate_limit'),

            'payinMin' => (float) $settings->get('payin.min_amount'),
            'payinMax' => (float) $settings->get('payin.max_amount'),
            'payoutMin' => (float) $settings->get('payout.min_amount'),
            'payoutMax' => (float) $settings->get('payout.max_amount'),

            'payoutMethods' => Payout::METHODS,
            'payoutStatuses' => array_keys(Payout::STATUSES),
            'paymentStatuses' => array_map(fn (PaymentStatus $s) => $s->value, PaymentStatus::cases()),

            'errors' => ApiErrors::forScope('merchant'),
            'verifierErrors' => ApiErrors::forScope('verifier'),
        ]);
    }
}
