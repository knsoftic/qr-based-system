<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SmsDevice;
use App\Models\SmsMessage;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * SMS forwarder: registered phones and the SMS they forwarded.
 */
class SmsController extends Controller
{
    public const APK_PATH = 'apk/pkpay-sms-forwarder.apk';

    public function index(Request $request): View
    {
        $request->validate([
            'device_id' => ['nullable', 'integer'],
            'sender' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return view('admin.sms.index', [
            'messages' => SmsMessage::query()
                ->with('device')
                ->when($request->input('device_id'), fn ($q, $id) => $q->where('sms_device_id', $id))
                ->when($request->input('sender'), fn ($q, $sender) => $q->where('sender', 'like', '%'.$sender.'%'))
                ->when($request->input('from'), fn ($q, $from) => $q->where('received_at', '>=', $from.' 00:00:00'))
                ->when($request->input('to'), fn ($q, $to) => $q->where('received_at', '<=', $to.' 23:59:59'))
                ->when($request->boolean('with_amount'), fn ($q) => $q->whereNotNull('amount'))
                ->latest('received_at')
                ->latest('id')
                ->paginate(50)
                ->withQueryString(),
            'devices' => SmsDevice::query()->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function devices(): View
    {
        return view('admin.sms.devices', [
            'devices' => SmsDevice::query()->withCount('messages')->orderBy('name')->get(),
            'apkAvailable' => is_file(resource_path(self::APK_PATH)),
        ]);
    }

    public function storeDevice(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);

        $device = new SmsDevice(['name' => $data['name'], 'is_active' => true]);
        $key = $device->issueKey();
        $device->save();

        Audit::log('sms_device.created', $device, [], ['name' => $device->name]);

        return redirect()->route('admin.sms.devices')
            ->with('new_device_key', $key)
            ->with('new_device_name', $device->name)
            ->with('success', 'Device added. Enter the server URL and this key in the app; the key is shown only once.');
    }

    public function toggleDevice(SmsDevice $device): RedirectResponse
    {
        $device->forceFill(['is_active' => ! $device->is_active])->save();
        Audit::log($device->is_active ? 'sms_device.enabled' : 'sms_device.disabled', $device);

        return back()->with('success', $device->is_active ? 'Device enabled.' : 'Device disabled; it can no longer send SMS.');
    }

    public function rotateDevice(SmsDevice $device): RedirectResponse
    {
        $key = $device->issueKey();
        $device->save();

        Audit::log('sms_device.key_rotated', $device);

        return redirect()->route('admin.sms.devices')
            ->with('new_device_key', $key)
            ->with('new_device_name', $device->name)
            ->with('success', 'New key generated. The old key stopped working; update it in the app.');
    }

    public function downloadApp(): BinaryFileResponse
    {
        $path = resource_path(self::APK_PATH);
        abort_unless(is_file($path), 404, 'The app has not been built yet.');

        Audit::log('sms_device.app_downloaded');

        return response()->download($path, 'pkpay-sms-forwarder.apk', [
            'Content-Type' => 'application/vnd.android.package-archive',
        ]);
    }
}
