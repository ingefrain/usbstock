param([ValidateSet('before','after')][string]$Stage='before')
$ErrorActionPreference='Stop'
$project=(Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$privateRoot=(Resolve-Path (Join-Path $project 'storage/app/private/taxonomy-20261118')).Path
if($privateRoot -ne [IO.Path]::GetFullPath((Join-Path $project 'storage/app/private/taxonomy-20261118'))){throw 'Unexpected backup path.'}
$paths=@($privateRoot)+@(Get-ChildItem -LiteralPath $privateRoot -Recurse -Force | ForEach-Object {$_.FullName})
foreach($p in $paths){
    if((Get-Item -LiteralPath $p).Attributes -band [IO.FileAttributes]::ReparsePoint){throw 'Linked backup path.'}
    $wide=@((Get-Acl -LiteralPath $p).Access | Where-Object {$_.AccessControlType -eq 'Allow' -and $_.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value -in @('S-1-1-0','S-1-5-11','S-1-5-32-545')})
    if($wide.Count){throw 'Private backup permissions are not verified.'}
}
function Read-Sql([string]$query){
    $result=@(& C:\MariaDB\11.4.13\bin\mariadb.exe --defaults-file=C:\Users\ing_e\.codex\mysql.ini --database=usbstock_dev --default-character-set=utf8mb4 --batch --raw --skip-column-names --execute="START TRANSACTION READ ONLY; $query ROLLBACK;")
    if($LASTEXITCODE -ne 0){throw 'Read-only backup query failed.'}
    return $result
}
$snapshot=[ordered]@{database='usbstock_dev';stage=$Stage;created_at=[DateTime]::UtcNow.ToString('o');permissions_verified=$true;tables=[ordered]@{}}
$tables=@('products','product_variants','product_categories','commerce_locks','admin_audit_events','migrations','quote_requests','quotes','quote_versions','documents','outbox_messages')
if($Stage -eq 'after'){$tables+=@('product_category_assignments')}
foreach($table in $tables){
    $columns=@(Read-Sql "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table' ORDER BY ORDINAL_POSITION;")
    if(!$columns.Count -or @($columns|Where-Object {$_ -notmatch '^[a-z_][a-z0-9_]*$'}).Count){throw 'Unexpected table schema.'}
    $parts=@($columns | ForEach-Object {"'$_',``$_``"})
    $expression='JSON_OBJECT('+($parts -join ',')+')'
    $rows=@(Read-Sql "SELECT $expression FROM ``$table`` ORDER BY id;")
    $ddl=@(Read-Sql "SHOW CREATE TABLE ``$table``;") -join "`n"
    $bytes=[Text.Encoding]::UTF8.GetBytes(($rows -join "`n"))
    $sha=[Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($bytes)).ToLowerInvariant()
    $snapshot.tables[$table]=[ordered]@{count=$rows.Count;sha256=$sha;create_table=$ddl;rows=$rows}
}
$target=Join-Path $privateRoot ('backup-'+$Stage+'.json')
if(Test-Path -LiteralPath $target){throw 'Snapshot already exists; preserve the original.'}
$json=$snapshot | ConvertTo-Json -Depth 8
[IO.File]::WriteAllText($target,$json,[Text.UTF8Encoding]::new($false))
$verified=Get-Content -Raw -LiteralPath $target | ConvertFrom-Json -AsHashtable
foreach($table in $tables){foreach($row in $verified.tables[$table].rows){$null=$row|ConvertFrom-Json}}
$manifest=[ordered]@{file=[IO.Path]::GetFileName($target);sha256=(Get-FileHash -LiteralPath $target -Algorithm SHA256).Hash.ToLowerInvariant();permissions_verified=$true;table_counts=[ordered]@{}}
foreach($table in $tables){$manifest.table_counts[$table]=$verified.tables[$table].count}
[IO.File]::WriteAllText((Join-Path $privateRoot ('backup-'+$Stage+'-manifest.json')),($manifest|ConvertTo-Json -Depth 4),[Text.UTF8Encoding]::new($false))
$manifest | ConvertTo-Json -Depth 4
