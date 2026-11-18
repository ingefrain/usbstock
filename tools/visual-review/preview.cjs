const {review,login,shot,origin}=require('./browser.cjs');
review(async browser=>{
 for(const width of [1440,820,390]){
  const page=await browser.newPage({viewport:{width,height:1000},reducedMotion:'reduce'});
  for(const [name,url]of[['home','/'],['catalog','/catalogo'],['login','/admin/login']]){await page.goto(origin+url,{waitUntil:'networkidle'});await shot(page,`iteration-${name}-${width}`,name==='home');console.log(name+' '+width);}
  await login(page);await page.goto(origin+'/admin',{waitUntil:'networkidle'});await shot(page,`iteration-dashboard-${width}`);await page.close();
 }
}).catch(e=>{console.error(e.message);process.exitCode=1;});
