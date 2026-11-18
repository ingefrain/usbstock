// This runner mutates only the private SQLite instance, never the development database.
const fs=require('node:fs'), path=require('node:path'), assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const {chromium}=require('C:/Users/ing_e/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const {startServer}=require('../development-catalog/server.cjs');
const root=path.resolve('storage/app/private/taxonomy-20261118/admin-repair/qa');
const mutate=mode=>execFileSync('C:/xampp/php/php.exe',['tools/admin-repair/qa-state.php',mode],{windowsHide:true,stdio:'pipe'});
(async()=>{
 const server=await startServer(path.join(root,'browser.log'),false,'tools/admin-repair/qa-router.php');
 const browser=await chromium.launch({executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless:true,args:['--disable-renderer-backgrounding','--disable-background-timer-throttling','--disable-backgrounding-occluded-windows','--disable-features=CalculateNativeWinOcclusion']});
 const report={checks:[],screenshots:[],errors:[],blocked:0}; let p;
 try {
  const c=await browser.newContext({viewport:{width:1039,height:1000}});
  await c.route('**/*',r=>{if(new URL(r.request().url()).origin===server.origin)return r.continue(); report.blocked++;return r.abort();});
  p=await c.newPage();p.setDefaultTimeout(45000);p.on('pageerror',e=>report.errors.push(e.message));
  const access=JSON.parse(fs.readFileSync(path.join(root,'access.json'))), data=JSON.parse(fs.readFileSync(path.join(root,'prepared.json')));
  const go=async url=>{assert.equal((await p.goto(server.origin+url,{waitUntil:'domcontentloaded'})).status(),200);};
  const line=i=>p.locator('[data-quote-lines] [data-quote-line]').nth(i), price=i=>line(i).locator('[name$="[unit_price]"]');
  const values=()=>p.locator('[data-quote-lines] [name$="[unit_price]"]').evaluateAll(v=>v.map(i=>i.value));
  const save=async()=>{await Promise.all([p.waitForNavigation(),p.getByRole('button',{name:'Guardar y calcular'}).click()]);assert.equal(await p.locator('.admin-alert-error').count(),0);};
  const shot=async name=>{await p.screenshot({path:path.join(root,name+'.png')});report.screenshots.push(name+'.png');};
  await go('/admin/login');await p.locator('[name=email]').fill(access.email);await p.locator('[name=password]').fill(access.password);
  await Promise.all([p.waitForURL(u=>!u.pathname.endsWith('/login')),p.locator('button[type=submit]').click()]);
  const draft='/admin/quotes/'+data.quote_uuid;
  await go(draft);assert.deepEqual(await values(),['0.00','0.00','99.00','0.00']);
  assert.deepEqual(await p.locator('[data-quote-lines] [data-price-proposed]').allTextContents(),['$5.40','$14.85','$94.50','—']);
  assert.match(await line(3).locator('[data-pricing-status]').innerText(),/costo/);
  await price(0).fill('7');await p.locator('[data-pricing-apply-zero]').click();assert.deepEqual(await values(),['7','14.85','99.00','0.00']);
  await go(draft);await p.locator('[data-pricing-apply-zero]').click();assert.deepEqual(await values(),['5.40','14.85','99.00','0.00']);
  await save();assert.deepEqual(await values(),['5.40','14.85','99.00','0.00']);
  assert.equal(await p.locator('[data-pricing-apply-zero]').isDisabled(),true);report.checks.push('bulk_save_only_eligible_zeros_preserves_saved_and_unsaved_manual_and_zero_cost');
  await price(0).fill('7');await line(0).locator('[name$="[pricing_reason]"]').fill('Acuerdo comercial ficticio de QA');await save();
  await line(0).locator('[name$="[quantity]"]').fill('100');await line(0).locator('[data-pricing-use]').waitFor({state:'visible'});assert.equal(await price(0).inputValue(),'7.00');await save();assert.equal(await price(0).inputValue(),'7.00');
  await line(0).locator('[data-pricing-use]').click();await save();assert.equal(await price(0).inputValue(),'5.40');report.checks.push('quantity_preserves_manual_until_explicit_recalculation');
  mutate('change-cost');await line(0).locator('[data-pricing-check]').click();await p.waitForFunction(()=>document.querySelector('[data-price-proposed]').textContent==='$10.80');assert.equal(await price(0).inputValue(),'5.40');
  await line(0).locator('[data-pricing-use]').click();await save();assert.equal(await price(0).inputValue(),'10.80');mutate('restore-cost');report.checks.push('cost_change_is_visible_without_silent_overwrite');
  mutate('disable-band');await line(0).locator('[data-pricing-check]').click();await p.waitForFunction(()=>document.querySelector('[data-pricing-status]').textContent.includes('No hay una escala'));assert.equal(await price(0).inputValue(),'10.80');assert(await line(0).locator('[data-pricing-use]').isHidden());mutate('restore-band');report.checks.push('missing_rule_retains_manual_capture');
  await go(draft);await line(0).scrollIntoViewIfNeeded();await shot('saved-price-and-proposal-1039');
  const other=await c.newPage();await other.goto(server.origin+draft);await price(0).fill('11');await line(0).locator('[name$="[pricing_reason]"]').fill('Edición concurrente ficticia');await save();
  const rejected=other.waitForResponse(r=>r.request().method()==='POST'&&r.url().includes('/quotes/'));await other.getByRole('button',{name:'Guardar y calcular'}).click();assert.equal((await rejected).status(),409);await other.close();report.checks.push('stale_browser_save_rejected');
  await go('/admin/quote-requests');await Promise.all([p.waitForURL('**/quotes/*'),p.locator('#request-'+data.request_uuid).getByRole('button',{name:'Crear borrador para costos'}).click()]);
  assert.deepEqual(await values(),['5.40','14.85']);report.checks.push('new_request_conversion_stores_prices_automatically');
  const issue=p.locator('form').filter({has:p.getByRole('button',{name:'Emitir cotización PDF'})});await issue.locator('[name=confirm]').check();await Promise.all([p.waitForNavigation(),issue.getByRole('button',{name:'Emitir cotización PDF'}).click()]);
  assert.equal(await p.locator('[data-pricing-panel]').count(),0);mutate('pdf-baseline');
  const revise=p.locator('form').filter({has:p.getByRole('button',{name:'Abrir revisión'})});await revise.locator('[name=confirm]').check();await Promise.all([p.waitForNavigation(),revise.getByRole('button',{name:'Abrir revisión'}).click()]);
  assert.deepEqual(await values(),['5.40','14.85']);await line(0).locator('[name$="[quantity]"]').fill('100');await line(0).locator('[data-pricing-use]').waitFor({state:'visible'});await save();mutate('verify');report.checks.push('issued_snapshot_pdf_and_origin_survive_revision');
  await p.setViewportSize({width:390,height:1000});await line(0).scrollIntoViewIfNeeded();await shot('revision-pricing-390');
  assert.equal(report.errors.length,0);assert.equal(report.blocked,0);report.success=true;
 } catch(e){report.success=false;report.failure=e.message;process.exitCode=1;if(p){report.path=new URL(p.url()).pathname;await p.screenshot({path:path.join(root,'failure.png')}).catch(()=>{});}}
 finally{fs.writeFileSync(path.join(root,'browser.json'),JSON.stringify(report,null,2));await browser.close();await server.stop();}
 console.log(JSON.stringify(report));
})();
