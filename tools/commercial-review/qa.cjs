const fs=require('node:fs');
const path=require('node:path');
const assert=require('node:assert/strict');
const {startServer}=require('./server.cjs');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'C:/Users/ing_e/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const origin='http://127.0.0.1:4388';
const output=path.resolve('storage/app/private/qa/commercial');
const access=JSON.parse(fs.readFileSync(path.join(output,'access.json')));
const report={started:new Date().toISOString(),browser:'',checks:[],screenshots:[],errors:[],externalRequests:[],quotes:[],run:Date.now().toString(36)};
const reportName=process.env.QA_REPORT||'browser-report.json';
if(path.basename(reportName)!==reportName)throw Error('Report must be a filename');
const widths=(process.env.QA_WIDTHS||'1440,820,390').split(',').map(Number);
assert(widths.every(w=>[1440,820,390].includes(w)));
const save=()=>fs.writeFileSync(path.join(output,reportName),JSON.stringify(report,null,2));
let server;
let browser;
async function check(name,fn){try{await fn();report.checks.push({name,passed:true});console.log('PASS '+name);}catch(e){report.checks.push({name,passed:false,error:e.message.slice(0,500)});console.log('FAIL '+name+': '+e.message.slice(0,300));}save();}
async function go(page,url){const r=await page.goto(origin+url,{waitUntil:'networkidle'});assert.equal(r.status(),200);assert((await page.title()).includes('USB Stock'));}
async function shot(page,name,fullPage=false){await page.evaluate(async()=>{await Promise.all([...document.images].map(async i=>{i.loading='eager';try{await i.decode();}catch{}}));});await page.waitForLoadState('networkidle');await page.screenshot({path:path.join(output,name+'.png'),fullPage});report.screenshots.push(name+'.png');save();}
async function metrics(page){const m=await page.evaluate(()=>({overflow:document.documentElement.scrollWidth>innerWidth+1,h1:document.querySelectorAll('h1').length,duplicateIds:[...document.querySelectorAll('[id]')].map(x=>x.id).filter((x,i,a)=>a.indexOf(x)!==i),unnamed:[...document.querySelectorAll('input:not([type=hidden]),select,textarea,button')].filter(x=>!x.getAttribute('aria-label')&&!x.getAttribute('aria-labelledby')&&!x.labels?.length&&!x.textContent.trim()).map(x=>x.tagName),badImages:[...document.images].filter(i=>i.getBoundingClientRect().width>0&&i.getBoundingClientRect().top<innerHeight&&(!i.complete||!i.naturalWidth)).map(x=>x.getAttribute('src'))}));assert.equal(m.overflow,false);assert.equal(m.h1,1);assert.deepEqual(m.duplicateIds,[]);assert.deepEqual(m.unnamed,[]);assert.deepEqual(m.badImages,[]);}
async function context(options){const ctx=await browser.newContext(options);await ctx.route('**/*',route=>{if(new URL(route.request().url()).origin===origin)return route.continue();report.externalRequests.push(route.request().url());return route.abort();});ctx.on('page',p=>{p.setDefaultTimeout(20000);p.setDefaultNavigationTimeout(60000);p.on('pageerror',e=>report.errors.push(e.message));});return ctx;}
async function add(page,url,quantity){await go(page,url);await page.locator('[name=variant]:not(:disabled)').first().check();await page.locator('[name=quantity]').fill(String(quantity));await page.locator('[name=printing]').fill('Logotipo · prueba Edge');await page.getByRole('button',{name:'Agregar a mi solicitud'}).click();await page.waitForURL('**/solicitar-cotizacion');}
(async()=>{try{
 server=await startServer(path.join(output,'browser-server.log'));
 for(let i=0;i<30;i++){try{if((await fetch(origin+'/up')).ok)break;}catch{}if(i===29)throw Error('Laravel unavailable');await new Promise(r=>setTimeout(r,300));}
 browser=await chromium.launch({executablePath:process.env.EDGE_EXECUTABLE||'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless:true,timeout:60000});report.browser=browser.version();
 for(const width of widths){
  const ctx=await context({viewport:{width,height:width===390?844:1000},reducedMotion:'reduce'});const page=await ctx.newPage();
  for(const [name,url] of [['home','/'],['catalog','/catalogo'],['product','/producto/usb017'],['no-photo','/producto/tec044'],['contact','/contacto']]){
   await check(`${name} ${width}`,async()=>{await go(page,url);await shot(page,`${name}-${width}`,name==='home'||name==='product');await metrics(page);if(name==='catalog'){await page.locator('[data-product-id]').first().scrollIntoViewIfNeeded();await shot(page,`catalog-cards-${width}`);}});
  }
  await check(`real public form ${width}`,async()=>{
   await add(page,'/producto/usb017',50);await add(page,'/producto/usb027',75);
   assert.equal(await page.locator('.selection-item').count(),2);
   const edit=page.locator('.selection-edit').first();await edit.locator('[name=quantity]').fill('80');await edit.getByRole('button',{name:'Actualizar'}).click();await page.waitForLoadState('networkidle');
   await page.locator('[name=name]').fill(`QA Edge ${width} ${report.run}`);await page.locator('[name=email]').fill(`edge-${width}@example.test`);await page.locator('[name=phone]').fill('5555555555');await page.locator('[name=company]').fill('Empresa QA');await page.locator('[name=country]').fill('México');
   await page.locator('[name=has_seller][value="1"]').check();await page.locator('[name=seller_name]').fill(' Josè Luis ');assert.equal(await page.locator('[name=seller_name]').getAttribute('autocomplete'),'off');assert.equal(await page.locator('select[name=seller_id],datalist').count(),0);
   await page.locator('[name=message]').fill(`Observación Edge ${width}`);await shot(page,`request-${width}`,true);await metrics(page);
   await page.getByRole('button',{name:'Enviar solicitud'}).click();await page.waitForURL('**/solicitud-recibida');assert(await page.getByRole('heading',{name:'Recibimos tu solicitud'}).isVisible());
   await shot(page,`received-${width}`);await metrics(page);const cookie=(await ctx.cookies()).find(c=>c.name==='usbstock_seller_preference');assert(cookie?.httpOnly);assert.equal(cookie.sameSite,'Lax');assert(!cookie.value.includes('Jos'));
  });
  await check(`costs conversion and PDF ${width}`,async()=>{
   await go(page,'/admin/login');await page.locator('[name=email]').fill(access.email);await page.locator('[name=password]').fill(access.password);await page.getByRole('button',{name:'Entrar',exact:true}).click();await page.waitForURL(url=>!url.pathname.includes('/login'));await page.waitForLoadState('networkidle');
   await shot(page,`admin-dashboard-${width}`);await metrics(page);
   await go(page,'/admin/quote-requests?q='+encodeURIComponent(`QA Edge ${width} ${report.run}`));await shot(page,`admin-requests-${width}`,true);await metrics(page);
   const assignment=page.locator('.seller-assignment').first();const seller=await assignment.locator('select option').nth(1).getAttribute('value');await assignment.locator('[name=seller_id]').selectOption(seller);await assignment.locator('[name=confirm]').check();await assignment.getByRole('button',{name:'Guardar asociación'}).click();await page.waitForLoadState('networkidle');
   await page.getByRole('button',{name:'Crear borrador para costos'}).click();await page.waitForURL(/\/admin\/quotes\//);await page.waitForLoadState('networkidle');assert.equal(await page.locator('[data-quote-line]').count(),2);assert.equal(await page.locator('#seller_id').inputValue(),seller);
   const quoteUrl=new URL(page.url()).pathname;report.quotes.push(quoteUrl);
   await page.locator('[name="items[0][unit_price]"]').fill('20.00');await page.locator('[name="items[1][unit_price]"]').fill('25.00');await page.locator('[name="items[0][printing_price]"]').fill('2.00');await page.locator('[name=shipping]').fill('50.00');await page.getByRole('button',{name:'Guardar y calcular'}).click();await page.waitForLoadState('networkidle');
   await shot(page,`admin-costs-${width}`,true);await metrics(page);
   const preview=page.locator('a').filter({hasText:'B · Catálogo comercial'});const previewUrl=await preview.getAttribute('href');assert(previewUrl);const pdfResponse=await ctx.request.get(previewUrl+'&format=pdf');assert.equal(pdfResponse.status(),200);fs.writeFileSync(path.join(output,`quote-b-${width}.pdf`),await pdfResponse.body());
   const issue=page.locator('form').filter({has:page.getByRole('button',{name:'Emitir cotización PDF'})});await issue.locator('[name=confirm]').check();await issue.getByRole('button',{name:'Emitir cotización PDF'}).click();await page.waitForLoadState('networkidle');
   const download=page.getByRole('link',{name:'Descargar versión 1'});assert(await download.isVisible());const pdf=await ctx.request.get(await download.getAttribute('href'));assert.equal(pdf.status(),200);const original=await pdf.body();assert(original.subarray(0,5).equals(Buffer.from('%PDF-')));fs.writeFileSync(path.join(output,`quote-official-${width}.pdf`),original);
   const revise=page.locator('form').filter({has:page.getByRole('button',{name:'Abrir revisión'})});await revise.locator('[name=confirm]').check();await revise.getByRole('button',{name:'Abrir revisión'}).click();await page.waitForLoadState('networkidle');assert.equal(await page.locator('#seller_id').inputValue(),seller);assert((await page.locator('.literal-name').innerText()).includes('Josè Luis'));
   const historical=await ctx.request.get(await page.getByRole('link',{name:'Descargar versión 1'}).getAttribute('href'));assert.deepEqual(await historical.body(),original);
  });
  for(const [name,url] of [['products','/admin/products'],['quotes','/admin/quotes'],['clients','/admin/clients'],['sellers','/admin/commercial-sellers']]) await check(`admin ${name} ${width}`,async()=>{await go(page,url);await shot(page,`admin-${name}-${width}`);await metrics(page);});
  await ctx.close();
 }
 await check('continuous catalog, return, errors, empty search and no JavaScript',async()=>{
  const ctx=await context({viewport:{width:1440,height:1000}});const page=await ctx.newPage();await go(page,'/catalogo');const cards=page.locator('[data-catalog-grid] [data-product-id]');assert.equal(await cards.count(),24);
  await page.locator('[data-catalog-sentinel]').scrollIntoViewIfNeeded();await page.waitForFunction(()=>document.querySelectorAll('[data-product-id]').length>=48);const loaded=await cards.count();assert(loaded>=48);const ids=await cards.evaluateAll(a=>a.map(e=>e.dataset.productId));assert.equal(new Set(ids).size,ids.length);
  await cards.nth(25).locator('h3 a').click();await page.waitForURL('**/producto/**');await page.goBack({waitUntil:'networkidle'});await page.waitForFunction(n=>document.querySelectorAll('[data-product-id]').length>=n,loaded);assert((await cards.count())>=loaded);
  await page.locator('#search').fill('ZZZNOMATCH987');await page.waitForFunction(()=>document.querySelector('[data-catalog-grid]')?.innerText.includes('No encontramos modelos'));await shot(page,'catalog-empty');
  await page.route('**/catalogo/bloques?*',route=>route.fulfill({status:503,body:'unavailable'}));await page.locator('#search').fill('USB');await page.waitForFunction(()=>document.querySelector('[data-catalog-status]')?.textContent.includes('No pudimos'));await shot(page,'catalog-error');await page.unroute('**/catalogo/bloques?*');await page.locator('[data-catalog-next]').click();await page.waitForFunction(()=>document.querySelectorAll('[data-product-id]').length===24);await ctx.close();
  const nojs=await context({javaScriptEnabled:false,viewport:{width:390,height:844}});const p=await nojs.newPage();await go(p,'/catalogo');assert(await p.locator('details.filters').getAttribute('open')!==null);const href=await p.locator('[data-catalog-next]').getAttribute('href');const first=await p.locator('[data-product-id]').first().getAttribute('data-product-id');await p.goto(href,{waitUntil:'networkidle'});assert.equal(await p.locator('[data-product-id]').count(),24);assert.notEqual(await p.locator('[data-product-id]').first().getAttribute('data-product-id'),first);await shot(p,'catalog-no-js');await nojs.close();
 });
 await check('no external requests or browser errors',async()=>{assert.deepEqual(report.externalRequests,[]);assert.deepEqual(report.errors,[]);});
} catch(e){report.checks.push({name:'harness',passed:false,error:e.message.slice(0,500)});console.log('FAIL harness '+e.message.slice(0,200));}finally{report.finished=new Date().toISOString();save();await browser?.close();await server?.stop();}console.log(JSON.stringify({checks:report.checks.length,failed:report.checks.filter(c=>!c.passed).length,screenshots:report.screenshots.length}));process.exitCode=report.checks.some(c=>!c.passed)?1:0;})();
