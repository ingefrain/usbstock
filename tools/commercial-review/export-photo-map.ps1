$ErrorActionPreference = 'Stop'
$project = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$target = Join-Path $project 'storage\app\private\qa\commercial\legacy-photo-map.jsonl'
$sql = @'
START TRANSACTION READ ONLY;
SELECT JSON_OBJECT('id', v.id, 'model_id', v.id_modelo, 'model', m.sku_modelo, 'sku', v.sku_completo, 'image', v.imagen, 'thumbnail', v.imagen_thumb)
FROM productos_variantes v JOIN productos_modelos m ON m.id = v.id_modelo ORDER BY v.id;
ROLLBACK;
'@
# Only catalog/photo references. Credentials stay in the existing defaults file.
$rows = $sql | & 'C:\MariaDB\11.4.13\bin\mariadb.exe' '--defaults-file=C:\Users\ing_e\.codex\mysql.ini' '--database=usbstock2' '--batch' '--raw' '--skip-column-names' '--default-character-set=utf8mb4'
if ($LASTEXITCODE -ne 0) { throw 'Read-only photo export failed; existing manifest preserved.' }
foreach ($line in $rows) { $null = $line | ConvertFrom-Json -ErrorAction Stop }
if ($rows.Count -eq 0) { throw 'Empty mapping; existing manifest preserved.' }
$null = New-Item -ItemType Directory -Force -Path (Split-Path $target -Parent)
[IO.File]::WriteAllLines($target, [string[]]$rows, [Text.UTF8Encoding]::new($false))
Write-Output "Exported $($rows.Count) catalog/photo references to the private QA manifest."
