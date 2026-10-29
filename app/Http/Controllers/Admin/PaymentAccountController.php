<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DecodeStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\PaymentAccount;
use App\Payments\AccountQrProcessor;
use App\Payments\Emv\Tlv;
use App\Payments\Exceptions\UnsupportedPayloadException;
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
            'provider' => ['required', Rule::in([ProviderRegistry::AUTO, ...array_keys($this->providers->options())])],
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
        $provider = $account->isDecoded() ? $this->providers->make($account->provider) : null;

        return view('admin.accounts.show', [
            'account' => $account,
            'providers' => $this->providers->options(),
            'fields' => $provider?->describe($account->raw_payload) ?? [],
            'editable' => $provider?->editableFields() ?? [],
            'payments' => $account->payments()->with('merchant')->latest('id')->limit(15)->get(),
        ]);
    }

    /**
     * Edit decoded QR fields (e.g. the IBAN). Admin password required; only
     * fields the provider marks editable are accepted; the rebuilt payload is
     * re-verified before it is saved. Applies to new payments only.
     */
    public function updatePayload(Request $request, PaymentAccount $account): RedirectResponse
    {
        if (! $account->isDecoded()) {
            return back()->with('error', 'Only a decoded QR can be edited.');
        }

        $editable = $this->providers->make($account->provider)->editableFields();
        $rules = ['password' => ['required', 'current_password']];
        foreach ($editable as $tag => $field) {
            $rules["fields.{$tag}"] = ['nullable', 'string', 'max:'.($field['max'] + 10)];
        }

        $data = $request->validate($rules, ['password.current_password' => 'Your admin password is incorrect.']);

        $items = $account->payload_template['items'] ?? [];
        $submitted = (array) $request->input('fields', []);
        $old = [];
        $changes = [];
        foreach (array_keys($editable) as $tag) {
            $tag = (string) $tag;

            // A field the form did not send is left alone (never read as "remove it").
            if (! array_key_exists($tag, $submitted)) {
                continue;
            }

            $current = (string) Tlv::find($items, $tag);
            $new = trim((string) ($data['fields'][$tag] ?? ''));

            if ($new !== $current) {
                $old[$tag] = $current === '' ? null : $current;
                $changes[$tag] = $new;
            }
        }

        if ($changes === []) {
            return back()->with('success', 'No changes to save.');
        }

        try {
            $this->processor->edit($account, $changes);
        } catch (UnsupportedPayloadException $e) {
            return back()->withInput($request->except('password'))->with('error', 'Not saved: '.$e->getMessage());
        }

        // Values as saved (e.g. the IBAN normalised to upper case); null = field removed.
        $saved = $account->payload_template['items'];
        $new = [];
        foreach (array_keys($changes) as $tag) {
            $new[(string) $tag] = Tlv::find($saved, (string) $tag);
        }

        Audit::log('account.payload_edited', $account, $old, $new);

        return redirect()->to(route('admin.accounts.show', $account).'#decoded')
            ->with('success', 'Saved. The QR data was rebuilt, its checksum recalculated and verified. New payments use it; existing QR codes are unchanged.');
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
            'provider' => ['required', Rule::in([ProviderRegistry::AUTO, ...array_keys($this->providers->options())])],
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
