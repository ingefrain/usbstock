$ErrorActionPreference = 'Stop'
$project = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$target = Join-Path $project 'storage/app/private/development-import/october-2026/legacy-photo-map.jsonl'
if (Test-Path -LiteralPath $target) { throw 'Existing mapping retained. Review before replacing the evidence.' }
$sql = @'
START TRANSACTION READ ONLY;
SELECT JSON_OBJECT('id', v.id, 'model_id', v.id_modelo, 'model', m.sku_modelo, 'sku', v.sku_completo)
FROM productos_variantes v JOIN productos_modelos m ON m.id = v.id_modelo ORDER BY v.id;
ROLLBACK;
'@
$rows = $sql | & 'C:\MariaDB\11.4.13\bin\mariadb.exe' '--defaults-file=C:\Users\ing_e\.codex\mysql.ini' '--database=usbstock2' '--batch' '--raw' '--skip-column-names' '--default-character-set=utf8mb4'
if ($LASTEXITCODE -ne 0 -or $rows.Count -eq 0) { throw 'Read-only mapping export failed.' }
foreach ($line in $rows) { $null = $line | ConvertFrom-Json -ErrorAction Stop }
[IO.File]::WriteAllLines($target, [string[]]$rows, [Text.UTF8Encoding]::new($false))
Write-Output "Exported $($rows.Count) catalog/photo references. No legacy code executed or data modified."
