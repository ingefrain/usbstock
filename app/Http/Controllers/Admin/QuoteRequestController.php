<?php

namespace App\Http\Controllers\Admin;

use App\Commerce\CommerceAccess;
use App\Commerce\PublicQuoteRequests;
use App\Models\QuoteRequest;
use App\Support\AdminAuditLogger;
use App\Support\ImpersonationManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class QuoteRequestController
{
    public function index()
    {
        CommerceAccess::authorize('quote_requests.manage');

        return view('admin.commerce.requests', ['requests' => QuoteRequest::latest()->paginate(20)]);
    }

    public function convert(Request $http, QuoteRequest $request, PublicQuoteRequests $service)
    {
        CommerceAccess::authorize('quote_requests.manage');
        CommerceAccess::authorize('quotes.view');
        CommerceAccess::authorize('quotes.manage');
        $actor = app(ImpersonationManager::class)->actor() ?? $http->user();
        $quote = $service->convert($request, $actor->id, $http->user()->name);

        return redirect()->route('admin.quotes.show', $quote)->with('notify', ['type' => 'success', 'message' => 'Borrador creado. Completa los precios de venta antes de emitir.']);
    }

    public function status(Request $http, QuoteRequest $request, AdminAuditLogger $audit)
    {
        CommerceAccess::authorize('quote_requests.manage');
        $data = $http->validate(['status' => 'required|in:review,discarded', 'confirm' => 'accepted']);
        DB::transaction(function () use ($request, $data, $audit) {
            $request = QuoteRequest::lockForUpdate()->findOrFail($request->id);
            abort_if($request->quote_id, 409);
            $request->update(['status' => $data['status']]);
            $audit->record('quote_request.status_changed', 'quote_request', $request->uuid, after: ['status' => $data['status']]);
        });

        return back();
    }
}
