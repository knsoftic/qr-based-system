<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Payout;
use App\Payments\PayoutService;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin approval of merchant withdrawals. The admin sends the money manually
 * (easypaisa / JazzCash), then marks the payout paid or rejected here.
 */
class PayoutController extends Controller
{
    public function __construct(private readonly PayoutService $payouts)
    {
    }

    public function index(Request $request): View
    {
        $request->validate([
            'status' => ['nullable', Rule::in(array_keys(Payout::STATUSES))],
            'merchant_id' => ['nullable', 'integer'],
        ]);

        return view('admin.payouts.index', [
            'payouts' => Payout::query()
                ->with(['merchant', 'processor'])
                ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
                ->when($request->input('merchant_id'), fn ($q, $id) => $q->where('merchant_id', $id))
                ->latest('id')
                ->paginate(30)
                ->withQueryString(),
            'merchants' => Merchant::query()->orderBy('name')->pluck('name', 'id'),
            'statuses' => Payout::STATUSES,
            'pendingTotal' => (float) Payout::query()->where('status', 'pending')->sum('amount'),
            'pendingCount' => Payout::query()->where('status', 'pending')->count(),
        ]);
    }

    public function markPaid(Request $request, Payout $payout): RedirectResponse
    {
        $data = $request->validate([
            'reference' => ['nullable', 'string', 'max:100'],
            'admin_note' => ['nullable', 'string', 'max:255'],
        ]);

        if (! $this->payouts->markPaid($payout, $request->user(), $data['reference'] ?? null, $data['admin_note'] ?? null)) {
            return back()->with('error', 'This payout is no longer pending.');
        }

        Audit::log('payout.paid', $payout, [], ['payout' => $payout->payout_ref, 'amount' => $payout->amount, 'reference' => $data['reference'] ?? null]);

        return back()->with('success', "Payout {$payout->payout_ref} marked as paid.");
    }

    public function reject(Request $request, Payout $payout): RedirectResponse
    {
        $data = $request->validate(['admin_note' => ['nullable', 'string', 'max:255']]);

        if (! $this->payouts->reject($payout, $request->user(), $data['admin_note'] ?? null)) {
            return back()->with('error', 'This payout is no longer pending.');
        }

        Audit::log('payout.rejected', $payout, [], ['payout' => $payout->payout_ref, 'amount' => $payout->amount]);

        return back()->with('success', "Payout {$payout->payout_ref} rejected; the amount returned to the merchant's balance.");
    }
}
