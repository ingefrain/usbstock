$ErrorActionPreference = 'Stop'
$project = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$root = (Resolve-Path (Join-Path $project 'storage/app/private/taxonomy-20261118')).Path
if ($root -ne [IO.Path]::GetFullPath((Join-Path $project 'storage/app/private/taxonomy-20261118')) -or ((Get-Item -LiteralPath $root).Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'Unexpected private path.' }
$wide = @((Get-Acl -LiteralPath $root).Access | Where-Object { $_.AccessControlType -eq 'Allow' -and $_.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value -in @('S-1-1-0','S-1-5-11','S-1-5-32-545') })
if ($wide.Count) { throw 'Restrict private directory permissions before exporting.' }
$target = Join-Path $root 'legacy-taxonomy.json'
if (Test-Path -LiteralPath $target) { throw 'Keep the reviewed original export; do not overwrite it.' }
$query = @'
START TRANSACTION READ ONLY;
SELECT JSON_OBJECT('source','usbstock2','version',1,
 'categories',(SELECT JSON_ARRAYAGG(JSON_OBJECT('id',id,'parent',parent,'name',txt,'slug',slug,'sort_order',orden) ORDER BY id) FROM productoscat),
 'models',(SELECT JSON_ARRAYAGG(JSON_OBJECT('id',id,'key',sku_modelo,'active',estatus) ORDER BY id) FROM productos_modelos),
 'relations',(SELECT JSON_ARRAYAGG(JSON_OBJECT('id',id,'model_id',item,'category_id',cat) ORDER BY id) FROM productoscatrel));
COMMIT;
'@
$raw = @(& C:\MariaDB\11.4.13\bin\mariadb.exe --defaults-file=C:\Users\ing_e\.codex\mysql.ini --database=usbstock2 --default-character-set=utf8mb4 --batch --raw --skip-column-names --execute=$query)
if ($LASTEXITCODE -ne 0 -or $raw.Count -ne 1) { throw 'Read-only export failed.' }
$source = $raw[0] | ConvertFrom-Json -AsHashtable
if ($source.source -ne 'usbstock2' -or $source.version -ne 1) { throw 'Unexpected source.' }
[IO.File]::WriteAllText($target, ($source | ConvertTo-Json -Depth 10), [Text.UTF8Encoding]::new($false))
[pscustomobject]@{categories=$source.categories.Count; models=$source.models.Count; relations=$source.relations.Count; sha256=(Get-FileHash -LiteralPath $target -Algorithm SHA256).Hash}
