<?php

namespace App\Http\Controllers\Admin;

use App\Commerce\CommerceAccess;
use App\Commerce\QuoteDesigns;
use App\Commerce\QuotePhotos;
use App\Models\Quote;
use App\Services\Documents\DocumentService;
use App\Support\AdminAuditLogger;
use App\Support\ImpersonationManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class QuotePhotoController
{
    public function store(Request $request, Quote $quote, DocumentService $documents)
    {
        CommerceAccess::authorize('quotes.view');
        CommerceAccess::authorize('quotes.manage');
        $data = $request->validate(['revision' => 'required|integer', 'variant_id' => 'required|integer', 'remove' => 'sometimes|boolean', 'image' => 'required_unless:remove,1|nullable|file|image|mimes:jpg,jpeg,png,webp|max:5120']);
        $created = null;
        try {
            DB::transaction(function () use ($quote, $request, $data, $documents, &$created): void {
                $quote = Quote::lockForUpdate()->findOrFail($quote->id);
                abort_unless($quote->status === 'draft' && $quote->revision === (int) $data['revision'], 409);
                abort_unless($quote->items()->where('product_variant_id', $data['variant_id'])->exists(), 422);
                $actor = app(ImpersonationManager::class)->actor() ?? $request->user();
                $old = $quote->documents()->where('category', 'quote.image')->get()->filter(fn ($d) => (int) ($d->metadata['variant_id'] ?? 0) === (int) $data['variant_id']);
                if ($request->hasFile('image') && ! ($data['remove'] ?? false)) {
                    $created = $documents->upload($request->file('image'), $quote, $actor, 'quote.image', ['title' => 'Imagen específica de cotización'], ['variant_id' => (int) $data['variant_id']]);
                }
                foreach ($old as $document) {
                    $documents->delete($document, $actor);
                }
                $quote->increment('revision');
                app(AdminAuditLogger::class)->record('quote.photo_changed', 'quote', $quote->uuid, after: ['variant_id' => (int) $data['variant_id'], 'source' => $created ? 'quote' : 'catalog'], actorId: $actor->id);
            });
        } catch (\Throwable $error) {
            if ($created) {
                Storage::disk($created->disk)->delete($created->path);
            }
            throw $error;
        }

        return back()->with('notify', ['type' => 'success', 'message' => 'Fotografía actualizada. Las versiones emitidas conservan sus copias.']);
    }

    public function preview(Request $request, Quote $quote, QuotePhotos $photos, QuoteDesigns $designs)
    {
        abort_unless(app()->environment(['local', 'testing']), 404);
        CommerceAccess::authorize('quotes.view');
        abort_unless($quote->status === 'draft', 409, 'Consulta el PDF histórico de esta cotización emitida.');
        $data = $request->validate(['concept' => 'required|in:a,b,c', 'format' => 'sometimes|in:html,pdf']);
        $snapshot = $photos->preview($quote);
        $pdf = ($data['format'] ?? 'html') === 'pdf';

        return response($pdf ? $designs->render($data['concept'], $snapshot) : $designs->html($data['concept'], $snapshot))
            ->header('Content-Type', $pdf ? 'application/pdf' : 'text/html; charset=UTF-8')->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow')->header('X-Content-Type-Options', 'nosniff');
    }
}
