(function () {
  const storage = {
    baseUrl: "portal.baseUrl",
    accessToken: "portal.accessToken",
    refreshToken: "portal.refreshToken"
  };

  const state = {
    baseUrl: normalizeUrl(localStorage.getItem(storage.baseUrl) || window.location.origin),
    accessToken: localStorage.getItem(storage.accessToken) || "",
    refreshToken: localStorage.getItem(storage.refreshToken) || "",
    loading: 0,
    activeModule: "overview",
    documents: {
      mode: "invoices",
      page: 1,
      pageSize: 50,
      totalCount: 0,
      search: "",
      type: "",
      fromDate: "",
      toDate: "",
      typeGuid: "",
      selectedGuid: "",
      rows: [],
      types: []
    },
    materials: {
      page: 1,
      pageSize: 40,
      search: "",
      selectedGuid: "",
      rows: []
    },
    customers: {
      page: 1,
      pageSize: 50,
      search: "",
      selectedCustomerGuid: "",
      selectedAccountGuid: ""
    },
    accountsDirectory: {
      page: 1,
      pageSize: 50,
      search: "",
      selectedAccountGuid: ""
    }
  };

  const ui = {
    baseUrlInput: document.getElementById("baseUrlInput"),
    authPill: document.getElementById("authPill"),
    toast: document.getElementById("toast"),
    loadingOverlay: document.getElementById("loadingOverlay"),
    documentModal: document.getElementById("documentModal"),
    modalTitle: document.getElementById("modalTitle"),
    modalSummary: document.getElementById("modalSummary"),
    modalItemsTable: document.querySelector("#modalItemsTable tbody"),
    modalItemsHead: document.querySelector("#modalItemsTable thead tr")
  };

  function normalizeUrl(value) {
    return String(value || "").trim().replace(/\/+$/, "");
  }

  function saveSession() {
    localStorage.setItem(storage.baseUrl, state.baseUrl);
    localStorage.setItem(storage.accessToken, state.accessToken);
    localStorage.setItem(storage.refreshToken, state.refreshToken);
  }

  function safeHtml(value) {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function formatDate(value) {
    if (!value) return "-";
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return "-";
    return date.toLocaleDateString("ar-SY");
  }

  function formatNumber(value) {
    if (value === null || value === undefined || value === "") return "-";
    const parsed = Number(value);
    if (Number.isNaN(parsed)) return String(value);
    return parsed.toLocaleString("ar-SY", { maximumFractionDigits: 2 });
  }

  function formatMoney(value, currencySymbol, currencyCode) {
    const numberValue = formatNumber(value);
    if (numberValue === "-") {
      return "-";
    }

    const symbol = String(currencySymbol || "").trim();
    if (symbol) {
      return `${numberValue} ${symbol}`;
    }

    const code = String(currencyCode || "").trim();
    if (code) {
      return `${numberValue} ${code}`;
    }

    return numberValue;
  }

  function showToast(message, isError) {
    ui.toast.textContent = message;
    ui.toast.style.borderColor = isError ? "#b91c1c" : "#16a34a";
    ui.toast.classList.add("show");
    clearTimeout(showToast.timer);
    showToast.timer = setTimeout(() => ui.toast.classList.remove("show"), 2600);
  }

  function setRaw(value) {
    void value;
  }

  function setLoading(active) {
    state.loading += active ? 1 : -1;
    if (state.loading < 0) {
      state.loading = 0;
    }

    ui.loadingOverlay.classList.toggle("hidden", state.loading === 0);
  }

  function setAuthPill() {
    if (state.accessToken) {
      ui.authPill.textContent = "متصل";
      ui.authPill.className = "pill success";
    } else {
      ui.authPill.textContent = "غير مسجل";
      ui.authPill.className = "pill danger";
    }
  }

  function toQuery(params) {
    const query = new URLSearchParams();
    Object.entries(params || {}).forEach(([key, value]) => {
      if (value === null || value === undefined || value === "") return;
      query.set(key, value);
    });
    const serialized = query.toString();
    return serialized ? `?${serialized}` : "";
  }

  function getItems(payload) {
    if (!payload) return [];
    if (Array.isArray(payload)) return payload;
    return payload.items || [];
  }

  async function apiCall(path, options) {
    const request = options || {};
    const method = request.method || "GET";
    const headers = request.headers || {};
    if (!request.noAuth && state.accessToken) {
      headers.Authorization = `Bearer ${state.accessToken}`;
    }

    const url = path.startsWith("http://") || path.startsWith("https://")
      ? path
      : `${state.baseUrl}${path}${toQuery(request.query)}`;
    const fetchOptions = { method, headers };
    if (request.body !== undefined && request.body !== null) {
      headers["Content-Type"] = "application/json";
      fetchOptions.body = JSON.stringify(request.body);
    }

    setLoading(true);
    try {
      const response = await fetch(url, fetchOptions);
      let data = null;
      if (response.status !== 204) {
        const contentType = response.headers.get("content-type") || "";
        data = contentType.includes("application/json")
          ? await response.json()
          : await response.text();
      }

      return { ok: response.ok, status: response.status, data };
    } catch (error) {
      return { ok: false, status: 0, data: { message: error.message } };
    } finally {
      setLoading(false);
    }
  }

  async function openFile(path) {
    const headers = {};
    if (state.accessToken) {
      headers.Authorization = `Bearer ${state.accessToken}`;
    }

    setLoading(true);
    try {
      const response = await fetch(`${state.baseUrl}${path}`, { headers });
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }

      const blob = await response.blob();
      const url = URL.createObjectURL(blob);
      window.open(url, "_blank");
      setTimeout(() => URL.revokeObjectURL(url), 20000);
    } catch (error) {
      showToast(`تعذر فتح الملف: ${error.message}`, true);
    } finally {
      setLoading(false);
    }
  }

  function bindNavigation() {
    document.querySelectorAll(".module-btn").forEach((button) => {
      button.addEventListener("click", () => {
        document.querySelectorAll(".module-btn").forEach((item) => item.classList.remove("active"));
        document.querySelectorAll(".module-screen").forEach((screen) => screen.classList.remove("active"));

        state.activeModule = button.dataset.module;
        button.classList.add("active");
        document.getElementById(`module-${state.activeModule}`).classList.add("active");
      });
    });
  }

  function bindSession() {
    ui.baseUrlInput.value = state.baseUrl;

    document.getElementById("saveBaseUrlBtn").addEventListener("click", () => {
      state.baseUrl = normalizeUrl(ui.baseUrlInput.value) || window.location.origin;
      saveSession();
      showToast("تم حفظ رابط الخادم");
    });

    document.getElementById("loginForm").addEventListener("submit", async (event) => {
      event.preventDefault();
      const form = new FormData(event.currentTarget);
      const payload = {
        userName: String(form.get("userName") || "").trim(),
        password: String(form.get("password") || "").trim()
      };
      const result = await apiCall("/api/auth/login", { method: "POST", body: payload, noAuth: true });
      setRaw(result.data || { status: result.status });
      if (!result.ok) {
        showToast("فشل تسجيل الدخول", true);
        return;
      }

      state.accessToken = result.data.accessToken || "";
      state.refreshToken = result.data.refreshToken || "";
      saveSession();
      setAuthPill();
      showToast("تم تسجيل الدخول");
      await loadOverview();
      await loadDocumentTypes();
      await loadDocuments();
    });

    document.getElementById("logoutBtn").addEventListener("click", async () => {
      if (state.refreshToken) {
        await apiCall("/api/auth/logout", { method: "POST", body: { refreshToken: state.refreshToken } });
      }

      state.accessToken = "";
      state.refreshToken = "";
      saveSession();
      setAuthPill();
      showToast("تم تسجيل الخروج");
    });

    document.getElementById("quickMeBtn").addEventListener("click", async () => {
      const result = await apiCall("/api/auth/me");
      setRaw(result.data || { status: result.status });
      showToast(result.ok ? "تم جلب المستخدم الحالي" : "تعذر جلب المستخدم الحالي", !result.ok);
    });

  }

  function bindOverview() {
    document.getElementById("refreshOverviewBtn").addEventListener("click", loadOverview);
  }

  async function loadOverview() {
    const [health, customers, materials, invoices, vouchers] = await Promise.all([
      apiCall("/api/health", { noAuth: true }),
      apiCall("/api/customers", { query: { page: 1, pageSize: 1 } }),
      apiCall("/api/materials", { query: { page: 1, pageSize: 1 } }),
      apiCall("/api/bills/invoices", { query: { page: 1, pageSize: 7 } }),
      apiCall("/api/bills/vouchers", { query: { page: 1, pageSize: 7 } })
    ]);

    document.getElementById("statHealth").textContent = health.ok ? "ok" : `HTTP ${health.status}`;
    document.getElementById("statCustomers").textContent = formatNumber(customers.data?.totalCount);
    document.getElementById("statMaterials").textContent = formatNumber(materials.data?.totalCount);
    document.getElementById("statInvoices").textContent = formatNumber(invoices.data?.totalCount);
    document.getElementById("statVouchers").textContent = formatNumber(vouchers.data?.totalCount);

    renderSimpleDocumentsTable("overviewInvoicesTable", getItems(invoices.data), true);
    renderSimpleDocumentsTable("overviewVouchersTable", getItems(vouchers.data), false);

    setRaw({
      health: health.data,
      customers: customers.data,
      materials: materials.data,
      invoices: invoices.data,
      vouchers: vouchers.data
    });
  }

  function renderSimpleDocumentsTable(tableId, items, invoiceMode) {
    const tbody = document.querySelector(`#${tableId} tbody`);
    const field = invoiceMode ? "customerName" : "accountName";
    tbody.innerHTML = items.map((item) => `
      <tr>
        <td>${safeHtml(item.number ?? "-")}</td>
        <td>${safeHtml(formatDate(item.date))}</td>
        <td>${safeHtml(item.typeName || item.typeCode || "-")}</td>
        <td>${safeHtml(item[field] || "-")}</td>
        <td>${safeHtml(formatMoney(item.netAmount, item.currencySymbol, item.currencyCode))}</td>
      </tr>
    `).join("");
  }

  function bindLedger() {
    document.getElementById("ledgerFilterForm").addEventListener("submit", async (event) => {
      event.preventDefault();
      const form = new FormData(event.currentTarget);
      const query = {
        accountGuid: String(form.get("accountGuid") || "").trim(),
        customerGuid: String(form.get("customerGuid") || "").trim(),
        fromDate: String(form.get("fromDate") || "").trim(),
        toDate: String(form.get("toDate") || "").trim(),
        page: 1,
        pageSize: Number(form.get("pageSize") || 100)
      };

      const result = await apiCall("/api/accounts/statement", { query });
      setRaw(result.data || { status: result.status, query });
      if (!result.ok) {
        showToast("فشل تحميل دفتر الأستاذ", true);
        renderLedgerRows([]);
        return;
      }

      renderLedgerRows(result.data.entries || [], result.data || {});
    });

    document.querySelector("#ledgerTable tbody").addEventListener("click", openSourceDocumentFromRow);
    document.querySelector("#ledgerTable tbody").addEventListener("dblclick", openSourceDocumentFromRow);
  }

  function reasonToDocumentKind(reasonType) {
    if (reasonType === "invoice") return "invoices";
    if (reasonType === "payment") return "vouchers";
    return "";
  }

  async function openSourceDocumentFromRow(event) {
    const row = event.target.closest("tr[data-ref-guid]");
    if (!row) return;
    const refGuid = row.dataset.refGuid;
    const kind = row.dataset.refKind;
    if (!refGuid || !kind) {
      showToast("لا يوجد مستند مصدر مرتبط بهذه الحركة", true);
      return;
    }
    await openDocumentModal(refGuid, kind);
  }

  function renderLedgerRows(rows, statementData) {
    const tbody = document.querySelector("#ledgerTable tbody");
    if (!rows.length) {
      tbody.innerHTML = `<tr><td colspan="10">لا توجد قيود.</td></tr>`;
      return;
    }

    const currencySymbol = statementData?.accountCurrencySymbol;
    const currencyCode = statementData?.accountCurrencyCode;
    tbody.innerHTML = rows.map((entry) => {
      const movementSymbol = entry.movementCurrencySymbol || currencySymbol;
      const movementCode = entry.movementCurrencyCode || currencyCode;
      const movementCurrencyLabel = [entry.movementCurrencyCode, entry.movementCurrencySymbol]
        .filter(Boolean)
        .join(" ") || entry.movementCurrencyName || "-";
      const kind = reasonToDocumentKind(entry.reasonType);
      const refGuid = entry.referenceGuid || "";
      const openable = kind && refGuid;
      return `
      <tr data-ref-guid="${refGuid}" data-ref-kind="${kind}" class="${openable ? "linkable" : ""}" title="${openable ? "اضغط لفتح المستند المصدر" : ""}">
        <td>${safeHtml(formatDate(entry.entryDate ?? entry.date))}</td>
        <td>${safeHtml(entry.entryNumber ?? entry.number ?? "-")}</td>
        <td>${safeHtml(formatMoney(entry.debit, movementSymbol, movementCode))}</td>
        <td>${safeHtml(formatMoney(entry.credit, movementSymbol, movementCode))}</td>
        <td>${safeHtml(movementCurrencyLabel)}</td>
        <td>${safeHtml(entry.reasonType || "-")}</td>
        <td>${safeHtml(entry.reasonDocumentType || "-")}</td>
        <td>${safeHtml(entry.referenceNumber ?? "-")}</td>
        <td>${safeHtml([entry.contraAccountNumber, entry.contraAccountName || entry.contraAccountCode].filter(Boolean).join(" - ") || "-")}</td>
        <td>${safeHtml(formatMoney(entry.runningBalance, currencySymbol, currencyCode))}</td>
      </tr>`;
    }).join("");
  }

  function bindDocuments() {
    document.getElementById("docInvoicesBtn").addEventListener("click", async () => {
      switchDocumentsMode("invoices");
      await loadDocumentTypes();
      await loadDocuments();
    });

    document.getElementById("docVouchersBtn").addEventListener("click", async () => {
      switchDocumentsMode("vouchers");
      await loadDocumentTypes();
      await loadDocuments();
    });

    document.getElementById("documentsFilterForm").addEventListener("submit", async (event) => {
      event.preventDefault();
      const form = new FormData(event.currentTarget);
      state.documents.search = String(form.get("search") || "").trim();
      state.documents.type = String(form.get("type") || "").trim();
      state.documents.fromDate = String(form.get("fromDate") || "").trim();
      state.documents.toDate = String(form.get("toDate") || "").trim();
      state.documents.pageSize = Number(form.get("pageSize") || 50);
      state.documents.page = 1;
      if (state.documents.type) {
        state.documents.typeGuid = "";
      }
      await loadDocuments();
    });

    document.getElementById("documentsPrevBtn").addEventListener("click", async () => {
      if (state.documents.page <= 1) return;
      state.documents.page -= 1;
      await loadDocuments();
    });

    document.getElementById("documentsNextBtn").addEventListener("click", async () => {
      const totalPages = Math.max(1, Math.ceil((state.documents.totalCount || 0) / state.documents.pageSize));
      if (state.documents.page >= totalPages) return;
      state.documents.page += 1;
      await loadDocuments();
    });

    document.querySelector("#documentsTable tbody").addEventListener("dblclick", async (event) => {
      const row = event.target.closest("tr[data-guid]");
      if (!row) return;
      await openDocumentModal(row.dataset.guid);
    });

    document.querySelector("#documentsTable tbody").addEventListener("click", (event) => {
      const row = event.target.closest("tr[data-guid]");
      if (!row) return;
      state.documents.selectedGuid = row.dataset.guid;
      highlightSelectedRow("#documentsTable", state.documents.selectedGuid);
    });

    document.getElementById("quickInvoiceTypesBtn").addEventListener("click", async () => {
      state.documents.mode = "invoices";
      await loadDocumentTypes();
      showToast("تم تحديث أنواع الفواتير");
    });

    document.getElementById("quickVoucherTypesBtn").addEventListener("click", async () => {
      state.documents.mode = "vouchers";
      await loadDocumentTypes();
      showToast("تم تحديث أنواع السندات");
    });

    document.getElementById("closeModalBtn").addEventListener("click", closeDocumentModal);
    ui.documentModal.addEventListener("click", (event) => {
      if (event.target === ui.documentModal) {
        closeDocumentModal();
      }
    });
  }

  function switchDocumentsMode(mode) {
    state.documents.mode = mode;
    state.documents.page = 1;
    state.documents.typeGuid = "";
    state.documents.selectedGuid = "";
    document.getElementById("docInvoicesBtn").classList.toggle("active", mode === "invoices");
    document.getElementById("docVouchersBtn").classList.toggle("active", mode === "vouchers");
  }

  async function loadDocumentTypes() {
    const endpoint = state.documents.mode === "invoices" ? "/api/bills/invoice-types" : "/api/bills/voucher-types";
    const result = await apiCall(endpoint);
    if (!result.ok) {
      state.documents.types = [];
      renderDocumentTypeChips();
      return;
    }

    state.documents.types = Array.isArray(result.data) ? result.data : [];
    renderDocumentTypeChips();
    setRaw({ endpoint, data: result.data });
  }

  function renderDocumentTypeChips() {
    const container = document.getElementById("documentTypesChips");
    const allClass = state.documents.typeGuid ? "chip" : "chip active";
    const allChip = `<button type="button" class="${allClass}" data-guid="">الكل</button>`;
    const chips = state.documents.types.map((type) => {
      const active = state.documents.typeGuid === String(type.typeGuid);
      const chipClass = active ? "chip active" : "chip";
      const label = `${type.typeName || type.typeCode || "نوع"} (${type.documentsCount || 0})`;
      return `<button type="button" class="${chipClass}" data-guid="${type.typeGuid}">${safeHtml(label)}</button>`;
    }).join("");
    container.innerHTML = allChip + chips;

    container.querySelectorAll("button[data-guid]").forEach((button) => {
      button.addEventListener("click", async () => {
        state.documents.typeGuid = button.dataset.guid || "";
        if (state.documents.typeGuid) {
          state.documents.type = "";
          document.querySelector('#documentsFilterForm input[name="type"]').value = "";
        }

        state.documents.page = 1;
        renderDocumentTypeChips();
        await loadDocuments();
      });
    });
  }

  async function loadDocuments() {
    const endpoint = state.documents.mode === "invoices" ? "/api/bills/invoices" : "/api/bills/vouchers";
    const query = {
      page: state.documents.page,
      pageSize: state.documents.pageSize,
      keyword: state.documents.search,
      type: state.documents.type,
      fromDate: state.documents.fromDate,
      toDate: state.documents.toDate
    };
    if (state.documents.typeGuid) {
      query.typeGuid = state.documents.typeGuid;
    }

    const result = await apiCall(endpoint, { query });
    setRaw(result.data || { status: result.status, endpoint, query });
    if (!result.ok) {
      showToast("فشل تحميل المستندات", true);
      renderDocumentsRows([]);
      return;
    }

    state.documents.rows = getItems(result.data);
    state.documents.totalCount = result.data.totalCount || 0;
    renderDocumentsRows(state.documents.rows);
    renderDocumentsPager();
  }

  function renderDocumentsRows(rows) {
    const tbody = document.querySelector("#documentsTable tbody");
    if (!rows.length) {
      tbody.innerHTML = `<tr><td colspan="10">لا توجد بيانات.</td></tr>`;
      return;
    }

    tbody.innerHTML = rows.map((item) => `
      <tr data-guid="${item.guid}">
        <td>${safeHtml(item.number ?? "-")}</td>
        <td>${safeHtml(formatDate(item.date))}</td>
        <td>${safeHtml(item.typeName || item.typeCode || "-")}</td>
        <td>${safeHtml(item.settlementTypeName || "-")}</td>
        <td>${safeHtml(item.customerName || "-")}</td>
        <td>${safeHtml(item.accountName || "-")}</td>
        <td>${safeHtml(formatMoney(item.totalAmount, item.currencySymbol, item.currencyCode))}</td>
        <td>${safeHtml(formatMoney(item.totalDiscount, item.currencySymbol, item.currencyCode))}</td>
        <td>${safeHtml(formatMoney(item.totalAdditions, item.currencySymbol, item.currencyCode))}</td>
        <td>${safeHtml(formatMoney(item.netAmount, item.currencySymbol, item.currencyCode))}</td>
      </tr>
    `).join("");

    if (state.documents.selectedGuid) {
      highlightSelectedRow("#documentsTable", state.documents.selectedGuid);
    }
  }

  function renderDocumentsPager() {
    const totalPages = Math.max(1, Math.ceil((state.documents.totalCount || 0) / state.documents.pageSize));
    document.getElementById("documentsPagerLabel").textContent = `صفحة ${state.documents.page} من ${totalPages} (${state.documents.totalCount} سجل)`;
  }

  async function openDocumentModal(guid, forcedKind) {
    const mode = forcedKind || state.documents.mode;
    const isInvoice = mode === "invoices";
    const endpoint = isInvoice
      ? `/api/bills/invoices/${guid}`
      : `/api/bills/vouchers/${guid}`;
    const result = await apiCall(endpoint);
    setRaw(result.data || { status: result.status, endpoint });
    if (!result.ok) {
      showToast("تعذر جلب تفاصيل المستند", true);
      return;
    }

    const document = result.data.document || {};
    const items = result.data.items || [];
    const entryLines = result.data.entryLines || [];
    ui.modalTitle.textContent = `${isInvoice ? "تفاصيل الفاتورة" : "تفاصيل السند"} رقم ${document.number ?? "-"}`;
    const summaryCells = [
      detailCell("النوع", document.typeName || document.typeCode || "-"),
      detailCell("التسوية", document.settlementTypeName || "-"),
      detailCell("العملة", [document.currencyName, document.currencyCode, document.currencySymbol].filter(Boolean).join(" - ") || "-"),
      detailCell("سعر التعادل", formatNumber(document.currencyRate)),
      detailCell("العميل", document.customerName || "-"),
      detailCell("الحساب", document.accountName || "-"),
      detailCell("الإجمالي", formatMoney(document.totalAmount, document.currencySymbol, document.currencyCode)),
      detailCell("الحسم", formatMoney(document.totalDiscount, document.currencySymbol, document.currencyCode)),
      detailCell("الإضافات", formatMoney(document.totalAdditions, document.currencySymbol, document.currencyCode)),
      detailCell("الصافي", formatMoney(document.netAmount, document.currencySymbol, document.currencyCode)),
      detailCell("عدد البنود", formatNumber(result.data.linesCount))
    ];
    if (isInvoice) {
      summaryCells.push(
        detailCell("إجمالي الكمية", formatNumber(result.data.totalQuantity)),
        detailCell("عدد الأزواج", formatNumber(document.pairsCount)),
        detailCell("عدد الأقلام", formatNumber(document.pensCount))
      );
    } else if (result.data.totalQuantity != null) {
      summaryCells.push(detailCell("إجمالي السند", formatMoney(result.data.totalQuantity, document.currencySymbol, document.currencyCode)));
    }
    summaryCells.push(
      detailCell("حساب الحسم", [document.discountAccountNumber, document.discountAccountName].filter(Boolean).join(" - ") || "-"),
      detailCell("حساب الإضافة", [document.additionAccountNumber, document.additionAccountName].filter(Boolean).join(" - ") || "-")
    );
    ui.modalSummary.innerHTML = `<div class="detail-grid">${summaryCells.join("")}</div>`;

    const modalHead = ui.modalItemsHead;
    if (isInvoice) {
      modalHead.innerHTML = "<th>المادة</th><th>كمية (و1)</th><th>كمية (و2)</th><th>سعر القطعة</th><th>حسم</th><th>إضافة</th><th>إجمالي</th>";
      if (!items.length) {
        ui.modalItemsTable.innerHTML = `<tr><td colspan="7">لا توجد عناصر.</td></tr>`;
      } else {
        ui.modalItemsTable.innerHTML = items.map((item) => `
          <tr>
            <td>${safeHtml(item.materialName || item.materialCode || item.materialGuid || "-")}</td>
            <td>${safeHtml(formatNumber(item.quantityUnit1 ?? item.quantity))}</td>
            <td>${safeHtml(formatNumber(item.quantityUnit2))}</td>
            <td>${safeHtml(formatMoney(item.unitPriceUnit1 ?? item.price, document.currencySymbol, document.currencyCode))}</td>
            <td>${safeHtml(formatMoney(item.discount, document.currencySymbol, document.currencyCode))}</td>
            <td>${safeHtml(formatMoney(item.additions, document.currencySymbol, document.currencyCode))}</td>
            <td>${safeHtml(formatMoney(item.lineTotal, document.currencySymbol, document.currencyCode))}</td>
          </tr>
        `).join("");
      }
    } else {
      modalHead.innerHTML = "<th>رقم</th><th>حساب</th><th>مقابل</th><th>مدين</th><th>دائن</th><th>عميل</th><th>ملاحظات</th>";
      if (!entryLines.length) {
        ui.modalItemsTable.innerHTML = `<tr><td colspan="7">لا توجد قيود محاسبية.</td></tr>`;
      } else {
        ui.modalItemsTable.innerHTML = entryLines.map((line) => `
          <tr>
            <td>${safeHtml(line.number ?? "-")}</td>
            <td>${safeHtml([line.accountNumber, line.accountName || line.accountCode].filter(Boolean).join(" - ") || "-")}</td>
            <td>${safeHtml([line.contraAccountNumber, line.contraAccountName || line.contraAccountCode].filter(Boolean).join(" - ") || "-")}</td>
            <td>${safeHtml(formatMoney(line.debit, document.currencySymbol, document.currencyCode))}</td>
            <td>${safeHtml(formatMoney(line.credit, document.currencySymbol, document.currencyCode))}</td>
            <td>${safeHtml(line.customerName || "-")}</td>
            <td>${safeHtml(line.notes || "-")}</td>
          </tr>
        `).join("");
      }
    }

    ui.documentModal.classList.remove("hidden");
  }

  function closeDocumentModal() {
    ui.documentModal.classList.add("hidden");
  }

  function detailCell(label, value) {
    return `<div class="detail-item"><span>${safeHtml(label)}</span><strong>${safeHtml(value)}</strong></div>`;
  }

  function bindMaterials() {
    document.getElementById("materialsFilterForm").addEventListener("submit", async (event) => {
      event.preventDefault();
      const form = new FormData(event.currentTarget);
      state.materials.search = String(form.get("search") || "").trim();
      state.materials.page = Number(form.get("page") || 1);
      state.materials.pageSize = Number(form.get("pageSize") || 40);
      await loadMaterials();
    });

    document.getElementById("materialsFilterOptionsBtn").addEventListener("click", async () => {
      const result = await apiCall("/api/materials/filter-options");
      setRaw(result.data || { status: result.status });
      showToast(result.ok ? "تم جلب خيارات الفلترة" : "تعذر جلب خيارات الفلترة", !result.ok);
    });

    document.querySelector("#materialsTable tbody").addEventListener("click", async (event) => {
      const row = event.target.closest("tr[data-guid]");
      if (!row) return;
      const guid = row.dataset.guid;
      state.materials.selectedGuid = guid;
      highlightSelectedRow("#materialsTable", guid);
      await loadMaterialDetails(guid);
    });

    document.querySelector("#materialImagesTable tbody").addEventListener("click", async (event) => {
      const button = event.target.closest("button[data-action]");
      if (!button) return;
      const imageGuid = button.dataset.guid;
      if (button.dataset.action === "file") {
        await openFile(`/api/material-images/${imageGuid}/file`);
      } else {
        await openFile(`/api/material-images/${imageGuid}/thumbnail`);
      }
    });
  }

  async function loadMaterials() {
    const query = {
      keyword: state.materials.search,
      page: state.materials.page,
      pageSize: state.materials.pageSize
    };
    const result = await apiCall("/api/materials", { query });
    setRaw(result.data || { status: result.status, query });
    if (!result.ok) {
      showToast("فشل تحميل المواد", true);
      renderMaterialsRows([]);
      return;
    }

    state.materials.rows = getItems(result.data);
    renderMaterialsRows(state.materials.rows);
  }

  function renderMaterialsRows(rows) {
    const tbody = document.querySelector("#materialsTable tbody");
    if (!rows.length) {
      tbody.innerHTML = `<tr><td colspan="6">لا توجد مواد.</td></tr>`;
      return;
    }

    tbody.innerHTML = rows.map((item) => `
      <tr data-guid="${item.guid}">
        <td>${safeHtml(item.number ?? "-")}</td>
        <td>${safeHtml(item.name || "-")}</td>
        <td>${safeHtml(item.code || "-")}</td>
        <td>${safeHtml(formatNumber(item.qty))}</td>
        <td>${safeHtml(formatNumber(item.whole))}</td>
        <td>${safeHtml(formatNumber(item.endUser))}</td>
      </tr>
    `).join("");

    if (state.materials.selectedGuid) {
      highlightSelectedRow("#materialsTable", state.materials.selectedGuid);
    }
  }

  async function loadMaterialDetails(guid) {
    const [materialRes, imagesRes] = await Promise.all([
      apiCall(`/api/materials/${guid}`),
      apiCall(`/api/materials/${guid}/images`)
    ]);

    setRaw({ material: materialRes.data, images: imagesRes.data });
    if (!materialRes.ok) {
      showToast("فشل تحميل تفاصيل المادة", true);
      return;
    }

    const material = materialRes.data;
    const card = document.getElementById("materialDetailCard");
    card.innerHTML = `
      <div class="detail-grid">
        ${detailCell("رقم", material.number ?? "-")}
        ${detailCell("الاسم", material.name || "-")}
        ${detailCell("الكود", material.code || "-")}
        ${detailCell("الكمية", formatNumber(material.qty))}
        ${detailCell("سعر جملة", formatNumber(material.whole))}
        ${detailCell("سعر نصف جملة", formatNumber(material.half))}
        ${detailCell("سعر شراء", formatNumber(material.endUser))}
      </div>
    `;

    const images = imagesRes.ok ? (imagesRes.data || []) : [];
    const imageTbody = document.querySelector("#materialImagesTable tbody");
    if (!images.length) {
      imageTbody.innerHTML = `<tr><td colspan="2">لا توجد صور.</td></tr>`;
      return;
    }

    imageTbody.innerHTML = images.map((image) => `
      <tr>
        <td>${safeHtml(image.name || image.guid)}</td>
        <td class="row">
          <button type="button" class="btn subtle" data-action="thumb" data-guid="${image.guid}">مصغرة</button>
          <button type="button" class="btn" data-action="file" data-guid="${image.guid}">الملف</button>
        </td>
      </tr>
    `).join("");
  }

  function bindCustomers() {
    document.getElementById("customersFilterForm").addEventListener("submit", async (event) => {
      event.preventDefault();
      const form = new FormData(event.currentTarget);
      state.customers.search = String(form.get("search") || "").trim();
      state.customers.page = Number(form.get("page") || 1);
      state.customers.pageSize = Number(form.get("pageSize") || 50);
      await loadCustomers();
    });

    document.querySelector("#customersTable tbody").addEventListener("click", async (event) => {
      const row = event.target.closest("tr[data-guid]");
      if (!row) return;

      state.customers.selectedCustomerGuid = row.dataset.guid;
      state.customers.selectedAccountGuid = row.dataset.accountGuid || "";
      highlightSelectedRow("#customersTable", state.customers.selectedCustomerGuid);
      state.accountsDirectory.selectedAccountGuid = "";
      highlightSelectedRow("#accountsTable", state.accountsDirectory.selectedAccountGuid);

      const form = document.getElementById("customerAccountForm");
      form.querySelector('input[name="customerGuid"]').value = state.customers.selectedCustomerGuid;
      form.querySelector('input[name="accountGuid"]').value = state.customers.selectedAccountGuid;
      await loadCustomerAccount();
    });

    document.getElementById("accountsFilterForm").addEventListener("submit", async (event) => {
      event.preventDefault();
      const form = new FormData(event.currentTarget);
      state.accountsDirectory.search = String(form.get("search") || "").trim();
      state.accountsDirectory.page = Number(form.get("page") || 1);
      state.accountsDirectory.pageSize = Number(form.get("pageSize") || 50);
      await loadAccountsDirectory();
    });

    document.querySelector("#accountsTable tbody").addEventListener("click", async (event) => {
      const row = event.target.closest("tr[data-guid]");
      if (!row) return;

      state.accountsDirectory.selectedAccountGuid = row.dataset.guid;
      state.customers.selectedCustomerGuid = "";
      state.customers.selectedAccountGuid = row.dataset.guid;
      highlightSelectedRow("#accountsTable", state.accountsDirectory.selectedAccountGuid);
      highlightSelectedRow("#customersTable", state.customers.selectedCustomerGuid);

      const form = document.getElementById("customerAccountForm");
      form.querySelector('input[name="customerGuid"]').value = "";
      form.querySelector('input[name="accountGuid"]').value = state.customers.selectedAccountGuid;
      await loadCustomerAccount();
    });

    document.getElementById("customerAccountForm").addEventListener("submit", async (event) => {
      event.preventDefault();
      await loadCustomerAccount();
    });
  }

  async function loadCustomers() {
    const query = {
      keyword: state.customers.search,
      page: state.customers.page,
      pageSize: state.customers.pageSize
    };
    const result = await apiCall("/api/customers", { query });
    setRaw(result.data || { status: result.status, query });
    if (!result.ok) {
      showToast("فشل تحميل العملاء", true);
      renderCustomersRows([]);
      return;
    }

    renderCustomersRows(getItems(result.data));
  }

  async function loadAccountsDirectory() {
    const query = {
      keyword: state.accountsDirectory.search,
      page: state.accountsDirectory.page,
      pageSize: state.accountsDirectory.pageSize
    };
    const result = await apiCall("/api/accounts", { query });
    if (!result.ok) {
      showToast("فشل تحميل الحسابات", true);
      renderAccountsRows([]);
      return;
    }

    renderAccountsRows(getItems(result.data));
  }

  function renderCustomersRows(rows) {
    const tbody = document.querySelector("#customersTable tbody");
    if (!rows.length) {
      tbody.innerHTML = `<tr><td colspan="4">لا توجد بيانات.</td></tr>`;
      return;
    }

    tbody.innerHTML = rows.map((item) => `
      <tr data-guid="${item.guid}" data-account-guid="${item.accountGuid || ""}">
        <td>${safeHtml(item.number ?? "-")}</td>
        <td>${safeHtml(item.customerName || "-")}</td>
        <td>${safeHtml(item.mobile || item.phone1 || "-")}</td>
        <td>${safeHtml(item.accountGuid || "-")}</td>
      </tr>
    `).join("");
  }

  function renderAccountsRows(rows) {
    const tbody = document.querySelector("#accountsTable tbody");
    if (!rows.length) {
      tbody.innerHTML = `<tr><td colspan="4">لا توجد بيانات.</td></tr>`;
      return;
    }

    tbody.innerHTML = rows.map((item) => `
      <tr data-guid="${item.guid}">
        <td>${safeHtml(item.number ?? "-")}</td>
        <td>${safeHtml(item.code || "-")}</td>
        <td>${safeHtml(item.name || "-")}</td>
        <td>${safeHtml(item.currencyRate ?? "-")}</td>
      </tr>
    `).join("");

    if (state.accountsDirectory.selectedAccountGuid) {
      highlightSelectedRow("#accountsTable", state.accountsDirectory.selectedAccountGuid);
    }
  }

  async function loadCustomerAccount() {
    const form = document.getElementById("customerAccountForm");
    const formData = new FormData(form);
    const querySummary = {
      accountGuid: String(formData.get("accountGuid") || "").trim(),
      customerGuid: String(formData.get("customerGuid") || "").trim()
    };
    const queryStatement = {
      ...querySummary,
      fromDate: String(formData.get("fromDate") || "").trim(),
      toDate: String(formData.get("toDate") || "").trim(),
      page: 1,
      pageSize: 100
    };
    if (!querySummary.accountGuid && !querySummary.customerGuid) {
      showToast("حدد Account أو Customer", true);
      return;
    }

    const [summaryRes, statementRes] = await Promise.all([
      apiCall("/api/accounts/summary", { query: querySummary }),
      apiCall("/api/accounts/statement", { query: queryStatement })
    ]);

    setRaw({ summary: summaryRes.data, statement: statementRes.data });
    if (!summaryRes.ok) {
      showToast("فشل تحميل ملخص الحساب", true);
      return;
    }

    const summary = summaryRes.data;
    const summaryCurrency = [summary.accountCurrencyName, summary.accountCurrencyCode, summary.accountCurrencySymbol]
      .filter(Boolean)
      .join(" - ");
    document.getElementById("customerSummaryCard").innerHTML = `
      <div class="detail-grid">
        ${detailCell("اسم العميل", summary.customerName || "-")}
        ${detailCell("اسم الحساب", summary.accountName || "-")}
        ${detailCell("كود الحساب", summary.accountCode || "-")}
        ${detailCell("عملة الحساب", summaryCurrency || "-")}
        ${detailCell("الرصيد الحالي", formatMoney(summary.currentBalance, summary.accountCurrencySymbol, summary.accountCurrencyCode))}
        ${detailCell("مدين", formatMoney(summary.currentDebit, summary.accountCurrencySymbol, summary.accountCurrencyCode))}
        ${detailCell("دائن", formatMoney(summary.currentCredit, summary.accountCurrencySymbol, summary.accountCurrencyCode))}
      </div>
    `;

    const statement = statementRes.ok ? (statementRes.data || {}) : {};
    const entries = statement.entries || [];
    const statementCurrencySymbol = statement.accountCurrencySymbol;
    const statementCurrencyCode = statement.accountCurrencyCode;
    const tbody = document.querySelector("#customerStatementTable tbody");
    if (!entries.length) {
      tbody.innerHTML = `<tr><td colspan="9">لا توجد حركات.</td></tr>`;
      return;
    }

    tbody.innerHTML = entries.map((entry) => {
      const movementSymbol = entry.movementCurrencySymbol || statementCurrencySymbol;
      const movementCode = entry.movementCurrencyCode || statementCurrencyCode;
      const movementCurrencyLabel = [entry.movementCurrencyCode, entry.movementCurrencySymbol]
        .filter(Boolean)
        .join(" ") || entry.movementCurrencyName || "-";
      const kind = reasonToDocumentKind(entry.reasonType);
      const refGuid = entry.referenceGuid || "";
      const openable = kind && refGuid;
      return `
      <tr data-ref-guid="${refGuid}" data-ref-kind="${kind}" class="${openable ? "linkable" : ""}" title="${openable ? "اضغط لفتح المستند المصدر" : ""}">
        <td>${safeHtml(formatDate(entry.entryDate ?? entry.date))}</td>
        <td>${safeHtml(entry.entryNumber ?? entry.number ?? "-")}</td>
        <td>${safeHtml(formatMoney(entry.debit, movementSymbol, movementCode))}</td>
        <td>${safeHtml(formatMoney(entry.credit, movementSymbol, movementCode))}</td>
        <td>${safeHtml(movementCurrencyLabel)}</td>
        <td>${safeHtml(entry.reasonType || "-")}</td>
        <td>${safeHtml(entry.reasonDocumentType || "-")}</td>
        <td>${safeHtml([entry.contraAccountNumber, entry.contraAccountName || entry.contraAccountCode].filter(Boolean).join(" - ") || "-")}</td>
        <td>${safeHtml(formatMoney(entry.runningBalance, statementCurrencySymbol, statementCurrencyCode))}</td>
      </tr>`;
    }).join("");

    tbody.onclick = openSourceDocumentFromRow;
    tbody.ondblclick = openSourceDocumentFromRow;
  }

  function highlightSelectedRow(tableSelector, guid) {
    document.querySelectorAll(`${tableSelector} tbody tr[data-guid]`).forEach((row) => {
      row.classList.toggle("selected", row.dataset.guid === guid);
    });
  }

  function hydrateFixedButtons() {
    document.getElementById("quickInvoiceTypesBtn").addEventListener("click", async () => {
      const result = await apiCall("/api/bills/invoice-types");
      setRaw(result.data || { status: result.status });
    });
    document.getElementById("quickVoucherTypesBtn").addEventListener("click", async () => {
      const result = await apiCall("/api/bills/voucher-types");
      setRaw(result.data || { status: result.status });
    });
  }

  async function bootstrap() {
    bindNavigation();
    bindSession();
    bindOverview();
    bindLedger();
    bindDocuments();
    bindMaterials();
    bindCustomers();
    hydrateFixedButtons();
    setAuthPill();

    await loadOverview();
    await loadDocumentTypes();
    await loadDocuments();
    await loadMaterials();
    await loadCustomers();
    await loadAccountsDirectory();
  }

  bootstrap().catch((error) => {
    setRaw({ error: error.message });
    showToast("خطأ في بدء الواجهة", true);
  });
})();
