<?php

namespace App\Commerce\Api;

use App\Models\Quote;
use App\Models\QuotePublicIdentifier;
use Illuminate\Support\Facades\DB;
use LogicException;

final class PublicQuoteIdentifiers
{
    public const LEGACY_MAX = 2147483647;

    public const NATIVE_MIN = 2147483648;

    public const MAX = 9999999999;

    /** Explicit write operation: call during issuance or the isolated backfill, never from GET. */
    public function assign(Quote $quote): QuotePublicIdentifier
    {
        return DB::transaction(function () use ($quote): QuotePublicIdentifier {
            // Keep the same lock order as QuoteService::issue; the indexed maximum is serialized.
            if (! DB::table('commerce_locks')->where('id', 1)->lockForUpdate()->first()) {
                throw new LogicException('No está disponible el bloqueo de numeración comercial.');
            }
            $quote = Quote::query()->lockForUpdate()->findOrFail($quote->getKey());
            if (! $quote->versions()->exists()) {
                throw new LogicException('Sólo se asignan identificadores públicos a cotizaciones con una versión emitida.');
            }
            if ($existing = QuotePublicIdentifier::query()->where('quote_id', $quote->id)->first()) {
                return $existing;
            }

            // All positive signed-int IDs remain reserved for a future verified legacy import.
            $last = (int) (QuotePublicIdentifier::query()->where('public_id', '>=', self::NATIVE_MIN)->max('public_id') ?? self::LEGACY_MAX);
            if ($last >= self::MAX) {
                throw new LogicException('Se agotó el intervalo de identificadores públicos nativos.');
            }

            return QuotePublicIdentifier::query()->create([
                'public_id' => $last + 1,
                'quote_id' => $quote->id,
                'origin' => 'laravel',
                'source_id' => $quote->uuid,
                'created_at' => now(),
            ]);
        });
    }
}
