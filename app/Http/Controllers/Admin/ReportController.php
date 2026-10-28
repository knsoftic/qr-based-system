<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ReportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = Carbon::parse($request->input('from', now()->subDays(6)->toDateString()))->startOfDay();
        $to = Carbon::parse($request->input('to', now()->toDateString()))->endOfDay();

        $base = fn () => Payment::query()->whereBetween('payments.created_at', [$from, $to]);

        $daily = $base()
            ->selectRaw('DATE(payments.created_at) as day')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid")
            ->selectRaw("SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired")
            ->selectRaw("SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) as volume")
            ->selectRaw("SUM(CASE WHEN status = 'paid' THEN fee_amount ELSE 0 END) as fees")
            ->groupBy(DB::raw('DATE(payments.created_at)'))
            ->orderBy('day', 'desc')
            ->get();

        if ($request->input('export') === 'csv') {
            return response()->streamDownload(function () use ($daily) {
                $out = fopen('php://output', 'w');
                fputcsv($out, ['Date', 'Requests', 'Paid', 'Expired', 'Paid volume', 'Fees']);
                foreach ($daily as $row) {
                    fputcsv($out, [$row->day, $row->total, $row->paid, $row->expired, $row->volume, $row->fees]);
                }
                fclose($out);
            }, "payin-report-{$from->toDateString()}-to-{$to->toDateString()}.csv", ['Content-Type' => 'text/csv']);
        }

        $paidSum = fn ($query, string $groupColumn) => $query
            ->where('payments.status', 'paid')
            ->selectRaw('COUNT(*) as paid, SUM(payments.amount) as volume, SUM(payments.fee_amount) as fees, SUM(payments.net_amount) as net')
            ->groupBy($groupColumn)
            ->orderByDesc('volume')
            ->get();

        return response()->view('admin.reports.index', [
            'from' => $from,
            'to' => $to,
            'daily' => $daily,
            'totals' => [
                'total' => $daily->sum('total'),
                'paid' => $daily->sum('paid'),
                'expired' => $daily->sum('expired'),
                'volume' => $daily->sum('volume'),
                'fees' => $daily->sum('fees'),
            ],
            'byMerchant' => $paidSum($base()->join('merchants', 'merchants.id', '=', 'payments.merchant_id')->addSelect('merchants.name'), 'merchants.name'),
            'byAccount' => $paidSum($base()->join('payment_accounts', 'payment_accounts.id', '=', 'payments.payment_account_id')->addSelect('payment_accounts.name'), 'payment_accounts.name'),
        ]);
    }
}
