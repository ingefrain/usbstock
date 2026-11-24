$ErrorActionPreference = 'Stop'
$project = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$private = Join-Path $project 'storage/app/private/qa/visual-gsap'
$target = Join-Path $private 'catalog-snapshot.jsonl'
if (Test-Path -LiteralPath $target) { throw 'The original snapshot already exists. Preserve it; preparation can be resumed without exporting again.' }
$null = New-Item -ItemType Directory -Force -Path $private
if ((Get-Item -LiteralPath $private).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'The snapshot directory must not be a link.' }
$OutputEncoding = [Text.UTF8Encoding]::new($false)
[Console]::OutputEncoding = [Text.UTF8Encoding]::new($false)

function Read-CatalogSql([string]$sql) {
    $rows = @($sql | & 'C:\MariaDB\11.4.13\bin\mariadb.exe' '--defaults-file=C:\Users\ing_e\.codex\mysql.ini' '--host=127.0.0.1' '--port=3307' '--database=usbstock_dev' '--batch' '--raw' '--skip-column-names' '--default-character-set=utf8mb4')
    if ($LASTEXITCODE -ne 0) { throw 'The read-only local catalog export failed.' }
    return $rows
}

# Only these catalog tables are read. No application bootstrap, users, requests,
# quotes, directory contacts or credentials are exported.
$tables = @('product_categories', 'products', 'product_variants', 'product_category_assignments', 'documents', 'commerce_locks')
$schemaSql = "START TRANSACTION READ ONLY; SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('" + ($tables -join "','") + "') ORDER BY TABLE_NAME, ORDINAL_POSITION; ROLLBACK;"
$columns = @(Read-CatalogSql $schemaSql)
$statements = @(
    'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ;',
    'START TRANSACTION READ ONLY;',
    "SELECT JSON_OBJECT('kind','source','database',DATABASE(),'port',@@port,'captured_utc',UTC_TIMESTAMP());"
)
foreach ($table in $tables) {
    $names = @($columns | Where-Object { $_.StartsWith($table + '.') } | ForEach-Object { $_.Substring($table.Length + 1) })
    if (!$names.Count -or @($names | Where-Object { $_ -notmatch '^[a-z_][a-z0-9_]*$' }).Count) { throw 'Unexpected catalog schema.' }
    $parts = @($names | ForEach-Object {
        $value = if ($table -eq 'documents' -and $_ -eq 'uploaded_by') { 'NULL' }
            elseif ($table -eq 'commerce_locks' -and $_ -eq 'quote_sequence') { '0' }
            else { 't.`' + $_ + '`' }
        "'$_'," + $value
    })
    $where = if ($table -eq 'documents') {
        " WHERE t.category='product.image' AND t.deleted_at IS NULL AND t.documentable_type=CONCAT('App',CHAR(92),'Models',CHAR(92),'Product') AND EXISTS (SELECT 1 FROM products p WHERE p.id=t.documentable_id)"
    } elseif ($table -eq 'commerce_locks') { ' WHERE t.id=1' } else { '' }
    $statements += "SELECT JSON_OBJECT('kind','row','table','$table','row',JSON_OBJECT(" + ($parts -join ',') + ')) FROM `' + $table + '` t' + $where + ' ORDER BY t.id;'
}
$statements += 'ROLLBACK;'
$rows = @(Read-CatalogSql ($statements -join "`n"))
$source = $null
$counts = [ordered]@{}
foreach ($table in $tables) { $counts[$table] = 0 }
foreach ($line in $rows) {
    $record = $line | ConvertFrom-Json -AsHashtable -ErrorAction Stop
    if ($record.kind -eq 'source') {
        if ($source -or $record.database -ne 'usbstock_dev' -or $record.port -ne 3307) { throw 'Unexpected source database.' }
        $source = $record
    } elseif ($record.kind -eq 'row' -and $tables -contains $record.table) { $counts[$record.table]++ }
    else { throw 'Unexpected snapshot record.' }
}
if (!$source -or !$counts.products -or !$counts.product_variants -or $counts.commerce_locks -ne 1) { throw 'The catalog snapshot is incomplete.' }
[IO.File]::WriteAllLines($target, [string[]]$rows, [Text.UTF8Encoding]::new($false))
$manifest = [ordered]@{ source = $source; snapshot_sha256 = (Get-FileHash -LiteralPath $target -Algorithm SHA256).Hash.ToLowerInvariant(); counts = $counts; source_transaction = 'READ ONLY'; copied_costs_are_private = $true; uploaded_by_copied = $false }
[IO.File]::WriteAllText((Join-Path $private 'snapshot-manifest.json'), ($manifest | ConvertTo-Json -Depth 5), [Text.UTF8Encoding]::new($false))
# Never print rows, costs or private settings.
Write-Output ($manifest | ConvertTo-Json -Depth 5)
