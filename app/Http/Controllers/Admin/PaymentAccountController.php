<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DecodeStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\PaymentAccount;
use App\Payments\AccountQrProcessor;
use App\Payments\Providers\ProviderRegistry;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PaymentAccountController extends Controller
{
    private const IMAGE_RULES = ['required', 'file', 'image', 'mimes:png,jpg,jpeg,gif,webp', 'max:4096'];

    public function __construct(
        private readonly AccountQrProcessor $processor,
        private readonly ProviderRegistry $providers,
    ) {
    }

    public function index(): View
    {
        $todayPaid = fn ($query) => $query
            ->where('status', PaymentStatus::Paid->value)
            ->where('created_at', '>=', now()->startOfDay());

        return view('admin.accounts.index', [
            'accounts' => PaymentAccount::query()
                ->withCount(['payments as paid_today' => $todayPaid])
                ->withSum(['payments as volume_today' => $todayPaid], 'amount')
                ->orderByDesc('is_active')
                ->orderBy('priority')
                ->orderBy('id')
                ->paginate(25),
        ]);
    }

    public function create(): View
    {
        return view('admin.accounts.create', ['providers' => $this->providers->options()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'provider' => ['required', Rule::in(array_keys($this->providers->options()))],
            'priority' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'daily_limit' => ['nullable', 'numeric', 'min:0'],
            'qr_image' => self::IMAGE_RULES,
        ]);

        $account = new PaymentAccount([
            'name' => $data['name'],
            'provider' => $data['provider'],
            'priority' => $data['priority'] ?? 100,
            'daily_limit' => $data['daily_limit'] ?? null,
        ]);
        $account->is_active = false;
        $account->decode_status = DecodeStatus::Pending;

        $this->processor->process($account, $request->file('qr_image'));

        if ($account->isDecoded() && $request->boolean('activate')) {
            $account->forceFill(['is_active' => true])->save();
        }

        Audit::log('account.created', $account, [], [
            'name' => $account->name,
            'provider' => $account->provider,
            'decode_status' => $account->decode_status,
            'is_active' => $account->is_active,
        ]);

        return redirect()->route('admin.accounts.show', $account)->with(...$this->decodeFlash($account));
    }

    public function show(PaymentAccount $account): View
    {
        return view('admin.accounts.show', [
            'account' => $account,
            'providers' => $this->providers->options(),
            'payments' => $account->payments()->with('merchant')->latest('id')->limit(15)->get(),
        ]);
    }

    public function edit(PaymentAccount $account): View
    {
        return view('admin.accounts.edit', ['account' => $account]);
    }

    public function update(Request $request, PaymentAccount $account): RedirectResponse
    {
        $account->fill($request->validate([
            'name' => ['required', 'string', 'max:100'],
            'priority' => ['required', 'integer', 'min:1', 'max:1000'],
            'daily_limit' => ['nullable', 'numeric', 'min:0'],
        ]))->save();

        Audit::log('account.updated', $account, ...Audit::changes($account));

        return redirect()->route('admin.accounts.show', $account)->with('success', 'Account updated.');
    }

    public function uploadQr(Request $request, PaymentAccount $account): RedirectResponse
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(array_keys($this->providers->options()))],
            'qr_image' => self::IMAGE_RULES,
        ]);

        $wasActive = $account->is_active;
        $account->provider = $data['provider'];
        $this->processor->process($account, $request->file('qr_image'));

        Audit::log('account.qr_uploaded', $account, ['is_active' => $wasActive], [
            'provider' => $account->provider,
            'decode_status' => $account->decode_status,
            'is_active' => $account->is_active,
        ]);

        return redirect()->route('admin.accounts.show', $account)->with(...$this->decodeFlash($account));
    }

    public function toggle(PaymentAccount $account): RedirectResponse
    {
        if (! $account->is_active && ! $account->isDecoded()) {
            return back()->with('error', 'Only accounts with a successfully decoded QR can be activated.');
        }

        $account->forceFill(['is_active' => ! $account->is_active])->save();
        Audit::log($account->is_active ? 'account.enabled' : 'account.disabled', $account);

        return back()->with('success', $account->is_active ? 'Account enabled.' : 'Account disabled.');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function decodeFlash(PaymentAccount $account): array
    {
        return match ($account->decode_status) {
            DecodeStatus::Decoded => ['success', 'QR decoded and verified. Payload template saved.'],
            DecodeStatus::Unsupported => ['error', 'QR read, but the payload was rejected: '.$account->decode_error],
            default => ['error', 'QR could not be decoded: '.$account->decode_error],
        };
    }
}
