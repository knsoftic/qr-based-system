<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Read-only tracking of an agent's / manager's downline: their merchants'
 * orders, paid volume, success rate and callback status. No actions are
 * possible here — it is for visibility only.
 */
class DownlineController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $ids = $this->scopeIds($user);

        $merchants = Merchant::query()->with('agent')->whereIn('id', $ids)->orderBy('name')->get();

        $stats = $ids === [] ? collect() : Payment::query()
            ->whereIn('merchant_id', $ids)
            ->selectRaw('merchant_id')
            ->selectRaw('COUNT(*) total')
            ->selectRaw("SUM(status='paid') paid")
            ->selectRaw("SUM(status='pending') pending")
            ->selectRaw("SUM(status IN ('failed','cancelled','expired')) failed")
            ->selectRaw("SUM(CASE WHEN status='paid' THEN amount ELSE 0 END) volume")
            ->selectRaw('MAX(created_at) last_at')
            ->groupBy('merchant_id')
            ->get()->keyBy('merchant_id');

        $rows = $merchants->map(function (Merchant $m) use ($stats) {
            $s = $stats->get($m->id);
            $total = (int) ($s->total ?? 0);
            $paid = (int) ($s->paid ?? 0);

            return [
                'merchant' => $m,
                'total' => $total,
                'paid' => $paid,
                'pending' => (int) ($s->pending ?? 0),
                'failed' => (int) ($s->failed ?? 0),
                'volume' => (float) ($s->volume ?? 0),
                'success' => $total ? round($paid / $total * 100, 1) : 0.0,
                'last_at' => $s->last_at ?? null,
            ];
        });

        return view('admin.downline.index', [
            'rows' => $rows,
            'showAgent' => $user->hasPermission('staff.manage') || $user->isManager(),
            'totalVolume' => (float) $rows->sum('volume'),
            'totalOrders' => (int) $rows->sum('total'),
        ]);
    }

    public function show(Request $request, Merchant $merchant): View
    {
        if (! in_array($merchant->id, $this->scopeIds($request->user()), true)) {
            abort(403, 'This merchant is not in your downline.');
        }

        return view('admin.downline.show', [
            'merchant' => $merchant,
            'payments' => $merchant->payments()->latest('id')->paginate(30),
        ]);
    }

    /**
     * Merchants this user may track: a super admin / reviewer sees the whole
     * agent network; everyone else sees only their own downline.
     *
     * @return list<int>
     */
    private function scopeIds(User $user): array
    {
        if ($user->hasPermission('staff.manage')) {
            return Merchant::query()->whereNotNull('agent_id')->pluck('id')->all();
        }

        return $user->downlineMerchantIds();
    }
}
