<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SmsDevice;
use App\Models\SmsMessage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Receives SMS from the Android forwarder app.
 */
class SmsController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $device = $this->device($request);

        $data = $request->validate([
            'message_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-]+$/'],
            'sender' => ['required', 'string', 'max:64'],
            'body' => ['required', 'string', 'max:5000'],
            'received_at' => ['required', 'integer', 'min:1500000000000'],
            'sim_slot' => ['nullable', 'integer', 'min:0', 'max:9'],
            'sim_name' => ['nullable', 'string', 'max:64'],
        ]);

        $this->touch($device, $request);

        $attributes = [
            'sender' => $data['sender'],
            'body' => $data['body'],
            'received_at' => Carbon::createFromTimestampMs($data['received_at'])->utc(),
            'sim_slot' => $data['sim_slot'] ?? null,
            'sim_name' => $data['sim_name'] ?? null,
            'amount' => SmsMessage::detectAmount($data['body']),
        ];

        try {
            $message = SmsMessage::query()->firstOrCreate(
                ['sms_device_id' => $device->id, 'message_uuid' => $data['message_id']],
                $attributes,
            );
        } catch (UniqueConstraintViolationException) {
            // The same SMS was retried in parallel; the first copy won.
            $message = SmsMessage::query()->where('sms_device_id', $device->id)->where('message_uuid', $data['message_id'])->firstOrFail();
        }

        $created = $message->wasRecentlyCreated;

        return response()->json([
            'status' => $created ? 'stored' : 'duplicate',
            'id' => $message->id,
            'amount' => $message->amount,
        ], $created ? 201 : 200);
    }

    /**
     * "Test connection" from the app: confirms URL + key and records the device.
     */
    public function ping(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $this->touch($device, $request);

        return response()->json([
            'status' => 'ok',
            'device' => $device->name,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    private function device(Request $request): SmsDevice
    {
        return $request->attributes->get('sms_device');
    }

    private function touch(SmsDevice $device, Request $request): void
    {
        $info = $request->validate([
            'app_version' => ['nullable', 'string', 'max:20'],
            'device' => ['nullable', 'array:model,manufacturer,android'],
            'device.*' => ['nullable', 'string', 'max:64'],
        ]);

        $device->forceFill([
            'last_seen_at' => now(),
            'last_ip' => $request->ip(),
            'app_version' => $info['app_version'] ?? $device->app_version,
            'device_info' => $info['device'] ?? $device->device_info,
        ])->save();
    }
}
