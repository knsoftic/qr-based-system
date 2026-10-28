@extends('layouts.admin')

@section('title', $merchant->name)

@section('content')
    <div class="page-head">
        <div>
            <h1>{{ $merchant->name }}</h1>
            <p>
                <span class="badge badge--{{ $merchant->is_active ? 'active' : 'inactive' }}">{{ $merchant->is_active ? 'Active' : 'Disabled' }}</span>
                @if ($merchant->website) · <a href="{{ $merchant->website }}" target="_blank" rel="noopener noreferrer">{{ $merchant->website }}</a>@endif
            </p>
        </div>
        <div class="actions">
            <a href="{{ route('admin.merchants.edit', $merchant) }}" class="btn">Edit</a>
            <form method="POST" action="{{ route('admin.merchants.toggle', $merchant) }}" class="inline-form">
                @csrf
                <button type="submit" class="btn {{ $merchant->is_active ? 'btn--danger' : 'btn--primary' }}">{{ $merchant->is_active ? 'Disable' : 'Enable' }}</button>
            </form>
        </div>
    </div>

    @if (session('new_secret'))
        <div class="alert alert--warning">
            <strong>API secret: copy it now.</strong> It is stored encrypted and will not be shown again.
            <div class="secret-box">{{ session('new_secret') }}</div>
        </div>
    @endif

    <div class="grid grid-2">
        <div class="card">
            <div class="card-head"><h2>API credentials</h2></div>
            <div class="card-body">
                <dl class="kv">
                    <dt>API key</dt><dd class="mono">{{ $merchant->api_key }}</dd>
                    <dt>API secret</dt><dd class="mono">sk_••••••••••••••••</dd>
                    <dt>Secret rotated</dt><dd>{{ $merchant->secret_rotated_at?->format('d M Y, H:i') ?? '—' }}</dd>
                    <dt>Endpoint</dt><dd class="mono">{{ url('/api/v1/payments') }}</dd>
                </dl>
                <form method="POST" action="{{ route('admin.merchants.rotate', $merchant) }}" style="margin-top: 1rem"
                      onsubmit="return confirm('Rotate the API secret? The current secret stops working immediately.')">
                    @csrf
                    <button type="submit" class="btn btn--danger">Rotate secret</button>
                    <a href="{{ route('docs') }}" target="_blank" class="btn">Integration docs</a>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>Settings</h2></div>
            <div class="card-body">
                <dl class="kv">
                    <dt>Contact</dt><dd>{{ $merchant->email ?: '—' }}</dd>
                    <dt>Callback URL</dt><dd class="mono">{{ $merchant->callback_url ?: '—' }}</dd>
                    <dt>Fees</dt>
                    <dd>
                        @if ($merchant->fee_percent !== null || $merchant->fee_fixed !== null)
                            {{ $merchant->fee_percent ?? 0 }}% + {{ $merchant->fee_fixed ?? 0 }} (merchant specific)
                        @else
                            Global fee settings
                        @endif
                    </dd>
                    <dt>Created</dt><dd>{{ $merchant->created_at->format('d M Y') }}</dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <h2>Recent payments</h2>
            <a href="{{ route('admin.transactions.index', ['merchant_id' => $merchant->id]) }}">View all →</a>
        </div>
        @include('admin.transactions._table', ['payments' => $payments, 'hideMerchant' => true])
    </div>
@endsection
