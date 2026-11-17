<?php

namespace App\Http\Controllers\Admin;

use App\Commerce\CommerceAccess;
use App\Commerce\SupplierSync;
use App\Models\SupplierSyncRun;
use App\Support\ImpersonationManager;
use Illuminate\Http\Request;

final class SupplierSyncController
{
    public function index()
    {
        CommerceAccess::authorize('supplier_sync.view');

        return view('admin.commerce.sync-index', ['runs' => SupplierSyncRun::latest('id')->paginate(20)]);
    }

    public function store(Request $request, SupplierSync $sync)
    {
        CommerceAccess::authorize('supplier_sync.view');
        CommerceAccess::authorize('supplier_sync.create');
        $request->validate(['file' => 'required|file|max:8192']);
        $run = $sync->preview(file_get_contents($request->file('file')->getRealPath()), $request->file('file')->getClientOriginalName(), (app(ImpersonationManager::class)->actor() ?? $request->user())->id);

        return redirect()->route('admin.supplier-sync.show', $run);
    }

    public function show(SupplierSyncRun $run, SupplierSync $sync)
    {
        CommerceAccess::authorize('supplier_sync.view');
        $rows = $run->status === 'invalid' ? [] : array_slice($sync->rows($run)['rows'], 0, 25);

        return view('admin.commerce.sync-show', compact('run', 'rows'));
    }

    public function apply(Request $request, SupplierSyncRun $run, SupplierSync $sync)
    {
        CommerceAccess::authorize('supplier_sync.view');
        CommerceAccess::authorize('supplier_sync.apply');
        $data = $request->validate(['confirm' => 'accepted', 'deactivate_missing' => 'sometimes|boolean', 'approve_large_changes' => 'sometimes|boolean']);
        if ($data['approve_large_changes'] ?? false) {
            CommerceAccess::authorize('supplier_sync.approve');
        }
        $sync->apply($run, (app(ImpersonationManager::class)->actor() ?? $request->user())->id, (bool) ($data['deactivate_missing'] ?? false), (bool) ($data['approve_large_changes'] ?? false));

        return back()->with('notify', ['type' => 'success', 'message' => 'Sincronización aplicada. Las personalizaciones se conservaron.']);
    }
}
