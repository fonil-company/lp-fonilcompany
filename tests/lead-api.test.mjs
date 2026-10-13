import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { copyFile, mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';

test('API confirms delivery only after the CRM accepts the lead', async t => {
  const directory = await mkdtemp(path.join(os.tmpdir(), 'fonil-lead-test-'));
  const root = path.join(directory, 'site');
  await mkdir(path.join(root, 'api'), { recursive: true });
  await mkdir(path.join(root, 'private'), { recursive: true });
  await mkdir(path.join(directory, 'temp'));
  await copyFile(new URL('../api/lead.php', import.meta.url), path.join(root, 'api/lead.php'));
  const received = [];
  let crmStatus = 503;
  const crm = http.createServer(async (req, res) => {
    const chunks = [];
    for await (const chunk of req) chunks.push(chunk);
    received.push(JSON.parse(Buffer.concat(chunks).toString()));
    res.writeHead(crmStatus);
    res.end();
  });
  crm.listen(0, '127.0.0.1');
  await once(crm, 'listening');
  const webhook = `http://127.0.0.1:${crm.address().port}/leads`;
  const configure = async url => {
    const config = Buffer.from(JSON.stringify({ site_host: '127.0.0.1', crm_webhook_url: url, ip_salt: 'test-only' })).toString('base64');
    await writeFile(path.join(root, 'private/config.php'), `<?php return json_decode(base64_decode('${config}'), true);`);
  };
  await configure(webhook);

  const probe = http.createServer();
  probe.listen(0, '127.0.0.1');
  await once(probe, 'listening');
  const port = probe.address().port;
  await new Promise(resolve => probe.close(resolve));
  const php = spawn(process.env.PHP_BINARY || 'php', ['-d', `sys_temp_dir=${path.join(directory, 'temp')}`, '-S', `127.0.0.1:${port}`, '-t', root], { windowsHide: true });
  let output = '';
  php.stdout.on('data', chunk => { output += chunk; });
  php.stderr.on('data', chunk => { output += chunk; });
  php.on('error', error => { output += error.message; });
  t.after(async () => {
    if (php.pid && php.exitCode === null && php.signalCode === null) {
      const stopped = once(php, 'exit');
      php.kill();
      await stopped;
    }
    crm.closeAllConnections();
    await new Promise(resolve => crm.close(resolve));
    assert.equal(path.dirname(path.resolve(directory)), path.resolve(os.tmpdir()));
    assert(path.basename(directory).startsWith('fonil-lead-test-'));
    await rm(directory, { recursive: true, force: true });
  });
  const url = `http://127.0.0.1:${port}/api/lead.php`;
  let ready = false;
  for (let attempt = 0; attempt < 100 && !ready; attempt++) {
    try { ready = (await fetch(url)).status === 405; } catch {}
    if (!ready) await new Promise(resolve => setTimeout(resolve, 50));
  }
  assert(ready, `PHP server did not start: ${output}`);
  const lead = { nome: 'Maria Silva', whatsapp: '11999999999', email: 'qa@example.com', empresa: 'Empresa Teste', faturamento: '1m-3m', cargo: 'diretor', website: '', elapsed_ms: 5000, event_id: '11111111-1111-4111-8111-111111111111', utm_source: 'qa' };
  const post = async body => {
    const res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', Origin: `http://127.0.0.1:${port}` }, body: JSON.stringify(body) });
    return { status: res.status, body: await res.json() };
  };
  for (const blocked of [{ ...lead, website: 'spam' }, { ...lead, elapsed_ms: 0 }]) {
    assert.deepEqual(await post(blocked), { status: 200, body: { ok: true, crm_sent: false } });
  }
  assert.equal(received.length, 0);
  assert.equal((await post({ ...lead, email: '' })).status, 422);
  await configure('');
  assert.equal((await post(lead)).body.ok, false);
  assert.equal(received.length, 0);
  await configure('http://127.0.0.1:1/unavailable');
  assert.equal((await post(lead)).body.ok, false);
  await configure(webhook);
  const rejected = await post(lead);
  assert.equal(rejected.status, 500);
  assert.equal(rejected.body.ok, false);
  assert.equal(rejected.body.crm_sent, undefined);
  crmStatus = 204;
  assert.deepEqual(await post(lead), { status: 200, body: { ok: true, crm_sent: true } });
  assert.equal(received.length, 2);
  assert.equal(received[1].event_id, lead.event_id);
  assert.equal(received[1].phone, '5511999999999');
  assert.equal(received[1].utm_source, 'qa');
});
