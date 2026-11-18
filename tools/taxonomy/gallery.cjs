const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve('storage/app/private/taxonomy-20261118');
const report = JSON.parse(fs.readFileSync(path.join(root, 'browser-report.json')));
if (!report.passed) throw Error('Complete browser verification before publishing the local evidence gallery.');
const groups = [
    ['Portada final', ['final-home-1440', 'final-home-820', 'final-home-390']],
    ['Navegación real', ['menu-1440', 'menu-820', 'menu-390']],
    ['Categorías y selección', ['category-1440', 'category-390', 'product-dock-390', 'selection-390']],
    ['Contactos simulados: no son vendedores reales', ['final-contacts-1440', 'final-contacts-820', 'final-contacts-390', 'contacts-empty', 'contacts-invalid', 'contacts-timeout']],
    ['Accesibilidad y administración', ['no-javascript-selection', 'reduced-motion-home', 'admin-categories', 'admin-insufficient', 'admin-categories-390', 'admin-products-390']]
];
const content = groups.map(([title, names]) => '<section><h2>' + title + '</h2><div class="grid">' + names.map(name => {
    if (!fs.existsSync(path.join(root, name + '.png'))) throw Error('Missing screenshot ' + name);
    return `<figure><a href="${name}.png"><img src="${name}.png" loading="lazy" alt="${name.replaceAll('-', ' ')}"></a><figcaption>${name.replaceAll('-', ' ')}</figcaption></figure>`;
}).join('') + '</div></section>').join('');
fs.writeFileSync(path.join(root, 'index.html'), `<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>USB Stock · Categorías y contactos</title><style>*{box-sizing:border-box}body{margin:0;background:#f5f6f2;color:#192b2b;font:15px/1.6 system-ui}main{max-width:1440px;padding:32px;margin:auto}h1{font-size:36px;line-height:1.2}h2{margin:42px 0 18px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px}figure{margin:0;background:white;border:1px solid #dce2d5;border-radius:12px;overflow:hidden}img{width:100%;height:440px;object-fit:contain;background:#fff;display:block}figcaption{padding:12px}a:focus-visible{outline:3px solid #c51636}p{max-width:850px}</style><main><p>USB STOCK / REVISIÓN LOCAL · 18 NOV 2026</p><h1>Categorías reales.<br>Navegación y contactos renovados.</h1><p>Capturas de Laravel en Edge a 1440, 820 y 390 px. Los contactos con la leyenda QA son respuestas simuladas; todas las salidas externas fueron bloqueadas. No se enviaron mensajes ni métricas. Pulsa una captura para abrirla completa.</p><p>${report.groups.length} grupos aprobados. Inventario y documentos históricos conservados. El panel corporativo real permanece desactivado hasta contar con una credencial autorizada.</p>${content}</main></html>`);
console.log('Private evidence gallery prepared.');
