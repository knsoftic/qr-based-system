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

    @php($reason = $account->maintenanceReason())

    <div class="page-head">
        <div>
            <h1>{{ $account->name }}</h1>
            <p>
                {{ $account->providerName() }} ·
                <span class="badge badge--{{ $account->decode_status->value }}">{{ $account->decode_status->label() }}</span>
                @if ($reason)
                    <span class="badge badge--warning">{{ $reason }}</span>
                @else
                    <span class="badge badge--active">Live</span>
                @endif
            </p>
        </div>
        <div class="actions">
            <a href="{{ route('admin.accounts.edit', $account) }}" class="btn">Edit</a>
            <form method="POST" action="{{ route('admin.accounts.maintenance', $account) }}" class="inline-form">
                @csrf
                <button type="submit" class="btn">{{ $account->maintenance ? 'End maintenance' : 'Maintenance' }}</button>
            </form>
            <form method="POST" action="{{ route('admin.accounts.toggle', $account) }}" class="inline-form">
                @csrf
                <button type="submit" class="btn {{ $account->is_active ? 'btn--danger' : 'btn--primary' }}">
                    {{ $account->is_active ? 'Disable' : 'Enable' }}
                </button>
            </form>
            <form method="POST" action="{{ route('admin.accounts.destroy', $account) }}" class="inline-form"
                  onsubmit="return confirm('Delete account &quot;{{ $account->name }}&quot;? Past transactions are kept but it stops receiving new payments.')">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn--danger">Delete</button>
            </form>
        </div>
    </div>

    @if ($reason && $account->is_active && $account->isDecoded())
        <div class="alert alert--warning"><strong>Account in maintenance mode:</strong> {{ $reason }}. It will not receive new payments until this clears (limits reset on the 1st, or end maintenance manually).</div>
    @endif

    @if ($account->decode_error)
        <div class="alert alert--danger"><strong>QR not usable:</strong> {{ $account->decode_error }}</div>
    @endif

    @if ($account->isDecoded())
        <div class="grid grid-4">
            <div class="stat">
                <div class="stat-label">Received this month</div>
                <div class="stat-value">{{ number_format($stats['month']['received'], 0) }}</div>
                <div class="stat-note">Today {{ number_format($stats['used_today'], 0) }}{{ $account->daily_limit !== null ? ' / '.number_format($account->daily_limit, 0) : '' }}</div>
            </div>
            <div class="stat">
                <div class="stat-label">Monthly limit used</div>
                <div class="stat-value">{{ $account->monthly_limit !== null ? number_format(min(100, $stats['used_month'] / max(1, (float) $account->monthly_limit) * 100), 0).'%' : '∞' }}</div>
                <div class="stat-note">{{ number_format($stats['used_month'], 0) }}{{ $account->monthly_limit !== null ? ' / '.number_format($account->monthly_limit, 0) : '' }}</div>
            </div>
            <div class="stat">
                <div class="stat-label">Verified this month</div>
                <div class="stat-value">{{ $stats['month']['verified'] }}</div>
                <div class="stat-note">{{ $stats['month']['attempts'] }} attempts · {{ $stats['month']['success_rate'] }}%</div>
            </div>
            <div class="stat">
                <div class="stat-label">Fees this month</div>
                <div class="stat-value">{{ number_format($stats['month']['fees'], 0) }}</div>
                <div class="stat-note">Net {{ number_format($stats['month']['net'], 0) }}</div>
            </div>
        </div>
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

    @if ($account->isDecoded())
        <div class="card">
            <div class="card-head"><h2>Account statistics</h2><span class="muted">Limits reset on the 1st; past months are kept below</span></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Period</th><th class="num">Attempts</th><th class="num">Verified</th><th class="num">Expired</th><th class="num">Failed</th><th class="num">Received</th><th class="num">Our fees</th><th class="num">Net to merchant</th><th class="num">Success</th></tr></thead>
                    <tbody>
                        <tr>
                            <td><strong>This month</strong></td>
                            <td class="num">{{ $stats['month']['attempts'] }}</td>
                            <td class="num">{{ $stats['month']['verified'] }}</td>
                            <td class="num">{{ $stats['month']['expired'] }}</td>
                            <td class="num">{{ $stats['month']['failed'] }}</td>
                            <td class="num">{{ number_format($stats['month']['received'], 2) }}</td>
                            <td class="num">{{ number_format($stats['month']['fees'], 2) }}</td>
                            <td class="num">{{ number_format($stats['month']['net'], 2) }}</td>
                            <td class="num">{{ $stats['month']['success_rate'] }}%</td>
                        </tr>
                        @foreach ($months as $m)
                            <tr>
                                <td>{{ $m->label() }}</td>
                                <td class="num">{{ $m->attempts }}</td>
                                <td class="num">{{ $m->verified }}</td>
                                <td class="num">{{ $m->expired }}</td>
                                <td class="num">{{ $m->failed }}</td>
                                <td class="num">{{ number_format($m->received, 2) }}</td>
                                <td class="num">{{ number_format($m->fees, 2) }}</td>
                                <td class="num">{{ number_format($m->net, 2) }}</td>
                                <td class="num">{{ $m->attempts ? round($m->verified / $m->attempts * 100, 1) : 0 }}%</td>
                            </tr>
                        @endforeach
                        <tr style="border-top: 2px solid var(--border)">
                            <td><strong>Lifetime</strong></td>
                            <td class="num">{{ $stats['all']['attempts'] }}</td>
                            <td class="num">{{ $stats['all']['verified'] }}</td>
                            <td class="num">{{ $stats['all']['expired'] }}</td>
                            <td class="num">{{ $stats['all']['failed'] }}</td>
                            <td class="num">{{ number_format($stats['all']['received'], 2) }}</td>
                            <td class="num">{{ number_format($stats['all']['fees'], 2) }}</td>
                            <td class="num">{{ number_format($stats['all']['net'], 2) }}</td>
                            <td class="num">{{ $stats['all']['success_rate'] }}%</td>
                        </tr>
                    </tbody>
                </table>
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
