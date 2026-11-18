const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'C:/Users/ing_e/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const fs=require('node:fs');
const out='storage/app/private/qa/visual';
(async()=>{const browser=await chromium.launch({executablePath:process.env.EDGE_EXECUTABLE||'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless:true});try{
 const page=await browser.newPage({viewport:{width:1440,height:1000}});const report={url:'https://xeions.com',samples:[]};
 await page.goto(report.url,{waitUntil:'load',timeout:60000});
 await page.waitForTimeout(2500);
 await page.evaluate(()=>window.scrollTo(0,0));
 for(const delay of [100,400,1000]){await page.waitForTimeout(delay);report.samples.push({delay,animations:await page.evaluate(()=>document.getAnimations().map(a=>({name:a.animationName||a.id,timing:a.effect.getTiming(),target:a.effect.target?.className})).slice(0,40))});}
 await page.screenshot({path:out+'/xeions-entry.png'});
 report.scriptSources=await page.locator('script[src]').evaluateAll(nodes=>nodes.map(n=>n.src));
 const sample=()=>page.evaluate(()=>[...document.querySelectorAll('[style], [class*=wow], [class*=anim], [class*=parallax]')].slice(0,150).map(el=>{const s=getComputedStyle(el);return {class:el.className,opacity:s.opacity,transform:s.transform,top:s.top};}));
 report.motionBefore=await sample();await page.mouse.wheel(0,700);await page.waitForTimeout(150);report.motionDuring=await sample();report.scrollAnimations=await page.evaluate(()=>document.getAnimations().map(a=>({name:a.animationName||a.id,timing:a.effect.getTiming(),target:a.effect.target?.className})).slice(0,40));
 await page.waitForTimeout(1100);report.motionAfter=await sample();await page.screenshot({path:out+'/xeions-scroll.png'});
 const link=page.locator('a:visible').filter({hasText:/MÁS INFORMACIÓN|SABER MÁS/i}).first();if(await link.count()){await link.hover();await page.waitForTimeout(180);report.hover=await link.evaluate(el=>{const s=getComputedStyle(el);return{transition:s.transition,transform:s.transform,background:s.backgroundColor,color:s.color};});await page.screenshot({path:out+'/xeions-hover.png'});}
 report.transitions=await page.evaluate(()=>[...document.querySelectorAll('*')].map(el=>{const s=getComputedStyle(el);return{tag:el.tagName,class:typeof el.className==='string'?el.className:'',animation:s.animation,transition:s.transition,transform:s.transform};}).filter(s=>!s.animation.startsWith('none')||s.transition!=='all').slice(0,100));
 fs.writeFileSync(out+'/xeions-reference.json',JSON.stringify(report,null,2));console.log(JSON.stringify({visited:report.url,samples:report.samples.map(s=>s.animations.length),scrollAnimations:report.scrollAnimations.length,hover:report.hover}));
}finally{await browser.close();}})().catch(e=>{console.error(e.message);process.exitCode=1;});
