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
mustContain('portal/src/Support/StaffDevice.php', 'jawish_staff', 'staff device cookie');
mustContain('portal/src/Services/PortalSessionService.php', 'noteStaffActor', 'customer login attributed to staff');
mustContain('portal/views/dashboard/visitor-analytics.php', 'داخل حساب', 'staff row names the customer account once');
mustContain('portal/src/Services/VisitorLogService.php', 'staffIdentity', 'visitor identity prefers staff');
mustContain('portal/src/Services/VisitorLogService.php', 'function describePath', 'readable page labels');
mustContain('portal/views/dashboard/visitor-analytics.php', 'page_title_ar', 'insights show Arabic page names');
mustContain('portal/views/dashboard/visitor-analytics-log.php', 'الصفحات التي زارها', 'profile lists visited pages');
mustContain('portal/src/Services/PortalSessionService.php', 'noteDeviceLogin', 'one login per device');
mustContain('portal/src/Services/PortalPresenceService.php', 'forgetSameDeviceGuests', 'same-device guest rows are cleared');
mustContain('portal/public/dashboard/visitor-analytics.php', "$row['display_name'] = 'زائر'", 'live guests display as زائر');
mustContain('portal/public/api/session-heartbeat.php', 'noteDeviceLogin', 'heartbeat notes the device login');
mustContain('portal/public/assets/site-analytics.js', 'enableHighAccuracy: highAccuracy', 'GPS asks for a precise fix');
mustContain('portal/public/assets/site-analytics.js', "const gestureEvents = ['pointerup', 'click', 'touchend', 'keydown']", 'GPS permission waits for a user gesture on touch devices');
mustContain('portal/public/assets/site-analytics.js', "send('gps_fix'", 'a late GPS fix is recorded after the page view');
mustContain('portal/src/Services/VisitorLogService.php', "'gps_fix' => 'موقع GPS'", 'GPS fix has an Arabic label');
mustContain('portal/views/layout.php', 'geolocation=(self)', 'pages allow the geolocation permission');

console.log('\n== Test scripts ==');
mustExist('portal/scripts/test-visitor-log.php');
mustExist('deploy/scripts/test-visitor-log-branches.sh');

console.log(`\n=== Result: ${pass} passed, ${fail} failed ===`);
process.exit(fail > 0 ? 1 : 0);
