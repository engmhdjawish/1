/**
 * Poll background sync worker status on material images dashboard.
 */
(function () {
  'use strict';

  const banner = document.getElementById('materialImageWorkerBanner');
  if (!banner) return;

  const apiUrl = banner.getAttribute('data-worker-api') || '/dashboard/material-images-api.php?action=worker-status';
  const summaryEl = document.getElementById('materialImageWorkerSummary');
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
        const name = escapeHtml(row.file_name || 'صورة');
        const err = row.amine_sync_error_ar
          ? ` <span class="font-sans text-red-700" dir="rtl"> — ${escapeHtml(row.amine_sync_error_ar)}</span>`
          : '';
        return `<li class="text-xs">${name}${err}</li>`;
      })
      .join('');
  }

  function applyPayload(data) {
    if (!data || !data.ok) return;

    const needsAttention = !!data.needs_attention;
    banner.classList.toggle('hidden', !needsAttention && (data.worker_enabled !== false));

    if (summaryEl && typeof data.user_summary === 'string' && data.user_summary !== '') {
      summaryEl.textContent = data.user_summary;
    }

    renderFailures(data.worker?.recent_failures || []);

    const failedEl = document.getElementById('statFailedCount');
    const pendingEl = document.getElementById('statPendingCount');
    const apiPill = document.getElementById('apiStatusPill');
    if (failedEl) failedEl.textContent = String(data.sync?.failed ?? 0);
    if (pendingEl) pendingEl.textContent = String(data.sync?.pending ?? 0);
    if (apiPill) {
      apiPill.innerHTML = data.api?.ok
        ? 'المحاسبة: <strong class="text-status-active">متصل</strong>'
        : 'المحاسبة: <strong class="text-status-rejected">غير متصل</strong>';
    }
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
