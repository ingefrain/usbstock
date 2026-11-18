const {review,login,shot,origin,output}=require('./browser.cjs');
const fs=require('node:fs');
const phase=process.env.VISUAL_PHASE||'before';
const paths=[['dashboard','/admin'],['products','/admin/products'],['sync','/admin/supplier-sync'],['requests','/admin/quote-requests'],['quotes','/admin/quotes'],['clients','/admin/clients'],['sellers','/admin/commercial-sellers'],['settings','/admin/settings'],['documents','/admin/documents'],['reports','/admin/reports'],['imports','/admin/imports'],['profile','/admin/profile'],['users','/admin/users'],['roles','/admin/roles'],['catalogs','/admin/catalogs'],['components','/admin/components']];
review(async browser=>{
 const report={phase,browser:browser.version(),pages:[],errors:[]};
 for(const width of [1440,820,390]){
  const ctx=await browser.newContext({viewport:{width,height:1000},reducedMotion:'reduce'});await ctx.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():r.abort());await ctx.addInitScript(()=>{window.__cls=[];new PerformanceObserver(list=>{for(const e of list.getEntries())if(!e.hadRecentInput)window.__cls.push({value:e.value,time:e.startTime});}).observe({type:'layout-shift',buffered:true});});const page=await ctx.newPage();page.on('pageerror',e=>report.errors.push(e.message));
  const visit=async(name,url)=>{const r=await page.goto(origin+url,{waitUntil:'networkidle'});await shot(page,`${phase}-${name}-${width}`,name==='home');const metrics=await page.evaluate(()=>({cls:window.__cls,overflow:document.documentElement.scrollWidth>innerWidth+1,h1:document.querySelectorAll('h1').length,files:document.querySelectorAll('input[type=file]').length,dropzones:document.querySelectorAll('[data-file-dropzone]').length,buttons:[...document.querySelectorAll('.button')].slice(0,30).map(el=>{const s=getComputedStyle(el);return{text:el.textContent.trim().slice(0,35),color:s.color,background:s.backgroundColor};})}));report.pages.push({name,width,status:r.status(),...metrics});fs.writeFileSync(output+`/${phase}-audit.json`,JSON.stringify(report,null,2));console.log(`${phase} ${width} ${name}: ${r.status()} overflow=${metrics.overflow}`);};
  for(const [name,url]of[['home','/'],['catalog','/catalogo'],['product','/producto/usb017'],['request','/solicitar-cotizacion'],['contact','/contacto'],['login','/admin/login']])await visit(name,url);
  await login(page);
  for(const [name,url]of paths.filter(([name])=>width===1440||['dashboard','products','sync','requests','quotes','documents'].includes(name)))await visit(name,url);
  await ctx.close();
 }
 console.log(JSON.stringify({pages:report.pages.length,errors:report.errors}));
}).catch(e=>{console.error(e.message);process.exitCode=1;});
