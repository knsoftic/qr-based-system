<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Payment;
use App\Models\PaymentAccount;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $today = Payment::query()->where('created_at', '>=', now()->startOfDay());

        $todayStats = (clone $today)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid")
            ->selectRaw("SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) as volume")
            ->selectRaw("SUM(CASE WHEN status = 'paid' THEN fee_amount ELSE 0 END) as fees")
            ->first();

        return view('admin.dashboard', [
            'stats' => [
                'today_total' => (int) $todayStats->total,
                'today_paid' => (int) $todayStats->paid,
                'today_volume' => (float) $todayStats->volume,
                'today_fees' => (float) $todayStats->fees,
                'success_rate' => $todayStats->total ? round($todayStats->paid / $todayStats->total * 100, 1) : 0,
                'pending' => Payment::query()->where('status', PaymentStatus::Pending->value)->where('expires_at', '>', now())->count(),
                'active_accounts' => PaymentAccount::query()->usable()->count(),
                'total_accounts' => PaymentAccount::query()->count(),
                'merchants' => Merchant::query()->where('is_active', true)->count(),
            ],
            'recent' => Payment::query()->with('merchant')->latest('id')->limit(10)->get(),
            'attention' => PaymentAccount::query()->where('decode_status', '!=', 'decoded')->latest('id')->limit(5)->get(),
        ]);
    }
}
