// Read-only post-merge acceptance; only the isolated fictional session is logged out.
delete process.env.DEBUG;
const fs=require('node:fs');const path=require('node:path');const cp=require('node:child_process');const crypto=require('node:crypto');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'C:/Users/ing_e/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const {startServer,origin}=require('./postmerge-server.cjs');const root=path.resolve(__dirname,'../..');const qa=path.join(root,'storage/app/private/qa/api-postmerge');
let server,browser,context,page,stage='preflight',operation='preflight';const report={ok:false,checks:[],screenshots:[],network:{blocked_external:0,blocked_mutations:0},javascript_errors:[]};
const check=(ok,code)=>{if(!ok){const e=new Error('QA validation failed');e.qaCode=code;throw e;}};
const save=()=>fs.writeFileSync(path.join(qa,'browser-report.json'),JSON.stringify(report,null,2));
async function step(name, action, timeout=25000) {
 operation=name;report.progress={stage,operation:name,width:report.current_width||null,started_at:new Date().toISOString()};save();
 let timer;
 try{return await Promise.race([Promise.resolve().then(action),new Promise((resolve,reject)=>{timer=setTimeout(()=>{const error=new Error('QA operation exceeded its bound');error.qaCode='operation_timeout';reject(error);},timeout);})]);}
 finally{clearTimeout(timer);}
}
async function ready(url){
 const target=new URL(url,origin);check(target.origin===origin,'navigation_origin_invalid');
 const response=await step('navigate-'+target.pathname,()=>page.goto(target.href,{waitUntil:'domcontentloaded',timeout:25000}),30000);
 check(response?.status()===200,'page_not_200');
 check(await step('fonts-ready',()=>page.evaluate(()=>Promise.race([document.fonts.ready.then(()=>true),new Promise(resolve=>setTimeout(()=>resolve(false),8000))])),10000),'fonts_not_ready');
}
async function shot(name){
 await step('screenshot-'+name,()=>page.screenshot({path:path.join(qa,`${name}.png`),animations:'disabled',fullPage:false,timeout:15000}),20000);
 report.screenshots.push(`${name}.png`);save();
}
async function group(name,fn){stage=name;console.log(`START ${name}`);await fn();report.checks.push({name,passed:true});save();console.log(`PASS ${name}`);}
(async()=>{try{
 check(root.replaceAll('\\','/')==='E:/proyectos/usbstock/site','exact_main_checkout_required');
 const instance=JSON.parse(fs.readFileSync(path.join(qa,'instance.json'),'utf8'));const manifest=JSON.parse(fs.readFileSync(path.join(qa,'manifest.json'),'utf8'));
 const git=(...args)=>cp.execFileSync('git',['-c','safe.directory='+root.replaceAll('\\','/'),...args],{cwd:root,encoding:'utf8',windowsHide:true}).trim();
 check(git('branch','--show-current')==='main'&&git('rev-parse','HEAD')===instance.commit&&manifest.commit===instance.commit,'reviewed_main_commit_required');
 const session=cp.spawnSync('php',['tools/api-commercial-validation/postmerge-check.php','session'],{cwd:root,encoding:'utf8',windowsHide:true,timeout:30000});check(session.status===0,'isolated_session_preparation_failed');
 server=await step('start-isolated-server',()=>startServer(),45000);browser=await step('launch-edge',()=>chromium.launch({executablePath:process.env.EDGE_BINARY||'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless:true,args:['--disable-background-timer-throttling','--disable-renderer-backgrounding'],timeout:45000}),50000);
 context=await step('create-browser-context',()=>browser.newContext({viewport:{width:1440,height:1000},storageState:path.join(qa,'browser-access.json'),serviceWorkers:'block'}),15000);context.setDefaultTimeout(15000);context.setDefaultNavigationTimeout(25000);
 await context.route('**/*',route=>{const r=route.request(),u=new URL(r.url());if(u.origin!==origin){report.network.blocked_external++;return route.abort();}if(!['GET','HEAD'].includes(r.method())&&!(r.method()==='POST'&&u.pathname==='/admin/logout')){report.network.blocked_mutations++;return route.abort();}return route.continue();});
 await context.addInitScript(()=>{
    window.__postmergeMotion=[];const started=performance.now();let attachedAt=null;
    const timer=setInterval(()=>{const photo=document.querySelector('[data-h-img]'),title=document.querySelector('[data-h-line]>span');const now=performance.now();
        if(photo&&title){const attached=!!(photo._gsap||title._gsap);if(attached&&attachedAt===null)attachedAt=now;const transform=getComputedStyle(title).transform;window.__postmergeMotion.push({ms:Math.round(now-started),gsapAttached:attached,photoGsap:!!photo._gsap,titleGsap:!!title._gsap,translateY:transform==='none'?0:new DOMMatrixReadOnly(transform).m42,opacity:Number(getComputedStyle(photo).opacity)});}
        if((attachedAt!==null&&now-attachedAt>4000)||now-started>60000)clearInterval(timer);
    },60);
 });
 page=await step('open-browser-page',()=>context.newPage(),15000);page.on('pageerror',e=>report.javascript_errors.push({stage,type:e.name||'Error'}));
 await group('home-desktop-mobile-gsap',async()=>{
    report.motion=[];
    for(const width of[1440,390]){
        report.current_width=width;await step('viewport-'+width,()=>page.setViewportSize({width,height:width===390?844:1000}),15000);await ready('/');
        await step('gsap-completion',()=>page.waitForFunction(()=>{const photo=document.querySelector('[data-h-img]'),title=document.querySelector('[data-h-line]>span');return photo&&title&&(photo._gsap||title._gsap)&&getComputedStyle(photo).opacity==='1'&&[...document.querySelectorAll('[data-h-line]>span')].every(n=>getComputedStyle(n).transform==='none')&&[...document.querySelectorAll('[data-h-chip]')].every(n=>getComputedStyle(n).opacity==='1');},null,{timeout:15000}),20000);
        const samples=await step('gsap-frames',()=>page.evaluate(()=>window.__postmergeMotion),5000);
        const transitions=samples.filter(sample=>sample.gsapAttached&&(Math.abs(sample.translateY)>0.1||(sample.opacity>0&&sample.opacity<1)));
        report.motion.push({width,motion_observed:transitions.length>0,samples:samples.length,transition_samples:transitions.slice(0,8),final_sample:samples.at(-1)||null});save();
        check(transitions.length>0,'gsap_motion_not_observed');check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'home_overflow');await shot(`home-${width}`);
    }
 });
 await group('catalog-and-product',async()=>{report.current_width=1440;await step('viewport-1440',()=>page.setViewportSize({width:1440,height:1000}),15000);await ready('/catalogo');check(await page.locator('[data-product-id]').count()===24,'catalog_first_block_invalid');await shot('catalog');await ready(`/producto/${instance.product_slug}`);const image=page.locator('.detail-image img');await step('product-image-loaded',()=>page.waitForFunction(()=>{const image=document.querySelector('.detail-image img');return image&&image.complete&&image.naturalWidth>0;},null,{timeout:15000}),20000);check(await step('product-image-check',()=>image.evaluate(i=>i.naturalWidth>0),5000),'product_photo_missing');check(await page.locator('[data-product-selection] [name=quantity]').getAttribute('min')==='50','minimum_quantity_changed');await shot('product');});
 await group('admin-requests-quote-pdfs',async()=>{await ready('/admin/');check(new URL(page.url()).pathname!=='/admin/login','admin_session_invalid');await shot('admin-dashboard');await ready('/admin/quote-requests?status=converted');check(await page.locator(`#request-${instance.request_uuid}`).count()===1,'request_not_present');await shot('admin-requests');await ready(`/admin/quotes/${instance.quote_uuid}`);check(await page.locator('[data-quote-editor] fieldset').isDisabled(),'issued_form_must_be_read_only');check((await page.locator('.quote-summary').textContent()).includes('Totales guardados'),'quote_totals_missing');const docs=page.getByRole('link',{name:/^Descargar versión [12]$/});check(await docs.count()===2,'issued_versions_missing');report.pdfs=[];for(const link of await docs.all()){const href=await link.getAttribute('href');check(new URL(href).origin===origin,'document_origin_invalid');const response=await step('download-admin-pdf',()=>context.request.get(href,{maxRedirects:0,timeout:20000}),25000);check(response.status()===200&&response.headers()['content-type'].startsWith('application/pdf'),'admin_pdf_not_200');const bytes=await step('read-admin-pdf-bytes',()=>response.body(),10000);check(bytes.subarray(0,5).toString()==='%PDF-','invalid_pdf');const hash=crypto.createHash('sha256').update(bytes).digest('hex');check(Object.values(manifest.files).some(f=>f.sha256===hash&&f.bytes===bytes.length),'pdf_not_equal_to_cloned_source');report.pdfs.push({version:Number((await link.textContent()).match(/[12]$/)[0]),bytes:bytes.length,sha256:hash});}await shot('admin-quote');});
 await group('api-disabled',async()=>{for(const id of[3000,3001]){const response=await step('disabled-api-quote-'+id,()=>context.request.get(`${origin}/api/v1/cotizaciones/${id}`,{maxRedirects:0,timeout:15000}),20000);check(response.status()===401&&(await response.json()).error.code==='UNAUTHORIZED','disabled_api_quote_exposed');}const pdf=await step('disabled-api-pdf',()=>context.request.get(`${origin}/api/v1/documentos/synthetic-capability`,{maxRedirects:0,timeout:15000}),20000);check(pdf.status()===404,'disabled_api_pdf_exposed');report.api_enabled=false;});
 await group('logout',async()=>{await ready(`/admin/quotes/${instance.quote_uuid}`);await page.locator('.profile-menu > summary').click();await step('logout-submit',()=>Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded',timeout:20000}),page.getByRole('button',{name:'Cerrar sesión',exact:true}).click()]),25000);await ready('/admin/quotes');check(new URL(page.url()).pathname==='/admin/login','logout_not_confirmed');report.fictional_admin_logged_out=true;});
 check(report.network.blocked_external===0&&report.network.blocked_mutations===0&&report.javascript_errors.length===0,'unexpected_network_or_js_failure');report.ok=true;report.commit=instance.commit;save();
}catch(error){report.failure={stage,operation,width:report.current_width||null,type:/^[A-Za-z]+$/.test(error.name||'')?error.name:'Error',code:error.qaCode||'browser_operation_failed'};process.exitCode=1;if(page&&!page.isClosed()){try{report.failure.motion=await Promise.race([page.evaluate(()=>window.__postmergeMotion||[]),new Promise(resolve=>setTimeout(()=>resolve([]),3000))]);}catch{}}if(fs.existsSync(qa))save();console.log(`FAIL ${stage}: ${report.failure.code}`);
}finally{await server?.stop();if(browser){await Promise.race([browser.close(),new Promise(resolve=>setTimeout(resolve,10000))]);}if(fs.existsSync(qa))save();console.log(`RESULT ${report.ok?'PASS':'FAIL'} isolated postmerge browser`);}})();
