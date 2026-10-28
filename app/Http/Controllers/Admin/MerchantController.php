<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Support\Audit;
use App\Support\UrlGuard;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MerchantController extends Controller
{
    public function index(): View
    {
        $paid = fn ($query) => $query->where('status', PaymentStatus::Paid->value);

        return view('admin.merchants.index', [
            'merchants' => Merchant::query()
                ->withCount('payments')
                ->withSum(['payments as paid_volume' => $paid], 'amount')
                ->latest('id')
                ->paginate(25),
        ]);
    }

    public function create(): View
    {
        return view('admin.merchants.create', ['merchant' => new Merchant(['is_active' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $merchant = new Merchant($this->validated($request));
        $secret = $merchant->issueCredentials();
        $merchant->save();

        Audit::log('merchant.created', $merchant, [], $merchant->only(['name', 'email', 'website', 'is_active']));

        return redirect()->route('admin.merchants.show', $merchant)
            ->with('new_secret', $secret)
            ->with('success', 'Merchant created. Copy the API secret now; it will not be shown again.');
    }

    public function show(Merchant $merchant): View
    {
        return view('admin.merchants.show', [
            'merchant' => $merchant,
            'payments' => $merchant->payments()->latest('id')->limit(15)->get(),
        ]);
    }

    public function edit(Merchant $merchant): View
    {
        return view('admin.merchants.edit', ['merchant' => $merchant]);
    }

    public function update(Request $request, Merchant $merchant): RedirectResponse
    {
        $merchant->fill($this->validated($request))->save();

        Audit::log('merchant.updated', $merchant, ...Audit::changes($merchant));

        return redirect()->route('admin.merchants.show', $merchant)->with('success', 'Merchant updated.');
    }

    public function rotate(Merchant $merchant): RedirectResponse
    {
        $secret = $merchant->rotateSecret();
        $merchant->save();

        Audit::log('merchant.secret_rotated', $merchant);

        return redirect()->route('admin.merchants.show', $merchant)
            ->with('new_secret', $secret)
            ->with('success', 'API secret rotated. The old secret stopped working immediately.');
    }

    public function toggle(Merchant $merchant): RedirectResponse
    {
        $merchant->forceFill(['is_active' => ! $merchant->is_active])->save();
        Audit::log($merchant->is_active ? 'merchant.enabled' : 'merchant.disabled', $merchant);

        return back()->with('success', $merchant->is_active ? 'Merchant enabled.' : 'Merchant disabled; its API key is rejected.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:150'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'callback_url' => [
                'nullable', 'url:http,https', 'max:255',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! UrlGuard::isAllowed($value)) {
                        $fail('The callback URL must be a public http(s) address.');
                    }
                },
            ],
            'fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fee_fixed' => ['nullable', 'numeric', 'min:0'],
        ]);

        return $data + ['is_active' => $request->boolean('is_active')];
    }
}
