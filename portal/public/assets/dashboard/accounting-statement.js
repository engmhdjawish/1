/**
 * كشف حساب — بحث تلقائي، اقتراحات، ونافذة تفاصيل المستند.
 * يُعاد تهيئته بعد التنقل عبر AJAX في لوحة التحكم.
 */
(function () {
  'use strict';

  const API_BASE = '/dashboard/accounting-statement-api.php';

  function escapeHtml(value) {
    return String(value ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#39;');
  }

  function formatDecimal(value, decimals = 2) {
    if (value === null || value === undefined || value === '') return '—';
    const number = Number(value);
    if (!Number.isFinite(number)) return String(value);
    return number.toLocaleString('en-US', {
      minimumFractionDigits: decimals,
      maximumFractionDigits: decimals,
    });
  }

  function formatDate(value) {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleDateString('ar-SY');
  }

  function formatAccountingMoney(value, symbol, code) {
    const formatted = formatDecimal(value);
    if (formatted === '—') return formatted;
    const suffix = String(symbol ?? '').trim() || String(code ?? '').trim();
    return suffix ? `${formatted} ${suffix}` : formatted;
  }

  function formatEntryAmount(value) {
    const number = Number(value ?? 0);
    if (!Number.isFinite(number) || number <= 0) return '—';
    return formatDecimal(number);
  }

  function formatMaterialLabel(item) {
    const code = String(item.materialCode ?? '').trim();
    const name = String(item.materialName ?? '').trim();
    if (code && name) return `${code} - ${name}`;
    return name || code || '—';
  }

  function resolveUnitHeader(items, field, fallback) {
    for (const item of items) {
      const value = String(item[field] ?? '').trim();
      if (value) return value;
    }
    return fallback;
  }

  function invoiceRowClass(index) {
    return `${index % 2 === 0 ? 'bg-white' : 'bg-surface-low/40'} border-b border-border-subtle last:border-0 hover:bg-primary/5 transition-colors`;
  }

  async function apiCall(action, params = {}) {
    const query = new URLSearchParams({ action, ...params });
    const response = await fetch(`${API_BASE}?${query.toString()}`, {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    });
    const payload = await response.json();
    if (!response.ok || !payload.ok) {
      throw new Error(payload.error || 'تعذر تنفيذ الطلب');
    }
    return payload.data;
  }

  function buildStatementUrl(params) {
    const query = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
      if (value !== null && value !== undefined && String(value).trim() !== '') {
        query.set(key, String(value));
      }
    });
    return '/dashboard/accounting-statement.php' + (query.toString() ? '?' + query.toString() : '');
  }

  function navigateToStatement(params) {
    const url = buildStatementUrl(params);
    if (window.dashboardApp && typeof window.dashboardApp.navigate === 'function') {
      window.dashboardApp.navigate(url);
      return;
    }
    window.location.href = url;
  }

  function submitStatementForm(form) {
    if (!form) return;
    if (form.hasAttribute('data-dashboard-filter') && window.dashboardApp?.navigate) {
      const params = new URLSearchParams(new FormData(form));
      window.dashboardApp.navigate('/dashboard/accounting-statement.php?' + params.toString());
      return;
    }
    form.submit();
  }

  window.portalAccountingStatementInit = function portalAccountingStatementInit(root = document) {
    if (window.__accountingStatementAbort) {
      window.__accountingStatementAbort.abort();
      window.__accountingStatementAbort = null;
    }

    const page = root.querySelector('[data-accounting-statement-page]');
    if (!page) return;

    const abort = new AbortController();
    window.__accountingStatementAbort = abort;
    const signal = abort.signal;

    const form = page.querySelector('#accountingStatementForm');
    const customerSearchInput = page.querySelector('#customerSearchInput');
    const accountSearchInput = page.querySelector('#accountSearchInput');
    const customerGuidHidden = page.querySelector('#customerGuidHidden');
    const accountGuidHidden = page.querySelector('#accountGuidHidden');
    const customerSuggestPanel = page.querySelector('#customerSuggestPanel');
    const accountSuggestPanel = page.querySelector('#accountSuggestPanel');
    const fromDateInput = form?.querySelector('input[name="fromDate"]');
    const toDateInput = form?.querySelector('input[name="toDate"]');

    let customerTimer = null;
    let accountTimer = null;

    function renderSuggestPanel(panel, items, onSelect) {
      if (!panel) return;
      if (!items.length) {
        panel.classList.add('hidden');
        panel.innerHTML = '';
        return;
      }
      panel.classList.remove('hidden');
      panel.innerHTML = items.map((item) => `
        <button type="button" class="w-full text-right px-3 py-2 text-sm border-b border-border-subtle last:border-0 hover:bg-primary/5 transition">
          <span class="font-semibold block">${escapeHtml(item.label)}</span>
          ${item.hint ? `<span class="text-xs text-text-muted block">${escapeHtml(item.hint)}</span>` : ''}
        </button>
      `).join('');
      panel.querySelectorAll('button').forEach((button, index) => {
        button.addEventListener('click', () => {
          panel.classList.add('hidden');
          onSelect(items[index]);
        }, { signal });
      });
    }

    async function searchCustomers(term) {
      const search = term.trim();
      if (search.length < 2) {
        customerSuggestPanel?.classList.add('hidden');
        return;
      }
      try {
        const data = await apiCall('customers', { search, pageSize: 20 });
        const customers = (data.items || []).map((customer) => ({
          guid: customer.guid,
          label: customer.customerName || '—',
          hint: customer.mobile || customer.phone1 || '',
          search: customer.customerName || search,
        }));
        renderSuggestPanel(customerSuggestPanel, customers, (item) => {
          navigateToStatement({
            customerSearch: item.search,
            customerGuid: item.guid,
            accountSearch: accountSearchInput?.value || '',
            fromDate: fromDateInput?.value || '',
            toDate: toDateInput?.value || '',
            page: 1,
          });
        });
      } catch (error) {
        if (window.dashboardApp?.showToast) {
          window.dashboardApp.showToast(error.message || 'تعذر البحث عن العملاء.', 'error');
        }
      }
    }

    async function searchAccounts(term) {
      const search = term.trim();
      if (search.length < 2) {
        accountSuggestPanel?.classList.add('hidden');
        return;
      }
      try {
        const data = await apiCall('accounts', { search, pageSize: 20 });
        const accounts = (data.items || []).map((account) => {
          const label = account.name || account.code || account.number || '—';
          return {
            guid: account.guid,
            label,
            hint: [account.code, account.number].filter(Boolean).join(' · '),
            search: label,
          };
        });
        renderSuggestPanel(accountSuggestPanel, accounts, (item) => {
          navigateToStatement({
            accountSearch: item.search,
            accountGuid: item.guid,
            customerSearch: customerSearchInput?.value || '',
            fromDate: fromDateInput?.value || '',
            toDate: toDateInput?.value || '',
            page: 1,
          });
        });
      } catch (error) {
        if (window.dashboardApp?.showToast) {
          window.dashboardApp.showToast(error.message || 'تعذر البحث عن الحسابات.', 'error');
        }
      }
    }

    if (customerSearchInput) {
      const originalCustomer = customerSearchInput.value.trim();
      customerSearchInput.addEventListener('input', () => {
        if (customerGuidHidden && customerSearchInput.value.trim() !== originalCustomer) {
          customerGuidHidden.value = '';
        }
        clearTimeout(customerTimer);
        customerTimer = setTimeout(() => searchCustomers(customerSearchInput.value), 300);
      }, { signal });
    }

    if (accountSearchInput) {
      const originalAccount = accountSearchInput.value.trim();
      accountSearchInput.addEventListener('input', () => {
        if (accountGuidHidden && accountSearchInput.value.trim() !== originalAccount) {
          accountGuidHidden.value = '';
        }
        clearTimeout(accountTimer);
        accountTimer = setTimeout(() => searchAccounts(accountSearchInput.value), 300);
      }, { signal });
    }

    document.addEventListener('click', (event) => {
      if (customerSuggestPanel && !customerSuggestPanel.contains(event.target) && event.target !== customerSearchInput) {
        customerSuggestPanel.classList.add('hidden');
      }
      if (accountSuggestPanel && !accountSuggestPanel.contains(event.target) && event.target !== accountSearchInput) {
        accountSuggestPanel.classList.add('hidden');
      }
    }, { signal });

    fromDateInput?.addEventListener('change', () => {
      if (customerGuidHidden?.value || accountGuidHidden?.value) {
        submitStatementForm(form);
      }
    }, { signal });

    toDateInput?.addEventListener('change', () => {
      if (customerGuidHidden?.value || accountGuidHidden?.value) {
        submitStatementForm(form);
      }
    }, { signal });

    const modal = page.querySelector('#documentModal');
    const modalTitle = page.querySelector('#modalTitle');
    const modalSubtitle = page.querySelector('#modalSubtitle');
    const modalLoading = page.querySelector('#modalLoading');
    const modalContent = page.querySelector('#modalContent');
    const modalSummary = page.querySelector('#modalSummary');
    const modalItemsTitle = page.querySelector('#modalItemsTitle');
    const modalItemsHead = page.querySelector('#modalItemsHead');
    const modalItemsBody = page.querySelector('#modalItemsBody');

    function summaryCard(label, value) {
      return `
        <article class="rounded-xl border border-border-subtle bg-surface-low/40 p-3">
          <div class="text-xs text-text-muted">${escapeHtml(label)}</div>
          <div class="font-bold mt-1 text-sm">${escapeHtml(value)}</div>
        </article>
      `;
    }

    function closeDocumentModal() {
      modal?.classList.add('hidden');
      modal?.classList.remove('flex');
      document.body.style.overflow = '';
    }

    async function openDocumentModal(guid, kind) {
      if (!modal) return;
      modal.classList.remove('hidden');
      modal.classList.add('flex');
      modalContent?.classList.add('hidden');
      modalLoading?.classList.remove('hidden');
      document.body.style.overflow = 'hidden';

      try {
        const data = await apiCall(kind, { guid });
        const document = data.document || {};
        const isInvoice = kind === 'invoice';
        if (modalTitle) {
          modalTitle.textContent = `${isInvoice ? 'تفاصيل الفاتورة' : 'تفاصيل السند'} رقم ${document.number ?? '—'}`;
        }
        if (modalSubtitle) {
          modalSubtitle.textContent = [
            document.typeName || document.typeCode,
            formatDate(document.date),
            document.customerName,
          ].filter(Boolean).join(' • ');
        }

        const summaryItems = [
          summaryCard('النوع', document.typeName || document.typeCode || '—'),
          summaryCard('التسوية', document.settlementTypeName || '—'),
          summaryCard('العملة', [document.currencyName, document.currencyCode, document.currencySymbol].filter(Boolean).join(' - ') || '—'),
          summaryCard('سعر التعادل', formatDecimal(document.currencyRate)),
          summaryCard('العميل', document.customerName || '—'),
          summaryCard('الحساب', [document.accountNumber, document.accountName].filter(Boolean).join(' - ') || '—'),
          summaryCard('الإجمالي', formatAccountingMoney(document.totalAmount, document.currencySymbol, document.currencyCode)),
          summaryCard('الحسم', formatAccountingMoney(document.totalDiscount, document.currencySymbol, document.currencyCode)),
          summaryCard('الإضافات', formatAccountingMoney(document.totalAdditions, document.currencySymbol, document.currencyCode)),
          summaryCard('الصافي', formatAccountingMoney(document.netAmount, document.currencySymbol, document.currencyCode)),
        ];
        if (modalSummary) modalSummary.innerHTML = summaryItems.join('');

        if (isInvoice) {
          if (modalItemsTitle) modalItemsTitle.textContent = 'بنود الفاتورة';
          const items = data.items || [];
          const unit2Header = resolveUnitHeader(items, 'materialUnit2', 'الوحدة الثانية');
          const unit1Header = resolveUnitHeader(items, 'materialUnity', 'الوحدة الأولى');
          if (modalItemsHead) {
            modalItemsHead.innerHTML = `
              <tr>
                <th class="text-right p-3 whitespace-nowrap">#</th>
                <th class="text-right p-3 whitespace-nowrap">المادة</th>
                <th class="text-right p-3 whitespace-nowrap">${escapeHtml(unit2Header)}</th>
                <th class="text-right p-3 whitespace-nowrap">${escapeHtml(unit1Header)}</th>
                <th class="text-right p-3 whitespace-nowrap">سعر القطعة</th>
                <th class="text-right p-3 whitespace-nowrap">الإجمالي</th>
              </tr>
            `;
          }
          if (modalItemsBody) {
            modalItemsBody.innerHTML = items.length
              ? items.map((item, index) => `
                <tr class="${invoiceRowClass(index)}">
                  <td class="p-3 whitespace-nowrap text-text-muted tabular-nums">${escapeHtml(String(index + 1))}</td>
                  <td class="p-3 font-semibold">${escapeHtml(formatMaterialLabel(item))}</td>
                  <td class="p-3 whitespace-nowrap tabular-nums">${escapeHtml(formatDecimal(item.quantityUnit2))}</td>
                  <td class="p-3 whitespace-nowrap tabular-nums">${escapeHtml(formatDecimal(item.quantityUnit1 ?? item.quantity))}</td>
                  <td class="p-3 whitespace-nowrap tabular-nums">${escapeHtml(formatAccountingMoney(item.unitPriceUnit1 ?? item.price, document.currencySymbol, document.currencyCode))}</td>
                  <td class="p-3 whitespace-nowrap tabular-nums font-bold">${escapeHtml(formatAccountingMoney(item.lineTotal, document.currencySymbol, document.currencyCode))}</td>
                </tr>
              `).join('')
              : '<tr><td colspan="6" class="p-4 text-center text-text-muted">لا توجد بنود.</td></tr>';
          }
        } else {
          if (modalItemsTitle) modalItemsTitle.textContent = 'قيود السند';
          if (modalItemsHead) {
            modalItemsHead.innerHTML = '<tr><th class="text-right p-3">رقم</th><th class="text-right p-3">حساب</th><th class="text-right p-3">مقابل</th><th class="text-right p-3">مدين</th><th class="text-right p-3">دائن</th><th class="text-right p-3">التعادل</th><th class="text-right p-3">عميل</th><th class="text-right p-3">ملاحظات</th></tr>';
          }
          const entryLines = data.entryLines || [];
          if (modalItemsBody) {
            modalItemsBody.innerHTML = entryLines.length
              ? entryLines.map((line) => {
                const debit = Number(line.debit ?? 0);
                const credit = Number(line.credit ?? 0);
                const equivalent = line.equivalentValue != null
                  ? formatAccountingMoney(line.equivalentValue, line.equivalentCurrencySymbol, line.equivalentCurrencyCode)
                  : '—';
                return `
                  <tr class="border-b border-border-subtle last:border-0">
                    <td class="p-3">${escapeHtml(line.number ?? '—')}</td>
                    <td class="p-3">${escapeHtml([line.accountNumber, line.accountName || line.accountCode].filter(Boolean).join(' - ') || '—')}</td>
                    <td class="p-3">${escapeHtml([line.contraAccountNumber, line.contraAccountName || line.contraAccountCode].filter(Boolean).join(' - ') || '—')}</td>
                    <td class="p-3 whitespace-nowrap tabular-nums ${debit > 0 ? 'text-emerald-700 font-semibold' : 'text-text-muted'}">${escapeHtml(formatEntryAmount(line.debit))}</td>
                    <td class="p-3 whitespace-nowrap tabular-nums ${credit > 0 ? 'text-rose-700 font-semibold' : 'text-text-muted'}">${escapeHtml(formatEntryAmount(line.credit))}</td>
                    <td class="p-3">${escapeHtml(equivalent)}</td>
                    <td class="p-3">${escapeHtml(line.customerName || '—')}</td>
                    <td class="p-3">${escapeHtml(line.notes || '—')}</td>
                  </tr>
                `;
              }).join('')
              : '<tr><td colspan="8" class="p-4 text-center text-text-muted">لا توجد قيود.</td></tr>';
          }
        }

        modalLoading?.classList.add('hidden');
        modalContent?.classList.remove('hidden');
      } catch (error) {
        modalLoading?.classList.add('hidden');
        modalContent?.classList.remove('hidden');
        if (modalSummary) {
          modalSummary.innerHTML = `<p class="text-sm text-red-700">${escapeHtml(error.message)}</p>`;
        }
        if (modalItemsBody) modalItemsBody.innerHTML = '';
        if (modalItemsHead) modalItemsHead.innerHTML = '';
      }
    }

    page.querySelectorAll('.doc-ref-btn').forEach((button) => {
      button.addEventListener('click', () => openDocumentModal(button.dataset.refGuid, button.dataset.refKind), { signal });
    });

    page.querySelector('#closeDocumentModalBtn')?.addEventListener('click', closeDocumentModal, { signal });
    modal?.addEventListener('click', (event) => {
      if (event.target === modal) closeDocumentModal();
    }, { signal });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') closeDocumentModal();
    }, { signal });
  };
})();
