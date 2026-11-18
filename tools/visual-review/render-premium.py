"""Render actual mPDF output, verify text/page geometry and build a private gallery."""
import hashlib
import html
import json
import math
import re
import shutil
import subprocess
from decimal import Decimal
from pathlib import Path

import pdfplumber
from PIL import Image, ImageDraw, ImageFont

root = Path('storage/app/private/qa/designs').resolve()
rendered = root / 'rendered'
contacts = root / 'contacts'
rendered.mkdir(exist_ok=True)
contacts.mkdir(exist_ok=True)
manifest = json.loads((root / 'premium-manifest.json').read_text(encoding='utf-8'))
binary = shutil.which('pdftoppm')
if not binary:
    raise RuntimeError('Poppler pdftoppm is required.')
font = ImageFont.truetype('C:/Windows/Fonts/arial.ttf', 18)
report = []
for entry in manifest:
    source = root / entry['file']
    stem = source.stem
    # Exact generated filenames only; no recursive filesystem operations.
    for old in rendered.glob(stem + '-*.png'):
        if re.fullmatch(re.escape(stem) + r'-\d+\.png', old.name):
            old.unlink()
    subprocess.run([binary, '-r', '110', '-png', str(source), str(rendered / stem)], check=True, capture_output=True)
    pages = sorted(rendered.glob(stem + '-*.png'), key=lambda p: int(p.stem.rsplit('-', 1)[1]))
    assert len(pages) == entry['pages']
    with pdfplumber.open(source) as pdf:
        text_pages = [page.extract_text() or '' for page in pdf.pages]
        combined = re.sub(r'\s+', ' ', '\n'.join(text_pages))
        assert len(pdf.pages) == len(pages)
        for index, page in enumerate(pdf.pages, 1):
            assert abs(float(page.width) - 612) < .1 and abs(float(page.height) - 792) < .1
            assert f'Página {index} de {len(pages)}' in text_pages[index - 1]
            assert all(float(c['x0']) >= 0 and float(c['x1']) <= 612.5 and float(c['top']) >= 0 and float(c['bottom']) <= 792.5 for c in page.chars)
        for number in range(1, entry['items'] + 1):
            assert len(re.findall(rf'\bPRUEBA-{number:02d}(?!\d)', combined)) == 1, (stem, number)
        total = '${:,.2f}'.format(Decimal(entry['total_minor']) / 100)
        assert total in combined, (stem, total)
        if entry['items'] == 35:
            for number in range(1, 36):
                assert f'Condición de prueba {number}.' in combined
        if entry['items'] > 1:
            # Read the photo column separately: row-order extraction interleaves
            # the adjacent B3 description between the two lines of this caption.
            photo_right = 125 if stem.startswith('b3-') else 170
            photo_text = re.sub(r'\s+', ' ', ' '.join(page.crop((0, 0, photo_right, 792)).extract_text() or '' for page in pdf.pages))
            assert 'Fotografía no disponible' in photo_text, stem
        assert 'IVA (16.00%)' in combined and 'Envío' in combined and 'Descuentos' in combined
    for start in range(0, len(pages), 6):
        batch = pages[start:start + 6]
        sheet = Image.new('RGB', (1240, math.ceil(len(batch) / 2) * 828), '#e7e9ec')
        draw = ImageDraw.Draw(sheet)
        for offset, page in enumerate(batch):
            x, y = (offset % 2) * 620, (offset // 2) * 828
            with Image.open(page) as image:
                image.thumbnail((600, 780))
                sheet.paste(image, (x + 10, y + 34))
            draw.text((x + 12, y + 8), f'{stem} / página {start + offset + 1} de {len(pages)}', font=font, fill='#20252b')
        sheet.save(contacts / f'{stem}-sheet-{start // 6 + 1}.png')
    report.append({**entry, 'sha256': hashlib.sha256(source.read_bytes()).hexdigest(), 'rendered_pages': [p.relative_to(root).as_posix() for p in pages], 'page_geometry': '612 × 792 pt / Letter portrait', 'all_items_and_terms': True, 'total_verified': total, 'all_footers': True, 'no_text_outside_page': True})
    print(f'{stem}: {len(pages)} páginas renderizadas y verificadas', flush=True)
(root / 'premium-verification.json').write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding='utf-8')
cards = []
for count in [1, 3, 10, 35]:
    cards.append(f'<section><h2>{count} {"partida" if count == 1 else "partidas"}</h2><div class="grid">')
    for entry in [e for e in report if e['items'] == count]:
        stem = Path(entry['file']).stem
        links = ' '.join(f'<a href="{p}">Página {i}</a>' for i, p in enumerate(entry['rendered_pages'], 1))
        pages_label = f'{entry["pages"]} ' + ('página' if entry['pages'] == 1 else 'páginas')
        cards.append(f'<article><p class="eyebrow">{stem.split("-")[0].upper()} / {pages_label}</p><h3>{html.escape(entry["name"])}</h3><a href="{entry["file"]}"><img src="{entry["rendered_pages"][0]}" alt="Primera página renderizada del PDF {stem}" loading="lazy"></a><p><a class="button" href="{entry["file"]}">Abrir PDF real</a></p><details><summary>Revisar todas las páginas PNG</summary><nav>{links}</nav></details><p class="meta">Total de prueba: {entry["total_verified"]} MXN</p></article>')
    cards.append('</div></section>')
gallery = '''<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>USB Stock · Laboratorio PDF B1/B2/B3</title><style>
*{box-sizing:border-box}body{margin:0;background:#f3f4f6;color:#20252b;font:15px/1.6 system-ui,sans-serif}header,main{max-width:1440px;margin:auto;padding:36px}header{border-bottom:1px solid #d8dce2}header p{max-width:850px}h1{font-size:42px;line-height:1.1;letter-spacing:-.05em}.eyebrow{color:#a3102c;font-size:12px;font-weight:700;letter-spacing:.1em}.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:24px}article{background:white;border:1px solid #dfe2e6;border-radius:16px;padding:20px}article img{width:100%;height:auto;border:1px solid #ddd}h2{margin-top:42px}h3{font-size:23px;margin:0 0 20px}a{color:#a3102c}.button{display:inline-block;padding:10px 18px;color:white;background:#b51635;border-radius:7px;text-decoration:none}nav{display:flex;flex-wrap:wrap;gap:12px}.meta{font-size:12px;color:#59616b}@media(max-width:900px){.grid{grid-template-columns:1fr}header,main{padding:20px}}:focus-visible{outline:3px solid #a3102c;outline-offset:3px}
</style><header><p class="eyebrow">USB STOCK / REVISIÓN DE GERENCIA</p><h1>Una propuesta.<br>Tres direcciones editoriales.</h1><p>Comparación de PDFs reales generados con mPDF y renderizados con Poppler. Carta vertical, 8.5 × 11 pulgadas. Datos ficticios, sin validez comercial. Ninguna variante está activada como plantilla oficial.</p><p>B1: equilibrio editorial. B2: fotografía protagonista. B3: densidad ejecutiva. Cada grupo usa exactamente las mismas partidas e importes.</p></header><main>'''+''.join(cards)+'</main></html>'
(root / 'index.html').write_text(gallery, encoding='utf-8')
print(f'{len(report)} PDF / {sum(e["pages"] for e in report)} páginas. Galería: {root / "index.html"}', flush=True)
