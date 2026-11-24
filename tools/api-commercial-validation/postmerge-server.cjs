// Loopback-only main-code QA server; all Laravel state uses postmerge-bootstrap.php.
const fs=require('node:fs');const path=require('node:path');const http=require('node:http');const net=require('node:net');const {spawn}=require('node:child_process');
const root=path.resolve(__dirname,'../..');const origin='http://127.0.0.1:4398';
const qa=path.join(root,'storage/app/private/qa/api-postmerge');
const types={'.css':'text/css; charset=utf-8','.js':'text/javascript; charset=utf-8','.png':'image/png','.jpg':'image/jpeg','.jpeg':'image/jpeg','.webp':'image/webp','.svg':'image/svg+xml','.ico':'image/x-icon','.ttf':'font/ttf','.woff':'font/woff','.woff2':'font/woff2'};
async function vacant(port){await new Promise((resolve,reject)=>{const s=net.createServer();s.once('error',()=>reject(Error('QA port unavailable')));s.listen(port,'127.0.0.1',()=>s.close(resolve));});}
async function startServer(){
 if(root.replaceAll('\\','/')!=='E:/proyectos/usbstock/site'||!fs.existsSync(path.join(qa,'manifest.json')))throw Error('Prepared main QA required');
 await vacant(4398);await vacant(4399);
 const fd=fs.openSync(path.join(qa,'server.log'),'a');const php=spawn('C:/xampp/php/php.exe',['-S','127.0.0.1:4399','-t',path.join(root,'public'),path.join(__dirname,'postmerge-router.php')],{cwd:root,windowsHide:true,stdio:['ignore',fd,fd]});
 const publicRoot=fs.realpathSync(path.join(root,'public'))+path.sep;const agent=new http.Agent({keepAlive:false,maxSockets:1});
 const front=http.createServer((req,res)=>{
  if(!['127.0.0.1','::1'].includes(req.socket.remoteAddress)){res.writeHead(403).end();return;}
  let candidate;try{const pathname=decodeURIComponent(new URL(req.url,origin).pathname);const resolved=fs.realpathSync(path.resolve(publicRoot,'.'+pathname));if(resolved.startsWith(publicRoot)&&types[path.extname(resolved).toLowerCase()]&&fs.statSync(resolved).isFile())candidate=resolved;}catch{}
  if(candidate&&['GET','HEAD'].includes(req.method)){res.writeHead(200,{'Content-Type':types[path.extname(candidate).toLowerCase()],'Content-Length':fs.statSync(candidate).size,'X-Content-Type-Options':'nosniff','Cache-Control':'no-cache'});if(req.method==='HEAD')res.end();else fs.createReadStream(candidate).on('error',()=>res.destroy()).pipe(res);return;}
  const upstream=http.request({hostname:'127.0.0.1',port:4399,method:req.method,path:req.url,agent,headers:{...req.headers,host:'127.0.0.1:4398'}},response=>{
   if(res.destroyed){response.destroy();return;}
   res.writeHead(response.statusCode,response.headers);response.pipe(res);response.on('error',()=>res.destroy());
  });
  // A cancelled image/navigation must release the only PHP socket, including queued requests.
  req.once('aborted',()=>upstream.destroy());
  res.once('close',()=>{if(!res.writableFinished)upstream.destroy();});
  upstream.setTimeout(30000,()=>upstream.destroy(new Error('QA upstream timeout')));
  upstream.on('error',()=>{if(res.destroyed)return;if(!res.headersSent)res.writeHead(502);res.end('QA application unavailable');});
  req.pipe(upstream);
 });
 let stopped=false;const stop=async()=>{if(stopped)return;stopped=true;front.closeAllConnections();if(front.listening)await new Promise(resolve=>front.close(resolve));agent.destroy();php.kill();fs.closeSync(fd);};
 try{for(let i=0;i<50;i++){let healthy=false;try{healthy=(await fetch('http://127.0.0.1:4399/up',{signal:AbortSignal.timeout(2000)})).ok;}catch{}if(healthy)break;if(i===49)throw Error('Isolated PHP unavailable');await new Promise(resolve=>setTimeout(resolve,200));}await new Promise((resolve,reject)=>{front.once('error',reject);front.listen(4398,'127.0.0.1',resolve);});return{origin,stop};}catch(error){await stop();throw error;}
}
module.exports={startServer,origin};
