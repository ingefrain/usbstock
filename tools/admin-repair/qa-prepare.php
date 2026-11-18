<?php

use App\Commerce\LegacyPriceScales;
use App\Commerce\PublicQuoteRequests;
use App\Commerce\QuoteService;
use App\Models\CommercialSetting;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

if (PHP_SAPI !== 'cli') {
    exit;
}
require __DIR__.'/qa-bootstrap.php';
if (is_file($reviewRoot.'/prepared.json')) {
    echo "Existing isolated review preserved.\n";
    exit;
}
Artisan::call('migrate', ['--pretend' => true, '--force' => true]);
file_put_contents($reviewRoot.'/migration-preview.txt', Artisan::output());
if (Artisan::call('migrate', ['--force' => true]) !== 0) {
    throw new RuntimeException('Isolated migration failed.');
}
Artisan::call('db:seed', ['--class' => PermissionsSeeder::class, '--force' => true]);
Artisan::call('db:seed', ['--class' => RolesSeeder::class, '--force' => true]);
$password = Str::random(32);
$user = User::factory()->create(['name' => 'Administración QA aislada', 'email' => 'admin-repair@example.test', 'password' => Hash::make($password)]);
$user->roles()->attach(Role::where('key', 'administrator')->sole());
file_put_contents($reviewRoot.'/access.json', json_encode(['email' => $user->email, 'password' => $password], JSON_THROW_ON_ERROR));
app(LegacyPriceScales::class)->import(json_decode(file_get_contents(base_path('tests/Fixtures/Commerce/legacy-price-scales.json')), true, flags: JSON_THROW_ON_ERROR), $user->id);
$lines = [];
$variants = [];
foreach ([['VAR015-NE', 400, '0'], ['VAR028-BL', 1100, '0'], ['USB082-NT', 7000, '99'], ['QA-ZERO', 0, '0']] as [$sku, $cost, $price]) {
    $product = Product::create(['external_key' => $sku, 'slug' => strtolower($sku), 'supplier_description' => 'Fixture aislada '.$sku, 'is_published' => true, 'provider_active' => true]);
    $v = $product->variants()->create(['sku' => $sku, 'color_code' => 'QA', 'supplier_description' => 'Fixture aislada '.$sku, 'supplier_price_minor' => $cost, 'stock' => 5000, 'is_enabled' => true, 'provider_active' => true]);
    $variants[] = $v;
    $lines[] = ['variant_id' => $v->id, 'quantity' => 50, 'unit_price' => $price, 'printing_price' => '0', 'discount' => '0'];
}
CommercialSetting::create(['id' => 1, 'data' => ['issuer_name' => 'USB Stock · QA aislado', 'issuer_contact' => 'qa@example.test', 'terms' => 'Documento ficticio de prueba. No enviar.']]);
$quote = app(QuoteService::class)->save(['revision' => 1, 'customer_name' => 'Cliente QA aislado', 'customer_email' => 'client@example.test', 'terms' => 'Documento ficticio de prueba. No enviar.', 'validity_days' => 30, 'tax_basis_points' => 1600, 'shipping' => '0', 'items' => $lines], $user->id);
$quote->items()->update(['pricing' => null]);
$request = app(PublicQuoteRequests::class)->submit(['name' => 'Solicitud QA aislada', 'email' => 'request@example.test', 'phone' => ''], [$variants[0]->id => 50, $variants[1]->id => 50], 'Nueva conversión aislada', (string) Str::uuid());
file_put_contents($reviewRoot.'/prepared.json', json_encode(['quote_uuid' => $quote->uuid, 'request_uuid' => $request->uuid, 'database' => 'isolated_sqlite'], JSON_PRETTY_PRINT));
echo "Isolated review prepared; no development business data written.\n";
