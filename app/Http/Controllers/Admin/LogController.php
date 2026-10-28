<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CallbackDelivery;
use App\Models\PaymentEvent;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LogController extends Controller
{
    public function audit(Request $request): View
    {
        return view('admin.logs.audit', [
            'logs' => AuditLog::query()
                ->with('user')
                ->when($request->input('action'), fn ($q, $action) => $q->where('action', 'like', $action.'%'))
                ->latest('id')
                ->paginate(50)
                ->withQueryString(),
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
        ]);
    }

    public function events(Request $request): View
    {
        return view('admin.logs.events', [
            'events' => PaymentEvent::query()
                ->with('payment')
                ->when($request->input('event'), fn ($q, $event) => $q->where('event', $event))
                ->latest('id')
                ->paginate(50)
                ->withQueryString(),
            'types' => PaymentEvent::query()->distinct()->orderBy('event')->pluck('event'),
        ]);
    }

    public function callbacks(Request $request): View
    {
        return view('admin.logs.callbacks', [
            'deliveries' => CallbackDelivery::query()
                ->with('payment')
                ->when($request->filled('success'), fn ($q) => $q->where('success', $request->boolean('success')))
                ->latest('id')
                ->paginate(50)
                ->withQueryString(),
        ]);
    }
}
