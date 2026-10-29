@extends('layouts.admin')

@section('title', $account->name)

@section('content')
    @php
        $summary = $account->payload_summary ?? [];
        $behaviours = [
            'fixed' => ['Copied as-is', 'inactive', 'Kept exactly as in the uploaded QR.'],
            'editable' => ['Editable', 'warning', 'You can change it below; copied as-is into every payment.'],
            'per_payment' => ['Set per payment', 'info', 'The gateway writes a new value into every payment\'s QR.'],
            'checksum' => ['Recalculated', 'info', 'Computed again for every QR the gateway builds.'],
            'removed' => ['Removed', 'danger', 'Left out of payment QRs so the amount stays exact.'],
        ];
    @endphp

    <div class="page-head">
        <div>
            <h1>{{ $account->name }}</h1>
            <p>
                {{ $account->providerName() }} ·
                <span class="badge badge--{{ $account->decode_status->value }}">{{ $account->decode_status->label() }}</span>
                <span class="badge badge--{{ $account->is_active ? 'active' : 'inactive' }}">{{ $account->is_active ? 'Active' : 'Disabled' }}</span>
            </p>
        </div>
        <div class="actions">
            <a href="{{ route('admin.accounts.edit', $account) }}" class="btn">Edit</a>
            <form method="POST" action="{{ route('admin.accounts.toggle', $account) }}" class="inline-form">
                @csrf
                <button type="submit" class="btn {{ $account->is_active ? 'btn--danger' : 'btn--primary' }}">
                    {{ $account->is_active ? 'Disable account' : 'Enable account' }}
                </button>
            </form>
        </div>
    </div>

    @if ($account->decode_error)
        <div class="alert alert--danger"><strong>QR not usable:</strong> {{ $account->decode_error }}</div>
    @endif

    <div class="grid grid-2">
        <div class="card">
            <div class="card-head"><h2>QR summary</h2></div>
            <div class="card-body">
                @if ($account->isDecoded())
                    <dl class="kv">
                        @foreach ($summary['details'] ?? ['Format' => $summary['format'] ?? '—'] as $label => $value)
                            <dt>{{ $label }}</dt><dd>{{ $value }}</dd>
                        @endforeach
                        <dt>Checksum</dt><dd><span class="badge badge--success">CRC {{ $summary['checksum'] ?? '' }}</span></dd>
                        <dt>Decoded at</dt><dd>{{ $account->decoded_at?->format('d M Y, H:i') }}</dd>
                        <dt>Added from</dt>
                        <dd>{{ ['image' => 'Uploaded QR image', 'text' => 'Pasted QR text', 'iban' => 'IBAN (standard Raast P2P QR built by the gateway)'][$summary['source'] ?? 'image'] ?? 'Uploaded QR image' }}</dd>
                        @if (! empty($summary['edited_at']))
                            <dt>Edited</dt>
                            <dd>
                                <span class="badge badge--warning">Manually edited</span>
                                {{ \Illuminate\Support\Carbon::parse($summary['edited_at'])->format('d M Y, H:i') }}
                                (fields {{ implode(', ', $summary['edited_tags'] ?? []) }}). See the audit log for old values.
                            </dd>
                        @endif
                    </dl>
                @else
                    <p class="muted">No verified payload. Upload a valid authorized QR.</p>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>{{ $account->qr_image_path ? 'Replace QR' : 'Upload QR' }}</h2></div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.accounts.qr', $account) }}" enctype="multipart/form-data">
                    @csrf
                    @include('admin.accounts._qr-source', ['providers' => $providers, 'selectedProvider' => $account->isDecoded() ? $account->provider : 'auto'])
                    <button type="submit" class="btn btn--primary">Replace &amp; verify</button>
                    <div class="hint">A new QR replaces all fields below, including manual edits.</div>
                </form>
            </div>
        </div>
    </div>

    @if ($account->isDecoded())
        <div class="card" id="decoded">
            <div class="card-head">
                <h2>Decoded QR data</h2>
                <span class="muted">{{ count($fields) }} fields · admin only, never shown to customers</span>
            </div>

            <form method="POST" action="{{ route('admin.accounts.payload', $account) }}">
                @csrf
                @method('PUT')
                <div class="table-wrap">
                    <table class="decoded-table">
                        <thead>
                        <tr>
                            <th>Tag</th>
                            <th>Field</th>
                            <th>What it does</th>
                            <th>Value</th>
                            <th>On each payment</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($fields as $row)
                            @include('admin.accounts._field-row', ['row' => $row, 'depth' => 0])
                            @foreach ($row['children'] as $child)
                                @include('admin.accounts._field-row', ['row' => $child, 'depth' => 1])
                            @endforeach
                        @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($editable)
                    <div class="card-body decoded-save">
                        <div class="filters">
                            <div class="form-row">
                                <label for="payload_password">Your admin password</label>
                                <input id="payload_password" type="password" name="password" required autocomplete="current-password">
                            </div>
                            <button type="submit" class="btn btn--primary">Save changes</button>
                        </div>
                        <p class="hint mb-0">
                            Only the <span class="badge badge--warning">Editable</span> fields can change. The QR data is rebuilt, its checksum recalculated and
                            verified before saving, and the change is written to the audit log. New payments use it; QR codes already shown keep the old data.
                        </p>
                    </div>
                @endif
            </form>

            <div class="card-body" style="border-top: 1px solid var(--border)">
                <details>
                    <summary class="muted">Raw payload ({{ strlen($account->raw_payload) }} characters)</summary>
                    <pre class="json" style="white-space: pre-wrap; word-break: break-all">{{ $account->raw_payload }}</pre>
                </details>
                <dl class="kv" style="margin-top: .75rem">
                    @foreach ($behaviours as [$label, $badge, $meaning])
                        <dt><span class="badge badge--{{ $badge }}">{{ $label }}</span></dt><dd class="muted">{{ $meaning }}</dd>
                    @endforeach
                </dl>
            </div>
        </div>
    @endif

    <div class="card">
        <div class="card-head">
            <h2>Recent payments on this account</h2>
            <a href="{{ route('admin.transactions.index', ['account_id' => $account->id]) }}">View all →</a>
        </div>
        @include('admin.transactions._table', ['payments' => $payments])
    </div>
@endsection
