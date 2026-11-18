<?php

namespace App\Http\Controllers\Admin;

use App\Commerce\CommerceAccess;
use App\Commerce\CommercialPricing;
use App\Commerce\Money;
use App\Models\AdminAuditEvent;
use App\Models\CommercialPriceScale;
use App\Models\ProductVariant;
use App\Support\ImpersonationManager;
use Illuminate\Http\Request;

final class CommercialPriceScaleController
{
    public function index(Request $request, CommercialPricing $pricing)
    {
        CommerceAccess::authorize('commerce.configure');
        $data = $request->validate(['cost' => ['nullable', 'regex:/^(0|[1-9][0-9]{0,6})(\.\d{1,2})?$/'], 'quantity' => 'nullable|integer|min:50|max:1000000']);
        $simulation = filled($data['cost'] ?? null) && filled($data['quantity'] ?? null) ? $pricing->calculate(Money::parse($data['cost']), (int) $data['quantity']) : null;

        return view('admin.commerce.price-scales', ['scales' => CommercialPriceScale::orderBy('lower_minor')->adminPaginate(50), 'simulation' => $simulation]);
    }

    public function form(?CommercialPriceScale $scale = null)
    {
        CommerceAccess::authorize('commerce.configure');

        return view('admin.commerce.price-scale', ['scale' => $scale ?? new CommercialPriceScale(['revision' => 1, 'is_active' => false, 'lower_minor' => 0, 'increment_basis_points' => 0])]);
    }

    public function save(Request $request, CommercialPricing $pricing, ?CommercialPriceScale $scale = null)
    {
        CommerceAccess::authorize('commerce.configure');
        $pricing->save($request->all(), (app(ImpersonationManager::class)->actor() ?? $request->user())->id, $scale);

        return redirect()->route('admin.price-scales.index')->with('notify', ['type' => 'success', 'message' => 'Escala guardada. Los precios de cotizaciones existentes no se modificaron.']);
    }

    public function status(Request $request, CommercialPricing $pricing, CommercialPriceScale $scale)
    {
        CommerceAccess::authorize('commerce.configure');
        $input = $request->validate(['is_active' => 'required|boolean', 'revision' => 'required|integer|min:1']);
        $updated = $pricing->save($input + ['name' => $scale->name, 'lower' => Money::decimal($scale->lower_minor), 'upper' => $scale->upper_minor === null ? null : Money::decimal($scale->upper_minor), 'increment' => Money::decimal($scale->increment_basis_points), 'source_reference' => $scale->source_reference], (app(ImpersonationManager::class)->actor() ?? $request->user())->id, $scale);

        return $request->expectsJson() ? response()->json(['success' => true, 'is_active' => (bool) $input['is_active'], 'revision' => $updated->revision]) : back()->with('notify', ['type' => 'success', 'message' => 'Estado actualizado.']);
    }

    public function history(CommercialPriceScale $scale)
    {
        CommerceAccess::authorize('commerce.configure');

        return view('admin.commerce.price-scale-history', ['scale' => $scale, 'events' => AdminAuditEvent::where('entity_type', 'price_scale')->where('entity_id', $scale->uuid)->with('actor')->latest('id')->adminPaginate(50)]);
    }

    public function proposal(Request $request, CommercialPricing $pricing)
    {
        CommerceAccess::authorize('quotes.view');
        CommerceAccess::authorize('quotes.manage');
        $data = $request->validate(['variant_id' => 'required|integer|exists:product_variants,id', 'quantity' => 'required|integer|min:50|max:1000000']);

        return response()->json($pricing->proposal(ProductVariant::findOrFail($data['variant_id']), (int) $data['quantity']))->header('Cache-Control', 'no-store, private');
    }
}
