<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Jobs\SendPaymentCallback;
use App\Models\Merchant;
use App\Models\Payment;
use App\Models\PaymentAccount;
use App\Payments\CallbackDispatcher;
use App\Payments\PaymentService;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransactionController extends Controller
{
    public function __construct(private readonly PaymentService $payments)
    {
    }

    public function index(Request $request): View
    {
        $this->payments->expireLapsed();

        return view('admin.transactions.index', [
            'payments' => $this->filtered($request)->with(['merchant', 'account'])->latest('id')->paginate(25)->withQueryString(),
            'merchants' => Merchant::query()->orderBy('name')->pluck('name', 'id'),
            'accounts' => PaymentAccount::query()->orderBy('name')->pluck('name', 'id'),
            'statuses' => PaymentStatus::cases(),
        ]);
    }

    public function show(Payment $payment): View
    {
        $this->payments->expireIfLapsed($payment);

        return view('admin.transactions.show', [
            'payment' => $payment->load(['merchant', 'account', 'events', 'callbackDeliveries']),
        ]);
    }

    public function markPaid(Request $request, Payment $payment): RedirectResponse
    {
        $data = $request->validate([
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $from = $payment->status;
        $actor = 'admin:'.$request->user()->id;

        if (! $this->payments->transition($payment, PaymentStatus::Paid, $actor, $data + ['late' => $from === PaymentStatus::Expired], $request->ip())) {
            return back()->with('error', "A {$from->value} payment cannot be marked as paid.");
        }

        Audit::log('payment.marked_paid', $payment, ['status' => $from->value], ['status' => 'paid'] + $data);

        return back()->with('success', 'Payment verified and marked as paid. The merchant is being notified.');
    }

    public function markFailed(Request $request, Payment $payment): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);
        $from = $payment->status;

        if (! $this->payments->transition($payment, PaymentStatus::Failed, 'admin:'.$request->user()->id, $data, $request->ip())) {
            return back()->with('error', "A {$from->value} payment cannot be marked as failed.");
        }

        Audit::log('payment.marked_failed', $payment, ['status' => $from->value], ['status' => 'failed'] + $data);

        return back()->with('success', 'Payment marked as failed.');
    }

    public function resendCallback(Payment $payment, CallbackDispatcher $dispatcher): RedirectResponse
    {
        if (! $dispatcher->callbackUrl($payment)) {
            return back()->with('error', 'No callback URL is configured for this payment or merchant.');
        }

        SendPaymentCallback::dispatchSync($payment->id);
        Audit::log('payment.callback_resent', $payment);

        $last = $payment->callbackDeliveries()->latest('id')->first();

        return back()->with($last?->success ? 'success' : 'error', $last?->success
            ? "Callback delivered (HTTP {$last->response_code})."
            : 'Callback failed: '.($last?->error ?: 'HTTP '.$last?->response_code));
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->filtered($request)->with(['merchant', 'account'])->latest('id');

        Audit::log('payment.exported', null, [], $request->only(['status', 'merchant_id', 'account_id', 'q', 'from', 'to']));

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Payment ID', 'Transaction ID', 'Order ID', 'Merchant', 'Account', 'Amount', 'Fee', 'Net', 'Currency', 'Status', 'Created', 'Paid', 'Reference']);

            $query->chunk(500, function ($payments) use ($out) {
                foreach ($payments as $payment) {
                    fputcsv($out, array_map([$this, 'csvSafe'], [
                        $payment->payment_id,
                        $payment->transaction_id,
                        $payment->order_id,
                        $payment->merchant?->name,
                        $payment->account?->name,
                        $payment->amount,
                        $payment->fee_amount,
                        $payment->net_amount,
                        $payment->currency,
                        $payment->status->value,
                        $payment->created_at?->toDateTimeString(),
                        $payment->paid_at?->toDateTimeString(),
                        $payment->verification_reference,
                    ]));
                }
            });

            fclose($out);
        }, 'transactions-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Prevent spreadsheet formula injection in exported cells.
     */
    public function csvSafe(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    private function filtered(Request $request): Builder
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'merchant_id' => ['nullable', 'integer'],
            'account_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return Payment::query()
            ->when($request->input('status'), fn (Builder $q, $status) => $q->where('status', $status))
            ->when($request->input('merchant_id'), fn (Builder $q, $id) => $q->where('merchant_id', $id))
            ->when($request->input('account_id'), fn (Builder $q, $id) => $q->where('payment_account_id', $id))
            ->when($request->input('from'), fn (Builder $q, $from) => $q->where('created_at', '>=', $from.' 00:00:00'))
            ->when($request->input('to'), fn (Builder $q, $to) => $q->where('created_at', '<=', $to.' 23:59:59'))
            ->when($request->input('q'), function (Builder $q, $term) {
                $q->where(fn (Builder $inner) => $inner
                    ->where('payment_id', $term)
                    ->orWhere('transaction_id', $term)
                    ->orWhere('order_id', $term)
                    ->orWhere('verification_reference', $term));
            });
    }
}
