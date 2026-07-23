#!/usr/bin/env node
/**
 * Load / stress runner that simulates website usage against the Amine API
 * and/or the PHP portal storefront.
 *
 * Usage:
 *   node loadtest/run.mjs --scenario api-browse --base-url http://127.0.0.1:5249 \
 *     --username portal-service --password '...' --vus 20 --duration 60
 *
 *   node loadtest/run.mjs --scenario site-browse --site-url http://127.0.0.1:8080 \
 *     --vus 30 --duration 90
 */

import { parseArgs } from 'node:util';
import { performance } from 'node:perf_hooks';

const { values } = parseArgs({
  options: {
    scenario: { type: 'string', default: 'api-browse' },
    'base-url': { type: 'string', default: process.env.LOADTEST_BASE_URL || 'http://127.0.0.1:5249' },
    'site-url': { type: 'string', default: process.env.LOADTEST_SITE_URL || 'http://127.0.0.1:8080' },
    username: { type: 'string', default: process.env.LOADTEST_USERNAME || '' },
    password: { type: 'string', default: process.env.LOADTEST_PASSWORD || '' },
    vus: { type: 'string', default: '10' },
    duration: { type: 'string', default: '60' },
    'ramp-up': { type: 'string', default: '10' },
    'think-ms': { type: 'string', default: '200' },
    'timeout-ms': { type: 'string', default: '30000' },
    insecure: { type: 'boolean', default: false },
    help: { type: 'boolean', default: false, short: 'h' },
  },
  strict: true,
  allowPositionals: false,
});

if (values.help) {
  console.log(`Usage: node loadtest/run.mjs [options]

Options:
  --scenario api-browse|site-browse
  --base-url URL          ASP.NET API base (default http://127.0.0.1:5249)
  --site-url URL          PHP portal base (default http://127.0.0.1:8080)
  --username USER         API login user (or LOADTEST_USERNAME)
  --password PASS         API login password (or LOADTEST_PASSWORD)
  --vus N                 concurrent virtual users (default 10)
  --duration SEC          test duration seconds (default 60)
  --ramp-up SEC           ramp-up seconds (default 10)
  --think-ms MS           pause between steps (default 200)
  --timeout-ms MS         request timeout (default 30000)
  --insecure              allow self-signed TLS
  -h, --help
`);
  process.exit(0);
}

const scenario = values.scenario;
const baseUrl = values['base-url'].replace(/\/$/, '');
const siteUrl = values['site-url'].replace(/\/$/, '');
const username = values.username;
const password = values.password;
const vus = Math.max(1, Number.parseInt(values.vus, 10) || 10);
const durationSec = Math.max(1, Number.parseInt(values.duration, 10) || 60);
const rampUpSec = Math.max(0, Number.parseInt(values['ramp-up'], 10) || 0);
const thinkMs = Math.max(0, Number.parseInt(values['think-ms'], 10) || 0);
const timeoutMs = Math.max(1000, Number.parseInt(values['timeout-ms'], 10) || 30000);

if (!['api-browse', 'site-browse'].includes(scenario)) {
  console.error(`Unknown scenario: ${scenario}`);
  process.exit(1);
}

if (scenario === 'api-browse' && (!username || !password)) {
  console.error('api-browse requires --username and --password (or LOADTEST_USERNAME / LOADTEST_PASSWORD).');
  process.exit(1);
}

if (values.insecure) {
  process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';
}

/** @type {Map<string, number[]>} */
const latencies = new Map();
/** @type {Map<string, { ok: number, fail: number, status: Map<number, number> }>} */
const counters = new Map();
let stopped = false;
let startedAt = 0;
let endedAt = 0;

function bucket(name) {
  if (!latencies.has(name)) latencies.set(name, []);
  if (!counters.has(name)) counters.set(name, { ok: 0, fail: 0, status: new Map() });
  return { times: latencies.get(name), stats: counters.get(name) };
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function pick(arr) {
  if (!arr.length) return null;
  return arr[Math.floor(Math.random() * arr.length)];
}

async function request(name, url, options = {}) {
  const { times, stats } = bucket(name);
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  const t0 = performance.now();
  try {
    const res = await fetch(url, {
      ...options,
      signal: controller.signal,
      headers: {
        Accept: 'application/json, text/html;q=0.9,*/*;q=0.8',
        'User-Agent': 'jawish-loadtest/1.0',
        ...(options.headers || {}),
      },
    });
    const elapsed = performance.now() - t0;
    times.push(elapsed);
    stats.status.set(res.status, (stats.status.get(res.status) || 0) + 1);
    const bodyText = await res.text();
    let json = null;
    const ct = res.headers.get('content-type') || '';
    if (ct.includes('application/json')) {
      try {
        json = JSON.parse(bodyText);
      } catch {
        json = null;
      }
    }
    if (res.ok) stats.ok += 1;
    else stats.fail += 1;
    return { ok: res.ok, status: res.status, json, text: bodyText, ms: elapsed };
  } catch (err) {
    const elapsed = performance.now() - t0;
    times.push(elapsed);
    stats.fail += 1;
    stats.status.set(0, (stats.status.get(0) || 0) + 1);
    return { ok: false, status: 0, error: err, ms: elapsed };
  } finally {
    clearTimeout(timer);
  }
}

async function login() {
  const res = await request('auth.login', `${baseUrl}/api/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ userName: username, password }),
  });
  if (!res.ok || !res.json?.accessToken) {
    throw new Error(`Login failed (HTTP ${res.status}). Check credentials and API URL.`);
  }
  return res.json.accessToken;
}

function authHeaders(token) {
  return { Authorization: `Bearer ${token}` };
}

async function apiBrowseJourney(token, shared) {
  await request('health', `${baseUrl}/api/health`);
  if (thinkMs) await sleep(thinkMs);

  const page = 1 + Math.floor(Math.random() * 5);
  const pageSize = pick([24, 48, 50]) || 50;
  const list = await request(
    'materials.list',
    `${baseUrl}/api/materials?page=${page}&pageSize=${pageSize}`,
    { headers: authHeaders(token) },
  );
  if (thinkMs) await sleep(thinkMs);

  if (Math.random() < 0.4) {
    await request('materials.filter-options', `${baseUrl}/api/materials/filter-options`, {
      headers: authHeaders(token),
    });
    if (thinkMs) await sleep(thinkMs);
  }

  const items = list.json?.items || list.json?.Items || [];
  if (Array.isArray(items) && items.length) {
    for (const item of items.slice(0, 8)) {
      const guid =
        item.materialGuid || item.MaterialGuid || item.guid || item.Guid || item.id || item.Id;
      if (guid && !shared.guids.includes(guid) && shared.guids.length < 200) {
        shared.guids.push(guid);
      }
      const imageGuid =
        item.productImageGuid ||
        item.ProductImageGuid ||
        item.pictureGuid ||
        item.PictureGuid ||
        item.imageGuid ||
        item.ImageGuid;
      if (imageGuid && !shared.imageGuids.includes(imageGuid) && shared.imageGuids.length < 200) {
        shared.imageGuids.push(imageGuid);
      }
    }
  }

  const materialGuid = pick(shared.guids);
  if (materialGuid) {
    await request('materials.detail', `${baseUrl}/api/materials/${materialGuid}`, {
      headers: authHeaders(token),
    });
    if (thinkMs) await sleep(thinkMs);

    await request('materials.images', `${baseUrl}/api/materials/${materialGuid}/images`, {
      headers: authHeaders(token),
    });
    if (thinkMs) await sleep(thinkMs);
  }

  const imageGuid = pick(shared.imageGuids);
  if (imageGuid && Math.random() < 0.5) {
    await request(
      'material-images.thumbnail',
      `${baseUrl}/api/material-images/${imageGuid}/thumbnail`,
      { headers: authHeaders(token) },
    );
  }
}

async function siteBrowseJourney(shared) {
  await request('site.home', `${siteUrl}/`);
  if (thinkMs) await sleep(thinkMs);

  const page = 1 + Math.floor(Math.random() * 4);
  const store = await request('site.store', `${siteUrl}/store.php?page=${page}`);
  if (thinkMs) await sleep(thinkMs);

  if (Math.random() < 0.5) {
    await request('site.filters', `${siteUrl}/api/store-filter-options.php`);
    if (thinkMs) await sleep(thinkMs);
  }

  // Harvest product GUIDs from HTML when present.
  if (store.text) {
    const matches = store.text.matchAll(/guid=([0-9a-fA-F-]{36})|data-guid="([0-9a-fA-F-]{36})"|\/product\.php\?[^"']*guid=([0-9a-fA-F-]{36})/g);
    for (const m of matches) {
      const guid = m[1] || m[2] || m[3];
      if (guid && !shared.guids.includes(guid) && shared.guids.length < 200) {
        shared.guids.push(guid);
      }
    }
    const imageMatches = store.text.matchAll(/\/api\/image\.php\?id=([0-9a-fA-F-]{36})/g);
    for (const m of imageMatches) {
      if (m[1] && !shared.imageGuids.includes(m[1]) && shared.imageGuids.length < 200) {
        shared.imageGuids.push(m[1]);
      }
    }
  }

  const productGuid = pick(shared.guids);
  if (productGuid) {
    await request('site.product-api', `${siteUrl}/api/store-product.php?guid=${encodeURIComponent(productGuid)}`);
    if (thinkMs) await sleep(thinkMs);

    if (Math.random() < 0.35) {
      await request('site.product-page', `${siteUrl}/product.php?guid=${encodeURIComponent(productGuid)}`);
      if (thinkMs) await sleep(thinkMs);
    }
  }

  const imageGuid = pick(shared.imageGuids);
  if (imageGuid) {
    await request(
      'site.image',
      `${siteUrl}/api/image.php?id=${encodeURIComponent(imageGuid)}&thumb=1`,
    );
    if (thinkMs) await sleep(thinkMs);
  }

  if (Math.random() < 0.4) {
    await request('site.cart', `${siteUrl}/api/store-cart.php`);
  }
}

async function vuLoop(id, token, shared) {
  // Stagger VU start during ramp-up.
  if (rampUpSec > 0) {
    const delay = (id / vus) * rampUpSec * 1000;
    await sleep(delay);
  }

  while (!stopped) {
    try {
      if (scenario === 'api-browse') {
        await apiBrowseJourney(token, shared);
      } else {
        await siteBrowseJourney(shared);
      }
    } catch (err) {
      const { stats } = bucket('journey.error');
      stats.fail += 1;
      if (id === 0) {
        console.error(`[vu-${id}]`, err instanceof Error ? err.message : err);
      }
    }
    if (thinkMs) await sleep(thinkMs + Math.floor(Math.random() * thinkMs));
  }
}

function percentile(sorted, p) {
  if (!sorted.length) return 0;
  const idx = Math.min(sorted.length - 1, Math.ceil((p / 100) * sorted.length) - 1);
  return sorted[idx];
}

function printReport() {
  const wallSec = Math.max(0.001, (endedAt - startedAt) / 1000);
  console.log('\n========== Load test report ==========');
  console.log(`scenario : ${scenario}`);
  console.log(`target   : ${scenario === 'api-browse' ? baseUrl : siteUrl}`);
  console.log(`vus      : ${vus}`);
  console.log(`duration : ${durationSec}s (ramp-up ${rampUpSec}s)`);
  console.log(`wall     : ${wallSec.toFixed(1)}s`);
  console.log('');
  console.log(
    `${'name'.padEnd(28)} ${'ok'.padStart(7)} ${'fail'.padStart(6)} ${'rps'.padStart(8)} ${'p50'.padStart(8)} ${'p95'.padStart(8)} ${'p99'.padStart(8)} ${'max'.padStart(8)}`,
  );

  let totalOk = 0;
  let totalFail = 0;
  const names = [...counters.keys()].sort();
  for (const name of names) {
    const stats = counters.get(name);
    const times = (latencies.get(name) || []).slice().sort((a, b) => a - b);
    const ok = stats.ok;
    const fail = stats.fail;
    totalOk += ok;
    totalFail += fail;
    const total = ok + fail;
    const rps = (total / wallSec).toFixed(1);
    const p50 = percentile(times, 50).toFixed(0);
    const p95 = percentile(times, 95).toFixed(0);
    const p99 = percentile(times, 99).toFixed(0);
    const max = (times[times.length - 1] || 0).toFixed(0);
    console.log(
      `${name.padEnd(28)} ${String(ok).padStart(7)} ${String(fail).padStart(6)} ${rps.padStart(8)} ${p50.padStart(8)} ${p95.padStart(8)} ${p99.padStart(8)} ${max.padStart(8)}`,
    );
  }

  console.log('');
  console.log(`TOTAL ok=${totalOk} fail=${totalFail} rps=${((totalOk + totalFail) / wallSec).toFixed(1)}`);
  if (totalFail > 0) {
    console.log('Statuses (non-exhaustive):');
    for (const name of names) {
      const stats = counters.get(name);
      if (!stats.fail) continue;
      const parts = [...stats.status.entries()]
        .filter(([code]) => code === 0 || code >= 400)
        .map(([code, n]) => `${code}:${n}`)
        .join(' ');
      if (parts) console.log(`  ${name}: ${parts}`);
    }
  }
  console.log('======================================\n');

  const failRate = totalOk + totalFail === 0 ? 1 : totalFail / (totalOk + totalFail);
  if (failRate > 0.05) {
    console.error(`FAIL: error rate ${(failRate * 100).toFixed(1)}% exceeds 5%.`);
    process.exitCode = 2;
  } else {
    console.log('PASS: error rate within 5%.');
  }
}

async function main() {
  console.log(`Starting ${scenario} | vus=${vus} duration=${durationSec}s ramp-up=${rampUpSec}s`);
  const shared = { guids: [], imageGuids: [] };
  let token = null;

  if (scenario === 'api-browse') {
    console.log(`Logging in to ${baseUrl} as ${username}...`);
    token = await login();
    console.log('Login OK.');
  } else {
    const probe = await request('site.probe', `${siteUrl}/`);
    if (!probe.ok && probe.status === 0) {
      throw new Error(`Cannot reach site at ${siteUrl}. Is the portal running?`);
    }
    console.log(`Site reachable at ${siteUrl} (HTTP ${probe.status}).`);
  }

  startedAt = performance.now();
  const workers = [];
  for (let i = 0; i < vus; i += 1) {
    workers.push(vuLoop(i, token, shared));
  }

  await sleep(durationSec * 1000);
  stopped = true;
  await Promise.allSettled(workers);
  endedAt = performance.now();
  printReport();
}

main().catch((err) => {
  console.error(err instanceof Error ? err.message : err);
  process.exit(1);
});
