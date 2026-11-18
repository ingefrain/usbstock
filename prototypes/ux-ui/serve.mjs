import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {createHmac,randomBytes,timingSafeEqual} from 'node:crypto';
import {products,concepts} from './data.mjs';
import {home,catalog,product,quote,admin,hub,card,empty} from './views.mjs';
const root=path.dirname(fileURLToPath(import.meta.url));
const port=Number(process.env.USBSTOCK_PROTOTYPE_PORT||4387);
if(!Number.isInteger(port)||port<1024||port>65535)throw Error('Invalid local port');
const origin=`http://127.0.0.1:${port}`;
const key=randomBytes(32); // Synthetic cursors, never APP_KEY or application credentials.
const fingerprint=p=>JSON.stringify(['q','category','capacity','available'].map(k=>p.get(k)||''));
function token(id,filters){const data=Buffer.from(JSON.stringify({id,filters,revision:1})).toString('base64url');return data+'.'+createHmac('sha256',key).update(data).digest('base64url');}
function decode(cursor,filters){try{const [data,mac]=cursor.split('.');const expected=createHmac('sha256',key).update(data).digest();const supplied=Buffer.from(mac,'base64url');if(supplied.length!==expected.length||!timingSafeEqual(supplied,expected))return null;const value=JSON.parse(Buffer.from(data,'base64url').toString());return value.revision===1&&value.filters===filters&&Number.isInteger(value.id)?value.id:null;}catch{return null;}}
function paginate(t,url){const p=url.searchParams;const signature=fingerprint(p);const cursor=p.get('cursor');const after=cursor?decode(cursor,signature):Infinity;if(after===null)return null;const query=(p.get('q')||'').trim().toLocaleLowerCase('es');const all=products.filter(x=>x.id<after&&(!query||`${x.name} ${x.model} ${x.description} ${x.category}`.toLocaleLowerCase('es').includes(query))&&(!p.get('category')||x.category===p.get('category'))&&(!p.get('capacity')||String(x.capacity)===p.get('capacity'))&&(p.get('available')!=='1'||x.stock>=50));const items=all.slice(0,24);let next=null;if(all.length>24){const target=new URL(`/${t}/catalogo`,origin);target.search=url.search;target.searchParams.set('cursor',token(items.at(-1).id,signature));next=target.href;}return {items,next};}
const assets=new Map(fs.readdirSync(path.join(root,'assets')).map(name=>['/assets/'+name,path.join(root,'assets',name)]));
// Fixed read-only mapping: exercise the current production interaction code unchanged.
assets.set('/assets/catalog.js',path.resolve(root,'../../resources/js/catalog.js'));
const types={'.css':'text/css; charset=utf-8','.js':'text/javascript; charset=utf-8','.jpg':'image/jpeg','.webp':'image/webp'};
const server=http.createServer((req,res)=>{
 res.setHeader('Cache-Control','no-store');res.setHeader('X-Robots-Tag','noindex, nofollow');res.setHeader('X-Content-Type-Options','nosniff');res.setHeader('Referrer-Policy','no-referrer');res.setHeader('Content-Security-Policy',"default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
 if(req.method!=='GET'&&req.method!=='HEAD'){res.writeHead(405);res.end('Prototype is read-only');return;}
 if(![`127.0.0.1:${port}`,`localhost:${port}`].includes(req.headers.host)){res.writeHead(403);res.end('Local preview only');return;}
 const url=new URL(req.url,origin);const send=(body,type='text/html; charset=utf-8',status=200)=>{res.writeHead(status,{'Content-Type':type});res.end(req.method==='HEAD'?'':body);};
 if(assets.has(url.pathname)){const file=assets.get(url.pathname);send(fs.readFileSync(file),types[path.extname(file)]||'application/octet-stream');return;}
 if(url.pathname==='/favicon.ico'){send('','image/x-icon',204);return;}
 if(url.pathname==='/'){send(hub());return;}
 const [t,route='',id]=url.pathname.split('/').filter(Boolean);if(!concepts[t]){send('Propuesta no encontrada.','text/plain; charset=utf-8',404);return;}
 if(!route){send(home(t));return;}
 if(route==='catalogo'||route==='bloques'){const page=paginate(t,url);if(!page){send(route==='bloques'?JSON.stringify({message:'Cursor de demostración vencido.'}):'El cursor venció. Vuelve al catálogo.',route==='bloques'?'application/json':'text/plain; charset=utf-8',409);return;}if(route==='bloques')send(JSON.stringify({html:page.items.length?page.items.map(p=>card(t,p)).join(''):empty(t),next:page.next,revision:1}),'application/json');else send(catalog(t,url,page));return;}
 if(route==='producto'){const selected=products.find(p=>p.id===Number(id));selected?send(product(t,selected)):send('Modelo demo no encontrado.','text/plain',404);return;}
 if(route==='cotizar'){send(quote(t));return;}
 if(route==='admin'){send(admin(t,url.searchParams));return;}
 send('Vista no encontrada.','text/plain; charset=utf-8',404);
});
server.listen(port,'127.0.0.1',()=>console.log(`USB Stock · prototypes only · ${origin}/`));
server.on('error',error=>{console.error(error.code==='EADDRINUSE'?'Local port is already in use. Choose USBSTOCK_PROTOTYPE_PORT.':'Could not start local prototype.');process.exitCode=1;});
process.on('SIGINT',()=>server.close(()=>process.exit(0)));
