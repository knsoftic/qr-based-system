<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Payments\PayInTester;
use App\Payments\PaymentService;
use App\Support\Audit;
use App\Support\IpAllowlist;
use App\Support\UrlGuard;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
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
        return view('admin.merchants.create', ['merchant' => new Merchant(['is_active' => true, 'portal_enabled' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $merchant = new Merchant($data['attributes']);
        $secret = $merchant->issueCredentials();
        $portalPassword = $this->applyPortalPassword($merchant, $data['password'], $merchant->portal_enabled);
        $merchant->save();

        Audit::log('merchant.created', $merchant, [], $merchant->only(['name', 'email', 'website', 'is_active', 'username', 'portal_enabled']));

        return redirect()->route('admin.merchants.show', $merchant)
            ->with('new_secret', $secret)
            ->with('new_portal_password', $portalPassword)
            ->with('success', 'Merchant created. Copy the API secret now; it will not be shown again.');
    }

    public function show(Merchant $merchant, PayInTester $tester, PaymentService $payments): View
    {
        $payments->expireLapsed();

        return view('admin.merchants.show', [
            'merchant' => $merchant,
            'payments' => $merchant->payments()->latest('id')->limit(15)->get(),
            'test' => $tester->present($merchant, session('payin_test')),
        ]);
    }

    public function edit(Merchant $merchant): View
    {
        return view('admin.merchants.edit', ['merchant' => $merchant]);
    }

    public function update(Request $request, Merchant $merchant): RedirectResponse
    {
        $data = $this->validated($request, $merchant);
        $wasEnabled = $merchant->portal_enabled;

        $merchant->fill($data['attributes']);
        $portalPassword = $this->applyPortalPassword($merchant, $data['password'], $merchant->portal_enabled && ! $merchant->password);

        if (($wasEnabled && ! $merchant->portal_enabled) || $merchant->isDirty('username')) {
            $merchant->revokePortalSessions();
        }

        $merchant->save();

        Audit::log('merchant.updated', $merchant, ...Audit::changes($merchant));

        return redirect()->route('admin.merchants.show', $merchant)
            ->with('new_portal_password', $portalPassword)
            ->with('success', 'Merchant updated.');
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
        $merchant->forceFill(['is_active' => ! $merchant->is_active]);
        if (! $merchant->is_active) {
            $merchant->revokePortalSessions();
        }
        $merchant->save();

        Audit::log($merchant->is_active ? 'merchant.enabled' : 'merchant.disabled', $merchant);

        return back()->with('success', $merchant->is_active ? 'Merchant enabled.' : 'Merchant disabled; its API key and portal login are rejected.');
    }

    /**
     * New random portal password, shown once; every open portal session is signed out.
     */
    public function resetPortalPassword(Merchant $merchant): RedirectResponse
    {
        if (! $merchant->username) {
            return back()->with('error', 'Set a portal username first (Edit merchant).');
        }

        $password = $this->applyPortalPassword($merchant, null, true);
        $merchant->save();

        Audit::log('merchant.portal_password_reset', $merchant);

        return redirect()->route('admin.merchants.show', $merchant)
            ->with('new_portal_password', $password)
            ->with('success', 'New portal password generated. Old sessions were signed out.');
    }

    public function testPayIn(Request $request, Merchant $merchant, PayInTester $tester): RedirectResponse
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0']]);

        $result = $tester->run($merchant, (string) $data['amount'], 'admin');

        Audit::log('merchant.payin_test', $merchant, [], ['amount' => $data['amount'], 'status' => $result['status'], 'payment_id' => $result['payment_id']]);

        return redirect()->to(route('admin.merchants.show', $merchant).'#payin-test')->with('payin_test', $result)->withInput(['amount' => $data['amount']]);
    }

    /**
     * @return array{attributes: array, password: ?string}
     */
    private function validated(Request $request, ?Merchant $merchant = null): array
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
            'payin_ip_allowlist' => ['nullable', 'string', 'max:4000', $this->ipAllowlistRule()],
            'payout_ip_allowlist' => ['nullable', 'string', 'max:4000', $this->ipAllowlistRule()],
            'username' => [
                'nullable', 'required_if:portal_enabled,1', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('merchants', 'username')->ignore($merchant?->id),
            ],
            'portal_password' => ['nullable', Password::min(10)->letters()->numbers()],
        ], [
            'username.required_if' => 'A portal username is required when the portal login is enabled.',
        ]);

        $attributes = collect($data)->except(['username', 'portal_password', 'payin_ip_allowlist', 'payout_ip_allowlist'])->all() + [
            'username' => isset($data['username']) ? Str::lower($data['username']) : null,
            'payin_ip_allowlist' => IpAllowlist::normalise($data['payin_ip_allowlist'] ?? null),
            'payout_ip_allowlist' => IpAllowlist::normalise($data['payout_ip_allowlist'] ?? null),
            'is_active' => $request->boolean('is_active'),
            'portal_enabled' => $request->boolean('portal_enabled'),
        ];

        return ['attributes' => $attributes, 'password' => $data['portal_password'] ?? null];
    }

    /**
     * Validates that every line of an IP allowlist is a valid IP or CIDR range.
     */
    private function ipAllowlistRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            foreach (IpAllowlist::parse(is_string($value) ? $value : null) as $rule) {
                if (! IpAllowlist::isValidRule($rule)) {
                    $fail("“{$rule}” is not a valid IP address or CIDR range.");
                }
            }
        };
    }

    /**
     * Set the typed password, or generate one when asked. Returns a generated
     * password (to show once); null when nothing was generated.
     */
    private function applyPortalPassword(Merchant $merchant, ?string $password, bool $generateIfMissing): ?string
    {
        if ($password !== null && $password !== '') {
            $merchant->password = $password;
            $merchant->revokePortalSessions();

            return null;
        }

        if (! $generateIfMissing) {
            return null;
        }

        $generated = Str::password(14, symbols: false);
        $merchant->password = $generated;
        $merchant->revokePortalSessions();

        return $generated;
    }
}
