@extends('layouts.admin')

@section('title', 'Audit Log')

@section('content')
    <div class="page-head">
        <div>
            <h1>Audit log</h1>
            <p>Every admin action. Secrets and payloads are always redacted.</p>
        </div>
        <form method="GET" class="filters">
            <div class="form-row">
                <label for="action">Action</label>
                <select id="action" name="action" onchange="this.form.submit()">
                    <option value="">All actions</option>
                    @foreach ($actions as $action)
                        <option value="{{ $action }}" @selected(request('action') === $action)>{{ $action }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table>
                <thead><tr><th>Time</th><th>Admin</th><th>Action</th><th>Subject</th><th>Changes</th><th>IP</th></tr></thead>
                <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td class="nowrap">{{ $log->created_at->format('d M Y, H:i:s') }}</td>
                        <td>{{ $log->user?->name ?? '—' }}</td>
                        <td><span class="badge badge--info">{{ $log->action }}</span></td>
                        <td class="nowrap">{{ $log->auditable_type ? class_basename($log->auditable_type).' #'.$log->auditable_id : '—' }}</td>
                        <td>
                            @if ($log->old_values || $log->new_values)
                                <pre class="json">{{ json_encode(array_filter(['old' => $log->old_values, 'new' => $log->new_values]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                            @else
                                <span class="muted">—</span>
                            @endif
                        </td>
                        <td class="mono">{{ $log->ip }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty">No audit entries.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $logs->links() }}
    </div>
@endsection
