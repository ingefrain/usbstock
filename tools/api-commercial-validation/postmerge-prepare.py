"""Post-merge QA preparation: clone the authorized experiment into a private sandbox.
Run only from site/main after integration, with the exact reviewed commit argument.
Never reads .env, connects to MariaDB, migrates, or writes to the source experiment.
"""
from pathlib import Path
import base64,hashlib,json,secrets,shutil,sqlite3,stat,subprocess,sys
ROOT=Path(__file__).resolve().parents[2]
SOURCE=Path('E:/proyectos/usbstock/api-grupotalento')
OUT=ROOT/'storage/app/private/qa/api-postmerge'
def require(ok,code):
    if not ok: raise RuntimeError(code)
def git(*args):
    return subprocess.check_output(['git','-c','safe.directory='+ROOT.as_posix(),*args],cwd=ROOT,text=True).strip()
def no_links(p):
    for candidate in [p,*p.parents]:
        if candidate.exists():
            require(not candidate.is_symlink() and not(candidate.stat(follow_symlinks=False).st_file_attributes&stat.FILE_ATTRIBUTE_REPARSE_POINT),'Linked path rejected')
def sha(p): return hashlib.sha256(p.read_bytes()).hexdigest()
def write(name,value): (OUT/name).write_text(json.dumps(value,indent=2,ensure_ascii=False),encoding='utf8')
def main():
    require(ROOT==Path('E:/proyectos/usbstock/site'),'Exact stable checkout required after merge')
    require(len(sys.argv)==2 and len(sys.argv[1])==40 and all(c in '0123456789abcdef' for c in sys.argv[1]),'Expected reviewed commit is required')
    require(git('branch','--show-current')=='main' and git('rev-parse','HEAD')==sys.argv[1],'Expected main commit required')
    require(not git('status','--porcelain'),'Clean main required')
    require(not OUT.exists(),'Preserve existing isolated QA instance')
    require(not (ROOT/'bootstrap/cache/config.php').exists(),'Cached main configuration is forbidden')
    source_qa=SOURCE/'storage/app/private/qa/api-commercial-validation'
    context=json.loads((source_qa/'context.json').read_text())
    report=json.loads((source_qa/'browser-flow.json').read_text())
    reconciliation=json.loads((source_qa/'economic-reconciliation.json').read_text())
    require(report['ok'] and report['fictional_admin_logged_out'] and report['public_id']==3001 and reconciliation['ok'],'Completed and reconciled fictional flow required')
    db=SOURCE/'database/api-grupotalento.sqlite';no_links(db);no_links(OUT)
    OUT.mkdir(parents=True)
    with sqlite3.connect(db.as_uri()+'?mode=ro',uri=True) as source:
        source.execute('PRAGMA query_only=ON');source.execute('BEGIN')
        require(source.execute('SELECT COUNT(*) FROM quotes').fetchone()[0]==2,'Unexpected source quote count')
        require(source.execute('SELECT COUNT(*) FROM quote_requests').fetchone()[0]==1,'Unexpected source request count')
        require(source.execute('SELECT COUNT(*) FROM outbox_messages').fetchone()[0]==0,'Unexpected commercial notifications')
        with sqlite3.connect(OUT/'review.sqlite') as clone:
            source.backup(clone)
            require(clone.execute('PRAGMA integrity_check').fetchone()[0]=='ok','Clone integrity failed')
            require(not clone.execute('PRAGMA foreign_key_check').fetchall(),'Clone foreign key failure')
            require(list(source.iterdump())==list(clone.iterdump()),'Clone differs from consistent source snapshot')
    runtime=OUT/'runtime'
    for folder in ['app/private','app/public','framework/cache/data','framework/sessions','framework/views','logs']:(runtime/folder).mkdir(parents=True,exist_ok=True)
    copied={}
    for folder in ['documents','quotes','quote-assets']:
        parent=SOURCE/'storage/app/private'/folder
        if not parent.exists():continue
        for src in parent.rglob('*'):
            no_links(src)
            if not src.is_file():continue
            relative=src.relative_to(SOURCE/'storage/app/private')
            dst=runtime/'app/private'/relative;no_links(dst)
            dst.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(src,dst)
            require(src.stat().st_size==dst.stat().st_size and sha(src)==sha(dst),'Copied file integrity failure')
            copied[relative.as_posix()]={'bytes':dst.stat().st_size,'sha256':sha(dst)}
    instance={'purpose':'api-postmerge-isolated-v1','commit':sys.argv[1],'key':'base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),'database':(OUT/'review.sqlite').as_posix(),'port':4398,'actor_id':context['actor_id'],'request_uuid':report['request_uuid'],'quote_uuid':report['quote_uuid'],'quote_folio':report['versions'][-1]['folio'],'public_id':3001,'product_slug':context['product']['slug'],'variant_uuid':context['product']['variant_uuid']}
    write('instance.json',instance)
    write('manifest.json',{'ok':True,'commit':sys.argv[1],'source':'api-grupotalento.sqlite','clone_sha256':sha(OUT/'review.sqlite'),'files':copied,'files_count':len(copied),'api_enabled':False,'main_env_read':False,'migrations_executed':False,'source_database_writes':False})
    print(json.dumps({'prepared':True,'files':len(copied),'api_enabled':False,'migrations_executed':False,'source_database_writes':False}))
if __name__=='__main__':
    try:main()
    except Exception as error:
        print(json.dumps({'prepared':False,'error_type':type(error).__name__,'reason':str(error) if isinstance(error,RuntimeError) else 'Preparation failed; private state preserved'}));sys.exit(1)
