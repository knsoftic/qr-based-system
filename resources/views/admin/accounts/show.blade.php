@extends('layouts.admin')

@section('title', $account->name)

@section('content')
    @php($summary = $account->payload_summary ?? [])

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
            <div class="card-head"><h2>Decoded payload</h2></div>
            <div class="card-body">
                @if ($account->isDecoded())
                    <dl class="kv">
                        <dt>Format</dt><dd>{{ $summary['format'] ?? '—' }}</dd>
                        <dt>Original QR type</dt><dd>{{ ucfirst($summary['initiation'] ?? '—') }}</dd>
                        <dt>Merchant name</dt><dd>{{ $summary['merchant_name'] ?? '—' }}</dd>
                        <dt>City / country</dt><dd>{{ $summary['merchant_city'] ?? '—' }}, {{ $summary['country'] ?? '—' }}</dd>
                        <dt>Currency (ISO 4217)</dt><dd>{{ $summary['currency'] ?? '—' }}</dd>
                        <dt>Category code</dt><dd>{{ $summary['mcc'] ?? '—' }}</dd>
                        <dt>Checksum</dt><dd><span class="badge badge--success">CRC {{ $summary['checksum'] ?? '' }}</span></dd>
                        <dt>Decoded at</dt><dd>{{ $account->decoded_at?->format('d M Y, H:i') }}</dd>
                    </dl>

                    <h3 style="margin-top: 1.25rem">Account identifiers (masked)</h3>
                    @foreach ($summary['accounts'] ?? [] as $acc)
                        <div class="muted" style="margin-bottom: .4rem">
                            Tag {{ $acc['tag'] }}@if ($acc['gui']) · <span class="mono">{{ $acc['gui'] }}</span>@endif
                            @foreach ($acc['identifiers'] as $sub => $value)
                                <div class="mono">{{ $sub }}: {{ $value }}</div>
                            @endforeach
                        </div>
                    @endforeach
                @else
                    <p class="muted">No verified payload. Upload a valid authorized QR below.</p>
                @endif
            </div>
        </div>

        <div>
            @if ($account->isDecoded())
                <div class="card">
                    <div class="card-head"><h2>Payload template</h2></div>
                    <div class="card-body">
                        <p class="muted">Fields changed for each payment (per provider rules):</p>
                        <dl class="kv">
                            @foreach ($summary['dynamic_fields'] ?? [] as $tag => $label)
                                <dt class="mono">{{ $tag }}</dt><dd>{{ $label }}</dd>
                            @endforeach
                            <dt>Date / time</dt>
                            <dd class="muted">{{ $summary['datetime_field'] ?? 'Not part of this format; not written.' }}</dd>
                        </dl>
                        <p class="muted" style="margin-top: .75rem">All other fields are copied unchanged. The original payload is stored encrypted and is never shown to customers.</p>
                    </div>
                </div>
            @endif

            <div class="card">
                <div class="card-head"><h2>{{ $account->qr_image_path ? 'Replace QR' : 'Upload QR' }}</h2></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.accounts.qr', $account) }}" enctype="multipart/form-data">
                        @csrf
                        <div class="form-row">
                            <label for="provider">Provider</label>
                            <select id="provider" name="provider">
                                @foreach ($providers as $key => $name)
                                    <option value="{{ $key }}" @selected($account->provider === $key)>{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-row">
                            <label for="qr_image">QR image</label>
                            <input id="qr_image" type="file" name="qr_image" accept="image/png,image/jpeg,image/gif,image/webp" required>
                        </div>
                        <button type="submit" class="btn btn--primary">Upload &amp; decode</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <h2>Recent payments on this account</h2>
            <a href="{{ route('admin.transactions.index', ['account_id' => $account->id]) }}">View all →</a>
        </div>
        @include('admin.transactions._table', ['payments' => $payments])
    </div>
@endsection
