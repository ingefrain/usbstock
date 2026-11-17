<?php

namespace App\Commerce;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;

final class ProductThumbnail
{
    public function response(Document $document)
    {
        abort_unless(preg_match('/\A[a-f0-9]{64}\z/', $document->checksum), 404);
        $path = 'product-thumbnails/v1/'.$document->checksum.'.webp';
        $disk = Storage::disk('local');
        if (! $disk->exists($path)) {
            // Reuse the verified local raster reader; no URLs or user-controlled paths.
            $bytes = app(QuotePhotos::class)->read($document);
            $image = imagecreatefromstring($bytes);
            $scale = min(1, 480 / max(imagesx($image), imagesy($image)));
            $thumb = imagescale($image, max(1, (int) round(imagesx($image) * $scale)), max(1, (int) round(imagesy($image) * $scale)));
            try {
                ob_start();
                imagewebp($thumb, null, 80);
                $content = ob_get_clean();
                abort_unless($disk->put($path, $content), 503);
            } finally {
                imagedestroy($image);
                imagedestroy($thumb);
            }
        }

        return $disk->response($path, null, ['Content-Type' => 'image/webp', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }
}
