import fs from 'node:fs';
const out='storage/app/private/qa/commercial';
const inventory=JSON.parse(fs.readFileSync(`${out}/photo-inventory.json`));
const map=JSON.parse(fs.readFileSync(`${out}/photo-mapping.json`));
const result={};
for(const [source,files] of Object.entries(inventory)){
 const hashes={}; for(const file of files)(hashes[file.sha256]??=[]).push(file.name);
 result[source]={files:files.length,formats:Object.fromEntries([...new Set(files.map(f=>f.mime))].map(mime=>[mime,files.filter(f=>f.mime===mime).length])),dimensions:{min:Math.min(...files.filter(f=>f.width).map(f=>Math.min(f.width,f.height))),max:Math.max(...files.filter(f=>f.width).map(f=>Math.max(f.width,f.height)))},under300:files.filter(f=>Math.min(f.width||0,f.height||0)<300).length,duplicateGroups:Object.values(hashes).filter(g=>g.length>1),bytes:files.reduce((s,f)=>s+f.bytes,0)};
}
result.missing=map.filter(m=>m.status!=='verified').map(m=>({sku:m.sku,id:m.legacy_variant_id,status:m.status}));
fs.writeFileSync(`${out}/photo-statistics.json`,JSON.stringify(result,null,2));
console.log(JSON.stringify(Object.fromEntries(Object.entries(result).map(([key,value])=>[key,Array.isArray(value)?value.length:{...value,duplicateGroups:value.duplicateGroups.length}]))));
