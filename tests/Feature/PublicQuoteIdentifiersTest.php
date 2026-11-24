<?php

namespace Tests\Feature;

use App\Commerce\Api\PublicQuoteIdentifiers;
use App\Commerce\QuoteService;
use App\Models\CommercialSetting;
use App\Models\Quote;
use App\Models\QuotePublicIdentifier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class PublicQuoteIdentifiersTest extends TestCase
{
    use RefreshDatabase;

    private function quote(bool $emitted = true): Quote
    {
        $quote = Quote::query()->create([
            'customer' => ['name' => 'Cliente ficticio', 'email' => 'cliente@example.invalid'],
            'seller' => '', 'terms' => 'Condiciones de prueba', 'status' => $emitted ? 'issued' : 'draft',
            'totals' => ['subtotal' => 5000, 'discount' => 0, 'tax' => 800, 'shipping' => 0, 'total' => 5800, 'currency' => 'MXN', 'line_totals' => [5000]],
        ]);
        if ($emitted) {
            $quote->versions()->create([
                'version' => 1, 'request_key' => (string) Str::uuid(), 'snapshot' => ['totals' => $quote->totals],
                'path' => 'synthetic.pdf', 'sha256' => str_repeat('0', 64), 'size' => 0,
                'renderer_version' => 'identifier-test', 'created_at' => now(),
            ]);
        }

        return $quote;
    }

    public function test_assignment_starts_at_3000_and_is_independent_of_uuid_and_folio(): void
    {
        $first = $this->quote();
        $second = $this->quote();
        $service = app(PublicQuoteIdentifiers::class);
        $identifier = $service->assign($first);

        $this->assertSame(3000, $identifier->public_id);
        $this->assertSame($first->id, $identifier->quote_id);
        $this->assertSame('laravel', $identifier->origin);
        $this->assertSame($first->uuid, $identifier->source_id);
        $this->assertSame($identifier->public_id, $service->assign($first)->public_id);
        $this->assertSame(3001, $service->assign($second)->public_id);
        $this->assertSame($first->uuid, $first->fresh()->uuid);
        $this->assertNull($first->fresh()->folio);
        $this->assertDatabaseCount('quote_public_identifiers', 2);
    }

    public function test_occupied_historical_3000_is_preserved_and_new_native_receives_3001(): void
    {
        $historical = $this->quote();
        $mapping = QuotePublicIdentifier::query()->create([
            'public_id' => 3000, 'quote_id' => $historical->id,
            'origin' => 'synthetic-legacy', 'source_id' => '3000', 'created_at' => now(),
        ]);
        $native = app(PublicQuoteIdentifiers::class)->assign($this->quote());

        $this->assertSame(3001, $native->public_id);
        $this->assertSame($historical->id, $mapping->fresh()->quote_id);
        $this->assertSame('synthetic-legacy', $mapping->fresh()->origin);
        $this->assertSame(3000, app(PublicQuoteIdentifiers::class)->assign($historical)->public_id);
    }

    public function test_all_origins_are_checked_and_assigned_ids_are_never_reused(): void
    {
        foreach ([2999, 3000, 3002] as $publicId) {
            QuotePublicIdentifier::query()->create([
                'public_id' => $publicId, 'quote_id' => $this->quote()->id,
                'origin' => 'synthetic-legacy', 'source_id' => (string) $publicId, 'created_at' => now(),
            ]);
        }
        $service = app(PublicQuoteIdentifiers::class);
        $first = $service->assign($this->quote());
        $second = $service->assign($this->quote());

        $this->assertSame(3001, $first->public_id);
        $this->assertSame(3003, $second->public_id);
        $this->assertSame([2999, 3000, 3001, 3002, 3003], QuotePublicIdentifier::query()->orderBy('public_id')->pluck('public_id')->all());
    }

    public function test_allocation_does_not_fill_gaps_behind_the_persisted_cursor(): void
    {
        DB::table('commerce_locks')->where('id', 1)->update(['public_quote_sequence' => 3002]);
        $identifier = app(PublicQuoteIdentifiers::class)->assign($this->quote());

        $this->assertSame(3003, $identifier->public_id);
        $this->assertSame(3003, (int) DB::table('commerce_locks')->where('id', 1)->value('public_quote_sequence'));
        $this->assertDatabaseMissing('quote_public_identifiers', ['public_id' => 3000]);
    }

    public function test_unissued_draft_does_not_receive_an_identifier(): void
    {
        $quote = $this->quote(false);
        try {
            app(PublicQuoteIdentifiers::class)->assign($quote);
            $this->fail('A draft without an emitted version must not receive a public ID.');
        } catch (LogicException) {
            $this->assertDatabaseCount('quote_public_identifiers', 0);
            $this->assertSame(2999, (int) DB::table('commerce_locks')->where('id', 1)->value('public_quote_sequence'));
        }
    }

    public function test_registry_joins_the_issuance_transaction_and_does_not_leave_orphans_on_failure(): void
    {
        $quote = $this->quote();
        try {
            DB::transaction(function () use ($quote): void {
                app(PublicQuoteIdentifiers::class)->assign($quote);
                throw new LogicException('Synthetic issuance failure.');
            });
        } catch (LogicException) {
            $this->assertDatabaseCount('quote_public_identifiers', 0);
            $this->assertSame(2999, (int) DB::table('commerce_locks')->where('id', 1)->value('public_quote_sequence'));
        }
    }

    public function test_existing_identifiers_cannot_be_reassigned(): void
    {
        $identifier = app(PublicQuoteIdentifiers::class)->assign($this->quote());
        $this->expectException(LogicException::class);
        $identifier->update(['public_id' => $identifier->public_id + 1]);
    }

    public function test_existing_identifiers_cannot_be_deleted(): void
    {
        $identifier = app(PublicQuoteIdentifiers::class)->assign($this->quote());
        $this->expectException(LogicException::class);
        $identifier->delete();
    }

    public function test_issuance_and_later_revision_keep_the_same_public_identifier(): void
    {
        Storage::fake('local');
        $actor = User::factory()->create();
        CommercialSetting::query()->create(['id' => 1, 'data' => ['issuer_name' => 'Emisor ficticio', 'issuer_contact' => 'emisor@example.invalid']]);
        $quote = $this->quote(false);
        $quote->items()->create([
            'position' => 1, 'product_variant_id' => null,
            'product' => ['sku' => 'TEST-A', 'name' => 'Producto ficticio', 'description' => 'Descripción ficticia', 'color' => 'Azul', 'capacity_gb' => null],
            'quantity' => 50, 'unit_minor' => 100, 'printing_minor' => 0, 'discount_minor' => 0,
        ]);
        $service = app(QuoteService::class);
        $requestKey = (string) Str::uuid();
        $firstVersion = $service->issue($quote, 1, $requestKey, $actor->id);
        $this->assertSame($firstVersion->id, $service->issue($quote, 1, $requestKey, $actor->id)->id);
        $identifier = QuotePublicIdentifier::query()->sole();
        $service->revise($quote->fresh(), 2, $actor->id);
        $this->assertSame($identifier->public_id, app(PublicQuoteIdentifiers::class)->assign($quote->fresh())->public_id);
        $service->issue($quote->fresh(), 3, (string) Str::uuid(), $actor->id);

        $this->assertDatabaseCount('quote_public_identifiers', 1);
        $this->assertDatabaseCount('quote_versions', 2);
        $this->assertSame($identifier->public_id, QuotePublicIdentifier::query()->sole()->public_id);
    }

    public function test_backfill_previews_then_assigns_only_emitted_quotes_and_can_be_repeated(): void
    {
        $emitted = $this->quote();
        $revising = $this->quote();
        $revising->update(['status' => 'draft']);
        $draft = $this->quote(false);
        $this->artisan('commerce:assign-public-quote-ids')->assertSuccessful();
        $this->assertDatabaseCount('quote_public_identifiers', 0);
        $this->artisan('commerce:assign-public-quote-ids', ['--apply' => true])->assertSuccessful();
        $original = QuotePublicIdentifier::query()->orderBy('quote_id')->pluck('public_id', 'quote_id')->all();
        $this->artisan('commerce:assign-public-quote-ids', ['--apply' => true])->assertSuccessful();

        $this->assertSame($original, QuotePublicIdentifier::query()->orderBy('quote_id')->pluck('public_id', 'quote_id')->all());
        $this->assertDatabaseCount('quote_public_identifiers', 2);
        $this->assertArrayHasKey($emitted->id, $original);
        $this->assertArrayHasKey($revising->id, $original);
        $this->assertArrayNotHasKey($draft->id, $original);
    }

    public function test_backfill_rejects_a_shared_database_before_connecting(): void
    {
        $original = config('database.default');
        config(['database.default' => 'unsafe_identifier_test', 'database.connections.unsafe_identifier_test' => [
            'driver' => 'mysql', 'database' => 'usbstock_dev', 'host' => '127.0.0.1', 'port' => 3307,
        ]]);
        try {
            $this->artisan('commerce:assign-public-quote-ids', ['--apply' => true])->assertFailed();
        } finally {
            config(['database.default' => $original]);
        }
    }

    public function test_preexisting_high_identifiers_are_not_reassigned_and_do_not_raise_the_new_sequence(): void
    {
        $service = app(PublicQuoteIdentifiers::class);
        foreach ([2147483648, PublicQuoteIdentifiers::MAX] as $publicId) {
            $existing = $this->quote();
            QuotePublicIdentifier::query()->create([
                'public_id' => $publicId, 'quote_id' => $existing->id,
                'origin' => 'laravel', 'source_id' => $existing->uuid, 'created_at' => now(),
            ]);
            $this->assertSame($publicId, $service->assign($existing)->public_id);
        }
        $this->assertSame(3000, $service->assign($this->quote())->public_id);
        $this->assertSame(3001, $service->assign($this->quote())->public_id);
        $this->assertSame([3000, 3001, 2147483648, PublicQuoteIdentifiers::MAX], QuotePublicIdentifier::query()->orderBy('public_id')->pluck('public_id')->all());
    }

    public function test_exhaustion_preserves_mappings_and_does_not_reset_the_cursor(): void
    {
        DB::table('commerce_locks')->where('id', 1)->update(['public_quote_sequence' => PublicQuoteIdentifiers::MAX]);
        try {
            app(PublicQuoteIdentifiers::class)->assign($this->quote());
            $this->fail('An exhausted cursor must never wrap around or fill earlier gaps.');
        } catch (LogicException) {
            $this->assertDatabaseCount('quote_public_identifiers', 0);
            $this->assertSame(PublicQuoteIdentifiers::MAX, (int) DB::table('commerce_locks')->where('id', 1)->value('public_quote_sequence'));
        }
    }
}
