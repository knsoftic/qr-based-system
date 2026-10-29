@extends('layouts.admin')

@section('title', 'SMS Devices')

@section('content')
    <div class="page-head">
        <div>
            <h1>SMS devices</h1>
            <p>Phones running the SMS forwarder app. Every new SMS on the phone is sent to this server.</p>
        </div>
        <div class="actions">
            <a href="{{ route('admin.sms.index') }}" class="btn"><x-icon name="message" :size="16" /> SMS inbox</a>
            @if ($apkAvailable)
                <a href="{{ route('admin.sms.app') }}" class="btn btn--primary"><x-icon name="download" :size="16" /> Download Android app (APK)</a>
            @endif
        </div>
    </div>

    @if (session('new_device_key'))
        <div class="alert alert--warning">
            <strong>Setup for "{{ session('new_device_name') }}": enter these two values in the app. The key is shown only once.</strong>
            <div class="grid grid-2" style="margin-top: .5rem">
                <div>Server URL<div class="secret-box">{{ url('/') }}</div></div>
                <div>Device key<div class="secret-box">{{ session('new_device_key') }}</div></div>
            </div>
        </div>
    @endif

    <div class="grid grid-2">
        <div class="card">
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Device</th><th>Last seen</th><th class="num">SMS</th><th>State</th><th></th></tr></thead>
                    <tbody>
                    @forelse ($devices as $device)
                        <tr>
                            <td>
                                <strong>{{ $device->name }}</strong>
                                <div class="muted mono" style="font-size: .75rem">key …{{ $device->key_hint }}</div>
                                @if ($device->device_info)
                                    <div class="muted" style="font-size: .75rem">
                                        {{ collect([$device->device_info['manufacturer'] ?? null, $device->device_info['model'] ?? null])->filter()->implode(' ') }}
                                        @if (! empty($device->device_info['android'])) · Android {{ $device->device_info['android'] }} @endif
                                        @if ($device->app_version) · app {{ $device->app_version }} @endif
                                    </div>
                                @endif
                            </td>
                            <td class="nowrap">
                                {{ $device->last_seen_at?->diffForHumans() ?? 'Never' }}
                                @if ($device->last_ip)<div class="muted mono" style="font-size: .75rem">{{ $device->last_ip }}</div>@endif
                            </td>
                            <td class="num">{{ $device->messages_count }}</td>
                            <td><span class="badge badge--{{ $device->is_active ? 'active' : 'inactive' }}">{{ $device->is_active ? 'Active' : 'Disabled' }}</span></td>
                            <td class="text-right nowrap">
                                <form method="POST" action="{{ route('admin.sms.devices.rotate', $device) }}" class="inline-form"
                                      onsubmit="return confirm('Generate a new key? The app stops sending until you enter the new key.')">
                                    @csrf
                                    <button type="submit" class="btn btn--sm">New key</button>
                                </form>
                                <form method="POST" action="{{ route('admin.sms.devices.toggle', $device) }}" class="inline-form">
                                    @csrf
                                    <button type="submit" class="btn btn--sm {{ $device->is_active ? 'btn--danger' : '' }}">{{ $device->is_active ? 'Disable' : 'Enable' }}</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="empty">No devices yet. Add the phone that receives payment SMS.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div>
            <div class="card">
                <div class="card-head"><h2>Auto-verification</h2></div>
                <div class="card-body">
                    <p class="muted mt-0">When a forwarded SMS says money was received and it matches exactly one pending payment of that amount, the payment is marked paid automatically and the merchant callback is sent. Two payments of the same amount at once are never auto-verified.</p>
                    <form method="POST" action="{{ route('admin.sms.settings') }}">
                        @csrf
                        <div class="form-row">
                            <label class="checkbox"><input type="checkbox" name="sms_auto_verify" value="1" @checked($settings['verification.sms_auto_verify'])> Auto-verify payments from SMS</label>
                        </div>
                        <div class="form-row">
                            <label for="sms_threshold">Match threshold (%)</label>
                            <input id="sms_threshold" type="number" name="sms_threshold" min="50" max="100" value="{{ $settings['verification.sms_threshold'] }}">
                            <div class="hint">A unique pending payment with the exact amount scores ≥ 90%. Raise this to be stricter.</div>
                        </div>
                        <button type="submit" class="btn btn--primary">Save</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-head"><h2>Add a device</h2></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.sms.devices.store') }}">
                        @csrf
                        <div class="form-row">
                            <label for="name">Device name</label>
                            <input id="name" type="text" name="name" required maxlength="100" placeholder="e.g. easypaisa phone (SIM 1)">
                        </div>
                        <button type="submit" class="btn btn--primary"><x-icon name="plus" :size="16" /> Add device &amp; create key</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-head"><h2>Setup on the phone</h2></div>
                <div class="card-body">
                    <ol style="padding-left: 1.1rem; margin: 0">
                        <li>Download the APK above on the phone that receives the payment SMS and install it (allow "install unknown apps" once).</li>
                        <li>Open <strong>PKPay SMS Forwarder</strong>, tap <strong>Allow SMS access</strong>.</li>
                        <li>Enter the <strong>Server URL</strong> and <strong>Device key</strong>, tap <strong>Save</strong>, then <strong>Test connection</strong>.</li>
                        <li>Tap <strong>Battery settings</strong> and allow the app to run in the background, so no SMS is missed.</li>
                    </ol>
                    <p class="muted" style="margin-top: .75rem">
                        Every new SMS (sender, full text, time, SIM) is sent over HTTPS. SMS that fail to send are kept on the phone and retried automatically.
                        In the app you can limit forwarding to certain senders (for example only easypaisa / JazzCash), so OTPs from other apps are not forwarded.
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection
