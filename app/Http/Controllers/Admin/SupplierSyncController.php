<?php

namespace App\Http\Controllers\Admin;

use App\Commerce\CommerceAccess;
use App\Commerce\SupplierNotifications;
use App\Commerce\SupplierSync;
use App\Models\SupplierSyncRun;
use App\Support\AdminAuditLogger;
use App\Support\ImpersonationManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SupplierSyncController
{
    public function index()
    {
        CommerceAccess::authorize('supplier_sync.view');

        return view('admin.commerce.sync-index', ['runs' => SupplierSyncRun::with('notifications.outbox')->latest('id')->adminPaginate(50), 'pending' => SupplierSyncRun::where('status', 'review')->count()]);
    }

    public function store(Request $request, SupplierSync $sync)
    {
        CommerceAccess::authorize('supplier_sync.view');
        CommerceAccess::authorize('supplier_sync.create');
        $request->validate(['file' => 'required|file|max:8192']);
        $run = $sync->preview(file_get_contents($request->file('file')->getRealPath()), $request->file('file')->getClientOriginalName(), (app(ImpersonationManager::class)->actor() ?? $request->user())->id);
        app(SupplierNotifications::class)->record($run);

        return redirect()->route('admin.supplier-sync.show', $run);
    }

    public function show(SupplierSyncRun $run, SupplierSync $sync)
    {
        CommerceAccess::authorize('supplier_sync.view');
        $rows = in_array($run->status, ['ready', 'review', 'completed'], true) && $run->path ? array_slice($sync->rows($run)['rows'], 0, 25) : [];
        $run->load('notifications.outbox');

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
        $applied = $sync->apply($run, (app(ImpersonationManager::class)->actor() ?? $request->user())->id, (bool) ($data['deactivate_missing'] ?? false), (bool) ($data['approve_large_changes'] ?? false));
        app(SupplierNotifications::class)->record($applied);

        return back()->with('notify', ['type' => 'success', 'message' => 'Sincronización aplicada. Las personalizaciones se conservaron.']);
    }

    public function dismiss(Request $request, SupplierSyncRun $run)
    {
        CommerceAccess::authorize('supplier_sync.view');
        CommerceAccess::authorize('supplier_sync.approve');
        $data = $request->validate(['reason' => 'required|string|min:10|max:500']);
        DB::transaction(function () use ($run, $request, $data): void {
            DB::table('commerce_locks')->where('id', 1)->lockForUpdate()->first();
            $run = SupplierSyncRun::lockForUpdate()->findOrFail($run->id);
            abort_unless($run->status === 'review', 409);
            $run->update(['status' => 'dismissed']);
            app(AdminAuditLogger::class)->record('supplier_sync.dismissed', 'supplier_sync', $run->uuid, after: ['reason' => $data['reason']], actorId: (app(ImpersonationManager::class)->actor() ?? $request->user())->id);
        });

        return back()->with('notify', ['type' => 'success', 'message' => 'Revisión cerrada sin aplicar. Se conservaron archivo, diferencias e historia.']);
    }
}
