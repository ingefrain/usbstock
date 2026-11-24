// Builds a private, offline comparison from real browser captures. No server or dependency is needed.
const fs = require('node:fs');
const path = require('node:path');
const { privateRoot } = require('./server.cjs');

const widths = [1440, 1039, 820, 390];
const views = [
    { id: 'home', name: 'Inicio completo', reference: 'lovable-home', experiment: 'home-full', route: 'home', note: 'Composición editorial, fotografías, categorías y ritmo entre secciones. La palabra «presente.» aparece como un bloque rojo en la referencia; la adaptación corrige su recorte de texto.' },
    { id: 'catalog', name: 'Catálogo', reference: 'lovable-catalog', experiment: 'catalog', route: 'catalog', note: 'Mismo lenguaje visual con búsqueda y catálogo continuo reales. Las familias, cantidades y filtros proceden de Laravel.' },
    { id: 'cards', name: 'Tarjetas', reference: 'lovable-cards', experiment: 'cards', route: 'catalog', note: 'Lovable repite fotografías y productos de demostración. Laravel muestra fotos importadas, descripciones y variantes reales; «Ver y cotizar» conserva la selección de variante antes de añadir.' },
    { id: 'navigation', name: 'Navegación', reference: 'lovable-menu', experiment: 'navigation', route: null, note: 'La taxonomía real y el acceso por teclado permanecen disponibles. A 1039 px Laravel utiliza navegación móvil para acomodar el contenido real.' },
];
const escape = value => String(value ?? '').replace(/[&<>"']/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character]));
function readJson(file) {
    try { return JSON.parse(fs.readFileSync(path.join(privateRoot, file), 'utf8')); }
    catch { return null; }
}
function imageSize(relative) {
    const file = path.join(privateRoot, relative);
    if (!fs.existsSync(file)) return null;
    const header = Buffer.alloc(24);
    const descriptor = fs.openSync(file, 'r');
    try { fs.readSync(descriptor, header, 0, header.length, 0); }
    finally { fs.closeSync(descriptor); }
    if (header.toString('hex', 0, 8) !== '89504e470d0a1a0a') return null;
    return { width: header.readUInt32BE(16), height: header.readUInt32BE(20) };
}
const browser = readJson('browser/report.json');
const reference = readJson('reference/reference-report.json');
const metrics = (route, width) => browser?.metrics?.find(item => item.route === route && item.width === width);
function measurement(route, width) {
    const metric = metrics(route, width);
    if (!metric) return '<span>Sin medición local disponible</span>';
    const cls = Number.isFinite(metric.cls) ? metric.cls.toFixed(4) : '—';
    const lcp = Number.isFinite(metric.lcp) ? `${(metric.lcp / 1000).toFixed(2)} s` : '—';
    return `<span>Laravel · CLS <b>${escape(cls)}</b></span><span>LCP observado <b>${escape(lcp)}</b></span>`;
}
function figure(folder, filename, label, description, width, view) {
    const source = `${folder}/${filename}-${width}.png`;
    const size = imageSize(source);
    const caption = `<figcaption><div><strong>${label}</strong><small>${description}</small></div>${size ? `<a href="${source}" target="_blank" rel="noopener">Abrir captura ↗</a>` : ''}</figcaption>`;
    if (!size) return `<figure>${caption}<div class="missing" role="status">Captura pendiente: ${escape(source)}</div></figure>`;
    return `<figure>${caption}<div class="frame" tabindex="0" role="region" aria-label="${escape(label)}: ${escape(view.name)}, ${width} píxeles"><img src="${source}" width="${size.width}" height="${size.height}" style="--capture-width:${size.width}px" alt="Captura real de ${escape(view.name.toLowerCase())} en ${escape(label)}, a ${width} píxeles" loading="lazy" decoding="async"></div><p class="dimensions">${size.width} × ${size.height} px · imagen original sin recorte</p></figure>`;
}
const referencePassed = reference?.checks?.length && reference.checks.every(check => check.passed === true);
const status = browser?.ok === true ? 'Validación Laravel aprobada' : browser ? 'Validación Laravel aún incompleta' : 'Informe Laravel pendiente';
const groupCount = Array.isArray(browser?.groups) ? browser.groups.length : 0;
const generated = `${new Intl.DateTimeFormat('es-MX', { timeZone: 'America/Mexico_City', dateStyle: 'long', timeStyle: 'short' }).format(new Date())} (Ciudad de México)`;
const sections = widths.map(width => `<div class="viewport-set" data-width="${width}">${views.map(view => `
<section id="${view.id}-${width}" class="comparison" data-view="${view.id}" aria-labelledby="title-${view.id}-${width}">
  <div class="section-heading"><div><p class="eyebrow">${width} píxeles</p><h2 id="title-${view.id}-${width}">${view.name}</h2></div><div class="measurements">${view.route ? measurement(view.route, width) : '<span>Menú abierto en ambos proyectos</span>'}</div></div>
  <p class="context">${view.note}</p>
  <div class="pair">
    ${figure('reference', view.reference, 'Lovable · referencia', 'Prototipo React con datos de demostración', width, view)}
    ${figure('browser', view.experiment, 'Laravel · experimento', 'Blade y servicios existentes · copia local aislada', width, view)}
  </div>
</section>`).join('')}</div>`).join('');

const html = `<!doctype html>
<html lang="es-MX"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>USB Stock · Comparación visual Lovable / Laravel</title>
<style>
:root{color-scheme:light;--ink:#192126;--muted:#5e696f;--red:#d91b3f;--line:#dce2e4;--surface:#f4f6f6}*{box-sizing:border-box}body{margin:0;background:var(--surface);color:var(--ink);font:15px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif}a{color:inherit}a,button,input,select{touch-action:manipulation}a:focus-visible,input:focus-visible,select:focus-visible,.frame:focus-visible{outline:3px solid var(--red);outline-offset:4px}header{background:#fff;border-bottom:1px solid var(--line)}.wrap{width:min(1800px,100%);margin:auto;padding:28px 32px}.eyebrow{color:var(--red);font-size:11px;font-weight:750;letter-spacing:.16em;text-transform:uppercase;margin:0 0 9px}h1{font-size:clamp(28px,3vw,44px);line-height:1.1;letter-spacing:-.035em;margin:0 0 16px}header p{max-width:1000px;margin:0;color:var(--muted)}.status{display:flex;gap:10px;flex-wrap:wrap;margin-top:22px}.status span,.status a{border:1px solid var(--line);border-radius:100px;padding:7px 12px;font-size:12px;text-decoration:none}.status .passed{color:#176348;background:#edf7f1;border-color:#cce5d5}.status .pending{background:#fff4df;color:#754a00}.controls{position:sticky;top:0;z-index:4;background:#fffffff5;border-bottom:1px solid var(--line);backdrop-filter:blur(12px)}.controls .wrap{display:flex;align-items:center;gap:20px;flex-wrap:wrap;padding-block:15px}.controls label{display:inline-flex;gap:8px;align-items:center;font-size:13px;font-weight:650}select{font:inherit;background:white;color:var(--ink);border:1px solid #a9b4ba;border-radius:10px;padding:9px 35px 9px 12px;min-height:42px}input{accent-color:var(--red);width:18px;height:18px}.jump{display:flex;gap:8px;flex-wrap:wrap;margin-left:auto}.jump a{text-decoration:none;border-radius:100px;padding:9px 12px;font-size:13px}.jump a:hover{background:var(--surface)}.comparison{scroll-margin-top:130px;margin:12px 0 58px}.section-heading{display:flex;align-items:end;justify-content:space-between;gap:20px}h2{font-size:27px;line-height:1.2;letter-spacing:-.025em;margin:0}.measurements{display:flex;gap:14px;flex-wrap:wrap;font-size:12px;color:var(--muted)}.measurements b{color:var(--ink);font-variant-numeric:tabular-nums}.context{color:var(--muted);max-width:1120px;font-size:13px;margin:14px 0 20px}.pair{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:22px;align-items:start}figure{margin:0;min-width:0;background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden;box-shadow:0 8px 24px #19212606}figcaption{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:17px 18px;border-bottom:1px solid var(--line);background:#fff}figcaption strong{display:block;font-size:15px}figcaption small{display:block;font-size:11px;color:var(--muted);margin-top:4px}figcaption a{font-size:11px;white-space:nowrap;color:var(--red);text-underline-offset:3px}.frame{overflow:auto;max-height:76vh;background:#e9eeee;scrollbar-gutter:stable;overscroll-behavior:contain}.frame img{display:block;width:100%;height:auto;max-width:none}.natural .frame img{width:var(--capture-width)}.dimensions{color:var(--muted);font-size:11px;padding:10px 18px;margin:0}.missing{display:grid;place-items:center;min-height:280px;padding:30px;text-align:center;color:var(--muted)}[hidden]{display:none!important}.notes{border-top:1px solid var(--line);padding-top:24px;color:var(--muted);font-size:13px;max-width:1200px}.notes ul{padding-left:20px}.notes li{margin-block:8px}footer{font-size:11px;color:var(--muted);margin-top:30px}noscript p{padding:12px;background:#fff4df;border-radius:8px}@media(max-width:850px){.wrap{padding-inline:18px}.pair{grid-template-columns:1fr}.frame{max-height:65vh}.controls .wrap{gap:12px}.jump{margin-left:0;width:100%;gap:4px}.jump a{padding:5px 8px}.section-heading{display:block}.measurements{margin-top:14px}figcaption{padding:14px}.comparison{scroll-margin-top:185px}}@media(prefers-reduced-motion:reduce){html{scroll-behavior:auto}}
</style></head><body>
<header><div class="wrap"><p class="eyebrow">USB Stock · Experimento visual local</p><h1>Lovable → Laravel</h1><p>Comparación de capturas reales del mismo ancho. Revisa la composición y la jerarquía visual junto con las diferencias necesarias para conservar el catálogo y el flujo comercial.</p><div class="status"><span class="${browser?.ok === true ? 'passed' : 'pending'}">${status}</span><span>${groupCount} grupos de comprobaciones registrados</span><span>${referencePassed ? 'Referencia capturada en Edge' : 'Informe de referencia pendiente'}</span><a href="browser/report.json">Informe Laravel ↗</a><a href="reference/reference-report.json">Informe Lovable ↗</a></div></div></header>
<div class="controls"><div class="wrap"><label for="width">Ancho de captura <select id="width">${widths.map(width=>`<option value="${width}">${width} px${width===390?' · móvil':width===820?' · tableta':width===1039?' · laptop':' · escritorio'}</option>`).join('')}</select></label><label><input id="natural" type="checkbox"> Tamaño real</label><label><input id="sync" type="checkbox"> Sincronizar scroll</label><nav class="jump" aria-label="Secciones comparativas">${views.map(view=>`<a data-jump="${view.id}" href="#${view.id}-1440">${view.name}</a>`).join('')}</nav></div></div>
<main class="wrap"><noscript><p>Sin JavaScript se muestran los cuatro anchos consecutivos. Cada captura puede abrirse por separado.</p></noscript>${sections}<aside class="notes"><h2>Cómo interpretar la comparación</h2><ul><li>Las fotografías del hero y las composiciones editoriales proceden de la referencia. Los productos del catálogo Laravel conservan sus fotos y nombres importados; Lovable utiliza ejemplos repetidos.</li><li>Las categorías, filtros, selección de variante, solicitudes y selector corporativo de WhatsApp obedecen a los servicios existentes. La prueba usa una copia aislada; no representa una actualización del inventario comercial.</li><li>El rojo de acción se oscureció ligeramente para mejorar contraste. La adaptación corrige el recorte del titular de referencia y conserva navegación sin JavaScript, reduced motion y manejo de foco.</li><li>Las medidas CLS y LCP son observaciones locales del recorrido de prueba, sin simulación de red móvil ni CPU lenta. No equivalen a métricas de campo ni a un contraste de rendimiento con Lovable.</li><li>«Sincronizar scroll» enlaza la posición relativa de cada pareja; las secciones pueden tener alturas distintas por sus datos reales. «Tamaño real» permite inspeccionar píxeles originales con desplazamiento horizontal.</li></ul></aside><footer>Generado ${escape(generated)} · Archivo local privado · Sin recursos externos.</footer></main>
<script>
const widthSelect=document.getElementById('width');
function showWidth(){const width=widthSelect.value;document.querySelectorAll('[data-width]').forEach(set=>{set.hidden=set.dataset.width!==width});document.querySelectorAll('[data-jump]').forEach(link=>{link.href='#'+link.dataset.jump+'-'+width});}
widthSelect.addEventListener('change',showWidth);showWidth();
document.getElementById('natural').addEventListener('change',event=>document.body.classList.toggle('natural',event.target.checked));
document.querySelectorAll('.pair').forEach(pair=>{const frames=[...pair.querySelectorAll('.frame')];let busy=false;frames.forEach(frame=>frame.addEventListener('scroll',()=>{if(busy||!document.getElementById('sync').checked)return;busy=true;const extent=frame.scrollHeight-frame.clientHeight;const ratio=extent>0?frame.scrollTop/extent:0;frames.filter(other=>other!==frame).forEach(other=>{other.scrollTop=ratio*(other.scrollHeight-other.clientHeight)});requestAnimationFrame(()=>{busy=false})},{passive:true}))});
</script></body></html>`;

fs.mkdirSync(privateRoot, { recursive: true });
const destination = path.join(privateRoot, 'comparison.html');
fs.writeFileSync(destination, html, 'utf8');
console.log(`Comparison gallery: ${destination}`);
console.log(`Laravel report: ${browser?.ok === true ? 'passed' : 'pending or incomplete'}; ${groupCount} completed groups; ${widths.length} widths.`);
