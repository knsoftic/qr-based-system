<?php

namespace App\Http\Controllers;

use App\Support\Settings;
use Illuminate\View\View;

/**
 * Public merchant integration documentation.
 */
class DocsController extends Controller
{
    public function __invoke(Settings $settings): View
    {
        return view('docs.index', [
            'baseUrl' => rtrim(url('/api/v1'), '/'),
            'expiryMinutes' => (int) round($settings->get('payin.expiry_seconds') / 60),
            'currency' => $settings->get('payin.currency'),
        ]);
    }
}
