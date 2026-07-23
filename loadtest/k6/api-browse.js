import http from 'k6/http';
import { check, sleep } from 'k6';

/**
 * Simulates portal service-account traffic against the ASP.NET API
 * (materials browse / filters / detail / images).
 *
 *   k6 run -e BASE_URL=http://127.0.0.1:5249 \
 *     -e USERNAME=portal-service -e PASSWORD='...' \
 *     loadtest/k6/api-browse.js
 */

const BASE_URL = (__ENV.BASE_URL || 'http://127.0.0.1:5249').replace(/\/$/, '');
const USERNAME = __ENV.USERNAME || '';
const PASSWORD = __ENV.PASSWORD || '';

export const options = {
  scenarios: {
    browse: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '10s', target: Number(__ENV.VUS || 20) },
        { duration: __ENV.DURATION || '60s', target: Number(__ENV.VUS || 20) },
        { duration: '10s', target: 0 },
      ],
      gracefulRampDown: '10s',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.05'],
    http_req_duration: ['p(95)<3000'],
  },
};

const sharedGuids = [];

export function setup() {
  if (!USERNAME || !PASSWORD) {
    throw new Error('Set USERNAME and PASSWORD env vars.');
  }
  const res = http.post(
    `${BASE_URL}/api/auth/login`,
    JSON.stringify({ userName: USERNAME, password: PASSWORD }),
    { headers: { 'Content-Type': 'application/json' } },
  );
  check(res, { 'login 200': (r) => r.status === 200 });
  const body = res.json();
  if (!body || !body.accessToken) {
    throw new Error(`Login failed: HTTP ${res.status}`);
  }
  return { token: body.accessToken };
}

export default function (data) {
  const headers = {
    Authorization: `Bearer ${data.token}`,
    Accept: 'application/json',
  };

  http.get(`${BASE_URL}/api/health`);

  const page = 1 + Math.floor(Math.random() * 5);
  const list = http.get(`${BASE_URL}/api/materials?page=${page}&pageSize=50`, { headers });
  check(list, { 'materials list ok': (r) => r.status === 200 });

  if (Math.random() < 0.4) {
    http.get(`${BASE_URL}/api/materials/filter-options`, { headers });
  }

  try {
    const json = list.json();
    const items = (json && (json.items || json.Items)) || [];
    for (const item of items.slice(0, 8)) {
      const guid = item.materialGuid || item.MaterialGuid || item.guid || item.Guid;
      if (guid && sharedGuids.length < 200) sharedGuids.push(guid);
    }
  } catch (_) {
    /* ignore parse errors under load */
  }

  if (sharedGuids.length) {
    const guid = sharedGuids[Math.floor(Math.random() * sharedGuids.length)];
    http.get(`${BASE_URL}/api/materials/${guid}`, { headers });
    http.get(`${BASE_URL}/api/materials/${guid}/images`, { headers });
  }

  sleep(0.2 + Math.random() * 0.3);
}
