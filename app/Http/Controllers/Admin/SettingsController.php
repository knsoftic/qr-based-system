<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Payments\AccountRouter;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public const TABS = ['payin', 'payout', 'fees', 'api', 'verification'];

    public function __construct(private readonly Settings $settings)
    {
    }

    public function edit(string $tab = 'payin'): View
    {
        return view('admin.settings.edit', [
            'tab' => $tab,
            'settings' => $this->settings->all(),
            'strategies' => AccountRouter::STRATEGIES,
        ]);
    }

    public function update(Request $request, string $tab): RedirectResponse
    {
        $rules = match ($tab) {
            'payin' => [
                'min_amount' => ['required', 'numeric', 'min:0.01'],
                'max_amount' => ['required', 'numeric', 'gt:min_amount'],
                'expiry_seconds' => ['required', 'integer', 'min:60', 'max:3600'],
                'routing_strategy' => ['required', Rule::in(array_keys(AccountRouter::STRATEGIES))],
            ],
            'payout' => [
                'min_amount' => ['required', 'numeric', 'min:0'],
                'max_amount' => ['required', 'numeric', 'gte:min_amount'],
                'daily_limit' => ['required', 'numeric', 'min:0'],
            ],
            'fees' => [
                'payin_percent' => ['required', 'numeric', 'min:0', 'max:100'],
                'payin_fixed' => ['required', 'numeric', 'min:0'],
                'payout_percent' => ['required', 'numeric', 'min:0', 'max:100'],
                'payout_fixed' => ['required', 'numeric', 'min:0'],
            ],
            'api' => [
                'rate_limit' => ['required', 'integer', 'min:1', 'max:10000'],
            ],
            default => abort(404),
        };

        $data = $request->validate($rules);

        if (in_array($tab, ['payin', 'payout'], true)) {
            $data['enabled'] = $request->boolean('enabled');
        }

        $values = collect($data)
            ->mapWithKeys(fn ($value, $key) => ["{$tab}.{$key}" => is_numeric($value) && ! is_int($value) ? (float) $value : $value])
            ->all();

        $old = Arr::only($this->settings->all(), array_keys($values));
        $this->settings->set($values);

        Audit::log('settings.updated', null, $old, $values);

        return redirect()->route('admin.settings.edit', $tab)->with('success', 'Settings saved.');
    }

    public function regenerateVerifierKey(): RedirectResponse
    {
        $key = 'vk_'.Str::random(48);
        $this->settings->set(['verification.key_hash' => hash('sha256', $key)]);

        Audit::log('settings.verifier_key_generated');

        return redirect()->route('admin.settings.edit', 'verification')
            ->with('verifier_key', $key)
            ->with('success', 'New verifier key generated. The previous key no longer works.');
    }
}
