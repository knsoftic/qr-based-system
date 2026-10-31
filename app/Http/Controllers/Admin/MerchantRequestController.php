<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantRequest;
use App\Support\Audit;
use App\Support\UrlGuard;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Agent account-creation requests. An agent submits the merchant's details
 * (rates, website, desired login); a reviewer approves — which creates the
 * merchant + portal login owned by that agent — or rejects.
 */
class MerchantRequestController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $canReview = $user->hasPermission('requests.review');

        $query = MerchantRequest::query()->with(['agent', 'reviewer', 'merchant'])->latest('id');
        if (! $canReview) {
            $query->where('agent_id', $user->id); // agents see only their own
        }

        return view('admin.requests.index', [
            'requests' => $query->paginate(25),
            'canReview' => $canReview,
            'canSubmit' => $user->hasPermission('requests.submit'),
            'pendingCount' => $canReview ? MerchantRequest::query()->where('status', 'pending')->count() : 0,
        ]);
    }

    public function create(): View
    {
        return view('admin.requests.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'callback_url' => [
                'nullable', 'url:http,https', 'max:255',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! UrlGuard::isAllowed($value)) {
                        $fail('The callback URL must be a public http(s) address.');
                    }
                },
            ],
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/', 'unique:merchants,username'],
            'password' => ['required', Password::min(10)->letters()->numbers()],
            'payin_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'payout_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $merchantRequest = MerchantRequest::query()->create([
            'request_ref' => MerchantRequest::newRef(),
            'agent_id' => $request->user()->id,
            'name' => $data['name'],
            'website' => $data['website'] ?? null,
            'callback_url' => $data['callback_url'] ?? null,
            'username' => Str::lower($data['username']),
            'password' => $data['password'],
            'payin_rate' => $data['payin_rate'] ?? null,
            'payout_rate' => $data['payout_rate'] ?? null,
            'note' => $data['note'] ?? null,
            'status' => 'pending',
        ]);

        Audit::log('merchant_request.submitted', $merchantRequest, [], [
            'ref' => $merchantRequest->request_ref, 'name' => $merchantRequest->name, 'username' => $merchantRequest->username,
        ], byAdmin: false);

        return redirect()->route('admin.requests.index')->with('success', "Request {$merchantRequest->request_ref} submitted for review.");
    }

    public function approve(Request $request, MerchantRequest $merchantRequest): RedirectResponse
    {
        if (! $merchantRequest->isPending()) {
            return back()->with('error', 'This request was already processed.');
        }

        $data = $request->validate(['admin_note' => ['nullable', 'string', 'max:500']]);

        if (Merchant::query()->where('username', $merchantRequest->username)->exists()) {
            return back()->with('error', "Username \"{$merchantRequest->username}\" is already taken; ask the agent to resubmit with another.");
        }

        $merchant = new Merchant([
            'name' => $merchantRequest->name,
            'website' => $merchantRequest->website,
            'callback_url' => $merchantRequest->callback_url,
            'agent_id' => $merchantRequest->agent_id,
            'fee_percent' => $merchantRequest->payin_rate,
            'is_active' => true,
            'username' => $merchantRequest->username,
            'portal_enabled' => true,
        ]);
        $secret = $merchant->issueCredentials();
        $merchant->password = $merchantRequest->password; // decrypted here, hashed by the Merchant cast
        $merchant->save();

        $merchantRequest->forceFill([
            'status' => 'approved',
            'admin_note' => $data['admin_note'] ?? null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'merchant_id' => $merchant->id,
        ])->save();

        Audit::log('merchant_request.approved', $merchant, [], [
            'request' => $merchantRequest->request_ref, 'merchant' => $merchant->merchant_code, 'agent_id' => $merchant->agent_id,
        ]);

        return redirect()->route('admin.requests.index')
            ->with('new_secret', $secret)
            ->with('new_merchant', ['name' => $merchant->name, 'code' => $merchant->merchant_code, 'username' => $merchant->username])
            ->with('success', "Approved. Merchant \"{$merchant->name}\" created with login \"{$merchant->username}\".");
    }

    public function reject(Request $request, MerchantRequest $merchantRequest): RedirectResponse
    {
        if (! $merchantRequest->isPending()) {
            return back()->with('error', 'This request was already processed.');
        }

        $data = $request->validate(['admin_note' => ['nullable', 'string', 'max:500']]);

        $merchantRequest->forceFill([
            'status' => 'rejected',
            'admin_note' => $data['admin_note'] ?? null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ])->save();

        Audit::log('merchant_request.rejected', $merchantRequest, [], ['ref' => $merchantRequest->request_ref]);

        return back()->with('success', "Request {$merchantRequest->request_ref} rejected.");
    }
}
