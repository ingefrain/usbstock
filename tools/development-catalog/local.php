<?php

use App\Commerce\CommerceAccess;
use App\Commerce\DevelopmentCatalogEnvironment;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$project = dirname(__DIR__, 2);
require $project.'/vendor/autoload.php';
$app = require $project.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$environment = app(DevelopmentCatalogEnvironment::class)->assertSafe();
Http::preventStrayRequests();
$identity = DB::selectOne('SELECT DATABASE() AS name, @@port AS port');
if ($identity->name !== 'usbstock_dev' || (int) $identity->port !== 3307) {
    throw new RuntimeException('Unexpected connected database.');
}
if (realpath(Storage::disk('local')->path('')) !== realpath(storage_path('app/private'))) {
    throw new RuntimeException('Unexpected private storage destination.');
}
$actor = User::findOrFail(1);
foreach (['supplier_sync.create', 'supplier_sync.apply', 'products.view', 'products.manage'] as $permission) {
    if (! CommerceAccess::allows($actor, $permission)) {
        throw new RuntimeException('The existing administrator lacks a required permission.');
    }
}
Auth::setUser($actor);
$runRoot = storage_path('app/private/development-import/october-2026');
File::ensureDirectoryExists($runRoot, 0700);

function writeDevelopmentReport(string $name, array $data): void
{
    file_put_contents(storage_path('app/private/development-import/october-2026/'.$name.'.json'), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function verifiedDevelopmentBackup(string $root): array
{
    $file = trim(file_get_contents($root.'/backup-pointer.txt'));
    $resolved = realpath($file);
    if (! $resolved || ! str_starts_with($resolved, realpath($root).DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Verified private backup is required.');
    }
    $backup = json_decode(file_get_contents($resolved), true, flags: JSON_THROW_ON_ERROR);
    if ($backup['source_database'] !== 'usbstock_dev' || ! $backup['database_verified'] || ! $backup['private_verified']
        || ! hash_equals($backup['database_sha256'], hash_file('sha256', $backup['database_file']))
        || ! hash_equals($backup['private_sha256'], hash_file('sha256', $backup['private_archive']))) {
        throw new RuntimeException('Backup verification is missing or the backup changed.');
    }

    return $backup;
}
