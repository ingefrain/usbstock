<?php

namespace App\Commerce\Api;

use App\Models\Quote;
use App\Models\QuotePublicIdentifier;
use Illuminate\Support\Facades\DB;
use LogicException;

final class PublicQuoteIdentifiers
{
    public const NATIVE_MIN = 3000;

    public const MAX = 9999999999;

    /** Explicit write operation: call during issuance or the isolated backfill, never from GET. */
    public function assign(Quote $quote): QuotePublicIdentifier
    {
        return DB::transaction(function () use ($quote): QuotePublicIdentifier {
            // SQLite ignores FOR UPDATE. Acquire its write reservation before reading IDs.
            if (DB::connection()->getDriverName() === 'sqlite') {
                DB::table('commerce_locks')->where('id', 1)->update(['quote_sequence' => DB::raw('quote_sequence')]);
            }
            // Keep the same lock order as QuoteService::issue; allocation is serialized.
            $lock = DB::table('commerce_locks')->where('id', 1)->lockForUpdate()->first();
            if (! $lock) {
                throw new LogicException('No está disponible el bloqueo de numeración comercial.');
            }
            $quote = Quote::query()->lockForUpdate()->findOrFail($quote->getKey());
            if (! $quote->versions()->exists()) {
                throw new LogicException('Sólo se asignan identificadores públicos a cotizaciones con una versión emitida.');
            }
            if ($existing = QuotePublicIdentifier::query()->where('quote_id', $quote->id)->first()) {
                return $existing;
            }

            // This cursor starts a new sequence, not a reserved historical range. Existing IDs
            // of every origin are skipped; gaps behind the cursor are never assigned again.
            if ((int) $lock->public_quote_sequence >= self::MAX) {
                throw new LogicException('Se agotó el intervalo de identificadores públicos nativos.');
            }
            $next = max(self::NATIVE_MIN, (int) $lock->public_quote_sequence + 1);
            foreach (QuotePublicIdentifier::query()->select('public_id')->where('public_id', '>=', $next)->lazyById(1000, 'public_id') as $occupied) {
                if ($occupied->public_id > $next) {
                    break;
                }
                if ($next === self::MAX) {
                    throw new LogicException('Se agotó el intervalo de identificadores públicos nativos.');
                }
                $next++;
            }

            $identifier = QuotePublicIdentifier::query()->create([
                'public_id' => $next,
                'quote_id' => $quote->id,
                'origin' => 'laravel',
                'source_id' => $quote->uuid,
                'created_at' => now(),
            ]);
            DB::table('commerce_locks')->where('id', 1)->update(['public_quote_sequence' => $next]);

            return $identifier;
        }, attempts: 5);
    }
}
