<?php

namespace App\Http\Controllers\Admin;

use App\Commerce\CommerceAccess;
use App\Models\CommercialClient;
use App\Models\CommercialSetting;
use App\Support\AdminAuditLogger;
use Illuminate\Http\Request;

final class CommercialConfigController
{
    public function settings()
    {
        CommerceAccess::authorize('commerce.configure');

        return view('admin.commerce.settings', ['settings' => CommercialSetting::values()]);
    }

    public function saveSettings(Request $request, AdminAuditLogger $audit)
    {
        CommerceAccess::authorize('commerce.configure');
        $data = $request->validate(['issuer_name' => 'required|string|max:180', 'issuer_contact' => 'required|string|max:2000', 'terms' => 'nullable|string|max:6000', 'public_email' => 'nullable|email|max:180', 'public_phone' => 'nullable|string|max:50', 'about' => 'nullable|string|max:4000']);
        $preference = $request->validate(['pdf_review_preference' => 'nullable|in:a,b,c']);
        $data = array_merge($data, $preference);
        CommercialSetting::updateOrCreate(['id' => 1], ['data' => $data]);
        $audit->record('commerce.settings_updated', 'commerce', 1, after: ['fields' => array_keys($data)]);

        return back()->with('notify', ['type' => 'success', 'message' => 'Configuración guardada.']);
    }

    public function clients()
    {
        CommerceAccess::authorize('clients.manage');

        return view('admin.commerce.clients', ['clients' => CommercialClient::orderBy('name')->paginate(20)]);
    }

    public function saveClient(Request $request, AdminAuditLogger $audit, ?CommercialClient $client = null)
    {
        CommerceAccess::authorize('clients.manage');
        $data = $request->validate(['name' => 'required|string|max:180', 'company' => 'nullable|string|max:180', 'email' => 'required|email|max:180', 'phone' => 'nullable|string|max:50', 'address' => 'nullable|string|max:2000', 'country' => 'nullable|string|max:100']);
        $client ??= new CommercialClient;
        $client->fill($data)->save();
        $audit->record('client.saved', 'client', $client->uuid);

        return back()->with('notify', ['type' => 'success', 'message' => 'Cliente guardado.']);
    }
}
