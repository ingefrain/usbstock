<?php

namespace App\Http\Controllers\Admin;

use App\Commerce\CommerceAccess;
use App\Commerce\QuoteService;
use App\Models\CommercialClient;
use App\Models\CommercialSetting;
use App\Models\ProductVariant;
use App\Models\Quote;
use App\Models\QuoteVersion;
use App\Support\ImpersonationManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

final class QuoteController
{
    private function access(string $permission = 'quotes.view'): void
    {
        CommerceAccess::authorize('quotes.view');
        CommerceAccess::authorize($permission);
    }

    private function actor(Request $request): int
    {
        return (app(ImpersonationManager::class)->actor() ?? $request->user())->id;
    }

    public function index(Request $request)
    {
        $this->access();
        $data = $request->validate(['q' => 'nullable|string|max:100', 'status' => 'nullable|in:draft,issued']);

        return view('admin.commerce.quotes', ['quotes' => Quote::when($data['q'] ?? null, fn ($q, $s) => $q->where('folio', 'like', '%'.$s.'%'))->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))->latest()->paginate(25)->withQueryString()]);
    }

    public function create(Request $request)
    {
        $this->access('quotes.manage');

        return $this->form(new Quote(['status' => 'draft', 'revision' => 1, 'seller' => $request->user()->name, 'tax_basis_points' => 1600, 'validity_days' => 30, 'shipping_minor' => 0, 'terms' => CommercialSetting::values()['terms'] ?? '']));
    }

    public function show(Quote $quote)
    {
        $this->access();

        return $this->form($quote);
    }

    private function form(Quote $quote)
    {
        return view('admin.commerce.quote', ['quote' => $quote->load(['items', 'versions']), 'variants' => ProductVariant::with('product')->orderBy('sku')->get(), 'clients' => CommercialClient::orderBy('name')->get()]);
    }

    public function store(Request $request, QuoteService $service)
    {
        $this->access('quotes.manage');
        $quote = $service->save($request->all(), $this->actor($request));

        return redirect()->route('admin.quotes.show', $quote)->with('notify', ['type' => 'success', 'message' => 'Borrador guardado.']);
    }

    public function update(Request $request, Quote $quote, QuoteService $service)
    {
        $this->access('quotes.manage');
        $service->save($request->all(), $this->actor($request), $quote);

        return back()->with('notify', ['type' => 'success', 'message' => 'Borrador actualizado y calculado.']);
    }

    public function issue(Request $request, Quote $quote, QuoteService $service)
    {
        $this->access('quotes.issue');
        $data = $request->validate(['revision' => 'required|integer', 'request_key' => 'required|uuid', 'confirm' => 'accepted']);
        $service->issue($quote, (int) $data['revision'], $data['request_key'], $this->actor($request));

        return back()->with('notify', ['type' => 'success', 'message' => 'Cotización emitida. El PDF ya está disponible.']);
    }

    public function revise(Request $request, Quote $quote, QuoteService $service)
    {
        $this->access('quotes.manage');
        $data = $request->validate(['revision' => 'required|integer', 'confirm' => 'accepted']);
        $service->revise($quote, (int) $data['revision'], $this->actor($request));

        return back();
    }

    public function download(Quote $quote, QuoteVersion $version)
    {
        $this->access();
        abort_unless($version->quote_id === $quote->id && $version->path === 'quotes/'.$quote->uuid.'/'.$version->uuid.'.pdf', 404);
        $bytes = Storage::disk('local')->get($version->path);
        abort_unless(is_string($bytes) && hash_equals($version->sha256, hash('sha256', $bytes)), 409, 'El documento no está disponible o falló su verificación.');

        return response($bytes)->header('Content-Type', 'application/pdf')->header('Content-Disposition', 'attachment; filename="'.$quote->folio.'-v'.$version->version.'.pdf"')->header('Cache-Control', 'no-store, private')->header('X-Content-Type-Options', 'nosniff');
    }
}
