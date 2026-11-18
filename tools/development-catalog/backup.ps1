param([string]$RunDirectory = 'storage/app/private/development-import/october-2026')
$ErrorActionPreference = 'Stop'
$project = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
Push-Location -LiteralPath $project
try {
    & 'C:\xampp\php\php.exe' -r "require 'tools/development-catalog/local.php';"
    if ($LASTEXITCODE -ne 0) { throw 'Local environment preflight failed.' }
} finally { Pop-Location }
$root = (Resolve-Path (Join-Path $project 'storage/app/private')).Path
$run = [IO.Path]::GetFullPath((Join-Path $project $RunDirectory))
if (-not $run.StartsWith($root + [IO.Path]::DirectorySeparatorChar) -or $run -notmatch 'development-import') { throw 'Backup destination must be private development-import storage.' }
if ($run -ne [IO.Path]::GetFullPath((Join-Path $project 'storage/app/private/development-import/october-2026'))) { throw 'Use the fixed October evidence directory.' }
$stamp = [DateTime]::UtcNow.ToString('yyyyMMdd_HHmmss')
$backup = Join-Path $run ('backup-' + $stamp)
$null = New-Item -ItemType Directory -Path $backup
# Fail before writing a dump if Windows permissions cannot be restricted and verified.
& (Join-Path $PSScriptRoot 'secure-evidence.ps1')
$client = 'C:\MariaDB\11.4.13\bin\mariadb.exe'
$dump = 'C:\MariaDB\11.4.13\bin\mariadb-dump.exe'
$defaults = '--defaults-file=C:\Users\ing_e\.codex\mysql.ini'
$sourceDb = 'usbstock_dev'
$restoreDb = 'usbstock_restore_oct_' + $stamp
function Query([string]$database, [string]$sql) {
    $result = & $client $defaults "--database=$database" --batch --raw --skip-column-names --default-character-set=utf8mb4 "--execute=$sql"
    if ($LASTEXITCODE -ne 0) { throw 'Database operation failed; inspect private diagnostics.' }
    return $result
}
$identity = Query $sourceDb 'SELECT CONCAT(@@port, CHAR(9), DATABASE());'
if ($identity -ne "3307`tusbstock_dev") { throw 'Unexpected source database.' }
$special = Query $sourceDb "SELECT (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()) + (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()) + (SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE());"
if ($special -ne '0') { throw 'Custom database objects require an expanded backup review.' }
$exists = Query $sourceDb "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$restoreDb';"
if ($exists -ne '0') { throw 'Restore database already exists. Nothing overwritten.' }
$sqlFile = Join-Path $backup 'usbstock_dev.sql'
$dumpOptions = @('--single-transaction','--quick','--hex-blob','--skip-comments','--skip-add-drop-table','--skip-add-locks','--default-character-set=utf8mb4','--order-by-primary')
& $dump $defaults @dumpOptions "--result-file=$sqlFile" $sourceDb 2> (Join-Path $backup 'dump.stderr')
if ($LASTEXITCODE -ne 0 -or (Get-Item $sqlFile).Length -eq 0) { throw 'Backup failed.' }
if (Select-String -LiteralPath $sqlFile -Pattern '^\s*(USE\s|CREATE\s+DATABASE|DROP\s)' -Quiet) { throw 'Dump contains database switching or destructive statements.' }
$null = Query $sourceDb "CREATE DATABASE $restoreDb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
$tables = Query $restoreDb 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE();'
if ($tables -ne '0') { throw 'Restore target is not empty.' }
$sourcePath = $sqlFile.Replace('\','/')
$null = Query $restoreDb "SOURCE $sourcePath;"
$roundtrip = Join-Path $backup 'restored.sql'
& $dump $defaults @dumpOptions "--result-file=$roundtrip" $restoreDb 2> (Join-Path $backup 'restore-dump.stderr')
if ($LASTEXITCODE -ne 0) { throw 'Restored dump failed.' }
$sqlHash = (Get-FileHash -LiteralPath $sqlFile -Algorithm SHA256).Hash.ToLowerInvariant()
$restoreHash = (Get-FileHash -LiteralPath $roundtrip -Algorithm SHA256).Hash.ToLowerInvariant()
if ($sqlHash -ne $restoreHash) { throw 'Restored schema/data do not reproduce the source dump exactly.' }
$tableNames = @(Query $restoreDb "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME;")
if ($tableNames.Count -eq 0 -or @($tableNames | Where-Object { $_ -notmatch '^[a-z_]+$' }).Count) { throw 'Unexpected restored table inventory.' }
$countSql = ($tableNames | ForEach-Object { "SELECT '$_' AS table_name, COUNT(*) AS row_count FROM $_" }) -join ' UNION ALL '
$counts = @(Query $restoreDb $countSql)
[IO.File]::WriteAllLines((Join-Path $backup 'restored-row-counts.tsv'), [string[]]$counts, [Text.UTF8Encoding]::new($false))
Add-Type -AssemblyName System.IO.Compression.FileSystem
$archivePath = Join-Path $backup 'private-files.zip'
$archive = [IO.Compression.ZipFile]::Open($archivePath, [IO.Compression.ZipArchiveMode]::Create)
$files = @()
try {
    foreach ($directory in Get-ChildItem -LiteralPath $root -Directory) {
        if ($directory.Name -in @('qa','pdf-temp','development-import')) { continue }
        foreach ($file in Get-ChildItem -LiteralPath $directory.FullName -File -Recurse -Force) {
            if ($file.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Unexpected linked private file.' }
            $relative = $file.FullName.Substring($root.Length + 1).Replace('\','/')
            $null = [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $file.FullName, $relative)
            $files += [pscustomobject]@{path=$relative;bytes=$file.Length;sha256=(Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash.ToLowerInvariant()}
        }
    }
} finally { $archive.Dispose() }
$restoredFiles = Join-Path $backup 'restored-private-files'
[IO.Compression.ZipFile]::ExtractToDirectory($archivePath, $restoredFiles)
foreach ($file in $files) {
    $restored = Join-Path $restoredFiles $file.path
    if ((Get-FileHash -LiteralPath $restored -Algorithm SHA256).Hash.ToLowerInvariant() -ne $file.sha256) { throw 'Restored private file hash differs.' }
}
$report = [ordered]@{version=1;created_at_utc=[DateTime]::UtcNow.ToString('o');source_database=$sourceDb;restore_database=$restoreDb;database_file=$sqlFile;database_sha256=$sqlHash;restored_sha256=$restoreHash;database_verified=$true;tables=$tableNames.Count;private_archive=$archivePath;private_sha256=(Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash.ToLowerInvariant();private_verified=$true;private_files=$files;excluded=@('qa: isolated test instances and visual evidence','pdf-temp: transient renderer files','development-import: this operation and backups');destructive_statements=$false;restore_database_retained=$true}
$manifest = Join-Path $backup 'verified-backup.json'
[IO.File]::WriteAllText($manifest, ($report | ConvertTo-Json -Depth 8), [Text.UTF8Encoding]::new($false))
[IO.File]::WriteAllText((Join-Path $run 'backup-pointer.txt'), $manifest, [Text.UTF8Encoding]::new($false))
Write-Output "Backup verified: $($tableNames.Count) tables, $($files.Count) private files. Restore database: $restoreDb. Manifest: $manifest"
