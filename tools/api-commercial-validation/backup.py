"""Create the pre-commercial-flow backup. Exact experiment only, no server writes."""
from pathlib import Path
import hashlib,json,re,sqlite3,subprocess,stat
root=Path(__file__).resolve().parents[2]
assert root==Path('E:/proyectos/usbstock/api-grupotalento')
e=dict((m[1],m[2].strip().strip('\"\'')) for l in (root/'.env').read_text(encoding='utf-8-sig').splitlines() if (m:=re.match(r'^([A-Z0-9_]+)=(.*)$',l)))
db=root/'database/api-grupotalento.sqlite'
assert e['APP_ENV']=='local' and e['DB_CONNECTION']=='sqlite' and Path(e['DB_DATABASE']).resolve()==db and not e.get('DB_URL')
assert not (root/'bootstrap/cache/config.php').exists()
assert subprocess.check_output(['git','-c','safe.directory='+root.as_posix(),'branch','--show-current'],cwd=root,text=True).strip()=='experiment/api-grupotalento'
for k in ['SUPPLIER_FTP_ENABLED','SUPPLIER_SYNC_ENABLED','SUPPLIER_SYNC_AUTO_APPLY','SUPPLIER_TELEGRAM_ENABLED','TELEGRAM_ENABLED','CORPORATE_CONTACTS_RECORD_CLICKS']:
 assert e.get(k,'false').lower()=='false'
assert not e.get('COMMERCE_NOTIFY_EMAIL')
qa=root/'storage/app/private/qa/api-commercial-validation';qa.mkdir(parents=True,exist_ok=True)
for p in [db,qa,*qa.parents]:
 assert not p.is_symlink() and not(p.stat().st_file_attributes&stat.FILE_ATTRIBUTE_REPARSE_POINT)
target=qa/'before-commercial.sqlite'
assert not target.exists(), 'Preserve existing backup'
c=sqlite3.connect(db.as_uri()+'?mode=ro',uri=True);c.row_factory=sqlite3.Row
sha=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
def rows(t):return [dict(r) for r in c.execute('SELECT * FROM '+t+' ORDER BY id')]
def digest(v):return hashlib.sha256(json.dumps(v,sort_keys=True,ensure_ascii=False,separators=(',',':')).encode()).hexdigest()
f=json.loads((root/'storage/logs/api-local-fixture.json').read_text())
assert f['fixture_only'] and f['public_id']==3000
assert len(rows('quotes'))==len(rows('quote_versions'))==1 and not rows('quote_requests') and not rows('commercial_clients') and not rows('commercial_sellers') and not rows('commercial_price_scales')
assert rows('quotes')[0]['uuid']==f['quote_uuid'] and rows('quote_versions')[0]['uuid']==f['version_uuid']
with sqlite3.connect(target) as b:
 c.backup(b)
 assert b.execute('PRAGMA integrity_check').fetchone()[0]=='ok' and not b.execute('PRAGMA foreign_key_check').fetchall()
 assert list(c.iterdump())==list(b.iterdump())
tables=['product_categories','products','product_variants','product_category_assignments','documents']
protected={t:digest(rows(t)) for t in tables}
original={t:digest(rows(t)) for t in ['quotes','quote_versions','quote_items','users']}
original_max={t:max((r['id'] for r in rows(t)),default=0) for t in original}
files={}
for folder in ['documents','quotes','quote-assets']:
 for p in (root/'storage/app/private'/folder).rglob('*'):
  if p.is_file(): files[p.relative_to(root/'storage/app/private').as_posix()]=sha(p)
pdf=root/'storage/app/private'/rows('quote_versions')[0]['path']
assert sha(pdf)==f['pdf_sha256']
report={'backup_sha256':sha(target),'backup_verified':True,'catalog_hashes':protected,'original_hashes':original,'original_max_ids':original_max,'original_files':files,'fixture_3000':f,'counts_before':{t:len(rows(t)) for t in tables+list(original)+['quote_requests','commercial_clients','commercial_sellers','commercial_price_scales','outbox_messages']}}
(qa/'backup.json').write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf8')
print(json.dumps({'backup_verified':True,'original_quote':3000,'catalog_products':len(rows('products')),'protected_files':len(files),'source_database_writes':False}))