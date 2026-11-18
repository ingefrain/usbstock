<?php

namespace App\Http\Controllers;

use App\Commerce\CorporateDirectory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

final class CorporateContactsController
{
    public function index(Request $request, CorporateDirectory $directory)
    {
        try {
            if (! $request->session()->has('corporate_contacts_context')) {
                $request->session()->put('corporate_contacts_context', Str::random(40));
            }
            $sellers = collect($directory->sellers())->shuffle()->map(function ($seller) use ($request) {
                $seller['ticket'] = $seller['url'] && config('commerce.corporate_contacts.record_clicks') ? Crypt::encryptString(json_encode(['id' => $seller['id'], 'nonce' => (string) Str::uuid(), 'expires' => now()->addMinutes(5)->timestamp, 'session' => hash('sha256', $request->session()->get('corporate_contacts_context'))])) : null;

                return $seller;
            })->values();

            return response()->json(['success' => true, 'sellers' => $sellers])->header('Cache-Control', 'no-store, private');
        } catch (Throwable) {
            return response()->json(['success' => false, 'message' => 'No pudimos consultar el directorio corporativo. Inténtalo de nuevo en unos momentos.'], 503)->header('Cache-Control', 'no-store, private');
        }
    }

    public function click(Request $request, CorporateDirectory $directory)
    {
        $data = $request->validate(['ticket' => 'required|string|max:2048']);
        try {
            $ticket = json_decode(Crypt::decryptString($data['ticket']), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            abort(422);
        }
        abort_unless(is_array($ticket) && is_int($ticket['id'] ?? null) && is_int($ticket['expires'] ?? null)
            && $ticket['expires'] >= now()->timestamp && is_string($ticket['nonce'] ?? null)
            && $request->session()->has('corporate_contacts_context')
            && hash_equals(hash('sha256', $request->session()->get('corporate_contacts_context')), (string) ($ticket['session'] ?? '')), 422);
        try {
            $recorded = $directory->record($ticket['id'], $ticket['nonce']);
        } catch (Throwable) {
            $recorded = false;
        }

        return response()->json(['success' => true, 'recorded' => $recorded], 202)->header('Cache-Control', 'no-store, private');
    }
}
