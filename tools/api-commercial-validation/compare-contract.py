"""Compare the supplied v1 example with local fixture 3000 without persisting payloads.

Run from the isolated experimental checkout with --reference-attachment PATH.
The original attachment, Bearer and PDF capability remain in memory only. No
legacy PHP, SQL, external URL, redirect or application mutation is executed.
HTTP rate counters and technical logs are expected application-side effects.
"""

import argparse
import hashlib
import json
import pathlib
import re
import secrets
import stat
import sys
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from decimal import Decimal

ROOT = pathlib.Path(__file__).resolve().parents[2]
EXPECTED_ROOT = pathlib.Path('E:/proyectos/usbstock/api-grupotalento')
ORIGIN = 'http://127.0.0.1:8001'
OUT = ROOT / 'storage/app/private/qa/api-commercial-validation/contract-comparison.json'
AUDIT = ROOT.parent / 'xtras/docs/API_GRUPOTALENTO_FASE_1_AUDITORIA.md'
NULLABLE_TEXT = ('string', 'null')
NULLABLE_INTEGER = ('integer', 'null')
SCHEMA = {
    'success': 'boolean',
    'data': {
        'id': NULLABLE_INTEGER, 'folio': NULLABLE_TEXT,
        'source': {'system': 'string', 'environment': 'string'},
        'dates': {'created_at': NULLABLE_TEXT, 'elaborated_at': NULLABLE_TEXT},
        'customer': {'id': NULLABLE_INTEGER, **{k: NULLABLE_TEXT for k in
                    ['name', 'company', 'email', 'phone', 'secondary_phone']}},
        'items': [{'id': NULLABLE_INTEGER, **{k: NULLABLE_TEXT for k in
                   ['sku', 'variant_sku', 'name', 'description', 'color', 'printing']},
                   'quantity': NULLABLE_INTEGER}],
        'unlisted_items': [{'id': NULLABLE_INTEGER, **{k: NULLABLE_TEXT for k in
                            ['name', 'description', 'color']}}],
        'items_html': NULLABLE_TEXT, 'sale_amount_available': 'boolean',
        'sale_amount_unavailable_reason': NULLABLE_TEXT, 'amounts_basis': 'string',
        'amounts_recorded_at': NULLABLE_TEXT,
        'amounts': {'currency': 'string', **{k: ('number', 'null') for k in
                    ['sale_amount', 'shipping', 'tax', 'total']}},
        'archived': 'boolean', 'comments': NULLABLE_TEXT,
        'pdf': {'available': 'boolean', 'url': NULLABLE_TEXT, 'basis': 'string'},
    },
}
SEMANTICS = [
    {'fields': ['data.id'], 'classification': 'intentional_difference',
     'legacy': 'Historical numeric quote PK.',
     'current': 'Immutable public mapping, native cursor starts at 3000 and skips occupied IDs.',
     'evidence': 'Fase 1 section 8; PublicQuoteIdentifiers and migration 000002; no historical identity inferred.'},
    {'fields': ['data.customer.id', 'data.items[].id', 'data.items[].variant_sku'],
     'classification': 'nullable_compatible_identity_not_equivalent',
     'legacy': 'Nullable source identifiers/text.',
     'current': 'Persisted snapshot identifiers only; null when absent; no GT crosswalk inferred.',
     'evidence': 'Fase 1 section 4; CotizacionService::integer/text; QuotePresenter.'},
    {'fields': ['data.items', 'data.dates.elaborated_at', 'data.amounts_recorded_at'],
     'classification': 'intentional_difference',
     'legacy': 'Current rows with latest sent amount capture and independently latest PDF.',
     'current': 'One issued version selected by greatest version ordinal; issued_at supplies issue dates.',
     'evidence': 'Fase 1 sections 2.3 and 5; QuoteReadController and QuotePresenter.'},
    {'fields': ['data.amounts.sale_amount', 'data.amounts.shipping', 'data.amounts.tax', 'data.amounts.total'],
     'classification': 'authorized_net_rule',
     'legacy': 'Persisted numeric amounts; no commission or delivery proof.',
     'current': 'Persisted subtotal minus persisted discount; shipping, tax and total remain separate.',
     'evidence': 'Explicit user confirmation in Fase 2; QuotePresenter; fixture cents checked at runtime.'},
    {'fields': ['data.amounts_basis'], 'classification': 'literal_compatible_semantics_clarified',
     'legacy': 'last_confirmed_quote_send is not proof of email delivery; migration may use initial_quote_reconstruction.',
     'current': 'last_confirmed_quote_send is retained for an issued snapshot; no sale, commission or email receipt inference.',
     'evidence': 'Fase 1 section 5; QuotePresenter; no consumer source inspected.'},
    {'fields': ['data.sale_amount_available', 'data.sale_amount_unavailable_reason', 'data.amounts'],
     'classification': 'intentional_difference',
     'legacy': 'No sent capture can return 200 with unavailable reason and null amounts.',
     'current': 'Issued coherent snapshot required; invalid snapshot or draft returns 404; successful response has amounts.',
     'evidence': 'Fase 1 section 4 state table; QuoteReadController/QuotePresenter; invalid states covered separately by PHPUnit.'},
    {'fields': ['data.items_html'], 'classification': 'nullable_compatible_security_difference',
     'legacy': 'Stored HTML or null, without escaping by the presenter.',
     'current': 'Six-column Blade output with escaped text from persisted line amounts, or null if incomplete.',
     'evidence': 'Fase 1 section 4; QuotePresenter::itemsHtml and api/quote-items.blade.php.'},
    {'fields': ['data.archived', 'data.comments'], 'classification': 'intentional_difference',
     'legacy': 'Archive flag and source commercial comment.',
     'current': 'archived=false because no archive state exists; comment only from frozen source request message.',
     'evidence': 'Fase 1 section 4; QuotePresenter; values of distinct quotes are not required to match.'},
    {'fields': ['data.pdf'], 'classification': 'structure_compatible_security_difference',
     'legacy': 'Predictable public PDF reference independent of amount snapshot; no per-record authorization.',
     'current': 'Opaque revocable capability for the same issued version, downloadable without a second Bearer header.',
     'evidence': 'Fase 1 section 2.4; QuoteDocument; current bytes compared with private fixture manifest.'},
    {'fields': ['HTTP.errors', 'HTTP.headers'], 'classification': 'compatible_core_with_security_additions',
     'legacy': 'Common JSON envelope and 400/401/404/405/500; no rate limiting implemented.',
     'current': 'Core errors retained; 429, private cache and no-referrer added; document errors are controlled JSON.',
     'evidence': 'Fase 1 section 2.2; QuoteApiBoundary and QuoteApiResponse; listed local cases checked at runtime.'},
]


class ValidationFailure(Exception):
    pass


def check(condition, code):
    if not condition:
        raise ValidationFailure(code)


def no_links(path):
    for part in [path, *path.parents]:
        check(not part.is_symlink(), 'linked_path')
        if part.exists():
            attributes = getattr(part.stat(follow_symlinks=False), 'st_file_attributes', 0)
            check(not attributes & getattr(stat, 'FILE_ATTRIBUTE_REPARSE_POINT', 0), 'linked_path')


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def settings(path):
    result = {}
    for line in path.read_text(encoding='utf-8-sig').splitlines():
        match = re.match(r'^([A-Z0-9_]+)=(.*)$', line)
        if not match:
            continue
        name, value = match.groups()
        check(name not in result, 'duplicate_private_setting')
        value = value.strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in '\"\'':
            value = value[1:-1]
        result[name] = value
    return result


def guard():
    check(ROOT == EXPECTED_ROOT, 'unexpected_checkout')
    no_links(ROOT); no_links(OUT); no_links(ROOT / '.env')
    env = settings(ROOT / '.env')
    db = ROOT / 'database/api-grupotalento.sqlite'
    no_links(db)
    check(env.get('APP_ENV') == 'local' and env.get('DB_CONNECTION') == 'sqlite', 'unexpected_environment')
    check(pathlib.Path(env.get('DB_DATABASE', '')).resolve() == db and db.is_file(), 'unexpected_database')
    check(not env.get('DB_URL') and not (ROOT / 'bootstrap/cache/config.php').exists(), 'configuration_override')
    check(env.get('USBSTOCK_API_ENABLED') == 'true' and env.get('USBSTOCK_API_BASE_URL') == ORIGIN, 'unexpected_api_configuration')
    token = env.get('USBSTOCK_API_BEARER_TOKEN', '')
    check(re.fullmatch(r'[A-Za-z0-9._~+/=\-]{32,512}', token) is not None, 'invalid_private_token')
    fixture_path = ROOT / 'storage/logs/api-local-fixture.json'
    no_links(fixture_path)
    fixture = json.loads(fixture_path.read_text(encoding='utf-8-sig'))
    check(fixture.get('fixture_only') is True and fixture.get('public_id') == 3000, 'unexpected_fixture')
    check(AUDIT.is_file(), 'audit_missing')
    audit_text = AUDIT.read_text(encoding='utf-8-sig')
    for anchor in ['nullable significa valor nulo posible', 'items[].id/quantity', 'JSON number o null', 'last_confirmed_quote_send']:
        check(anchor in audit_text, 'audit_reference_changed')
    return token, fixture


def pairs(values):
    result = {}
    for key, value in values:
        check(key not in result, 'duplicate_json_key')
        result[key] = value
    return result


def reject_constant(_):
    raise ValidationFailure('nonfinite_json_number')


def decoder():
    return json.JSONDecoder(parse_float=Decimal, parse_constant=reject_constant, object_pairs_hook=pairs)


def original_example(path):
    source = path.read_text(encoding='utf-8-sig')
    found = []
    parser = decoder()
    for index, char in enumerate(source):
        if char != '{':
            continue
        try:
            candidate, _ = parser.raw_decode(source[index:])
        except (json.JSONDecodeError, ValidationFailure):
            continue
        if (isinstance(candidate, dict) and candidate.get('success') is True
                and isinstance(candidate.get('data'), dict) and candidate['data'].get('id') == 2565):
            found.append(candidate)
    check(len(found) == 1, 'reference_example_not_unique')
    return found[0]


def kind(value):
    if value is None: return 'null'
    if isinstance(value, bool): return 'boolean'
    if isinstance(value, int): return 'integer'
    if isinstance(value, Decimal): return 'number'
    if isinstance(value, str): return 'string'
    if isinstance(value, list): return 'array'
    if isinstance(value, dict): return 'object'
    raise ValidationFailure('unexpected_json_type')


def observations(value, path='$', result=None):
    result = {} if result is None else result
    result.setdefault(path, set()).add(kind(value))
    if isinstance(value, dict):
        for key, child in value.items(): observations(child, path+'.'+key, result)
    if isinstance(value, list):
        for child in value: observations(child, path+'[]', result)
    return result


def validate_shape(value, schema, path='$'):
    if isinstance(schema, dict):
        check(isinstance(value, dict), 'object_type_at_'+path)
        check(set(value) == set(schema), 'keys_at_'+path)
        for key, child in schema.items(): validate_shape(value[key], child, path+'.'+key)
    elif isinstance(schema, list):
        check(isinstance(value, list), 'array_type_at_'+path)
        for child in value: validate_shape(child, schema[0], path+'[]')
    else:
        allowed = (schema,) if isinstance(schema, str) else schema
        actual = kind(value)
        check(actual in allowed or (actual == 'integer' and 'number' in allowed), 'type_at_'+path)


def schema_fields(schema, path='$'):
    if isinstance(schema, dict):
        result = {path: ['object']}
        for key, child in schema.items(): result.update(schema_fields(child, path+'.'+key))
        return result
    if isinstance(schema, list):
        return {path: ['array'], **schema_fields(schema[0], path+'[]')}
    return {path: [schema] if isinstance(schema, str) else list(schema)}


class NoRedirects(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *_):
        raise ValidationFailure('redirect_refused')


def fetch(url, token=None, method='GET', limit=2 * 1024 * 1024):
    parsed = urllib.parse.urlsplit(url)
    check(parsed.scheme == 'http' and parsed.netloc == '127.0.0.1:8001'
          and not parsed.query and not parsed.fragment and parsed.path.startswith('/api/v1/'), 'nonlocal_url_refused')
    headers = {'Accept': 'application/json', 'User-Agent': 'USBStock-local-contract-validation'}
    if token is not None: headers['Authorization'] = 'Bearer '+token
    request = urllib.request.Request(url, headers=headers, method=method)
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirects())
    try:
        response = opener.open(request, timeout=30)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        body = response.read(limit + 1)
        check(len(body) <= limit, 'response_size_limit')
        return response.status, {k.lower(): v for k, v in response.headers.items()}, body


def json_body(body):
    return decoder().decode(body.decode('utf-8', errors='strict'))


def security_headers(headers, json_response=True):
    checks = {
        'no_store': 'no-store' in headers.get('cache-control', '').lower(),
        'private_cache': 'private' in headers.get('cache-control', '').lower(),
        'nosniff': headers.get('x-content-type-options') == 'nosniff',
        'no_referrer': headers.get('referrer-policy') == 'no-referrer',
        'request_id_32_hex': re.fullmatch('[a-f0-9]{32}', headers.get('x-request-id', '')) is not None,
    }
    if json_response:
        content_type = headers.get('content-type', '').lower().replace(' ', '')
        checks['json_utf8'] = content_type == 'application/json;charset=utf-8'
    check(all(checks.values()), 'security_headers_mismatch')
    return checks


def iso_date(value):
    if value is None: return True
    if not re.fullmatch(r'\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}', value): return False
    try: return datetime.fromisoformat(value).utcoffset() is not None
    except ValueError: return False


def run(reference):
    token, fixture = guard()
    original = original_example(reference)
    validate_shape(original, SCHEMA)
    status, headers, body = fetch(ORIGIN+'/api/v1/cotizaciones/3000', token)
    check(status == 200, 'fixture_http_not_200')
    actual = json_body(body)
    validate_shape(actual, SCHEMA)
    data = actual['data']
    check(actual['success'] is True and data['id'] == 3000, 'wrong_quote_response')
    check(data['source'] == {'system': 'USB Stock', 'environment': 'local'}, 'unexpected_source')
    header_checks = security_headers(headers)
    first_id = headers['x-request-id']; request_ids = [first_id]
    before = observations(original); after = observations(actual)
    fields = []
    for path, allowed in schema_fields(SCHEMA).items():
        old, new = sorted(before.get(path, [])), sorted(after.get(path, []))
        fields.append({'path': path, 'reference_observed_types': old, 'current_observed_types': new,
                       'allowed_types': allowed, 'required_when_parent_object_exists': True,
                       'same_observed_types': old == new, 'schema_compatible': True,
                       'evidence': 'supplied_example_and_static_audit' if old else 'static_audit_only_empty_reference_array',
                       'current_members_observed': bool(new)})
    date_checks = {path: iso_date(value) for path, value in {
        'data.dates.created_at': data['dates']['created_at'],
        'data.dates.elaborated_at': data['dates']['elaborated_at'],
        'data.amounts_recorded_at': data['amounts_recorded_at'],
    }.items()}
    check(all(date_checks.values()), 'invalid_iso_dates')
    check(data['sale_amount_available'] is True and data['sale_amount_unavailable_reason'] is None,
          'issued_amounts_unavailable')
    check(data['amounts_basis'] == 'last_confirmed_quote_send'
          and data['amounts']['currency'] == 'MXN', 'amount_literals_changed')
    expected = fixture['totals_minor']
    amounts = {}
    for name in ['sale_amount', 'shipping', 'tax', 'total']:
        value = data['amounts'][name]
        check(kind(value) in ['integer', 'number'], 'amount_not_numeric')
        cents = Decimal(value) * 100
        check(cents == cents.to_integral_value(), 'amount_precision_exceeds_cents')
        wanted = expected['subtotal'] - expected['discount'] if name == 'sale_amount' else expected[name]
        check(int(cents) == wanted, 'fixture_amount_mismatch')
        amounts[name] = {'json_numeric': True, 'minor_units': int(cents), 'matches_fixture': True}
    errors = []
    invalid_token = secrets.token_urlsafe(40)
    check(invalid_token != token, 'test_token_collision')
    cases = [
        ('missing_bearer', '/cotizaciones/3000', None, 'GET', 401, 'UNAUTHORIZED', 'Autenticación requerida o inválida'),
        ('invalid_bearer', '/cotizaciones/3000', invalid_token, 'GET', 401, 'UNAUTHORIZED', 'Autenticación requerida o inválida'),
        ('unavailable_identifier', '/cotizaciones/9999999999', token, 'GET', 404, 'QUOTE_NOT_FOUND', 'Cotización no encontrada'),
        ('invalid_identifier', '/cotizaciones/0', token, 'GET', 400, 'INVALID_ID', 'ID de cotización inválido'),
        ('unknown_route', '/contract-validation-unknown', token, 'GET', 404, 'ROUTE_NOT_FOUND', 'Ruta no encontrada'),
        ('unsupported_options', '/cotizaciones/3000', token, 'OPTIONS', 405, 'METHOD_NOT_ALLOWED', 'Sólo se permite el método GET'),
        ('invalid_pdf_capability', '/documentos/invalid', None, 'GET', 404, 'QUOTE_NOT_FOUND', 'Cotización no encontrada'),
    ]
    for name, route, credential, method, wanted_status, code, message in cases:
        response_status, response_headers, response_body = fetch(ORIGIN+'/api/v1'+route, credential, method)
        check(response_status == wanted_status, 'error_status_'+name)
        check(json_body(response_body) == {'success': False, 'error': {'code': code, 'message': message}}, 'error_envelope_'+name)
        checked_headers = security_headers(response_headers)
        if wanted_status == 401:
            check(response_headers.get('www-authenticate') == 'Bearer realm="Publimarca API"', 'bearer_challenge_changed')
        if wanted_status == 405: check(response_headers.get('allow') == 'GET', 'allow_header_changed')
        request_ids.append(response_headers['x-request-id'])
        errors.append({'case': name, 'http_status': response_status, 'code': code,
                       'exact_envelope_and_message': True, 'headers': checked_headers,
                       'legacy_reference': 'Fase 1 section 2.2' if name != 'invalid_pdf_capability' else 'security improvement over legacy PDF server'})
    pdf = data['pdf']
    check(pdf['basis'] == 'latest_generated_quote_pdf' and pdf['available'] is True, 'fixture_pdf_unavailable')
    check(isinstance(pdf['url'], str) and re.fullmatch(re.escape(ORIGIN)+r'/api/v1/documentos/[A-Za-z0-9_-]+', pdf['url']), 'unexpected_pdf_url')
    pdf_status, pdf_headers, pdf_body = fetch(pdf['url'], limit=64 * 1024 * 1024)
    check(pdf_status == 200 and pdf_headers.get('content-type', '').lower() == 'application/pdf', 'pdf_http_or_mime')
    check(pdf_body.startswith(b'%PDF-') and len(pdf_body) == fixture['pdf_bytes'], 'pdf_signature_or_size')
    pdf_hash = hashlib.sha256(pdf_body).hexdigest()
    check(pdf_hash == fixture['pdf_sha256'], 'pdf_hash_mismatch')
    check(pdf_headers.get('content-length') == str(len(pdf_body)), 'pdf_content_length')
    check(pdf_headers.get('content-disposition', '').startswith('attachment;'), 'pdf_disposition')
    pdf_headers_checked = security_headers(pdf_headers, False)
    request_ids.append(pdf_headers['x-request-id'])
    head_status, head_headers, head_body = fetch(pdf['url'], method='HEAD')
    check(head_status == 200 and head_body == b'' and head_headers.get('content-length') == str(len(pdf_body)), 'pdf_head')
    security_headers(head_headers, False); request_ids.append(head_headers['x-request-id'])
    check(len(set(request_ids)) == len(request_ids), 'request_ids_reused')
    return {
        'ok': True, 'captured_utc': datetime.now(timezone.utc).isoformat(),
        'scope': {'local_api_only': True, 'database_opened_by_tool': False, 'legacy_executed': False,
                  'application_mutations_requested': False, 'expected_technical_effects': ['rate counters', 'technical audit logs'],
                  'original_personal_values_persisted': False, 'tokens_or_capability_urls_persisted': False},
        'reference': {'kind': 'exact_user_supplied_json_example', 'reference_quote_id': 2565,
                      'attachment_sha256': digest(reference), 'audit_sha256': digest(AUDIT),
                      'audit_sections': ['2.1', '2.2', '2.3', '2.4', '4', '5'],
                      'nullable_rules_source': 'static legacy code documented in Fase 1; not inferred from one example'},
        'current': {'quote_id': 3000, 'http_status': status, 'json_utf8': True},
        'structure': {'names_and_observed_hierarchy_equal': set(before) == set(after), 'all_required_keys_present': True,
                      'hierarchy_compatible_with_audited_array_members': True,
                      'all_types_fit_audited_contract': True, 'same_observed_types_as_one_example': all(f['same_observed_types'] for f in fields),
                      'field_count_including_containers_and_audit_only_members': len(fields),
                      'nullable_type_variations': [f for f in fields if not f['same_observed_types']],
                      'arrays': [{'path': '$.data.'+name, 'reference_length': len(original['data'][name]),
                                  'current_length': len(data[name]), 'equal_lengths_required': False,
                                  'empty_members_not_runtime_validated': len(data[name]) == 0}
                                 for name in ['items', 'unlisted_items']], 'fields': fields},
        'date_checks': date_checks, 'amounts_synthetic_fixture_only': amounts,
        'security_headers': header_checks, 'unique_request_ids': True, 'errors': errors,
        'server_observations': {'powered_by_header_present': 'x-powered-by' in headers,
                                'scope': 'Local PHP server header; not part of legacy mandatory contract.'},
        'pdf': {'http_status': pdf_status, 'head_http_status': head_status, 'head_empty': True,
                'download_without_bearer': True, 'signature_valid': True, 'mime_valid': True,
                'bytes': len(pdf_body), 'sha256': pdf_hash, 'matches_preexisting_fixture_version': True,
                'private_path_not_exposed': True, 'capability_url_recorded': False, 'headers': pdf_headers_checked},
        'semantics': SEMANTICS,
        'limits': ['A single response does not demonstrate every nullable or nonempty unlisted-items branch.',
                   '429, internal 500, unavailable snapshot, draft/reissue and revocation are covered separately, not induced here.',
                   'Legacy Host restrictions and PDF security defects are intentionally not reproduced.',
                   'No original monetary values or customer data were compared for equality across different quotes.',
                   'No GT consumer code or external consumer behavior was inspected; consumer confirmation remains pending.'],
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--reference-attachment', type=pathlib.Path, required=True)
    args = parser.parse_args()
    try:
        report = run(args.reference_attachment)
        no_links(OUT)
        OUT.parent.mkdir(parents=True, exist_ok=True)
        temporary = OUT.with_suffix('.tmp')
        no_links(temporary)
        with temporary.open('x', encoding='utf-8') as handle:
            json.dump(report, handle, ensure_ascii=False, indent=2)
            handle.write('\n')
        temporary.replace(OUT)
        print(json.dumps({'ok': True, 'quote_id': 3000,
                          'field_count': report['structure']['field_count_including_containers_and_audit_only_members'],
                          'nullable_type_variations': len(report['structure']['nullable_type_variations']),
                          'error_cases': len(report['errors']), 'pdf_verified': True}))
        return 0
    except Exception as error:
        # Never print request/response objects, exception messages, URLs, headers or original values.
        code = str(error) if isinstance(error, ValidationFailure) else type(error).__name__
        print(json.dumps({'ok': False, 'code': code}))
        return 1


if __name__ == '__main__':
    sys.exit(main())
