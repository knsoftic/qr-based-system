<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\SettlementService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Staff earnings for a month: salary + pay-in / pay-out commission on their
 * downline's paid volume. An admin sees everyone; a manager / agent sees only
 * their own.
 */
class SettlementController extends Controller
{
    public function index(Request $request, SettlementService $settlements): View
    {
        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);

        $month = $request->filled('month')
            ? Carbon::createFromFormat('Y-m', $request->input('month'))->startOfMonth()
            : now()->startOfMonth();
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $user = $request->user();
        $isAdmin = $user->hasPermission('staff.manage');

        $people = $isAdmin
            ? User::query()->whereIn('role', [User::ROLE_MANAGER, User::ROLE_AGENT])->orderBy('role')->orderBy('name')->get()
            : collect([$user]);

        $rows = $people->map(fn (User $p) => ['staff' => $p] + $settlements->statement($p, $start, $end));

        return view('admin.settlements.index', [
            'rows' => $rows,
            'month' => $month,
            'isAdmin' => $isAdmin,
            'grandTotal' => (float) $rows->sum('total'),
        ]);
    }
}
