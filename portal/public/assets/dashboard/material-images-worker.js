/**
 * Poll background sync worker status on material images dashboard.
 */
(function () {
  'use strict';

  const banner = document.getElementById('materialImageWorkerBanner');
  if (!banner) return;

  const apiUrl = banner.getAttribute('data-worker-api') || '/dashboard/material-images-api.php?action=worker-status';
  const summaryEl = document.getElementById('materialImageWorkerSummary');
  const missingEl = document.getElementById('materialImageWorkerMissing');
  const failuresEl = document.getElementById('materialImageWorkerFailures');

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  function renderFailures(items) {
    if (!failuresEl) return;
    const list = Array.isArray(items) ? items : [];
    if (list.length === 0) {
      failuresEl.classList.add('hidden');
      failuresEl.innerHTML = '';
      return;
    }
    failuresEl.classList.remove('hidden');
    failuresEl.innerHTML = list
      .map((row) => {
        const name = escapeHtml(row.file_name || '');
        const err = row.amine_sync_error_ar ? ` <span class="font-sans text-red-700" dir="rtl"> — ${escapeHtml(row.amine_sync_error_ar)}</span>` : '';
        return `<li class="font-mono" dir="ltr">${name}${err}</li>`;
      })
      .join('');
  }

  function applyPayload(data) {
    if (!data || !data.ok) return;
    const worker = data.worker || {};
    const sync = data.sync || {};
    const lastRun = worker.last_run_at || '';
    const message = worker.last_message || '';
    if (summaryEl && lastRun) {
      summaryEl.innerHTML = `آخر تشغيل: <span dir="ltr">${escapeHtml(lastRun)}</span>${message ? ` — ${escapeHtml(message)}` : ''}`;
    }
    if (missingEl != null && worker.missing_local_count != null) {
      missingEl.textContent = String(worker.missing_local_count);
    }
    renderFailures(worker.recent_failures || []);

    const failedEl = document.getElementById('statFailedCount');
    const pendingEl = document.getElementById('statPendingCount');
    if (failedEl) failedEl.textContent = String(sync.failed ?? 0);
    if (pendingEl) pendingEl.textContent = String(sync.pending ?? 0);
  }

  async function poll() {
    try {
      const res = await fetch(apiUrl, { credentials: 'same-origin', cache: 'no-store' });
      const data = await res.json();
      applyPayload(data);
    } catch {
      /* ignore */
    }
  }

  poll();
  window.setInterval(poll, 45000);
}());
