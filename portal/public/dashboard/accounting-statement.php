<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;

WebSession::requirePermission('orders.view');
require dirname(__DIR__, 2) . '/views/helpers.php';

$initialState = [
    'customerSearch' => trim((string) ($_GET['customerSearch'] ?? '')),
    'customerGuid' => trim((string) ($_GET['customerGuid'] ?? '')),
    'fromDate' => trim((string) ($_GET['fromDate'] ?? '')),
    'toDate' => trim((string) ($_GET['toDate'] ?? '')),
];

$user = WebSession::user();
$currentRoute = '/dashboard/accounting-statement.php';

ob_start();
?>
<style>
  .customer-suggest-item:hover,
  .customer-suggest-item.active {
    background: #fef2f2;
    color: #D81921;
  }
  .statement-row-openable {
    cursor: pointer;
  }
  .statement-row-openable:hover {
    background: #f9fafb;
  }
  .doc-ref-btn {
    color: #D81921;
    font-weight: 600;
    text-decoration: underline;
    text-underline-offset: 2px;
  }
  .doc-ref-btn:hover {
    color: #b51218;
  }
  #documentModal:not(.hidden) {
    display: flex;
  }
  #documentModal.hidden {
    display: none;
  }
</style>

<section class="bg-white border rounded-xl p-5 mb-4">
  <h1 class="text-xl font-bold mb-2">كشف حساب عميل</h1>
  <p class="text-sm text-gray-600">ابحث بالاسم أو الهاتف — تظهر النتائج تلقائياً، وعند اختيار العميل يُعرض كشف الحساب مباشرة.</p>
</section>

<section class="bg-white border rounded-xl p-4 mb-4">
  <div class="grid md:grid-cols-4 gap-3 items-end">
    <div class="text-sm md:col-span-2 relative">
      <span class="text-gray-600">بحث العميل (الاسم أو الهاتف)</span>
      <input
        type="text"
        id="customerSearchInput"
        value="<?= h($initialState['customerSearch']) ?>"
        class="mt-1 w-full border rounded px-3 py-2"
        placeholder="مثال: أحمد / 0932..."
        autocomplete="off"
      >
      <div id="customerSuggestPanel" class="hidden absolute z-30 mt-1 w-full bg-white border rounded-lg shadow-lg max-h-72 overflow-auto"></div>
    </div>
    <label class="text-sm">
      <span class="text-gray-600">من تاريخ</span>
      <input type="date" id="fromDateInput" value="<?= h($initialState['fromDate']) ?>" class="mt-1 w-full border rounded px-3 py-2">
    </label>
    <label class="text-sm">
      <span class="text-gray-600">إلى تاريخ</span>
      <input type="date" id="toDateInput" value="<?= h($initialState['toDate']) ?>" class="mt-1 w-full border rounded px-3 py-2">
    </label>
  </div>
</section>

<div id="statusBanner" class="hidden mb-4 rounded border px-3 py-2 text-sm"></div>

<section id="selectedCustomerBar" class="hidden mb-4">
  <p class="inline-flex items-center gap-2 rounded-full bg-blue-50 text-blue-700 px-3 py-1 text-sm">
    العميل المحدد: <strong id="selectedCustomerName">—</strong>
    <button type="button" id="clearCustomerBtn" class="text-blue-800 underline">تغيير</button>
  </p>
</section>

<section id="customerResultsSection" class="hidden bg-white border rounded-xl overflow-hidden mb-4">
  <div class="px-4 py-3 border-b bg-gray-50 flex items-center justify-between">
    <h2 class="font-semibold">نتائج البحث</h2>
    <span id="customerResultsCount" class="text-xs text-gray-500"></span>
  </div>
  <div id="customerResultsBody" class="divide-y"></div>
</section>

<section id="summaryCards" class="hidden grid gap-3 grid-cols-2 md:grid-cols-4 mb-4">
  <article class="bg-white border rounded-lg p-3">
    <div class="text-xs text-gray-500">الرصيد الافتتاحي</div>
    <div id="summaryOpening" class="font-bold mt-1">0.00</div>
  </article>
  <article class="bg-white border rounded-lg p-3">
    <div class="text-xs text-gray-500">إجمالي المدين</div>
    <div id="summaryDebit" class="font-bold mt-1">0.00</div>
  </article>
  <article class="bg-white border rounded-lg p-3">
    <div class="text-xs text-gray-500">إجمالي الدائن</div>
    <div id="summaryCredit" class="font-bold mt-1">0.00</div>
  </article>
  <article class="bg-white border rounded-lg p-3">
    <div class="text-xs text-gray-500">الرصيد الختامي</div>
    <div id="summaryClosing" class="font-bold mt-1">0.00</div>
  </article>
</section>

<section class="bg-white border rounded-xl overflow-hidden">
  <div id="statementEmpty" class="p-4 text-sm text-gray-500">ابحث عن العميل بالاسم أو الهاتف ثم اختره من القائمة لعرض كشف الحساب.</div>
  <div id="statementLoading" class="hidden p-8 text-center text-sm text-gray-500">
    <span class="material-symbols-outlined animate-spin inline-block">progress_activity</span>
    جاري تحميل كشف الحساب...
  </div>
  <div id="statementTableWrap" class="hidden overflow-auto">
    <table class="w-full text-sm min-w-[1020px]">
      <thead class="bg-gray-50 text-gray-600 border-b">
        <tr>
          <th class="text-right p-3">التاريخ</th>
          <th class="text-right p-3">الرقم</th>
          <th class="text-right p-3">المدين</th>
          <th class="text-right p-3">الدائن</th>
          <th class="text-right p-3">الرصيد الجاري</th>
          <th class="text-right p-3">نوع السند</th>
          <th class="text-right p-3">المرجع</th>
          <th class="text-right p-3">الحساب المقابل</th>
        </tr>
      </thead>
      <tbody id="statementTableBody"></tbody>
    </table>
  </div>
</section>

<div id="documentModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/45 p-4" role="dialog" aria-modal="true">
  <div class="bg-white border rounded-xl shadow-2xl w-full max-w-5xl max-h-[90vh] overflow-hidden flex flex-col">
    <div class="flex items-center justify-between gap-3 px-5 py-4 border-b bg-gray-50">
      <div>
        <h3 id="modalTitle" class="text-lg font-bold">تفاصيل المستند</h3>
        <p id="modalSubtitle" class="text-xs text-gray-500 mt-0.5"></p>
      </div>
      <button type="button" id="closeDocumentModalBtn" class="inline-flex items-center justify-center w-9 h-9 rounded-full hover:bg-red-50 text-gray-600">
        <span class="material-symbols-outlined">close</span>
      </button>
    </div>
    <div id="modalLoading" class="hidden p-10 text-center text-sm text-gray-500">جاري تحميل التفاصيل...</div>
    <div id="modalContent" class="hidden overflow-auto p-5 space-y-4">
      <div id="modalSummary" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"></div>
      <div class="border rounded-xl overflow-hidden">
        <div class="px-4 py-2 border-b bg-gray-50 font-semibold text-sm" id="modalItemsTitle">البنود</div>
        <div class="overflow-auto">
          <table class="w-full text-sm min-w-[720px]">
            <thead class="bg-gray-50 text-gray-600 border-b" id="modalItemsHead"></thead>
            <tbody id="modalItemsBody"></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(() => {
  const initialState = <?= json_encode($initialState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const apiBase = '/dashboard/accounting-statement-api.php';

  const state = {
    customerSearch: initialState.customerSearch || '',
    customerGuid: initialState.customerGuid || '',
    customerName: '',
    fromDate: initialState.fromDate || '',
    toDate: initialState.toDate || '',
    searchTimer: null,
    statement: null,
    searching: false,
  };

  const ui = {
    searchInput: document.getElementById('customerSearchInput'),
    suggestPanel: document.getElementById('customerSuggestPanel'),
    fromDateInput: document.getElementById('fromDateInput'),
    toDateInput: document.getElementById('toDateInput'),
    statusBanner: document.getElementById('statusBanner'),
    selectedCustomerBar: document.getElementById('selectedCustomerBar'),
    selectedCustomerName: document.getElementById('selectedCustomerName'),
    clearCustomerBtn: document.getElementById('clearCustomerBtn'),
    customerResultsSection: document.getElementById('customerResultsSection'),
    customerResultsBody: document.getElementById('customerResultsBody'),
    customerResultsCount: document.getElementById('customerResultsCount'),
    summaryCards: document.getElementById('summaryCards'),
    summaryOpening: document.getElementById('summaryOpening'),
    summaryDebit: document.getElementById('summaryDebit'),
    summaryCredit: document.getElementById('summaryCredit'),
    summaryClosing: document.getElementById('summaryClosing'),
    statementEmpty: document.getElementById('statementEmpty'),
    statementLoading: document.getElementById('statementLoading'),
    statementTableWrap: document.getElementById('statementTableWrap'),
    statementTableBody: document.getElementById('statementTableBody'),
    documentModal: document.getElementById('documentModal'),
    modalTitle: document.getElementById('modalTitle'),
    modalSubtitle: document.getElementById('modalSubtitle'),
    modalLoading: document.getElementById('modalLoading'),
    modalContent: document.getElementById('modalContent'),
    modalSummary: document.getElementById('modalSummary'),
    modalItemsTitle: document.getElementById('modalItemsTitle'),
    modalItemsHead: document.getElementById('modalItemsHead'),
    modalItemsBody: document.getElementById('modalItemsBody'),
    closeDocumentModalBtn: document.getElementById('closeDocumentModalBtn'),
  };

  function escapeHtml(value) {
    return String(value ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#39;');
  }

  function formatNumber(value) {
    const number = Number(value ?? 0);
    if (!Number.isFinite(number)) return '0.00';
    return number.toLocaleString('ar-SY', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function formatDate(value) {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleDateString('ar-SY');
  }

  function formatMoney(value, symbol, code) {
    const suffix = symbol || code || '';
    return `${formatNumber(value)}${suffix ? ' ' + suffix : ''}`;
  }

  function showStatus(message, isError = false) {
    if (!message) {
      ui.statusBanner.classList.add('hidden');
      ui.statusBanner.textContent = '';
      return;
    }
    ui.statusBanner.classList.remove('hidden');
    ui.statusBanner.textContent = message;
    ui.statusBanner.className = `mb-4 rounded border px-3 py-2 text-sm ${
      isError ? 'bg-red-50 border-red-200 text-red-700' : 'bg-blue-50 border-blue-200 text-blue-700'
    }`;
  }

  async function apiCall(action, params = {}) {
    const query = new URLSearchParams({ action, ...params });
    const response = await fetch(`${apiBase}?${query.toString()}`, {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    });
    const payload = await response.json();
    if (!response.ok || !payload.ok) {
      throw new Error(payload.error || `تعذر تنفيذ الطلب (رمز ${payload.status || response.status})`);
    }
    return payload.data;
  }

  function updateUrl() {
    const params = new URLSearchParams();
    if (state.customerSearch) params.set('customerSearch', state.customerSearch);
    if (state.customerGuid) params.set('customerGuid', state.customerGuid);
    if (state.fromDate) params.set('fromDate', state.fromDate);
    if (state.toDate) params.set('toDate', state.toDate);
    const next = `${window.location.pathname}${params.toString() ? '?' + params.toString() : ''}`;
    window.history.replaceState({}, '', next);
  }

  function reasonToDocumentKind(reasonType) {
    if (reasonType === 'invoice') return 'invoice';
    if (reasonType === 'payment') return 'voucher';
    return '';
  }

  function renderCustomerResults(customers) {
    if (!customers.length) {
      ui.customerResultsSection.classList.remove('hidden');
      ui.customerResultsCount.textContent = '0 نتيجة';
      ui.customerResultsBody.innerHTML = '<p class="p-4 text-sm text-gray-500">لا توجد نتائج مطابقة. جرّب كلمة أخرى.</p>';
      return;
    }

    ui.customerResultsSection.classList.remove('hidden');
    ui.customerResultsCount.textContent = `${customers.length} نتيجة`;
    ui.customerResultsBody.innerHTML = customers.map((customer) => `
      <button
        type="button"
        class="customer-suggest-item w-full text-right px-4 py-3 flex items-center justify-between gap-3 hover:bg-red-50 transition"
        data-guid="${escapeHtml(customer.guid)}"
        data-name="${escapeHtml(customer.customerName || '')}"
      >
        <span>
          <span class="font-semibold block">${escapeHtml(customer.customerName || '—')}</span>
          <span class="text-xs text-gray-500">${escapeHtml(customer.mobile || customer.phone1 || '—')}</span>
        </span>
        <span class="text-xs rounded-full bg-primary/10 text-primary px-2 py-1">عرض الكشف</span>
      </button>
    `).join('');

    ui.customerResultsBody.querySelectorAll('[data-guid]').forEach((button) => {
      button.addEventListener('click', () => selectCustomer(button.dataset.guid, button.dataset.name));
    });
  }

  function renderSuggestPanel(customers) {
    if (!customers.length) {
      ui.suggestPanel.classList.add('hidden');
      ui.suggestPanel.innerHTML = '';
      return;
    }

    ui.suggestPanel.classList.remove('hidden');
    ui.suggestPanel.innerHTML = customers.slice(0, 8).map((customer) => `
      <button
        type="button"
        class="customer-suggest-item w-full text-right px-3 py-2 text-sm border-b last:border-0"
        data-guid="${escapeHtml(customer.guid)}"
        data-name="${escapeHtml(customer.customerName || '')}"
      >
        <span class="font-semibold">${escapeHtml(customer.customerName || '—')}</span>
        <span class="text-xs text-gray-500 block">${escapeHtml(customer.mobile || customer.phone1 || '—')}</span>
      </button>
    `).join('');

    ui.suggestPanel.querySelectorAll('[data-guid]').forEach((button) => {
      button.addEventListener('click', () => {
        ui.suggestPanel.classList.add('hidden');
        selectCustomer(button.dataset.guid, button.dataset.name);
      });
    });
  }

  async function searchCustomers(term) {
    const search = term.trim();
    if (search.length < 2) {
      ui.customerResultsSection.classList.add('hidden');
      ui.suggestPanel.classList.add('hidden');
      return;
    }

    if (state.searching) return;
    state.searching = true;
    try {
      const data = await apiCall('customers', { search, pageSize: 20 });
      const customers = data.items || [];
      renderSuggestPanel(customers);
      if (!state.customerGuid) {
        renderCustomerResults(customers);
      }
    } catch (error) {
      showStatus(error.message, true);
    } finally {
      state.searching = false;
    }
  }

  function clearCustomerSelection() {
    state.customerGuid = '';
    state.customerName = '';
    state.statement = null;
    ui.selectedCustomerBar.classList.add('hidden');
    ui.summaryCards.classList.add('hidden');
    ui.statementTableWrap.classList.add('hidden');
    ui.statementLoading.classList.add('hidden');
    ui.statementEmpty.classList.remove('hidden');
    ui.statementEmpty.textContent = 'ابحث عن العميل بالاسم أو الهاتف ثم اختره من القائمة لعرض كشف الحساب.';
    updateUrl();
    if (state.customerSearch.trim().length >= 2) {
      searchCustomers(state.customerSearch);
    }
  }

  async function selectCustomer(guid, name) {
    state.customerGuid = guid;
    state.customerName = name || '';
    state.customerSearch = name || state.customerSearch;
    ui.searchInput.value = state.customerSearch;
    ui.selectedCustomerName.textContent = state.customerName || 'عميل';
    ui.selectedCustomerBar.classList.remove('hidden');
    ui.customerResultsSection.classList.add('hidden');
    ui.suggestPanel.classList.add('hidden');
    updateUrl();
    await loadStatement();
  }

  function renderSummary(statement, entries) {
    const opening = Number(statement.openingBalance ?? 0);
    let totalDebit = 0;
    let totalCredit = 0;
    entries.forEach((entry) => {
      totalDebit += Number(entry.debit ?? 0);
      totalCredit += Number(entry.credit ?? 0);
    });
    const closing = entries.length
      ? Number(entries[entries.length - 1].runningBalance ?? opening)
      : opening;

    ui.summaryOpening.textContent = formatMoney(opening, statement.accountCurrencySymbol, statement.accountCurrencyCode);
    ui.summaryDebit.textContent = formatMoney(totalDebit, statement.accountCurrencySymbol, statement.accountCurrencyCode);
    ui.summaryCredit.textContent = formatMoney(totalCredit, statement.accountCurrencySymbol, statement.accountCurrencyCode);
    ui.summaryClosing.textContent = formatMoney(closing, statement.accountCurrencySymbol, statement.accountCurrencyCode);
    ui.summaryCards.classList.remove('hidden');
  }

  function renderStatementRows(statement) {
    const entries = statement.entries || [];
    const currencySymbol = statement.accountCurrencySymbol;
    const currencyCode = statement.accountCurrencyCode;

    if (!entries.length) {
      ui.statementEmpty.classList.remove('hidden');
      ui.statementEmpty.textContent = 'لا توجد حركات في الفترة المحددة.';
      ui.statementTableWrap.classList.add('hidden');
      ui.summaryCards.classList.add('hidden');
      return;
    }

    ui.statementEmpty.classList.add('hidden');
    ui.statementTableWrap.classList.remove('hidden');
    renderSummary(statement, entries);

    ui.statementTableBody.innerHTML = entries.map((entry) => {
      const kind = reasonToDocumentKind(entry.reasonType);
      const refGuid = entry.referenceGuid || '';
      const openable = Boolean(kind && refGuid);
      const refLabel = [entry.reasonDocumentType, entry.referenceNumber].filter(Boolean).join(' ') || '—';
      const contra = [entry.contraAccountNumber, entry.contraAccountName || entry.contraAccountCode].filter(Boolean).join(' - ') || '—';
      const movementSymbol = entry.movementCurrencySymbol || currencySymbol;
      const movementCode = entry.movementCurrencyCode || currencyCode;

      return `
        <tr
          class="border-b last:border-0 ${openable ? 'statement-row-openable' : ''}"
          ${openable ? `data-ref-guid="${escapeHtml(refGuid)}" data-ref-kind="${escapeHtml(kind)}" title="اضغط لفتح المستند"` : ''}
        >
          <td class="p-3">${escapeHtml(formatDate(entry.entryDate ?? entry.date))}</td>
          <td class="p-3">${escapeHtml(entry.entryNumber ?? entry.number ?? '—')}</td>
          <td class="p-3">${escapeHtml(formatMoney(entry.debit, movementSymbol, movementCode))}</td>
          <td class="p-3">${escapeHtml(formatMoney(entry.credit, movementSymbol, movementCode))}</td>
          <td class="p-3 font-semibold">${escapeHtml(formatMoney(entry.runningBalance, currencySymbol, currencyCode))}</td>
          <td class="p-3">${escapeHtml(entry.reasonDocumentType || entry.reasonType || '—')}</td>
          <td class="p-3">
            ${openable
              ? `<button type="button" class="doc-ref-btn" data-ref-guid="${escapeHtml(refGuid)}" data-ref-kind="${escapeHtml(kind)}">${escapeHtml(refLabel)}</button>`
              : escapeHtml(refLabel)}
          </td>
          <td class="p-3">${escapeHtml(contra)}</td>
        </tr>
      `;
    }).join('');

    ui.statementTableBody.querySelectorAll('[data-ref-guid]').forEach((element) => {
      element.addEventListener('click', (event) => {
        event.stopPropagation();
        openDocumentModal(element.dataset.refGuid, element.dataset.refKind);
      });
    });
  }

  async function loadStatement() {
    if (!state.customerGuid) return;

    showStatus('');
    ui.statementEmpty.classList.add('hidden');
    ui.statementTableWrap.classList.add('hidden');
    ui.summaryCards.classList.add('hidden');
    ui.statementLoading.classList.remove('hidden');

    try {
      const data = await apiCall('statement', {
        customerGuid: state.customerGuid,
        fromDate: state.fromDate,
        toDate: state.toDate,
        pageSize: 100,
      });
      state.statement = data;
      if (data.customerName) {
        state.customerName = data.customerName;
        ui.selectedCustomerName.textContent = data.customerName;
      }
      renderStatementRows(data);
    } catch (error) {
      showStatus(error.message, true);
      ui.statementEmpty.classList.remove('hidden');
      ui.statementEmpty.textContent = 'تعذر تحميل كشف الحساب.';
    } finally {
      ui.statementLoading.classList.add('hidden');
    }
  }

  function summaryCard(label, value) {
    return `
      <article class="bg-gray-50 border rounded-lg p-3">
        <div class="text-xs text-gray-500">${escapeHtml(label)}</div>
        <div class="font-bold mt-1 text-sm">${escapeHtml(value)}</div>
      </article>
    `;
  }

  async function openDocumentModal(guid, kind) {
    ui.documentModal.classList.remove('hidden');
    ui.modalContent.classList.add('hidden');
    ui.modalLoading.classList.remove('hidden');
    document.body.style.overflow = 'hidden';

    try {
      const data = await apiCall(kind, { guid });
      const document = data.document || {};
      const isInvoice = kind === 'invoice';
      ui.modalTitle.textContent = `${isInvoice ? 'تفاصيل الفاتورة' : 'تفاصيل السند'} رقم ${document.number ?? '—'}`;
      ui.modalSubtitle.textContent = [
        document.typeName || document.typeCode,
        formatDate(document.date),
        document.customerName,
      ].filter(Boolean).join(' • ');

      const summaryItems = [
        summaryCard('النوع', document.typeName || document.typeCode || '—'),
        summaryCard('التسوية', document.settlementTypeName || '—'),
        summaryCard('العملة', [document.currencyName, document.currencyCode, document.currencySymbol].filter(Boolean).join(' - ') || '—'),
        summaryCard('سعر التعادل', formatNumber(document.currencyRate)),
        summaryCard('العميل', document.customerName || '—'),
        summaryCard('الحساب', [document.accountNumber, document.accountName].filter(Boolean).join(' - ') || '—'),
        summaryCard('الإجمالي', formatMoney(document.totalAmount, document.currencySymbol, document.currencyCode)),
        summaryCard('الحسم', formatMoney(document.totalDiscount, document.currencySymbol, document.currencyCode)),
        summaryCard('الإضافات', formatMoney(document.totalAdditions, document.currencySymbol, document.currencyCode)),
        summaryCard('الصافي', formatMoney(document.netAmount, document.currencySymbol, document.currencyCode)),
        summaryCard('عدد البنود', String(data.linesCount ?? (isInvoice ? (data.items || []).length : (data.entryLines || []).length))),
      ];

      if (isInvoice) {
        summaryItems.push(
          summaryCard('إجمالي الكمية', formatNumber(data.totalQuantity)),
          summaryCard('عدد الأزواج', formatNumber(document.pairsCount)),
          summaryCard('عدد الأقلام', formatNumber(document.pensCount)),
        );
      } else if (data.totalQuantity != null) {
        summaryItems.push(summaryCard('إجمالي السند', formatMoney(data.totalQuantity, document.currencySymbol, document.currencyCode)));
      }

      ui.modalSummary.innerHTML = summaryItems.join('');

      if (isInvoice) {
        ui.modalItemsTitle.textContent = 'بنود الفاتورة';
        ui.modalItemsHead.innerHTML = `
          <tr>
            <th class="text-right p-3">المادة</th>
            <th class="text-right p-3">كمية (و1)</th>
            <th class="text-right p-3">كمية (و2)</th>
            <th class="text-right p-3">سعر القطعة</th>
            <th class="text-right p-3">حسم</th>
            <th class="text-right p-3">إضافة</th>
            <th class="text-right p-3">إجمالي</th>
          </tr>
        `;
        const items = data.items || [];
        ui.modalItemsBody.innerHTML = items.length
          ? items.map((item) => `
            <tr class="border-b last:border-0">
              <td class="p-3">${escapeHtml(item.materialName || item.materialCode || '—')}</td>
              <td class="p-3">${escapeHtml(formatNumber(item.quantityUnit1 ?? item.quantity))}</td>
              <td class="p-3">${escapeHtml(formatNumber(item.quantityUnit2))}</td>
              <td class="p-3">${escapeHtml(formatMoney(item.unitPriceUnit1 ?? item.price, document.currencySymbol, document.currencyCode))}</td>
              <td class="p-3">${escapeHtml(formatMoney(item.discount, document.currencySymbol, document.currencyCode))}</td>
              <td class="p-3">${escapeHtml(formatMoney(item.additions, document.currencySymbol, document.currencyCode))}</td>
              <td class="p-3 font-semibold">${escapeHtml(formatMoney(item.lineTotal, document.currencySymbol, document.currencyCode))}</td>
            </tr>
          `).join('')
          : '<tr><td colspan="7" class="p-4 text-center text-gray-500">لا توجد بنود.</td></tr>';
      } else {
        ui.modalItemsTitle.textContent = 'قيود السند';
        ui.modalItemsHead.innerHTML = `
          <tr>
            <th class="text-right p-3">رقم</th>
            <th class="text-right p-3">حساب</th>
            <th class="text-right p-3">مقابل</th>
            <th class="text-right p-3">مدين</th>
            <th class="text-right p-3">دائن</th>
            <th class="text-right p-3">التعادل</th>
            <th class="text-right p-3">عميل</th>
            <th class="text-right p-3">ملاحظات</th>
          </tr>
        `;
        const entryLines = data.entryLines || [];
        ui.modalItemsBody.innerHTML = entryLines.length
          ? entryLines.map((line) => {
            const equivalent = line.equivalentValue != null
              ? formatMoney(line.equivalentValue, line.equivalentCurrencySymbol, line.equivalentCurrencyCode)
              : '—';
            return `
              <tr class="border-b last:border-0">
                <td class="p-3">${escapeHtml(line.number ?? '—')}</td>
                <td class="p-3">${escapeHtml([line.accountNumber, line.accountName || line.accountCode].filter(Boolean).join(' - ') || '—')}</td>
                <td class="p-3">${escapeHtml([line.contraAccountNumber, line.contraAccountName || line.contraAccountCode].filter(Boolean).join(' - ') || '—')}</td>
                <td class="p-3">${escapeHtml(formatMoney(line.debit, document.currencySymbol, document.currencyCode))}</td>
                <td class="p-3">${escapeHtml(formatMoney(line.credit, document.currencySymbol, document.currencyCode))}</td>
                <td class="p-3">${escapeHtml(equivalent)}</td>
                <td class="p-3">${escapeHtml(line.customerName || '—')}</td>
                <td class="p-3">${escapeHtml(line.notes || '—')}</td>
              </tr>
            `;
          }).join('')
          : '<tr><td colspan="8" class="p-4 text-center text-gray-500">لا توجد قيود.</td></tr>';
      }

      ui.modalLoading.classList.add('hidden');
      ui.modalContent.classList.remove('hidden');
    } catch (error) {
      ui.modalLoading.classList.add('hidden');
      ui.modalContent.classList.remove('hidden');
      ui.modalSummary.innerHTML = `<p class="text-sm text-red-700">${escapeHtml(error.message)}</p>`;
      ui.modalItemsBody.innerHTML = '';
      ui.modalItemsHead.innerHTML = '';
    }
  }

  function closeDocumentModal() {
    ui.documentModal.classList.add('hidden');
    document.body.style.overflow = '';
  }

  ui.searchInput.addEventListener('input', () => {
    state.customerSearch = ui.searchInput.value;
    if (state.customerGuid && state.customerSearch.trim() !== state.customerName.trim()) {
      state.customerGuid = '';
      state.customerName = '';
      ui.selectedCustomerBar.classList.add('hidden');
      ui.summaryCards.classList.add('hidden');
      ui.statementTableWrap.classList.add('hidden');
      ui.statementEmpty.classList.remove('hidden');
      ui.statementEmpty.textContent = 'اختر عميلاً من القائمة لعرض كشف الحساب.';
    }
    clearTimeout(state.searchTimer);
    state.searchTimer = setTimeout(() => searchCustomers(state.customerSearch), 300);
    updateUrl();
  });

  ui.searchInput.addEventListener('focus', () => {
    if (ui.suggestPanel.innerHTML.trim() !== '') {
      ui.suggestPanel.classList.remove('hidden');
    }
  });

  document.addEventListener('click', (event) => {
    if (!ui.suggestPanel.contains(event.target) && event.target !== ui.searchInput) {
      ui.suggestPanel.classList.add('hidden');
    }
  });

  ui.fromDateInput.addEventListener('change', () => {
    state.fromDate = ui.fromDateInput.value;
    updateUrl();
    if (state.customerGuid) loadStatement();
  });

  ui.toDateInput.addEventListener('change', () => {
    state.toDate = ui.toDateInput.value;
    updateUrl();
    if (state.customerGuid) loadStatement();
  });

  ui.clearCustomerBtn.addEventListener('click', clearCustomerSelection);
  ui.closeDocumentModalBtn.addEventListener('click', closeDocumentModal);
  ui.documentModal.addEventListener('click', (event) => {
    if (event.target === ui.documentModal) closeDocumentModal();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeDocumentModal();
  });

  if (state.customerGuid) {
    selectCustomer(state.customerGuid, state.customerSearch);
  } else if (state.customerSearch.trim().length >= 2) {
    searchCustomers(state.customerSearch);
  }
})();
</script>
<?php
$content = ob_get_clean();
$title = 'كشف حساب عميل';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
