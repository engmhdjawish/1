#!/usr/bin/env node
/**
 * Static checks for visitor-log branches (no PHP/DB required).
 * Usage: node portal/scripts/test-visitor-log-static.mjs
 */
import { readFileSync, existsSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
let pass = 0;
let fail = 0;

const ok = (msg) => { pass++; console.log(`  ✓ ${msg}`); };
const bad = (msg) => { fail++; console.log(`  ✗ ${msg}`); };

const mustExist = (rel) => {
  const path = join(root, rel);
  if (existsSync(path)) ok(`${rel}`);
  else bad(`Missing ${rel}`);
};

const mustContain = (rel, needle, label) => {
  const path = join(root, rel);
  if (!existsSync(path)) { bad(`Missing ${rel}`); return; }
  const text = readFileSync(path, 'utf8');
  if (text.includes(needle)) ok(label || `${rel} contains ${needle.slice(0, 40)}`);
  else bad(`${rel} missing: ${needle.slice(0, 60)}`);
};

console.log('=== Visitor log static checks ===\n');

console.log('== Hub files ==');
[
  'portal/public/dashboard/visitor-analytics.php',
  'portal/views/dashboard/visitor-analytics.php',
  'portal/public/css/visitor-log.css',
  'portal/public/dashboard/sessions.php',
].forEach(mustExist);

console.log('\n== Identity files ==');
mustExist('docs/portal-migrations/013-orders-visitor-session.sql');
mustContain('portal/scripts/run-migrations.php', '013-orders-visitor-session.sql', 'migration 013 registered');
mustContain('portal/src/Services/VisitorLogService.php', 'resolveIdentitiesForSessions', 'identity resolver');
mustContain('portal/src/Services/VisitorLogService.php', 'mapExternalUrl', 'map URL helper');
mustContain('portal/src/Services/VisitorLogService.php', 'order_placed', 'order_placed action');
mustContain('portal/src/Services/OrderService.php', 'visitor_session_id', 'order session linkage');
mustContain('portal/public/assets/store-cart.js', 'jawish_vid', 'cart sends jawish_vid');
mustContain('portal/views/dashboard/visitor-analytics.php', 'عرض على الخريطة', 'map button in UI');

console.log('\n== Test scripts ==');
mustExist('portal/scripts/test-visitor-log.php');
mustExist('deploy/scripts/test-visitor-log-branches.sh');

console.log(`\n=== Result: ${pass} passed, ${fail} failed ===`);
process.exit(fail > 0 ? 1 : 0);
