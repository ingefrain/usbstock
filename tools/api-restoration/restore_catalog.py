"""One-off, guarded local restoration. Never writes MariaDB or stable storage."""
import hashlib, json, os, pathlib, re, sqlite3, stat, subprocess, sys

ROOT = pathlib.Path(__file__).resolve().parents[2]
EXPECTED = pathlib.Path('E:/proyectos/usbstock/api-grupotalento')
SOURCE = pathlib.Path('E:/proyectos/usbstock/site')
DB = ROOT / 'database/api-grupotalento.sqlite'
PRIVATE = ROOT / 'storage/app/private/qa/api-restoration'
TABLES = ['product_categories', 'products', 'product_variants', 'product_category_assignments', 'documents']
FIXTURE = ROOT / 'storage/logs/api-local-fixture.json'

def require(ok, message):
    if not ok:
        raise RuntimeError(message)

def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()

def write(path, data):
    path.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding='utf-8')

def read(path):
    return json.loads(path.read_text(encoding='utf-8'))

def env(path):
    return dict((m[1], m[2].strip().strip('\"\'')) for line in path.read_text(encoding='utf-8-sig').splitlines() if (m := re.match(r'^([A-Z0-9_]+)=(.*)$', line)))

def no_links(path):
    for p in [path, *path.parents]:
        require(not p.is_symlink() and (not p.exists() or not (p.stat(follow_symlinks=False).st_file_attributes & stat.FILE_ATTRIBUTE_REPARSE_POINT)), 'Linked storage/path is prohibited')

def guard():
    require(ROOT == EXPECTED and SOURCE.resolve() != ROOT, 'Wrong checkout')
    no_links(ROOT); no_links(DB); no_links(PRIVATE)
    require(DB.is_file() and not (ROOT / 'bootstrap/cache/config.php').exists(), 'Database/cache guard')
    branch = subprocess.check_output(['git', '-c', 'safe.directory='+ROOT.as_posix(), 'branch', '--show-current'], cwd=ROOT, text=True).strip()
    require(branch == 'experiment/api-grupotalento', 'Wrong branch')
    e = env(ROOT / '.env'); s = env(SOURCE / '.env')
    require(e.get('APP_ENV') == 'local' and e.get('DB_CONNECTION') == 'sqlite' and pathlib.Path(e['DB_DATABASE']).resolve() == DB, 'Wrong experimental database')
    require(not e.get('DB_URL') and not s.get('DB_URL'), 'Database URL not supported')
    require(s.get('APP_ENV') == 'local' and s.get('DB_CONNECTION') == 'mysql' and s.get('DB_HOST') == '127.0.0.1' and s.get('DB_PORT') == '3307' and s.get('DB_DATABASE') == 'usbstock_dev', 'Wrong catalog source')
    for key in ['SUPPLIER_FTP_ENABLED', 'SUPPLIER_SYNC_ENABLED', 'SUPPLIER_SYNC_AUTO_APPLY', 'SUPPLIER_TELEGRAM_ENABLED', 'TELEGRAM_ENABLED', 'CORPORATE_CONTACTS_RECORD_CLICKS']:
        require(e.get(key, 'false').lower() == 'false', 'External writes must remain disabled')
    PRIVATE.mkdir(parents=True, exist_ok=True)

def connection(readonly=False):
    c = sqlite3.connect(DB.as_uri()+('?mode=ro' if readonly else '?mode=rw'), uri=True, timeout=15)
    c.row_factory = sqlite3.Row
    c.execute('PRAGMA foreign_keys=ON')
    return c

def rows(c, table):
    require(re.fullmatch(r'[a-z_]+', table) is not None, 'Invalid table')
    return [dict(row) for row in c.execute('SELECT * FROM '+table+' ORDER BY id')]

def digest(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True, ensure_ascii=False, separators=(',', ':')).encode()).hexdigest()

def protected(c):
    tables = [r[0] for r in c.execute("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")]
    excluded = {*TABLES, 'commerce_locks', 'quote_public_identifiers', 'migrations'}
    result = {t: digest([dict(r) for r in c.execute('SELECT * FROM "'+t+'"')]) for t in sorted(set(tables)-excluded)}
    result['fixture_products'] = digest([dict(r) for r in c.execute('SELECT * FROM products WHERE id IN (1,2) ORDER BY id')])
    result['fixture_variants'] = digest([dict(r) for r in c.execute('SELECT * FROM product_variants WHERE id IN (1,2) ORDER BY id')])
    result['quote_sequence'] = c.execute('SELECT quote_sequence FROM commerce_locks WHERE id=1').fetchone()[0]
    return result

def fixture_check(c):
    f = read(FIXTURE)
    require(f['fixture_only'] is True, 'No fixture provenance')
    q = rows(c, 'quotes'); v = rows(c, 'quote_versions'); u = rows(c, 'users')
    require(len(q) == len(v) == len(u) == 1 and q[0]['uuid'] == f['quote_uuid'] and v[0]['uuid'] == f['version_uuid'] and v[0]['quote_id'] == q[0]['id'], 'Unexpected quote/version/actor')
    require(q[0]['status'] == 'issued' and q[0]['commercial_client_id'] is None and q[0]['seller_id'] is None, 'Unexpected commercial links')
    require(json.loads(q[0]['customer'])['email'] == 'cliente-api@example.invalid' and u[0]['email'] == 'actor-api@example.invalid' and q[0]['created_by'] == u[0]['id'], 'Nonfictional identity')
    for t in ['commercial_clients', 'commercial_sellers', 'quote_requests', 'outbox_messages']:
        require(c.execute('SELECT count(*) FROM '+t).fetchone()[0] == 0, 'Unexpected commercial data')
    require([r['product_variant_id'] for r in rows(c, 'quote_items')] == [1,2], 'Fixture relationships changed')
    require([r[0] for r in c.execute('SELECT external_key FROM products WHERE id IN (1,2) ORDER BY id')] == ['APIENSAYO1','APIENSAYO2'], 'Fixture products changed')
    snapshot = json.loads(v[0]['snapshot'])
    require(snapshot['totals'] == f['totals_minor'], 'Snapshot totals changed')
    require(v[0]['path'] == 'quotes/'+f['quote_uuid']+'/'+f['version_uuid']+'.pdf', 'Unexpected private PDF path')
    pdf = ROOT/'storage/app/private'/v[0]['path']; no_links(pdf)
    require(pdf.is_file() and sha(pdf) == f['pdf_sha256'] == v[0]['sha256'] and pdf.stat().st_size == f['pdf_bytes'], 'PDF changed')
    require(not c.execute('PRAGMA foreign_key_check').fetchall() and c.execute('PRAGMA integrity_check').fetchone()[0] == 'ok', 'SQLite integrity failure')
    return f, q[0]

def backup():
    target = PRIVATE/'before-restoration.sqlite'
    require(not target.exists(), 'Original backup already exists; preserve it')
    c = connection(True); f, q = fixture_check(c)
    require(c.execute('SELECT count(*) FROM products').fetchone()[0] == 2, 'Expected fixture-only starting catalog')
    before = protected(c)
    with sqlite3.connect(target) as b:
        c.backup(b)
        b.row_factory = sqlite3.Row
        require(protected(b) == before and b.execute('PRAGMA integrity_check').fetchone()[0] == 'ok', 'Backup verification failed')
    report = {'backup_sha256': sha(target), 'protected': before, 'quote_uuid': q['uuid'], 'pdf_sha256': f['pdf_sha256'], 'public_id_before': f['public_id'], 'fixture_only_verified': True}
    write(PRIVATE/'backup.json', report)
    print(json.dumps({'backup_verified': True, 'fixture_only_verified': True, 'pdf_unchanged': True}))

def verified_backup():
    b = read(PRIVATE/'backup.json')
    require(sha(PRIVATE/'before-restoration.sqlite') == b['backup_sha256'], 'Backup mismatch')
    return b

def sql_read(sql):
    command = ['C:/MariaDB/11.4.13/bin/mariadb.exe', '--defaults-file=C:/Users/ing_e/.codex/mysql.ini', '--host=127.0.0.1', '--port=3307', '--database=usbstock_dev', '--connect-timeout=5', '--batch', '--raw', '--skip-column-names', '--default-character-set=utf8mb4']
    proc = subprocess.run(command, input=sql, text=True, encoding='utf-8', capture_output=True, timeout=60)
    require(proc.returncode == 0, 'Read-only local catalog query failed; no credentials or rows printed')
    return proc.stdout.splitlines()

def export():
    verified_backup()
    target = PRIVATE/'catalog-snapshot.jsonl'
    require(not target.exists(), 'Preserve existing snapshot')
    quoted = ','.join("'"+t+"'" for t in TABLES)
    cols = sql_read("START TRANSACTION READ ONLY; SELECT CONCAT(TABLE_NAME,'.',COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ("+quoted+") ORDER BY TABLE_NAME,ORDINAL_POSITION; ROLLBACK;")
    statements = ["SET TRANSACTION ISOLATION LEVEL REPEATABLE READ;", "START TRANSACTION READ ONLY;", "SELECT JSON_OBJECT('kind','source','database',DATABASE(),'port',@@port,'captured_utc',UTC_TIMESTAMP());"]
    for t in TABLES:
        names = [x.split('.')[1] for x in cols if x.startswith(t+'.')]
        require(names and all(re.fullmatch('[a-z_]+', x) for x in names), 'Unexpected schema')
        parts = ["'"+x+"',"+('NULL' if t == 'documents' and x == 'uploaded_by' else 't.`'+x+'`') for x in names]
        where = " WHERE t.category='product.image' AND t.deleted_at IS NULL AND t.documentable_type=CONCAT('App',CHAR(92),'Models',CHAR(92),'Product') AND EXISTS(SELECT 1 FROM products p WHERE p.id=t.documentable_id)" if t == 'documents' else ''
        statements.append("SELECT JSON_OBJECT('kind','row','table','"+t+"','row',JSON_OBJECT("+','.join(parts)+")) FROM `"+t+'` t'+where+' ORDER BY t.id;')
    statements.append('ROLLBACK;')
    lines = sql_read('\n'.join(statements)); records = [json.loads(line) for line in lines]
    require(records[0]['database'] == 'usbstock_dev' and records[0]['port'] == 3307, 'Wrong source')
    counts = {t:sum(r.get('table') == t for r in records) for t in TABLES}
    require(counts['products'] > 0 and counts['product_variants'] > 0, 'Empty source')
    target.write_text('\n'.join(lines)+'\n', encoding='utf-8')
    manifest = {'source': records[0], 'counts': counts, 'snapshot_sha256': sha(target), 'read_only': True, 'uploaded_by_copied': False}
    write(PRIVATE/'snapshot-manifest.json', manifest)
    print(json.dumps(manifest))

def remap():
    b = verified_backup(); c = connection(); c.execute('BEGIN IMMEDIATE')
    try:
        f, q = fixture_check(c)
        mapping = [dict(r) for r in c.execute('SELECT * FROM quote_public_identifiers')]
        require(len(mapping) == 1 and mapping[0]['quote_id'] == q['id'] and mapping[0]['source_id'] == q['uuid'] and mapping[0]['origin'] == 'laravel', 'Unexpected mapping or additional assignments')
        if mapping[0]['public_id'] == 3000:
            require((PRIVATE/'fixture-remap.json').exists(), 'Unrecorded remapping requires review')
        else:
            require(mapping[0]['public_id'] == f['public_id'] == b['public_id_before'] == 2147483648, 'Only the recorded fictional mapping may change')
            require(protected(c) == b['protected'], 'Protected data changed before remap')
            require(c.execute('UPDATE quote_public_identifiers SET public_id=3000 WHERE public_id=2147483648 AND quote_id=? AND source_id=?', (q['id'], q['uuid'])).rowcount == 1, 'Mapping update failed')
        require(protected(c) == b['protected'], 'Protected data changed')
        c.commit()
    except Exception:
        c.rollback(); raise
    f['public_id'] = 3000; write(FIXTURE, f)
    write(PRIVATE/'fixture-remap.json', {'old_id':2147483648, 'new_id':3000, 'only_recorded_fixture':True, 'protected_unchanged':True, 'pdf_sha256':f['pdf_sha256'], 'external_reference_scope':'Local-only fixture, private test Bearer, no deployed consumer; no external systems queried. Old local PDF capabilities must be obtained again.'})
    print(json.dumps({'fixture_id':3000, 'quote_and_pdf_unchanged':True}))

def snapshot():
    m = read(PRIVATE/'snapshot-manifest.json'); p = PRIVATE/'catalog-snapshot.jsonl'
    require(m['read_only'] and m['source']['database'] == 'usbstock_dev' and m['source']['port'] == 3307 and sha(p) == m['snapshot_sha256'], 'Snapshot mismatch')
    data = {t:[] for t in TABLES}
    for line in p.read_text(encoding='utf-8').splitlines():
        r = json.loads(line)
        if r['kind'] == 'source': continue
        require(r['kind'] == 'row' and r['table'] in data, 'Unexpected record')
        data[r['table']].append({k:json.dumps(v, ensure_ascii=False, separators=(',', ':')) if isinstance(v,(dict,list)) else v for k,v in r['row'].items()})
    require({t:len(v) for t,v in data.items()} == m['counts'], 'Count mismatch')
    return m, data

def photographs(data, copying):
    manifest = []
    for d in data['documents']:
        require(d['disk'] == 'local' and d['documentable_type'] == 'App\\Models\\Product' and d['category'] == 'product.image' and d['uploaded_by'] is None and d['deleted_at'] is None, 'Unexpected document type')
        require(re.fullmatch(r'documents/[a-f0-9-]{36}\.webp',d['path']) and d['path'] == 'documents/'+d['stored_name'] and re.fullmatch('[a-f0-9]{64}',d['checksum']), 'Unexpected document path/hash')
        source = SOURCE/'storage/app/private'/d['path']; target = ROOT/'storage/app/private'/d['path']
        no_links(source); no_links(target)
        require(source.is_file() and source.stat().st_size == d['size_bytes'] and sha(source) == d['checksum'], 'Source photograph mismatch')
        if copying and not target.exists():
            target.parent.mkdir(parents=True, exist_ok=True)
            with target.open('xb') as out: out.write(source.read_bytes())
        require(target.is_file() and target.stat().st_size == d['size_bytes'] and sha(target) == d['checksum'], 'Copied photograph mismatch')
        manifest.append({'path':d['path'], 'sha256':d['checksum'], 'bytes':d['size_bytes']})
    return manifest

def translated(t, original, maps):
    row = dict(original)
    def mapped(table, key):
        if row[key] is not None: row[key] = maps[table][str(row[key])]
    if t == 'product_categories': mapped(t, 'parent_id')
    if t == 'products': mapped('product_categories','product_category_id')
    if t in ['product_variants','product_category_assignments']: mapped('products','product_id')
    if t == 'product_category_assignments': mapped('product_categories','product_category_id')
    if t == 'documents': row['documentable_id'] = str(maps['products'][str(row['documentable_id'])])
    return row

def import_catalog():
    verified_backup(); m, data = snapshot()
    if (PRIVATE/'import.json').exists(): return verify()
    c = connection(); fixture_check(c)
    require(protected(c) == read(PRIVATE/'backup.json')['protected'], 'Protected data changed')
    require({t:len(rows(c,t)) for t in TABLES} == dict(zip(TABLES,[0,2,2,0,0])), 'Preserve existing destination catalog; import requires original fixture only')
    for t, records in data.items():
        columns = {r[1] for r in c.execute('PRAGMA table_info('+t+')')}
        require(all(set(r) == columns for r in records), 'Catalog schema differs')
    photos = photographs(data, True)
    maps = {t:{} for t in TABLES}; expected = {t:[] for t in TABLES}
    c.execute('BEGIN IMMEDIATE')
    try:
        for t in TABLES:
            pending = list(data[t])
            while pending:
                eligible = [r for r in pending if t != 'product_categories' or r['parent_id'] is None or str(r['parent_id']) in maps[t]]
                require(eligible, 'Broken category hierarchy')
                for original in eligible:
                    row = translated(t, original, maps); row.pop('id')
                    keys = list(row)
                    cur = c.execute('INSERT INTO '+t+' ('+','.join(keys)+') VALUES ('+','.join('?' for _ in keys)+')', [row[k] for k in keys])
                    maps[t][str(original['id'])] = cur.lastrowid; row['id'] = cur.lastrowid; expected[t].append(row)
                    pending.remove(original)
        c.execute('UPDATE commerce_locks SET catalog_revision=catalog_revision+1 WHERE id=1')
        require(protected(c) == read(PRIVATE/'backup.json')['protected'], 'Protected data changed during import')
        require(not c.execute('PRAGMA foreign_key_check').fetchall(), 'Foreign key integrity failure')
        c.commit()
    except Exception:
        c.rollback(); raise
    write(PRIVATE/'import.json', {'snapshot_sha256':m['snapshot_sha256'], 'maps':maps, 'expected_hashes':{t:digest(v) for t,v in expected.items()}, 'photos':photos, 'source_writes':False})
    verify()

def verify():
    b = verified_backup(); m, data = snapshot(); report = read(PRIVATE/'import.json'); c = connection(True)
    f,q = fixture_check(c)
    require(report['snapshot_sha256'] == m['snapshot_sha256'], 'Import/snapshot mismatch')
    require(protected(c) == b['protected'], 'Noncatalog data changed')
    require(c.execute('SELECT public_id FROM quote_public_identifiers WHERE quote_id=?',(q['id'],)).fetchone()[0] == f['public_id'] == 3000, 'Mapping changed')
    maps = report['maps']
    for t in TABLES:
        expected = []
        for original in data[t]:
            row = translated(t, original, maps); row['id'] = maps[t][str(original['id'])]
            actual = c.execute('SELECT * FROM '+t+' WHERE id=?',(row['id'],)).fetchone()
            require(actual and dict(actual) == row, 'Imported catalog row changed: '+t)
            expected.append(row)
        require(len(rows(c,t)) == len(data[t])+(2 if t in ['products','product_variants'] else 0), 'Unexpected catalog count')
    photos = photographs(data, False)
    require(photos == report['photos'], 'Photo manifest changed')
    result = {'ok':True,'source':'usbstock_dev@127.0.0.1:3307','destination':DB.as_posix(),'source_counts':m['counts'], 'destination_counts':{t:len(rows(c,t)) for t in TABLES},'photos_verified':len(photos),'photo_bytes':sum(p['bytes'] for p in photos),'public_id':3000,'protected_unchanged':True,'pdf_unchanged':True,'foreign_keys_ok':True,'source_writes':False}
    write(PRIVATE/'verification.json', result); print(json.dumps(result))

if __name__ == '__main__':
    guard()
    actions = {'backup':backup, 'export':export, 'remap-fictional-id':remap, 'import':import_catalog, 'verify':verify}
    require(len(sys.argv) == 2 and sys.argv[1] in actions, 'Use backup, export, remap-fictional-id, import or verify')
    actions[sys.argv[1]]()