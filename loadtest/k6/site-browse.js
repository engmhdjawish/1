import http from 'k6/http';
import { check, sleep } from 'k6';

/**
 * Simulates storefront visitors hitting the PHP portal
 * (home → store pages → filters → product → image → cart).
 *
 *   k6 run -e SITE_URL=http://127.0.0.1:8080 loadtest/k6/site-browse.js
 */

const SITE_URL = (__ENV.SITE_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const guids = [];
const imageGuids = [];

export const options = {
  scenarios: {
    visitors: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '10s', target: Number(__ENV.VUS || 30) },
        { duration: __ENV.DURATION || '90s', target: Number(__ENV.VUS || 30) },
        { duration: '10s', target: 0 },
      ],
      gracefulRampDown: '10s',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.05'],
    http_req_duration: ['p(95)<5000'],
  },
};

function harvest(html) {
  if (!html) return;
  const guidRe = /guid=([0-9a-fA-F-]{36})|data-guid="([0-9a-fA-F-]{36})"/g;
  let m;
  while ((m = guidRe.exec(html)) !== null) {
    const g = m[1] || m[2];
    if (g && guids.length < 200) guids.push(g);
  }
  const imgRe = /\/api\/image\.php\?id=([0-9a-fA-F-]{36})/g;
  while ((m = imgRe.exec(html)) !== null) {
    if (m[1] && imageGuids.length < 200) imageGuids.push(m[1]);
  }
}

export default function () {
  const home = http.get(`${SITE_URL}/`);
  check(home, { 'home ok': (r) => r.status < 500 });

  const page = 1 + Math.floor(Math.random() * 4);
  const store = http.get(`${SITE_URL}/store.php?page=${page}`);
  check(store, { 'store ok': (r) => r.status < 500 });
  harvest(store.body);

  if (Math.random() < 0.5) {
    http.get(`${SITE_URL}/api/store-filter-options.php`);
  }

  if (guids.length) {
    const guid = guids[Math.floor(Math.random() * guids.length)];
    http.get(`${SITE_URL}/api/store-product.php?guid=${guid}`);
    if (Math.random() < 0.35) {
      http.get(`${SITE_URL}/product.php?guid=${guid}`);
    }
  }

  if (imageGuids.length) {
    const id = imageGuids[Math.floor(Math.random() * imageGuids.length)];
    http.get(`${SITE_URL}/api/image.php?id=${id}&thumb=1`);
  }

  if (Math.random() < 0.4) {
    http.get(`${SITE_URL}/api/store-cart.php`);
  }

  sleep(0.2 + Math.random() * 0.4);
}
