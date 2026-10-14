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

  function renderFailures(items, repairItems) {
    if (!failuresEl) return;
    const list = Array.isArray(items) ? items : [];
    const repairs = Array.isArray(repairItems) ? repairItems : [];
    if (list.length === 0 && repairs.length === 0) {
      failuresEl.classList.add('hidden');
      failuresEl.innerHTML = '';
      return;
    }
    failuresEl.classList.remove('hidden');
    const syncHtml = list
      .map((row) => {
        const name = escapeHtml(row.file_name || 'صورة');
        const err = row.amine_sync_error_ar
          ? ` <span class="font-sans text-red-700" dir="rtl"> — ${escapeHtml(row.amine_sync_error_ar)}</span>`
          : '';
        return `<li class="text-xs">${name}${err}</li>`;
      })
      .join('');
    const repairHtml = repairs
      .map((row) => {
        const code = escapeHtml(row.material_code || 'صورة');
        const err = row.message ? ` — ${escapeHtml(row.message)}` : '';
        return `<li class="text-xs" dir="rtl">${code}${err}</li>`;
      })
      .join('');
    failuresEl.innerHTML = syncHtml + repairHtml;
  }

  function applyQueuePill(data) {
    const pill = document.getElementById('queueHealthPill');
    if (!pill) return;
    const failed = Number(data.sync?.failed ?? 0);
    const pending = Number(data.sync?.pending ?? 0);
    const missing = Number(data.worker?.missing_local_count ?? 0);
    if (missing > 0) {
      pill.classList.remove('hidden', 'border-green-200', 'bg-green-50', 'text-green-800');
      pill.classList.add('border-amber-200', 'bg-amber-50', 'text-amber-900');
      pill.textContent = 'غير جاهزة للعرض (' + missing + ')';
      return;
    }
    if (failed > 0 || pending > 0) {
      pill.classList.add('hidden');
      return;
    }
    pill.classList.remove('hidden', 'border-amber-200', 'bg-amber-50', 'text-amber-900');
    pill.classList.add('border-green-200', 'bg-green-50', 'text-green-800');
    pill.textContent = 'الطابور نظيف';
  }

  function applyPayload(data) {
    if (!data || !data.ok) return;

    const needsAttention = !!data.needs_attention;
    banner.classList.toggle('hidden', !needsAttention && (data.worker_enabled !== false));

    if (summaryEl && typeof data.user_summary === 'string' && data.user_summary !== '') {
      summaryEl.textContent = data.user_summary;
    }

    renderFailures(data.worker?.recent_failures || [], data.worker?.recent_repair_failures || []);
    applyQueuePill(data);

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
