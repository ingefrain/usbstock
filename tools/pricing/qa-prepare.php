<?php

use App\Commerce\LegacyPriceScales;
use App\Commerce\Money;
use App\Commerce\PublicQuoteRequests;
use App\Models\CommercialSetting;
use App\Models\Product;
use App\Models\ProductVariant;
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
$app = require __DIR__.'/qa-bootstrap.php';
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
$user = User::firstOrCreate(['email' => 'pricing-qa@example.test'], ['name' => 'Administración QA aislada', 'password' => Hash::make($password), 'email_verified_at' => now()]);
$user->roles()->syncWithoutDetaching([Role::where('key', 'administrator')->sole()->id]);
if ($user->wasRecentlyCreated) {
    file_put_contents($reviewRoot.'/access.json', json_encode(['email' => $user->email, 'password' => $password], JSON_THROW_ON_ERROR));
}
$reference = json_decode(file_get_contents(base_path('tests/Fixtures/Commerce/legacy-price-scales.json')), true, flags: JSON_THROW_ON_ERROR);
app(LegacyPriceScales::class)->import($reference, $user->id);
$cases = collect($reference['cases'])->unique('sku')->map(fn ($r) => ['sku' => $r['sku'], 'cost' => $r['variant_cost']])->push(['sku' => 'USB082-BL', 'cost' => '54.00']);
foreach ($cases as $case) {
    [$model, $color] = explode('-', $case['sku'], 2);
    $product = Product::firstOrCreate(['external_key' => $model], ['slug' => strtolower($model), 'supplier_description' => 'Modelo de revisión aislada '.$model, 'is_published' => true, 'provider_active' => true]);
    $product->variants()->firstOrCreate(['sku' => $case['sku']], ['color_code' => $color, 'supplier_description' => 'Variante de revisión aislada '.$case['sku'], 'supplier_price_minor' => Money::parse($case['cost']), 'stock' => 5000, 'is_enabled' => true, 'provider_active' => true]);
}
CommercialSetting::create(['id' => 1, 'data' => ['issuer_name' => 'USB Stock · revisión aislada', 'issuer_contact' => 'qa@example.test', 'terms' => 'Cotización ficticia de prueba. No enviar.']]);
$variant = ProductVariant::where('sku', 'USB082-NT')->sole();
$request = app(PublicQuoteRequests::class)->submit(['name' => 'Cliente de revisión aislada', 'email' => 'client@example.test', 'phone' => ''], [$variant->id => 50], 'Solicitud ficticia para comprobar escalas', (string) Str::uuid());
file_put_contents($reviewRoot.'/prepared.json', json_encode(['request_uuid' => $request->uuid, 'variant_id' => $variant->id, 'database' => 'isolated_sqlite', 'main_database_used' => false], JSON_PRETTY_PRINT));
echo "Isolated pricing review prepared; no development business rows modified.\n";
