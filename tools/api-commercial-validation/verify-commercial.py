"""Read-only economic verifier for the completed fictional browser flow.
Use bundled Codex Python and Poppler after all four states and browser success.
No Laravel/legacy PHP, HTTP, MariaDB or SQL write is executed.
"""
import hashlib
import json
import pathlib
import re
import sqlite3
import stat
import subprocess
import sys
import tempfile
import unicodedata
from datetime import datetime, timezone
from decimal import Decimal, ROUND_HALF_UP

ROOT = pathlib.Path(__file__).resolve().parents[2]
EXPECTED_ROOT = pathlib.Path('E:/proyectos/usbstock/api-grupotalento')
DB = ROOT / 'database/api-grupotalento.sqlite'
PRIVATE = ROOT / 'storage/app/private'
QA = PRIVATE / 'qa/api-commercial-validation'
OUT = QA / 'economic-reconciliation.json'
POPPLER = pathlib.Path('C:/Users/ing_e/.cache/codex-runtimes/codex-primary-runtime/dependencies/native/poppler/Library/bin/pdftoppm.exe')
CATALOG = ['product_categories', 'products', 'product_variants', 'product_category_assignments', 'documents']
PREFIXES = ['quotes', 'quote_versions', 'quote_items', 'users']
TOTAL_KEYS = ['subtotal', 'tax', 'discount', 'shipping', 'total']
STAGES = ['draft', 'issued-v1', 'revision', 'issued-v2']
REQUIRED = ['backup.json', 'before-commercial.sqlite', 'context.json', 'browser-flow.json',
            'quote-v1.pdf', 'quote-v2.pdf', *['state-'+stage+'.json' for stage in STAGES]]
REPORT = {'ok': False, 'checks': [], 'versions': [], 'visual_review': 'pending_root_image_inspection'}


class VerificationFailure(Exception):
    pass


def check(condition, code):
    if not condition: raise VerificationFailure(code)
    REPORT['checks'].append(code)


def no_links(path):
    for part in [path, *path.parents]:
        if part.is_symlink(): raise VerificationFailure('linked_path')
        if part.exists():
            attributes = getattr(part.stat(follow_symlinks=False), 'st_file_attributes', 0)
            if attributes & getattr(stat, 'FILE_ATTRIBUTE_REPARSE_POINT', 0):
                raise VerificationFailure('reparse_point')


def sha(path):
    no_links(path)
    return hashlib.sha256(path.read_bytes()).hexdigest()


def digest(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True, ensure_ascii=False, separators=(',', ':')).encode()).hexdigest()


def load(name, decimal=False):
    path = QA / name
    no_links(path)
    return json.loads(path.read_text(encoding='utf-8-sig'), parse_float=Decimal if decimal else float)


def settings(path):
    result = {}
    for line in path.read_text(encoding='utf-8-sig').splitlines():
        match = re.match(r'^([A-Z0-9_]+)=(.*)$', line)
        if not match: continue
        key, value = match.groups()
        if key in result: raise VerificationFailure('duplicate_private_setting')
        value = value.strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in '\"\'': value = value[1:-1]
        result[key] = value
    return result


def guard():
    check(ROOT == EXPECTED_ROOT, 'exact_experimental_checkout')
    for path in [ROOT, DB, QA, ROOT / '.env']: no_links(path)
    env = settings(ROOT / '.env')
    check(env.get('APP_ENV') == 'local' and env.get('DB_CONNECTION') == 'sqlite', 'local_sqlite_configuration')
    check(pathlib.Path(env.get('DB_DATABASE', '')).resolve() == DB and DB.is_file(), 'exact_experimental_database')
    check(not env.get('DB_URL') and not (ROOT / 'bootstrap/cache/config.php').exists(), 'no_connection_override_or_cached_configuration')
    for key in ['SUPPLIER_FTP_ENABLED', 'SUPPLIER_SYNC_ENABLED', 'SUPPLIER_SYNC_AUTO_APPLY',
                'SUPPLIER_TELEGRAM_ENABLED', 'TELEGRAM_ENABLED', 'CORPORATE_CONTACTS_RECORD_CLICKS']:
        check(env.get(key, 'false').lower() == 'false', 'external_write_disabled_'+key)
    check(not env.get('COMMERCE_NOTIFY_EMAIL') and env.get('MAIL_MAILER') == 'array', 'notifications_disabled_and_array_mailer')
    check(all((QA / name).is_file() for name in REQUIRED), 'all_completed_flow_artifacts_present')
    browser = load('browser-flow.json', True)
    check(browser.get('ok') is True, 'browser_report_successful')
    backup = load('backup.json')
    check(backup.get('backup_verified') is True and sha(QA / 'before-commercial.sqlite') == backup['backup_sha256'], 'pre_flow_backup_hash_verified')
    context = load('context.json')
    check(context.get('scale_is_fictional') is True and context.get('external_writes_disabled') is True, 'context_explicitly_fictional_and_local')
    check(context.get('customer_email') == 'qa-api-commercial@example.invalid'
          and 'ficticio' in context.get('customer_name', '').lower()
          and 'ficticio' in context.get('seller_name', '').lower(), 'fictitious_identity_context')
    return backup, context, browser


def connect(path):
    no_links(path)
    connection = sqlite3.connect(path.as_uri()+'?mode=ro', uri=True, timeout=15)
    connection.row_factory = sqlite3.Row
    connection.execute('PRAGMA query_only=ON')
    connection.execute('PRAGMA foreign_keys=ON')
    connection.execute('BEGIN')
    return connection


def rows(connection, table, maximum=None):
    allowed = set(CATALOG + PREFIXES + ['quote_requests', 'commercial_clients', 'commercial_sellers',
                  'commercial_price_scales', 'outbox_messages', 'quote_public_identifiers', 'commerce_locks', 'admin_audit_events'])
    check(table in allowed, 'read_whitelisted_table_'+table)
    sql = 'SELECT * FROM '+table; params = ()
    if maximum is not None:
        check(type(maximum) is int and maximum >= 0, 'valid_protected_prefix_'+table)
        sql += ' WHERE id<=?'; params = (maximum,)
    return [dict(row) for row in connection.execute(sql+' ORDER BY '+('public_id' if table == 'quote_public_identifiers' else 'id'), params)]


def sole(values, code):
    check(len(values) == 1, code)
    return values[0]


def json_column(row, key):
    value = row[key]
    return json.loads(value) if isinstance(value, str) else value


def cents(value, code):
    check(not isinstance(value, bool) and isinstance(value, (str, int, Decimal)), code+'_decimal_type')
    text = str(value)
    check(re.fullmatch(r'\d+(?:\.\d{1,2})?', text) is not None, code+'_decimal_format')
    number = Decimal(text) * 100
    check(number == number.to_integral_value(), code+'_exact_cents')
    return int(number)


def integer(value, code):
    check(type(value) is int and value >= 0, code+'_nonnegative_integer')
    return value


def equal_totals(actual, expected, code):
    check(isinstance(actual, dict) and all(key in actual for key in TOTAL_KEYS), code+'_keys')
    check(all(type(actual[key]) is int for key in TOTAL_KEYS), code+'_integer_cents')
    check({key: actual[key] for key in TOTAL_KEYS} == {key: expected[key] for key in TOTAL_KEYS}, code+'_matches')


def verify_protected(connection, original, backup):
    catalog_counts = {}
    for table in CATALOG:
        values = rows(connection, table)
        check(digest(values) == backup['catalog_hashes'][table], 'unchanged_catalog_'+table)
        catalog_counts[table] = len(values)
    for table in PREFIXES:
        check(digest(rows(connection, table, backup['original_max_ids'][table])) == backup['original_hashes'][table], 'unchanged_original_prefix_'+table)
    for relative, expected in backup['original_files'].items():
        path = PRIVATE / relative
        check(path.resolve().is_relative_to(PRIVATE.resolve()), 'protected_file_stays_private')
        check(path.is_file() and sha(path) == expected, 'unchanged_preexisting_private_file')
    before_mappings = rows(original, 'quote_public_identifiers')
    check(len(before_mappings) == 1 and before_mappings[0]['public_id'] == 3000, 'backup_contains_only_original_public_mapping')
    check([r for r in rows(connection, 'quote_public_identifiers') if r['public_id'] == 3000] == before_mappings, 'original_public_mapping_3000_unchanged')
    fixture = backup['fixture_3000']
    old_quote = sole([r for r in rows(connection, 'quotes') if r['uuid'] == fixture['quote_uuid']], 'original_quote_present')
    old_version = sole([r for r in rows(connection, 'quote_versions') if r['uuid'] == fixture['version_uuid']], 'original_version_present')
    check(json_column(old_quote, 'totals') == fixture['totals_minor'] and json_column(old_version, 'snapshot')['totals'] == fixture['totals_minor'], 'original_3000_totals_unchanged')
    check(sha(PRIVATE / old_version['path']) == fixture['pdf_sha256'], 'original_3000_pdf_unchanged')
    check(not connection.execute('PRAGMA foreign_key_check').fetchall(), 'all_current_foreign_keys_valid')
    check(connection.execute('PRAGMA integrity_check').fetchone()[0] == 'ok', 'current_sqlite_integrity_ok')
    REPORT['preservation'] = {'catalog_tables_unchanged': catalog_counts, 'stock_and_costs_unchanged': True,
                              'original_prefix_tables_unchanged': PREFIXES, 'preexisting_files_unchanged': len(backup['original_files']),
                              'original_public_id': 3000, 'original_totals_and_pdf_unchanged': True, 'backup_sha256': backup['backup_sha256']}


def verify_entities(connection, original, backup, context, browser):
    all_quotes = rows(connection, 'quotes')
    check(len(all_quotes) == backup['counts_before']['quotes'] + 1, 'exactly_one_new_quote')
    quote = sole([q for q in all_quotes if q['uuid'] == browser['quote_uuid']], 'browser_quote_resolved')
    check(quote['id'] > backup['original_max_ids']['quotes'] and quote['created_by'] == context['actor_id'], 'new_quote_owned_by_fictitious_actor')
    request = sole(rows(connection, 'quote_requests'), 'exactly_one_public_request')
    check(request['uuid'] == browser['request_uuid'] and request['quote_id'] == quote['id'] and request['status'] == 'converted', 'request_converted_to_same_quote')
    client = sole(rows(connection, 'commercial_clients'), 'exactly_one_new_client')
    seller = sole(rows(connection, 'commercial_sellers'), 'exactly_one_fictitious_seller')
    check(quote['commercial_client_id'] == client['id'] and quote['seller_id'] == seller['id'] and request['seller_id'] == seller['id'], 'client_and_seller_links_match')
    check(seller['name'] == context['seller_name'] and seller['is_active'] == 1
          and request['seller_name'] == context['seller_name'] and request['has_seller'] == 1
          and quote['seller'] == context['seller_name'] and quote['seller_original_name'] == context['seller_name'], 'fictitious_seller_attribution_preserved')
    check(request['seller_confirmed_by'] == context['actor_id'] and request['seller_confirmed_at'] is not None, 'seller_explicitly_confirmed_by_qa_actor')
    for customer in [client, json_column(request, 'customer'), json_column(quote, 'customer')]:
        check(customer['name'] == context['customer_name'] and customer['email'] == context['customer_email'] and customer.get('phone') == '0000000000', 'fictitious_customer_identity_preserved')
    check(isinstance(request['message'], str) and 'QA' in request['message'], 'request_marked_qa')
    selected = sole(json_column(request, 'items'), 'request_has_one_selected_variant')
    check(selected['variant_id'] == context['product']['variant_id'] and selected['sku'] == context['product']['sku'] and selected['quantity'] == 75, 'request_matches_catalog_selection')
    users = rows(connection, 'users')
    check(len(users) == backup['counts_before']['users'] + 1, 'exactly_one_added_qa_actor')
    actor = sole([u for u in users if u['id'] == context['actor_id']], 'qa_actor_exists')
    check(actor['email'] == 'qa-api-admin@example.invalid' and 'ficticio' in actor['name'].lower(), 'qa_actor_is_fictional')
    scale = sole(rows(connection, 'commercial_price_scales'), 'exactly_one_qa_price_scale')
    check(scale['name'] == context['scale_name'] and scale['is_active'] == 1 and scale['increment_basis_points'] == 2500
          and 'QA' in scale['source_reference'], 'scale_is_explicit_fictional_25_percent_increment')
    base = context['product']['cost_minor'] * 75
    check(scale['lower_minor'] <= base and (scale['upper_minor'] is None or scale['upper_minor'] >= base), 'scale_covers_actual_variant_quantity_cost')
    check(not rows(connection, 'outbox_messages'), 'no_outbox_messages')
    mappings = rows(connection, 'quote_public_identifiers')
    check(len(mappings) == 2, 'only_original_and_new_public_mappings')
    mapping = sole([m for m in mappings if m['quote_id'] == quote['id']], 'new_quote_mapping_exists')
    old_lock = sole(rows(original, 'commerce_locks'), 'backup_commerce_lock')
    lock = sole(rows(connection, 'commerce_locks'), 'current_commerce_lock')
    occupied = {m['public_id'] for m in rows(original, 'quote_public_identifiers')}
    expected_public = max(3000, old_lock['public_quote_sequence'] + 1)
    while expected_public in occupied: expected_public += 1
    check(mapping['public_id'] == browser['public_id'] == expected_public and mapping['source_id'] == quote['uuid'] and mapping['origin'] == 'laravel', 'next_available_public_id_without_reassignment')
    check(lock['public_quote_sequence'] == expected_public and lock['quote_sequence'] == old_lock['quote_sequence'] + 1
          and lock['catalog_revision'] == old_lock['catalog_revision'], 'public_cursor_folio_and_catalog_revision_consistent')
    versions = [v for v in rows(connection, 'quote_versions') if v['quote_id'] == quote['id']]
    check([v['version'] for v in versions] == [1, 2] and len(rows(connection, 'quote_versions')) == backup['counts_before']['quote_versions'] + 2, 'exactly_two_new_immutable_versions')
    check(quote['status'] == 'issued' and browser.get('revision_status404') is True and browser.get('revision_http_status') == 404, 'revision_was_unavailable_and_final_quote_issued')
    REPORT['flow'] = {'request_uuid': request['uuid'], 'quote_uuid': quote['uuid'], 'public_id': mapping['public_id'], 'folio': quote['folio'],
                      'one_request_one_client_one_fictitious_seller': True, 'two_versions': True, 'revision_http_status': 404,
                      'outbox_count': 0, 'scale_is_fictional': True, 'scale_increment_basis_points': 2500}
    return quote, request, selected, scale, versions


def verify_states(quote, versions, browser):
    states = {stage: load('state-'+stage+'.json') for stage in STAGES}
    expectations = {'draft': ('draft', 0, None), 'issued-v1': ('issued', 1, browser['public_id']),
                    'revision': ('draft', 1, browser['public_id']), 'issued-v2': ('issued', 2, browser['public_id'])}
    for stage, (status, count, public_id) in expectations.items():
        state = states[stage]
        check(state['quote']['uuid'] == quote['uuid'] and state['quote']['status'] == status and len(state['versions']) == count
              and state['public_id'] == public_id and state['outbox_count'] == 0, 'state_transition_'+stage)
    for version in versions:
        for stage in ['issued-v1', 'revision', 'issued-v2'] if version['version'] == 1 else ['issued-v2']:
            observed = sole([v for v in states[stage]['versions'] if v['version'] == version['version']], 'state_version_present_'+stage)
            check(all(observed[k] == version[k] for k in ['uuid', 'path', 'sha256', 'size'])
                  and observed['snapshot'] == json_column(version, 'snapshot'), 'issued_version_unchanged_in_'+stage)
    check(states['issued-v1']['quote']['folio'] == states['revision']['quote']['folio'] == states['issued-v2']['quote']['folio'] == quote['folio'], 'folio_preserved_during_revision')
    # editVersion(2) captures this state again after saving the new draft amounts.
    check(states['revision']['quote']['totals'] == states['issued-v2']['quote']['totals'], 'saved_revision_matches_second_issue_amounts')
    return states


def normalize(text):
    return unicodedata.normalize('NFKC', text).replace('\xa0', ' ')


def pdf_check(version, snapshot, observed, version_report, context):
    import pdfplumber
    number = version['version']
    downloaded = QA / ('quote-v'+str(number)+'.pdf')
    stored = PRIVATE / version['path']
    expected_path = 'quotes/'+observed['quote_uuid']+'/'+version['uuid']+'.pdf'
    check(version['path'] == expected_path and stored.resolve().is_relative_to(PRIVATE.resolve()), 'version_private_canonical_pdf_path')
    check(sha(downloaded) == sha(stored) == version['sha256'] == observed['pdf_sha256'], 'download_and_stored_pdf_hash_match_v'+str(number))
    check(downloaded.stat().st_size == stored.stat().st_size == version['size'] == observed['pdf_bytes'], 'download_and_stored_pdf_size_match_v'+str(number))
    check(downloaded.read_bytes().startswith(b'%PDF-'), 'download_pdf_signature_v'+str(number))
    with pdfplumber.open(downloaded) as document:
        pages = len(document.pages)
        check(1 <= pages <= 10, 'bounded_pdf_page_count_v'+str(number))
        text = normalize('\n'.join(page.extract_text(layout=False) or '' for page in document.pages))
    check(POPPLER.is_file(), 'bundled_poppler_available')
    render_dir = QA / 'pdf-renders'
    no_links(render_dir); render_dir.mkdir(parents=True, exist_ok=True)
    prefix = render_dir / ('quote-v'+str(number))
    command = [str(POPPLER), '-png', '-r', '120', '-f', '1', '-l', str(pages), str(downloaded), str(prefix)]
    completed = subprocess.run(command, capture_output=True, timeout=60)
    check(completed.returncode == 0, 'private_pdf_render_v'+str(number))
    images = sorted(render_dir.glob(prefix.name+'-*.png'))
    check(len(images) == pages and all(image.stat().st_size > 0 for image in images), 'all_pdf_pages_rendered_v'+str(number))
    version_report['pdf'] = {'bytes': version['size'], 'sha256': version['sha256'], 'pages': pages,
                             'download_matches_persisted_version': True,
                             'rendered_private_files': [image.relative_to(QA).as_posix() for image in images],
                             'raw_text_saved': False, 'visual_inspection': 'pending'}
    check(snapshot['folio'] in text and re.search(r'Versi[oó]n\s+'+str(number)+r'\b', text) is not None, 'pdf_folio_and_version_v'+str(number))
    check(context['customer_name'] in text and context['customer_email'] in text and context['seller_name'] in text, 'pdf_fictional_commercial_parties_v'+str(number))
    line = snapshot['items'][0]
    check(line['product']['sku'] in text and normalize(line['printing']) in text, 'pdf_selected_product_and_printing_v'+str(number))
    money = r'(\$\s*\d[\d,]*\.\d{2})'
    patterns = {'subtotal': r'\bSubtotal\s+'+money, 'tax': r'\bIVA\s+(?:\(?\d+(?:\.\d{1,2})?%\)?\s+)?'+money,
                'discount': r'\bDescuentos\s+[−–-]?\s*'+money, 'shipping': r'\bEnv[ií]o\s+'+money,
                'total': r'\bTotal\s+MXN\s+'+money}
    pdf_totals = {}
    for key, pattern in patterns.items():
        matches = re.findall(pattern, text, flags=re.IGNORECASE)
        check(len(matches) == 1, 'pdf_label_exactly_once_'+key+'_v'+str(number))
        pdf_totals[key] = cents(re.sub(r'[$,\s]', '', matches[0]), 'pdf_'+key)
    version_report['pdf']['totals_minor_by_label'] = pdf_totals
    version_report['pdf']['exact_cent_match'] = pdf_totals == {k: snapshot['totals'][k] for k in TOTAL_KEYS}
    equal_totals(pdf_totals, snapshot['totals'], 'pdf_labels_v'+str(number))
    def formatted(minor): return '$'+format(Decimal(minor)/100, ',.2f')
    for key in ['unit_minor', 'printing_minor', 'discount_minor']:
        check(formatted(line[key]) in text, 'pdf_line_'+key+'_v'+str(number))
    photo = line.get('photo')
    check(isinstance(photo, dict) and photo.get('source') == 'catalog', 'snapshot_catalog_photo_frozen_v'+str(number))
    image_path = PRIVATE / photo['path']
    check(image_path.resolve().is_relative_to((PRIVATE/'quotes'/observed['quote_uuid']).resolve())
          and image_path.is_file() and sha(image_path) == photo['sha256'], 'frozen_photo_hash_v'+str(number))


def verify_version(number, version, state, quote, request, selected, scale, context, observed):
    check(observed['version'] == number and observed['quote_uuid'] == quote['uuid'] and observed['folio'] == quote['folio'], 'browser_version_identity_v'+str(number))
    snapshot = json_column(version, 'snapshot')
    check(snapshot['version'] == number and snapshot['folio'] == quote['folio'] and version['created_by'] == context['actor_id'], 'persisted_version_identity_v'+str(number))
    check(snapshot['customer'] == state['quote']['customer'] and snapshot['client_id'] == quote['commercial_client_id']
          and snapshot['seller_id'] == quote['seller_id'] and snapshot['seller'] == context['seller_name'], 'snapshot_customer_seller_v'+str(number))
    check(snapshot['source_request']['uuid'] == request['uuid'] and snapshot['source_request']['message'] == request['message']
          and snapshot['seller_original_name'] == request['seller_name'], 'snapshot_request_provenance_v'+str(number))
    line = sole(snapshot['items'], 'single_snapshot_line_v'+str(number))
    check(line['product'] == {k: v for k, v in selected.items() if k not in ['variant_id', 'quantity', 'printing']}, 'frozen_request_product_v'+str(number))
    form = observed['form']; quantity = int(form['quantity'])
    check(str(form['quantity']) == str(quantity) and quantity == 75, 'form_quantity_v'+str(number))
    entered = {key: cents(form[field], 'form_'+field+'_v'+str(number)) for key, field in
               [('unit_minor', 'unit_price'), ('printing_minor', 'printing_price'), ('discount_minor', 'discount'), ('shipping', 'shipping'), ('tax_basis_points', 'tax_percent')]}
    cost = integer(context['product']['cost_minor'], 'variant_cost')
    proposal = int((Decimal(cost) * Decimal('1.25')).quantize(Decimal('1'), rounding=ROUND_HALF_UP))
    intended = {'unit_minor': proposal+(17 if number == 2 else 0), 'printing_minor': 203 if number == 1 else 257,
                'discount_minor': 1017 if number == 1 else 1109, 'shipping': 3503 if number == 1 else 4507, 'tax_basis_points': 1600}
    check(entered == intended, 'actual_form_matches_authorized_qa_scenario_v'+str(number))
    check(line['quantity'] == quantity and all(line[key] == entered[key] for key in ['unit_minor', 'printing_minor', 'discount_minor'])
          and line['printing'] == form['printing'] and 'QA' in line['printing'], 'form_and_snapshot_line_v'+str(number))
    check(snapshot['tax_basis_points'] == entered['tax_basis_points'] and state['quote']['shipping_minor'] == entered['shipping'], 'form_and_snapshot_tax_shipping_v'+str(number))
    subtotal = quantity * (entered['unit_minor'] + entered['printing_minor'])
    tax = int((Decimal(subtotal) * Decimal(entered['tax_basis_points']) / 10000).quantize(Decimal('1'), rounding=ROUND_HALF_UP))
    expected = {'subtotal': subtotal, 'tax': tax, 'discount': entered['discount_minor'], 'shipping': entered['shipping'],
                'total': subtotal + tax - entered['discount_minor'] + entered['shipping']}
    equal_totals(snapshot['totals'], expected, 'form_vs_snapshot_v'+str(number))
    check(snapshot['totals']['currency'] == 'MXN' and snapshot['totals']['line_totals'] == [subtotal], 'snapshot_currency_line_totals_v'+str(number))
    equal_totals(state['quote']['totals'], expected, 'saved_quote_vs_snapshot_v'+str(number))
    equal_totals(observed['admin_totals_minor'], expected, 'displayed_admin_vs_snapshot_v'+str(number))
    pricing = line['pricing']; basis = pricing['basis']
    check(pricing['mode'] == ('automatic' if number == 1 else 'manual') and pricing['actor_id'] == context['actor_id'], 'pricing_mode_and_actor_v'+str(number))
    check(basis['algorithm'] == 'legacy_quantity_cost_v1' and basis['cost_minor'] == cost and basis['quantity'] == quantity
          and basis['base_minor'] == cost * quantity and basis['price_minor'] == proposal and basis['scale_uuid'] == scale['uuid']
          and basis['scale_revision'] == scale['revision'] and basis['increment_basis_points'] == 2500
          and basis['cost_source'] == 'current_supplier_variant' and basis['variant_id'] == context['product']['variant_id']
          and basis['sku'] == context['product']['sku'] and basis['rounding'] == 'HALF_UP_2', 'pricing_provenance_actual_cost_scale_v'+str(number))
    if number == 2:
        check(isinstance(pricing.get('reason'), str) and 'QA' in pricing['reason'], 'manual_adjustment_has_qa_reason')
        if 'pricing_reason' in form: check(form['pricing_reason'] == pricing['reason'], 'manual_reason_matches_form')
    api = observed['api_amounts']
    check(api['currency'] == 'MXN', 'api_currency_v'+str(number))
    api_minor = {key: cents(api[key], 'api_'+key+'_v'+str(number)) for key in ['sale_amount', 'shipping', 'tax', 'total']}
    check(api_minor == {'sale_amount': subtotal-expected['discount'], 'shipping': expected['shipping'], 'tax': tax, 'total': expected['total']}, 'api_exact_net_and_separate_amounts_v'+str(number))
    check(all(not isinstance(api[key], (str, bool)) and isinstance(api[key], (int, Decimal)) for key in api_minor), 'api_json_numbers_v'+str(number))
    api_customer = observed['api_customer']
    check(api_customer['id'] == snapshot['client_id'] and all(api_customer.get(key) == snapshot['customer'].get(key)
          for key in ['name', 'company', 'email', 'phone']), 'api_customer_matches_issue_v'+str(number))
    api_line = sole(observed['api_items'], 'single_api_line_v'+str(number))
    check(api_line['id'] is None and api_line['quantity'] == quantity and api_line['printing'] == line['printing']
          and all(api_line.get(key) == line['product'].get(key) for key in ['sku', 'name', 'description', 'color'])
          and api_line['variant_sku'] is None, 'api_line_matches_issue_v'+str(number))
    check(observed['api_comments'] == snapshot['source_request']['message'], 'api_comment_matches_original_request_v'+str(number))
    check(datetime.fromisoformat(observed['api_dates']['elaborated_at']) == datetime.fromisoformat(snapshot['issued_at']), 'api_issue_date_matches_snapshot_v'+str(number))
    result = {'version': number, 'public_id': observed['public_id'], 'folio': snapshot['folio'], 'quantity': quantity,
              'form_minor': entered, 'displayed_admin_totals_minor': observed['admin_totals_minor'], 'snapshot_totals_minor': expected,
              'quote_saved_totals_match': True, 'api_amounts_minor': api_minor,
              'pricing': {'mode': pricing['mode'], 'cost_minor': cost, 'proposed_unit_minor': proposal, 'increment_basis_points': 2500,
                          'manual_delta_minor': 17 if number == 2 else 0, 'reason_present': bool(pricing.get('reason')), 'scale_fictional': True},
              'rounding_rule': 'HALF_UP aggregate tax on subtotal before discount; shipping untaxed; PDF must match exact cents.'}
    REPORT['versions'].append(result)
    pdf_check(version, snapshot, observed, result, context)
    return snapshot


def verify_audit(connection, quote, request, scale, context):
    events = rows(connection, 'admin_audit_events')
    requirements = [('quote_request.received', request['uuid'], 1), ('quote_request.seller_assigned', request['uuid'], 1),
                    ('quote_request.converted', request['uuid'], 1), ('pricing.scale_saved', scale['uuid'], 1),
                    ('quote.issued', quote['uuid'], 2), ('quote.revision_started', quote['uuid'], 1)]
    summary = {}
    for action, entity, count in requirements:
        matching = [e for e in events if e['action'] == action and e['entity_id'] == entity]
        check(len(matching) == count, 'audit_count_'+action)
        if action != 'quote_request.received': check(all(e['actor_id'] == context['actor_id'] for e in matching), 'audit_actor_'+action)
        summary[action] = len(matching)
    for action in ['quote.saved', 'quote.pricing_saved']:
        matching = [e for e in events if e['action'] == action and e['entity_id'] == quote['uuid']]
        check(len(matching) >= 3 and all(e['actor_id'] == context['actor_id'] for e in matching), 'audit_updates_'+action)
        summary[action] = len(matching)
    issued = [e for e in events if e['action'] == 'quote.issued' and e['entity_id'] == quote['uuid']]
    revised = sole([e for e in events if e['action'] == 'quote.revision_started' and e['entity_id'] == quote['uuid']], 'one_revision_audit')
    check([json_column(e, 'after')['version'] for e in issued] == [1, 2] and issued[0]['id'] < revised['id'] < issued[1]['id'], 'audit_issue_revision_issue_order')
    REPORT['audit'] = {'counts': summary, 'actor_is_fictional': True, 'issue_revision_order_valid': True}


def run():
    backup, context, browser = guard()
    with connect(DB) as connection, connect(QA/'before-commercial.sqlite') as original:
        verify_protected(connection, original, backup)
        quote, request, selected, scale, versions = verify_entities(connection, original, backup, context, browser)
        states = verify_states(quote, versions, browser)
        observed = browser['versions']
        check(len(observed) == 2 and [v['version'] for v in observed] == [1, 2]
              and all(v['public_id'] == browser['public_id'] for v in observed), 'two_api_versions_same_public_identifier')
        snapshots = []
        for index, version in enumerate(versions):
            snapshots.append(verify_version(index+1, version, states['issued-v'+str(index+1)], quote, request, selected, scale, context, observed[index]))
        equal_totals(json_column(quote, 'totals'), snapshots[1]['totals'], 'current_quote_equals_second_issue')
        current_line = sole([r for r in rows(connection, 'quote_items') if r['quote_id'] == quote['id']], 'one_final_quote_line')
        check(current_line['product_variant_id'] == context['product']['variant_id']
              and all(current_line[key] == snapshots[1]['items'][0][key] for key in ['quantity', 'unit_minor', 'printing_minor', 'discount_minor', 'printing'])
              and json_column(current_line, 'pricing') == snapshots[1]['items'][0]['pricing'], 'current_line_equals_second_issue')
        check(snapshots[0]['totals'] != snapshots[1]['totals'] and versions[0]['sha256'] != versions[1]['sha256'], 'second_issue_has_updated_amounts_and_distinct_pdf')
        verify_audit(connection, quote, request, scale, context)
    REPORT.update({'ok': True, 'captured_utc': datetime.now(timezone.utc).isoformat(),
                   'scope': {'database_read_only': True, 'database': 'isolated_experimental_sqlite', 'http_requests': 0,
                             'legacy_executed': False, 'economic_engine_changed': False, 'actual_contacts_saved': False},
                   'evidence_sha256': {name: sha(QA/name) for name in REQUIRED if name != 'before-commercial.sqlite'}})


def save_report():
    no_links(QA); no_links(OUT)
    with tempfile.NamedTemporaryFile(mode='w', encoding='utf-8', dir=QA, prefix='.economic-', suffix='.tmp', delete=False) as handle:
        temporary = pathlib.Path(handle.name)
        json.dump(REPORT, handle, ensure_ascii=False, indent=2)
        handle.write('\n')
    temporary.replace(OUT)


def main():
    try:
        run(); save_report()
        print(json.dumps({'ok': True, 'public_id': REPORT['flow']['public_id'], 'versions': len(REPORT['versions']),
                          'catalog_and_original_fixture_unchanged': True, 'pdf_cent_values_exact': True, 'visual_inspection_pending': True}))
        return 0
    except Exception as error:
        # Never print SQL rows, environment, customer details, cookies or arbitrary error messages.
        code = str(error) if isinstance(error, VerificationFailure) else type(error).__name__
        REPORT['failure_code'] = code
        if ROOT == EXPECTED_ROOT and QA.is_dir(): save_report()
        print(json.dumps({'ok': False, 'code': code}))
        return 1


if __name__ == '__main__':
    sys.exit(main())
