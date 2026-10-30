<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Payout;
use App\Payments\Exceptions\GatewayException;
use App\Payments\PayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Merchant withdrawals over the API. Payouts are PKR to easypaisa / JazzCash
 * and are approved manually by an admin after the money is sent.
 */
class PayoutController extends Controller
{
    public function __construct(private readonly PayoutService $payouts)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $merchant = $this->merchant($request);

        $data = $request->validate([
            'method' => ['required', Rule::in(array_keys(Payout::METHODS))],
            'account_number' => ['required', 'string', 'regex:/^0?3\d{2}[\s-]?\d{7}$/'],
            'account_name' => ['nullable', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'account_number.regex' => 'Enter a valid mobile number, e.g. 03001234567.',
        ]);

        $data['account_number'] = $this->normalise($data['account_number']);
        $payout = $this->payouts->request($merchant, $data);

        return response()->json(['data' => $payout->toApiArray()], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $payouts = $this->merchant($request)->payouts()->latest('id')->limit(50)->get();

        return response()->json(['data' => $payouts->map->toApiArray()]);
    }

    public function show(Request $request, string $ref): JsonResponse
    {
        $payout = $this->merchant($request)->payouts()->where('payout_ref', $ref)->first();

        if (! $payout) {
            throw new GatewayException('Payout not found.', 'not_found', 404);
        }

        return response()->json(['data' => $payout->toApiArray()]);
    }

    private function merchant(Request $request): Merchant
    {
        return $request->attributes->get('merchant');
    }

    private function normalise(string $number): string
    {
        $digits = preg_replace('/\D/', '', $number);

        return strlen($digits) === 10 ? '0'.$digits : $digits;
    }
}
