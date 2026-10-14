import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const source = await readFile(new URL('../assets/js/main.js', import.meta.url), 'utf8');

function page(fetch, consent = 'granted') {
  const element = () => ({
    value: '', tagName: 'INPUT', hidden: false, style: {}, dataset: {}, listeners: {},
    addEventListener(name, listener) { this.listeners[name] = listener; },
    setAttribute() {}, removeAttribute() {}, focus() {},
  });
  const values = { nome: 'Maria Silva', whatsapp: '11999999999', email: 'qa@example.com', empresa: 'Empresa Teste', faturamento: '1m-3m', cargo: 'diretor', website: '' };
  const form = element();
  form.elements = Object.fromEntries(Object.entries(values).map(([key, value]) => [key, { ...element(), value }]));
  const nodes = { 'lead-form': form, 'lead-done': { ...element(), hidden: true }, 'btn-submit': element(), 'form-error': element() };
  Object.keys(values).forEach(key => { nodes['e-' + key] = element(); });
  const context = {
    URLSearchParams,
    document: { getElementById: id => nodes[id], querySelectorAll: () => [], createElement: element, getElementsByTagName: () => [{ parentNode: { insertBefore() {} } }] },
    location: { search: '?utm_source=qa', href: 'https://site.test/?utm_source=qa' },
    localStorage: { getItem: () => consent }, sessionStorage: { getItem: () => null, setItem() {} },
    crypto: { randomUUID: () => '11111111-1111-4111-8111-111111111111' },
    fetch,
  };
  context.window = context;
  // The Pixel base code lives in index.html <head> and is skipped when consent was denied
  if (consent !== 'denied') {
    context.fbq = function () { context.fbq.queue.push(arguments); };
    context.fbq.queue = [];
  }
  vm.runInNewContext(source, context);
  return {
    form, nodes,
    submit: () => form.listeners.submit({ preventDefault() {} }),
    leads: () => JSON.parse(JSON.stringify((context.fbq?.queue || []).map(args => Array.from(args)))).filter(args => args[0] === 'track' && args[1] === 'Lead'),
    conversions: () => context.dataLayer.filter(item => item.event === 'generate_lead'),
  };
}

const response = (ok, data) => ({ ok, json: async () => data });
const settle = () => new Promise(resolve => setImmediate(resolve));

for (const [name, ok, data] of [
  ['acknowledgement without CRM delivery', true, { ok: true }],
  ['anti-spam acknowledgement', true, { ok: true, crm_sent: false }],
  ['non-boolean CRM confirmation', true, { ok: true, crm_sent: 'true' }],
  ['CRM error', false, { ok: false }],
  ['HTTP error with a misleading success body', false, { ok: true, crm_sent: true }],
]) {
  test(`no Lead for ${name}`, async () => {
    const p = page(async () => response(ok, data));
    p.submit();
    await settle();
    assert.equal(p.leads().length, 0);
    assert.equal(p.conversions().length, 0);
  });
}

test('Lead waits for CRM confirmation, keeps the retry ID and fires once', async () => {
  const payloads = [];
  let confirm;
  const p = page(async (_url, options) => {
    payloads.push(JSON.parse(options.body));
    if (payloads.length === 1) throw new Error('Failed to fetch');
    return new Promise(resolve => { confirm = () => resolve(response(true, { ok: true, crm_sent: true })); });
  });
  assert.equal(p.leads().length, 0);
  p.submit();
  await settle();
  assert.equal(p.nodes['btn-submit'].disabled, false);
  assert.equal(p.leads().length, 0);
  p.submit();
  await settle();
  assert.equal(p.nodes['btn-submit'].disabled, true);
  assert.equal(p.leads().length, 0);
  p.submit();
  assert.equal(payloads.length, 2);
  confirm();
  await settle();
  assert.equal(payloads[0].event_id, payloads[1].event_id);
  assert.deepEqual(p.leads(), [['track', 'Lead', { content_name: 'diagnostico_comercial' }, { eventID: payloads[1].event_id }]]);
  assert.equal(p.conversions().length, 1);
  assert.equal(p.nodes['lead-done'].hidden, false);
  p.submit();
  await settle();
  assert.equal(payloads.length, 2);
  assert.equal(p.leads().length, 1);
});

test('invalid fields do not submit or count Lead', async () => {
  let requests = 0;
  const p = page(async () => { requests++; return response(true, { ok: true, crm_sent: true }); });
  p.form.elements.email.value = '';
  p.submit();
  await settle();
  assert.equal(requests, 0);
  assert.equal(p.leads().length, 0);
});

test('marketing consent remains required for the pixel', async () => {
  const p = page(async () => response(true, { ok: true, crm_sent: true }), 'denied');
  p.submit();
  await settle();
  assert.equal(p.nodes['lead-done'].hidden, false);
  assert.equal(p.leads().length, 0);
});
