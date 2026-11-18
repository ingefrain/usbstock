"""Read-only MariaDB reference; no legacy PHP is executed and no credentials are read."""
import hashlib
import json
import subprocess
from pathlib import Path

root = Path(__file__).resolve().parents[2]
private = root / 'storage/app/private/taxonomy-20261118/corporate-pricing'
cases = [('TEC004-NE', q) for q in [50, 149, 150, 151, 499, 500, 501, 999, 1000, 1001]]
cases += [('USB017-AZ', q) for q in [50, 105, 106, 210, 211]]
cases += [('VAR008-BL', q) for q in [50, 2499, 2500, 2501]]
cases += [('VAR009-BL', 50)]
cases += [(sku, q) for sku in ['USB082-NT', 'USB093-BL'] for q in [50, 143, 286]]
derived = ' UNION ALL '.join(f"SELECT '{sku}' AS sku, {qty} AS quantity" for sku, qty in cases)
sql = f"""SET TRANSACTION READ ONLY; START TRANSACTION;
SELECT JSON_OBJECT('id',id,'lower',limite_bajo,'upper',limite_alto,'increment',incremento) FROM productos_escalas ORDER BY limite_bajo,id;
SELECT JSON_OBJECT('sku',v.sku_completo,'quantity',c.quantity,'variant_cost',CAST(v.precio_sin_iva AS CHAR),'legacy_model_cost',CAST(m.precio AS CHAR),
'scale_id',s.id,'increment',s.incremento,'expected_variant_price',CAST(CAST(v.precio_sin_iva*(1+s.incremento/100) AS DECIMAL(10,2)) AS CHAR),
'legacy_scale_id',ls.id,'legacy_increment',ls.incremento,'expected_legacy_price',CAST(CAST(m.precio*(1+ls.incremento/100) AS DECIMAL(10,2)) AS CHAR))
FROM ({derived}) c JOIN productos_variantes v ON v.sku_completo=c.sku JOIN productos_modelos pm ON pm.id=v.id_modelo JOIN vw_productos_total m ON m.id=pm.id
JOIN productos_escalas s ON s.id=(SELECT id FROM productos_escalas WHERE c.quantity*v.precio_sin_iva >= limite_bajo AND (limite_alto=0 OR c.quantity*v.precio_sin_iva<=limite_alto) ORDER BY limite_bajo,id LIMIT 1)
JOIN productos_escalas ls ON ls.id=(SELECT id FROM productos_escalas WHERE c.quantity*m.precio >= limite_bajo AND (limite_alto=0 OR c.quantity*m.precio<=limite_alto) ORDER BY limite_bajo,id LIMIT 1)
ORDER BY v.sku_completo,c.quantity;
COMMIT;"""
result = subprocess.run(['C:/MariaDB/11.4.13/bin/mariadb.exe', '--defaults-file=C:/Users/ing_e/.codex/mysql.ini', '--database=usbstock2', '--batch', '--raw', '--skip-column-names', '--execute=' + sql], capture_output=True, check=True)
rows = [json.loads(line) for line in result.stdout.decode('utf-8').splitlines() if line.strip()]
bands = [r for r in rows if 'quantity' not in r]
matrix = [r for r in rows if 'quantity' in r]
if len(bands) != 4 or len(matrix) != len(cases):
    raise SystemExit('Reference incomplete; no fixture written.')
sources = {}
for name in ['includes/widgets.php', 'admin/modulos/cotizaciones/precotizacion-cfg.php', 'admin/modulos/cotizaciones/precotizacion-review.php', 'templates/default/pages/productos-detalle.php']:
    sources[name] = hashlib.sha256((Path('C:/xampp/htdocs/usbstock/codexv0') / name).read_bytes()).hexdigest()
data = {'source': 'usbstock2 read-only, 2026-11-18; codexv0 static audit', 'oracle': 'MariaDB exact DECIMAL expressions over actual legacy rows and first inclusive matching band; not Laravel results', 'cost_policy': 'quoted variant own cost approved by user; legacy model-minimum results retained for comparison', 'source_sha256': sources, 'bands': bands, 'cases': matrix}
text = json.dumps(data, ensure_ascii=False, indent=2) + '\n'
(private / 'legacy-reference.json').write_text(text, encoding='utf-8', newline='\n')
fixture = root / 'tests/Fixtures/Commerce/legacy-price-scales.json'
fixture.parent.mkdir(parents=True, exist_ok=True)
fixture.write_text(text, encoding='utf-8', newline='\n')
print(json.dumps({'bands': len(bands), 'reference_cases': len(matrix), 'legacy_cost_differences': sum(r['expected_variant_price'] != r['expected_legacy_price'] for r in matrix), 'legacy_writes': 0}))
