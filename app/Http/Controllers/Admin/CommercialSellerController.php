<?php

namespace App\Http\Controllers\Admin;

use App\Commerce\CommerceAccess;
use App\Models\CommercialSeller;
use App\Support\AdminAuditLogger;
use Illuminate\Http\Request;

final class CommercialSellerController
{
    public function index()
    {
        CommerceAccess::authorize('sellers.manage');

        return view('admin.commerce.sellers', ['sellers' => CommercialSeller::orderBy('name')->adminPaginate(200)]);
    }

    public function save(Request $request, AdminAuditLogger $audit, ?CommercialSeller $seller = null)
    {
        CommerceAccess::authorize('sellers.manage');
        $data = $request->validate(['name' => 'required|string|max:180', 'is_active' => $seller?->exists ? 'exclude' : 'required|boolean']);
        $seller ??= new CommercialSeller;
        $before = $seller->exists ? $seller->only(['name', 'is_active']) : null;
        $seller->fill($data)->save();
        $audit->record('commercial_seller.saved', 'commercial_seller', $seller->uuid, before: $before, after: $seller->only(['name', 'is_active']));

        return back()->with('notify', ['type' => 'success', 'message' => 'Vendedor guardado. Las cotizaciones emitidas conservan su nombre histórico.']);
    }

    public function status(Request $request, CommercialSeller $seller, AdminAuditLogger $audit)
    {
        CommerceAccess::authorize('sellers.manage');
        $data = $request->validate(['is_active' => 'required|boolean']);
        $before = $seller->is_active;
        $seller->update($data);
        $audit->record('commercial_seller.status_changed', 'commercial_seller', $seller->uuid, before: ['is_active' => $before], after: ['is_active' => $seller->is_active]);

        return $request->expectsJson() ? response()->json(['success' => true, 'is_active' => $seller->is_active, 'message' => 'Estado actualizado.']) : back();
    }
}
