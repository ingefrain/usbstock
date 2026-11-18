<?php

namespace App\Http\Controllers\Admin;

use App\Commerce\CommerceAccess;
use App\Commerce\PublicQuoteRequests;
use App\Models\QuoteRequest;
use App\Models\CommercialSeller;
use App\Support\AdminAuditLogger;
use App\Support\ImpersonationManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class QuoteRequestController
{
    public function index(Request $http)
    {
        CommerceAccess::authorize('quote_requests.manage');

        $filters = $http->validate(['status' => 'nullable|in:received,review,discarded,converted', 'q' => 'nullable|string|max:180']);

        return view('admin.commerce.requests', ['requests' => QuoteRequest::with('seller', 'quote')->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))->when($filters['q'] ?? null, fn ($query, $term) => $query->where(fn ($q) => $q->where('seller_name', 'like', '%'.$term.'%')->orWhere('customer->name', 'like', '%'.$term.'%')))->latest()->paginate(20)->withQueryString(), 'sellers' => CommercialSeller::where('is_active', true)->orderBy('name')->get()]);
    }

    public function convert(Request $http, QuoteRequest $request, PublicQuoteRequests $service)
    {
        CommerceAccess::authorize('quote_requests.manage');
        CommerceAccess::authorize('quotes.view');
        CommerceAccess::authorize('quotes.manage');
        $actor = app(ImpersonationManager::class)->actor() ?? $http->user();
        $quote = $service->convert($request, $actor->id);

        return redirect()->route('admin.quotes.show', $quote)->with('notify', ['type' => 'success', 'message' => 'Borrador creado. Completa los precios de venta antes de emitir.']);
    }

    public function status(Request $http, QuoteRequest $request, AdminAuditLogger $audit)
    {
        CommerceAccess::authorize('quote_requests.manage');
        $data = $http->validate(['status' => 'required|in:review,discarded', 'confirm' => 'accepted']);
        DB::transaction(function () use ($request, $data, $audit) {
            $request = QuoteRequest::lockForUpdate()->findOrFail($request->id);
            abort_if($request->quote_id, 409);
            $request->update(['status' => $data['status'], 'revision' => $request->revision + 1]);
            $audit->record('quote_request.status_changed', 'quote_request', $request->uuid, after: ['status' => $data['status']]);
        });

        return back();
    }

    public function assignSeller(Request $http, QuoteRequest $request, PublicQuoteRequests $service)
    {
        CommerceAccess::authorize('quote_requests.manage');
        $data = $http->validate(['seller_id' => 'nullable|integer', 'revision' => 'required|integer|min:1', 'confirm' => 'accepted']);
        $actor = app(ImpersonationManager::class)->actor() ?? $http->user();
        $service->assignSeller($request, isset($data['seller_id']) ? (int) $data['seller_id'] : null, (int) $data['revision'], $actor->id);

        return back()->with('notify', ['type' => 'success', 'message' => 'Asociación guardada. El nombre original permanece intacto.']);
    }
}
