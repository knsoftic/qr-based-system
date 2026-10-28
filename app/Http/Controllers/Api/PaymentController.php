<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CreatePaymentRequest;
use App\Models\Payment;
use App\Payments\Exceptions\GatewayException;
use App\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments)
    {
    }

    public function store(CreatePaymentRequest $request): JsonResponse
    {
        $key = (string) $request->header('Idempotency-Key');

        if (! preg_match('/^[A-Za-z0-9_\-:.]{8,128}$/', $key)) {
            throw new GatewayException('A valid Idempotency-Key header (8-128 characters: letters, digits, _ - : .) is required.', 'idempotency_key_required', 400);
        }

        [$payment, $replayed] = $this->payments->create($request->merchant(), $request->validated(), $key, $request->ip());

        return response()
            ->json(['data' => $payment->toApiArray()], $replayed ? 200 : 201)
            ->header('Idempotent-Replayed', $replayed ? 'true' : 'false');
    }

    public function show(Request $request, string $paymentId): JsonResponse
    {
        $payment = Payment::query()
            ->where('merchant_id', $request->attributes->get('merchant')->id)
            ->where('payment_id', $paymentId)
            ->first();

        if (! $payment) {
            throw new GatewayException('Payment not found.', 'not_found', 404);
        }

        $this->payments->expireIfLapsed($payment);

        return response()->json(['data' => $payment->toApiArray()]);
    }
}
