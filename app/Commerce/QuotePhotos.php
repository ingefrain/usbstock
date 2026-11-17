<?php

namespace App\Commerce;

use App\Models\CommercialSetting;
use App\Models\Document;
use App\Models\Quote;
use App\Services\Documents\ManagedDocumentPath;
use Illuminate\Support\Facades\Storage;

final class QuotePhotos
{
    /** Resolve local catalog/quote images only. Never fetch an image from a provider URL. */
    public function sources(Quote $quote): array
    {
        $specific = $quote->documents()->where('category', 'quote.image')->latest('id')->get();
        $sources = [];
        foreach ($quote->items()->with('variant.product.image')->get() as $index => $item) {
            $document = $specific->first(fn ($doc) => (int) ($doc->metadata['variant_id'] ?? 0) === $item->product_variant_id);
            $origin = $document ? 'quote' : 'catalog';
            $document ??= $item->variant?->product?->image;
            if ($document) {
                $sources[$index] = ['document' => $document, 'source' => $origin, 'bytes' => $this->read($document)];
            }
        }

        return $sources;
    }

    public function read(Document $document): string
    {
        abort_unless(app(ManagedDocumentPath::class)->matches($document) && config('filesystems.disks.'.$document->disk.'.driver') === 'local' && $document->size_bytes <= 5 * 1024 * 1024, 409, 'No se pudo verificar la fotografía local.');
        $bytes = Storage::disk($document->disk)->get($document->path);
        abort_unless(is_string($bytes) && hash_equals($document->checksum, hash('sha256', $bytes)), 409, 'La fotografía cambió o no está disponible.');
        $info = @getimagesizefromstring($bytes);
        abort_unless($info && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) && $info[0] * $info[1] <= 16000000, 409);

        return $bytes;
    }

    public function freeze(Quote $quote, string $versionUuid, array &$lines, array &$createdPaths): void
    {
        foreach ($this->sources($quote) as $index => $source) {
            $doc = $source['document'];
            $path = 'quotes/'.$quote->uuid.'/assets/'.$versionUuid.'-'.$index.'.'.$doc->extension;
            if (! Storage::disk('local')->put($path, $source['bytes'])) {
                throw new \RuntimeException('No se pudo conservar una fotografía histórica.');
            }
            $createdPaths[] = $path;
            $lines[$index]['photo'] = ['path' => $path, 'sha256' => hash('sha256', $source['bytes']), 'source' => $source['source'], 'document_uuid' => $doc->uuid, 'mime' => $doc->mime_type];
        }
    }

    public function square(string $bytes): string
    {
        // Preserve aspect ratio within a white square; no CSS object-fit dependency in mPDF.
        $source = imagecreatefromstring($bytes);
        $canvas = imagecreatetruecolor(600, 600);
        try {
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            $scale = min(600 / imagesx($source), 600 / imagesy($source));
            $width = max(1, (int) round(imagesx($source) * $scale));
            $height = max(1, (int) round(imagesy($source) * $scale));
            imagecopyresampled($canvas, $source, (int) ((600 - $width) / 2), (int) ((600 - $height) / 2), 0, 0, $width, $height, imagesx($source), imagesy($source));
            ob_start();
            imagepng($canvas);

            return ob_get_clean();
        } finally {
            imagedestroy($source);
            imagedestroy($canvas);
        }
    }

    public function preview(Quote $quote): array
    {
        $lines = $quote->items()->get()->map(fn ($i) => $i->only(['product', 'quantity', 'unit_minor', 'printing_minor', 'discount_minor', 'printing']))->all();
        foreach ($this->sources($quote) as $i => $photo) {
            $lines[$i]['photo_bytes'] = $this->square($photo['bytes']);
        }

        return ['folio' => $quote->folio ?? 'BORRADOR-'.substr($quote->uuid, 0, 8), 'version' => $quote->revision, 'issued_at' => now()->toIso8601String(), 'valid_until' => now()->addDays($quote->validity_days)->toDateString(), 'issuer' => array_merge(['issuer_name' => 'Emisor pendiente', 'issuer_contact' => 'Contacto pendiente'], CommercialSetting::values()), 'customer' => $quote->customer, 'seller' => $quote->seller, 'terms' => $quote->terms ?? '', 'tax_basis_points' => $quote->tax_basis_points, 'items' => $lines, 'totals' => $quote->totals, 'calculation_version' => $quote->calculation_version];
    }
}
