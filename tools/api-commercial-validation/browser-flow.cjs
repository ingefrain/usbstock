// Authorized local commercial acceptance flow. All state and artifacts stay private.
delete process.env.DEBUG;
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const cp = require('node:child_process');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/ing_e/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const root = path.resolve(__dirname, '../..');
const origin = 'http://127.0.0.1:8001';
const qa = path.join(root, 'storage/app/private/qa/api-commercial-validation');
const file = name => path.join(qa, name);
const read = name => JSON.parse(fs.readFileSync(file(name), 'utf8'));
const save = (name, value) => fs.writeFileSync(file(name), JSON.stringify(value, null, 2));
const uuid = /^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i;
const ctx = read('context.json');
const backup = read('backup.json');
const resume = process.argv.includes('--resume');
let journal, report, stage = 'preflight', browser, publicContext, adminContext, publicPage, adminPage, bearer;
const check = (ok, code) => { if (!ok) { const e = new Error('Local QA assertion failed.'); e.qaCode = code; throw e; } };
const same = (a, b, code) => check(JSON.stringify(a) === JSON.stringify(b), code);
const digest = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const decimal = minor => `${Math.floor(minor / 100)}.${String(minor % 100).padStart(2, '0')}`;
function minor(value) {
    const s = String(value).replace(/[^0-9.-]/g, '');
    check(/^\d+\.\d{2}$/.test(s), 'money_format_invalid');
    const [whole, cents] = s.split('.'); return Number(whole) * 100 + Number(cents);
}
function setting(source, key) {
    const values = source.split(/\r?\n/).filter(line => line.startsWith(`${key}=`));
    check(values.length === 1, 'private_setting_missing_or_duplicate');
    return values[0].slice(key.length + 1).trim().replace(/^(["'])(.*)\1$/, '$2');
}
function persist() { save('flow-journal.json', journal); save('browser-flow.json', report); }
function helper(mode, quote, label) {
    const r = cp.spawnSync('php', ['tools/api-commercial-validation/local.php', mode, ...(quote ? [quote] : []), ...(label ? [label] : [])], {cwd: root, encoding: 'utf8', windowsHide: true, timeout: 30000});
    check(r.status === 0, `guarded_helper_${mode}_failed`);
    if (mode === 'state') return read(`state-${label}.json`);
}
async function shot(page, name) {
    try { await page.screenshot({path: file(`${name}.png`), fullPage: true, animations: 'disabled', timeout: 20000}); report.screenshots.push(`${name}.png`); }
    catch { report.screenshot_failures.push(name); }
    persist();
}
async function navigate(page, pathname) {
    const url = new URL(pathname, origin); check(url.origin === origin, 'external_navigation_forbidden');
    const response = await page.goto(url.href, {waitUntil: 'domcontentloaded', timeout: 30000});
    check(response?.status() === 200, 'local_page_not_200');
    if (url.pathname.startsWith('/admin/') && url.pathname !== '/admin/login') check(new URL(page.url()).pathname !== '/admin/login', 'fictional_admin_session_expired');
}
async function checkpoint(name, action) {
    if (journal.completed.includes(name)) return;
    stage = name; report.current_stage = name; persist(); console.log(`START ${name}`);
    await action();
    journal.completed.push(name); report.stages.push({name, passed: true}); persist();
    if (publicContext) await publicContext.storageState({path: file('public-browser-state.json')});
    console.log(`PASS ${name}`);
}
async function submit(page, form, label, verify, buttonName) {
    check(!journal.pending, 'ambiguous_mutation_requires_manual_review');
    journal.pending = {label, quote_uuid: journal.quote_uuid || null, request_uuid: journal.request_uuid || null}; persist();
    const button = buttonName ? form.getByRole('button', {name: buttonName, exact: true}) : form.locator('button[type=submit],button:not([type])').last();
    const [response] = await Promise.all([page.waitForNavigation({waitUntil: 'domcontentloaded', timeout: 45000}), button.click()]);
    check(response?.status() === 200, 'mutation_did_not_return_success_page');
    await verify(); journal.pending = null; persist();
}
async function network(context) {
    await context.route('**/*', async route => {
        const req = route.request(), u = new URL(req.url());
        if (u.origin === origin) {
            if (!['GET', 'HEAD'].includes(req.method())) {
                const allowed = req.method() === 'POST' && (['/seleccion','/solicitar-cotizacion','/admin/commercial-sellers','/admin/price-scales','/admin/logout'].includes(u.pathname)
                    || (journal.request_uuid && [`/admin/quote-requests/${journal.request_uuid}/seller`,`/admin/quote-requests/${journal.request_uuid}/convert`].includes(u.pathname))
                    || (journal.quote_uuid && [`/admin/quotes/${journal.quote_uuid}`,`/admin/quotes/${journal.quote_uuid}/issue`,`/admin/quotes/${journal.quote_uuid}/revise`].includes(u.pathname)));
                if (!allowed) { report.network.blocked_mutations++; return route.abort(); }
            }
            return route.continue();
        }
        if (req.method() === 'GET' && req.resourceType() === 'image' && u.origin === 'https://grupotalento.com.mx' && !u.search && !u.hash && /\.(png|jpg|jpeg|webp)$/i.test(u.pathname)) return route.continue();
        report.network.blocked_external++; return route.abort();
    });
}
async function getForm(page) {
    const values = await page.locator('[data-quote-editor]').evaluate(form => Object.fromEntries(new FormData(form)));
    // Disabled fieldsets do not enter FormData; inputs still show the issued values.
    const input = async name => page.locator(`[data-quote-editor] [name="${name}"]`).inputValue();
    const result = {};
    for (const field of ['quantity','unit_price','printing_price','discount','printing']) result[field] = values[`items[0][${field}]`] ?? await input(`items[0][${field}]`);
    for (const field of ['shipping','tax_percent']) result[field] = values[field] ?? await input(field);
    result.pricing_reason = values['items[0][pricing_reason]'] ?? '';
    return result;
}
async function totals(page) {
    const rows = await page.locator('.quote-summary tr').evaluateAll(rows => rows.map(row => [row.querySelector('th').textContent.trim(),row.querySelector('td').textContent.trim()]));
    const labels = {'Subtotal':'subtotal','IVA':'tax','Descuentos':'discount','Envío':'shipping','Total MXN':'total'};
    check(rows.length === 5, 'admin_totals_missing');
    return Object.fromEntries(rows.map(([label,value]) => {check(labels[label], 'unexpected_total_label');return [labels[label],minor(value)];}));
}
async function apiCheck(version, evidenceLabel) {
    const state = helper('state', journal.quote_uuid, evidenceLabel);
    check(state.quote.status === 'issued' && state.versions.length === version, 'emission_state_invalid');
    const record = state.versions.find(v => v.version === version); check(record, 'issued_version_missing');
    const publicId = state.public_id; check(Number.isInteger(publicId) && publicId >= 3001, 'next_identifier_invalid');
    if (journal.public_id) check(publicId === journal.public_id, 'public_id_changed');
    journal.public_id = publicId; report.public_id = publicId; persist();
    const response = await publicContext.request.get(`${origin}/api/v1/cotizaciones/${publicId}`, {headers:{Authorization:`Bearer ${bearer}`,Accept:'application/json'},maxRedirects:0});
    check(response.status() === 200, 'quote_api_not_200');
    const headers = response.headers();
    check(headers['content-type'] === 'application/json; charset=utf-8' && headers['cache-control'].includes('no-store') && headers['x-content-type-options'] === 'nosniff' && headers['referrer-policy'] === 'no-referrer', 'api_security_headers_missing');
    const body = await response.json(); check(body.success === true, 'api_success_missing'); const data = body.data;
    check(data.id === publicId && data.folio === record.snapshot.folio, 'api_identity_mismatch');
    check(data.customer.name === ctx.customer_name && data.customer.email === ctx.customer_email, 'api_customer_mismatch');
    check(data.items.length === 1 && data.items[0].sku === ctx.product.sku && data.items[0].quantity === 75, 'api_item_mismatch');
    const t = record.snapshot.totals;
    for (const [field,value] of Object.entries({sale_amount:t.subtotal-t.discount,shipping:t.shipping,tax:t.tax,total:t.total})) check(Math.round(data.amounts[field]*100) === value, `api_${field}_mismatch`);
    check(data.amounts.currency === 'MXN' && data.sale_amount_available === true, 'api_currency_or_availability_invalid');
    const shown = await totals(adminPage); for (const k of Object.keys(shown)) check(shown[k] === t[k], `admin_${k}_mismatch`);
    check(data.pdf.available === true && typeof data.pdf.url === 'string', 'pdf_unavailable');
    const pdfUrl = new URL(data.pdf.url); check(pdfUrl.origin === origin && /^\/api\/v1\/documentos\/[A-Za-z0-9_-]+$/.test(pdfUrl.pathname) && !pdfUrl.search && !pdfUrl.hash, 'pdf_capability_scope_invalid');
    const pdf = await publicContext.request.get(pdfUrl.href, {maxRedirects:0});
    check(pdf.status() === 200 && pdf.headers()['content-type'].startsWith('application/pdf'), 'pdf_download_failed');
    const bytes = await pdf.body(); check(bytes.subarray(0,5).toString() === '%PDF-' && bytes.length === record.size && digest(bytes) === record.sha256, 'pdf_bytes_integrity_mismatch');
    fs.writeFileSync(file(`quote-v${version}.pdf`), bytes);
    // Capability retained only in the private journal for the old-version integrity check.
    journal[`capability_v${version}`] = pdfUrl.href;
    report.versions = report.versions.filter(v=>v.version !== version);
    report.versions.push({version,public_id:publicId,quote_uuid:journal.quote_uuid,folio:record.snapshot.folio,admin_totals_minor:shown,form:report[`saved_form_v${version}`],api_amounts:data.amounts,api_customer:data.customer,api_items:data.items,api_comments:data.comments,pdf_bytes:bytes.length,pdf_sha256:digest(bytes),api_dates:data.dates});
    report.quote_uuid = journal.quote_uuid; persist();
}
async function editVersion(version) {
    await navigate(adminPage, `/admin/quotes/${journal.quote_uuid}`);
    const editor = adminPage.locator('[data-quote-editor]');
    check(await editor.locator('[name="customer_name"]').inputValue() === ctx.customer_name, 'converted_customer_mismatch');
    check(await editor.locator('[name="items[0][variant_id]"]').inputValue() === String(ctx.product.variant_id), 'converted_variant_mismatch');
    check(await editor.locator('[name="items[0][quantity]"]').inputValue() === '75', 'converted_quantity_mismatch');
    if (version === 1) {
        const proposal = JSON.parse(await adminPage.locator('[data-pricing-panel]').getAttribute('data-initial-proposal'));
        check(proposal.available === true && proposal.price_minor === Math.floor((ctx.product.cost_minor*12500+5000)/10000), 'fictional_scale_price_mismatch');
        check(proposal.basis.scale_name === ctx.scale_name && proposal.basis.increment_basis_points === 2500, 'fictional_scale_basis_mismatch');
        journal.proposal_minor = proposal.price_minor; report.fictional_proposal_verified = true; persist();
        await adminPage.locator('[data-pricing-use]').click();
        check(await editor.locator('[data-pricing-action]').inputValue() === 'automatic', 'proposal_not_explicitly_accepted');
    } else {
        await editor.locator('[name="items[0][unit_price]"]').fill(decimal(journal.proposal_minor+17));
        await editor.locator('[name="items[0][pricing_reason]"]').fill('QA local: ajuste ficticio autorizado para comprobar revisión API.');
    }
    await editor.locator('[name="items[0][printing_price]"]').fill(version===1?'2.03':'2.57');
    await editor.locator('[name="items[0][discount]"]').fill(version===1?'10.17':'11.09');
    await editor.locator('[name="items[0][printing]"]').fill('QA API impresión local');
    await editor.locator('[name="shipping"]').fill(version===1?'35.03':'45.07');
    await editor.locator('[name="tax_percent"]').fill('16');
    await editor.locator('[name="terms"]').fill('QA API: cotización ficticia local, sin venta ni envío comercial.');
    report[`saved_form_v${version}`] = await getForm(adminPage); persist();
    await submit(adminPage, editor, `save-v${version}`, async()=>{
        check(await adminPage.locator('[name="items[0][unit_price]"]').inputValue() === decimal(journal.proposal_minor+(version===1?0:17)), 'saved_price_mismatch');
        const state=helper('state',journal.quote_uuid,version===1?'draft':'revision');
        check(state.quote.status === 'draft', 'saved_quote_not_draft');
        const line=state.quote.items[0]; check(line.pricing.mode === (version===1?'automatic':'manual'), 'pricing_provenance_mismatch');
        check(line.printing === 'QA API impresión local' && line.quantity === 75, 'saved_printing_mismatch');
        const shown=await totals(adminPage); for(const key of Object.keys(shown))check(shown[key]===state.quote.totals[key],`saved_total_${key}_mismatch`);
    }, 'Guardar y calcular');
    await shot(adminPage, `draft-v${version}`);
}
async function issue(version) {
    await navigate(adminPage, `/admin/quotes/${journal.quote_uuid}`);
    const form=adminPage.locator(`form[action="${origin}/admin/quotes/${journal.quote_uuid}/issue"]`);
    journal[`issue_request_key_v${version}`]=await form.locator('[name=request_key]').inputValue();persist();
    await form.locator('[name=confirm]').check();
    await submit(adminPage,form,`issue-v${version}`,async()=>{
        check(await adminPage.getByRole('link',{name:`Descargar versión ${version}`,exact:true}).count()===1,'issued_version_link_missing');
        const state=helper('state',journal.quote_uuid,`issued-v${version}`);
        check(state.versions.length===version,'unexpected_version_count');
    },'Emitir cotización PDF');
    await shot(adminPage,`issued-v${version}`);
}
(async()=>{
    try {
        check(root.replaceAll('\\','/')==='E:/proyectos/usbstock/api-grupotalento','exact_worktree_required');
        check(backup.backup_verified && digest(fs.readFileSync(file('before-commercial.sqlite')))===backup.backup_sha256,'verified_backup_required');
        check(ctx.database==='api-grupotalento.sqlite' && ctx.external_writes_disabled && ctx.scale_is_fictional && uuid.test(ctx.run_uuid),'isolated_context_required');
        check(!fs.existsSync(path.join(root,'bootstrap/cache/config.php')),'cached_config_forbidden');
        const env=fs.readFileSync(path.join(root,'.env'),'utf8');
        check(setting(env,'APP_ENV')==='local' && setting(env,'DB_CONNECTION')==='sqlite' && setting(env,'DB_DATABASE').replaceAll('\\','/')===`${root.replaceAll('\\','/')}/database/api-grupotalento.sqlite`,'private_database_guard_failed');
        check(setting(env,'USBSTOCK_API_ENABLED')==='true' && setting(env,'MAIL_MAILER')==='array' && setting(env,'COMMERCE_NOTIFY_EMAIL')==='','api_or_mail_guard_failed');
        for(const key of ['SUPPLIER_FTP_ENABLED','SUPPLIER_SYNC_ENABLED','SUPPLIER_SYNC_AUTO_APPLY','SUPPLIER_TELEGRAM_ENABLED','TELEGRAM_ENABLED','CORPORATE_CONTACTS_RECORD_CLICKS'])check(setting(env,key)==='false','external_write_guard_failed');
        bearer=setting(env,'USBSTOCK_API_BEARER_TOKEN');check(/^[A-Za-z0-9._~+/=\-]{32,512}$/.test(bearer),'private_token_invalid');
        if(fs.existsSync(file('flow-journal.json'))){check(resume,'existing_journal_requires_resume');journal=read('flow-journal.json');report=read('browser-flow.json');check(journal.run_uuid===ctx.run_uuid && !journal.pending,'ambiguous_mutation_requires_manual_review');if(report.ok){console.log('PASS flow already completed; evidence preserved');return;}}
        else {check(!resume,'resume_requires_journal');journal={run_uuid:ctx.run_uuid,completed:[],pending:null};report={ok:false,stages:[],versions:[],screenshots:[],screenshot_failures:[],network:{blocked_external:0,blocked_mutations:0}};persist();}
        browser=await chromium.launch({executablePath:process.env.EDGE_BINARY||'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless:true});
        publicContext=await browser.newContext({viewport:{width:1440,height:1000},serviceWorkers:'block',...(resume&&fs.existsSync(file('public-browser-state.json'))?{storageState:file('public-browser-state.json')}:{})});
        adminContext=await browser.newContext({viewport:{width:1440,height:1000},serviceWorkers:'block',storageState:file('browser-access.json')});
        for(const context of [publicContext,adminContext]){context.setDefaultTimeout(15000);await network(context);}
        publicPage=await publicContext.newPage();adminPage=await adminContext.newPage();
        await checkpoint('catalog-cart',async()=>{
            await navigate(publicPage,'/catalogo');check(await publicPage.locator('.product-card').count()>0,'catalog_empty');
            await publicPage.locator('#search').fill('USB017');await publicPage.getByRole('button',{name:'Buscar productos'}).click();
            const productLink=publicPage.locator(`.product-card h3 a[href="${origin}/producto/${ctx.product.slug}"]`);await productLink.waitFor({state:'visible'});await productLink.click();
            await publicPage.waitForURL(`${origin}/producto/${ctx.product.slug}`);
            const img=publicPage.locator('.detail-image img');await img.evaluate(img=>img.decode());check(await img.evaluate(img=>img.naturalWidth>0),'product_image_missing');
            const form=publicPage.locator('[data-product-selection]');await form.locator(`[name=variant][value="${ctx.product.variant_uuid}"]`).check();
            await form.locator('[name=quantity]').fill('49');await form.getByRole('button').click();check(await form.locator('[name=quantity]').evaluate(el=>el.validity.rangeUnderflow),'minimum_49_not_rejected');
            await form.locator('[name=quantity]').fill('50');await form.locator('[name=printing]').fill('QA API impresión local');
            const [added]=await Promise.all([publicPage.waitForResponse(r=>new URL(r.url()).pathname==='/seleccion'&&r.request().method()==='POST'),form.getByRole('button').click()]);check(added.status()===200 && (added.headers()['content-type']||'').includes('application/json'),'ajax_add_failed');
            await navigate(publicPage,'/solicitar-cotizacion');const update=publicPage.locator('[data-cart-action=update]');check(await update.locator('[name=quantity]').inputValue()==='50','cart_50_missing');
            await update.locator('[name=quantity]').fill('75');const [changed]=await Promise.all([publicPage.waitForResponse(r=>new URL(r.url()).pathname==='/seleccion'&&r.request().method()==='POST'),update.getByRole('button',{name:'Actualizar'}).click()]);check(changed.status()===200,'ajax_update_failed');
            await publicPage.waitForFunction(()=>document.querySelector('[data-cart-action=update] [name=quantity]')?.value==='75');
            report.catalog={minimum_49_rejected:true,ajax_add_50:true,ajax_update_75:true,photo_loaded:true,selected_variant_uuid:ctx.product.variant_uuid};await shot(publicPage,'cart-75');
        });
        await checkpoint('public-request',async()=>{
            await navigate(publicPage,'/solicitar-cotizacion');const form=publicPage.locator('form[action="'+origin+'/solicitar-cotizacion"]');
            await form.locator('[name=has_seller][value="1"]').check();
            for(const [key,value]of Object.entries({name:ctx.customer_name,email:ctx.customer_email,phone:'0000000000',company:'Empresa ficticia QA API',country:'México',seller_name:ctx.seller_name,message:'QA API: ensayo comercial ficticio local; no enviar mensajes externos.'}))await form.locator(`[name=${key}]`).fill(value);
            await form.locator('[name=has_seller][value="1"]').check();journal.public_request_key=await form.locator('[name=request_key]').inputValue();check(uuid.test(journal.public_request_key),'public_request_key_invalid');await publicContext.storageState({path:file('public-browser-state.json')});persist();
            await submit(publicPage,form,'public-request-submit',async()=>{const value=(await publicPage.locator('.receipt-reference').textContent()).trim();check(uuid.test(value),'receipt_uuid_missing');journal.request_uuid=value;report.request_uuid=value;},'Enviar solicitud');await shot(publicPage,'request-received');
        });
        await checkpoint('seller-create',async()=>{
            await navigate(adminPage,'/admin/commercial-sellers');const input=adminPage.locator('#seller-name-new');await input.fill(ctx.seller_name);const form=input.locator('..').locator('..');
            await submit(adminPage,form,'seller-create',async()=>{const saved=adminPage.locator('input[name=name]');const rows=await saved.evaluateAll(inputs=>inputs.map(i=>({name:i.value,id:i.id})));const match=rows.filter(r=>r.name===ctx.seller_name);check(match.length===1,'saved_seller_not_unique');journal.seller_id=Number(match[0].id.replace('seller-name-',''));check(Number.isInteger(journal.seller_id),'seller_id_missing');},'Registrar vendedor');await shot(adminPage,'seller-created');
        });
        await checkpoint('fictional-scale',async()=>{
            await navigate(adminPage,'/admin/price-scales/create');for(const[key,value]of Object.entries({name:ctx.scale_name,lower:'0',upper:'',increment:'25',source_reference:'Autorización QA experimental: regla ficticia 25% para validar API local; no es regla comercial real.'}))await adminPage.locator(`[name=${key}]`).fill(value);
            await adminPage.locator('[name=is_active]').selectOption('1');const form=adminPage.locator('form[action="'+origin+'/admin/price-scales"]');
            await submit(adminPage,form,'fictional-scale-create',async()=>{const row=adminPage.locator('tr').filter({hasText:ctx.scale_name});check(await row.count()===1,'saved_scale_not_unique');const href=await row.getByRole('link',{name:'Editar',exact:true}).getAttribute('href');const match=new URL(href).pathname.match(/^\/admin\/price-scales\/([^/]+)\/edit$/);check(match&&uuid.test(match[1]),'scale_uuid_missing');journal.scale_uuid=match[1];},'Guardar escala');await shot(adminPage,'scale-fictional');
        });
        await checkpoint('seller-association',async()=>{
            await navigate(adminPage,'/admin/quote-requests');await adminPage.locator('#request-search').fill(ctx.customer_name);await Promise.all([adminPage.waitForNavigation({waitUntil:'domcontentloaded'}),adminPage.getByRole('button',{name:'Filtrar solicitudes'}).click()]);
            const row=adminPage.locator(`#request-${journal.request_uuid}`);check(await row.count()===1 && await adminPage.locator('.request-record').count()===1,'exact_request_not_found');check((await row.locator('h2').textContent()).trim()===ctx.customer_name,'request_customer_mismatch');
            const form=row.locator('.seller-assignment');await form.locator('[name=seller_id]').selectOption(String(journal.seller_id));await form.locator('[name=confirm]').check();await submit(adminPage,form,'seller-association',async()=>{check(await adminPage.locator(`#request-${journal.request_uuid} [name=seller_id]`).inputValue()===String(journal.seller_id),'seller_association_failed');},'Guardar asociación');await shot(adminPage,'request-associated');
        });
        await checkpoint('convert-request',async()=>{
            await navigate(adminPage,`/admin/quote-requests?q=${encodeURIComponent(ctx.customer_name)}`);const form=adminPage.locator(`form[action="${origin}/admin/quote-requests/${journal.request_uuid}/convert"]`);
            await submit(adminPage,form,'convert-request',async()=>{const match=new URL(adminPage.url()).pathname.match(/^\/admin\/quotes\/([^/]+)$/);check(match&&uuid.test(match[1]),'converted_quote_uuid_missing');journal.quote_uuid=match[1];report.quote_uuid=match[1];helper('state',match[1],'draft');},'Crear borrador para costos');
        });
        await checkpoint('draft-v1',()=>editVersion(1));
        await checkpoint('issue-v1',()=>issue(1));
        await checkpoint('read-api-v1',async()=>{helper('grant',journal.quote_uuid);await navigate(adminPage,`/admin/quotes/${journal.quote_uuid}`);await apiCheck(1,'issued-v1');});
        await checkpoint('revision',async()=>{
            await navigate(adminPage,`/admin/quotes/${journal.quote_uuid}`);const form=adminPage.locator(`form[action="${origin}/admin/quotes/${journal.quote_uuid}/revise"]`);await form.locator('[name=confirm]').check();
            await submit(adminPage,form,'open-revision',async()=>{const state=helper('state',journal.quote_uuid,'revision');check(state.quote.status==='draft'&&state.versions.length===1&&state.public_id===journal.public_id,'revision_state_mismatch');},'Abrir revisión');
            const response=await publicContext.request.get(`${origin}/api/v1/cotizaciones/${journal.public_id}`,{headers:{Authorization:`Bearer ${bearer}`,Accept:'application/json'},maxRedirects:0});check(response.status()===404&&(await response.json()).error.code==='QUOTE_NOT_FOUND','revision_must_be_404');report.revision_status404=true;report.revision_http_status=404;await shot(adminPage,'revision-draft');
        });
        await checkpoint('draft-v2',()=>editVersion(2));
        await checkpoint('issue-v2',()=>issue(2));
        await checkpoint('read-api-v2',async()=>{await navigate(adminPage,`/admin/quotes/${journal.quote_uuid}`);await apiCheck(2,'issued-v2');const previous=await publicContext.request.get(journal.capability_v1,{maxRedirects:0});check(previous.status()===200&&digest(await previous.body())===report.versions[0].pdf_sha256,'previous_pdf_not_immutable');report.previous_pdf_preserved=true;});
        check(report.network.blocked_mutations===0 && report.network.blocked_external===0,'unexpected_network_attempt');report.ok=true;report.completed_at=new Date().toISOString();persist();
    }catch(error){if(report){report.ok=false;report.failure={stage,type:/^[A-Za-z]+$/.test(error.name||'')?error.name:'Error',code:error.qaCode||'browser_operation_failed'};persist();}console.log(`FAIL ${stage}: ${error.qaCode||'browser_operation_failed'}`);process.exitCode=1;
    }finally{
        if(adminPage&&!adminPage.isClosed()){try{await navigate(adminPage,'/admin/quotes');const logout=adminPage.locator(`form[action="${origin}/admin/logout"]`);check(await logout.count()===1,'logout_form_missing');await adminPage.locator('.profile-menu > summary').click();await Promise.all([adminPage.waitForNavigation({waitUntil:'domcontentloaded'}),logout.locator('button').click()]);report.fictional_admin_logged_out=true;}catch{if(report){report.fictional_admin_logged_out=false;report.ok=false;report.cleanup_failure='fictional_admin_logout_not_confirmed';process.exitCode=1;}}}
        if(publicContext)await publicContext.storageState({path:file('public-browser-state.json')}).catch(()=>{});
        bearer=undefined;if(report)persist();await browser?.close();console.log(`RESULT ${report?.ok?'PASS':'FAIL'}; private evidence preserved`);
    }
})();
