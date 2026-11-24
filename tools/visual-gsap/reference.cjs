const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require('C:/Users/ing_e/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const origin = 'http://127.0.0.1:4178';
const project = path.resolve(__dirname, '../..');
const output = path.join(project, 'storage/app/private/qa/visual-gsap/reference');
fs.mkdirSync(output, { recursive: true });
const report = { reference: 'b5b9631', browser: '', screenshots: [], metrics: [], pageErrors: [], checks: [], substitutions: ['Logo virtual Lovable resuelto con public/brand/logo.webp de Laravel', 'Misma familia Manrope servida desde archivo local existente'], started: new Date().toISOString() };
const save = () => fs.writeFileSync(path.join(output, 'reference-report.json'), JSON.stringify(report, null, 2));
let browser;
async function setup(context) {
  // Later, specific routes fulfill the virtual logo and remote font locally.
  await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
  await context.route('**/__l5e/assets-v1/**', route => route.fulfill({ contentType: 'image/webp', body: fs.readFileSync(path.join(project, 'public/brand/logo.webp')) }));
  await context.route('https://fonts.googleapis.com/**', route => route.fulfill({ contentType: 'text/css', body: '@font-face{font-family:Manrope;font-style:normal;font-weight:200 800;font-display:swap;src:url("'+origin+'/__qa/manrope.ttf") format("truetype")}' }));
  await context.route('**/__qa/manrope.ttf', route => route.fulfill({ contentType: 'font/ttf', body: fs.readFileSync(path.join(project, 'public/brand/fonts/manrope-variable.ttf')) }));
  context.on('page', page => { page.on('pageerror', error => report.pageErrors.push(error.message)); page.setDefaultTimeout(15000); });
}
async function shot(page, name, fullPage = false) {
  await page.evaluate(async () => { await document.fonts.ready; await Promise.all([...document.images].map(async image => { image.loading = 'eager'; try { await image.decode(); } catch {} })); });
  await page.screenshot({ path: path.join(output, name+'.png'), fullPage });
  report.screenshots.push(name+'.png'); save();
}
async function hydrated(page) {
  // The reference hydrates its SSR markup asynchronously; wait before changing image loading hints.
  await page.waitForFunction(() => [...document.querySelectorAll('button')].some(button => Object.keys(button).some(key => key.startsWith('__reactProps$'))));
}
async function metric(page, name, width) {
  const value = await page.evaluate(() => ({ overflow: document.documentElement.scrollWidth > innerWidth + 1, h1: document.querySelector('h1')?.textContent, font: getComputedStyle(document.body).fontFamily, cards: document.querySelectorAll('article').length, brokenVisibleImages: [...document.images].filter(i => i.getBoundingClientRect().top < innerHeight && i.getBoundingClientRect().bottom > 0 && !i.naturalWidth).map(i=>i.src) }));
  report.metrics.push({ name, width, ...value }); save();
}
(async () => {
  try {
    browser = await chromium.launch({ executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true, timeout: 60000 });
    report.browser = browser.version();
    for (const width of [1440,1039,820,390]) {
      const context = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 1000 }, reducedMotion: 'reduce' });
      await setup(context);
      const page = await context.newPage();
      await page.goto(origin+'/', { waitUntil:'networkidle', timeout:60000 });
      await hydrated(page);
      await shot(page, 'lovable-home-'+width, true); await metric(page, 'home', width);
      if(width >=1024) { await page.getByRole('button',{name:'Categorías',exact:true}).hover(); await page.waitForFunction(()=>[...document.querySelectorAll('header button')].some(b=>b.textContent.trim()==='Categorías'&&b.getAttribute('aria-expanded')==='true')); await page.waitForTimeout(350); await shot(page,'lovable-menu-'+width); await page.mouse.move(0,900); }
      else { await page.getByRole('button',{name:'Abrir menú',exact:true}).click(); await shot(page,'lovable-menu-'+width); await page.getByRole('button',{name:'Cerrar menú',exact:true}).click(); }
      await page.goto(origin+'/catalogo', { waitUntil:'networkidle', timeout:60000 });
      await hydrated(page);
      await page.locator('article').first().waitFor();
      await shot(page,'lovable-catalog-'+width); await metric(page, 'catalog', width);
      await page.locator('article').first().scrollIntoViewIfNeeded();
      await shot(page,'lovable-cards-'+width);
      if (width===1440 || width===390) {
        await page.getByRole('button',{name:'Agregar a cotización',exact:true}).first().click();
        await page.getByRole('button',{name:/Revisar cotización, /}).click();
        await shot(page,'lovable-quote-'+width);
        report.quoteAriaHidden = await page.locator('aside[aria-label="Tu cotización"]').evaluate(el=>el.parentElement.getAttribute('aria-hidden'));
        await page.locator('aside[aria-label="Tu cotización"] button[aria-label="Cerrar"]').click();
        await page.getByRole('button',{name:'Abrir WhatsApp',exact:true}).click();
        await shot(page,'lovable-whatsapp-'+width);
      }
      await context.close();
    }
    const motionContext=await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'no-preference'}); await setup(motionContext);
    const page=await motionContext.newPage(); await page.goto(origin+'/',{waitUntil:'domcontentloaded',timeout:60000});
    const samples=[];
    for(let i=0;i<32;i++) { samples.push(await page.evaluate(()=>({time:performance.now(),headline:[...document.querySelectorAll('[data-h-line]>span')].map(n=>({transform:getComputedStyle(n).transform,opacity:getComputedStyle(n).opacity})),image:document.querySelector('[data-h-img]')?.getAttribute('style')}))); await page.waitForTimeout(250); }
    report.motionSamples=samples; await shot(page,'lovable-motion-settled-1440');
    await motionContext.close();
    report.checks.push({name:'reference screenshots and demo UI',passed:true});
  } catch (error) { report.checks.push({name:'reference capture',passed:false,error:error.message}); process.exitCode=1; }
  finally { save(); await browser?.close(); }
})();
