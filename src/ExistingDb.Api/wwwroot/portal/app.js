(function () {
  const storageKeys = {
    baseUrl: "portal.baseUrl",
    accessToken: "portal.accessToken",
    refreshToken: "portal.refreshToken"
  };

  const state = {
    baseUrl: normalizeBaseUrl(localStorage.getItem(storageKeys.baseUrl) || window.location.origin),
    accessToken: localStorage.getItem(storageKeys.accessToken) || "",
    refreshToken: localStorage.getItem(storageKeys.refreshToken) || ""
  };

  const ui = {
    baseUrlInput: document.getElementById("baseUrlInput"),
    saveBaseUrlBtn: document.getElementById("saveBaseUrlBtn"),
    meQuickBtn: document.getElementById("meQuickBtn"),
    authBadge: document.getElementById("authBadge"),
    toast: document.getElementById("toast"),
    accessTokenBox: document.getElementById("accessTokenBox"),
    refreshTokenBox: document.getElementById("refreshTokenBox")
  };

  function notify(message, isError) {
    ui.toast.textContent = message;
    ui.toast.style.borderColor = isError ? "#b91c1c" : "#16a34a";
    ui.toast.classList.add("show");
    window.clearTimeout(ui.toastTimer);
    ui.toastTimer = window.setTimeout(() => ui.toast.classList.remove("show"), 2500);
  }

  function normalizeBaseUrl(value) {
    return (value || "").trim().replace(/\/+$/, "");
  }

  function persistState() {
    localStorage.setItem(storageKeys.baseUrl, state.baseUrl);
    localStorage.setItem(storageKeys.accessToken, state.accessToken);
    localStorage.setItem(storageKeys.refreshToken, state.refreshToken);
  }

  function syncAuthWidgets() {
    ui.accessTokenBox.value = state.accessToken;
    ui.refreshTokenBox.value = state.refreshToken;
    if (state.accessToken) {
      ui.authBadge.textContent = "مسجل";
      ui.authBadge.className = "badge success";
    } else {
      ui.authBadge.textContent = "غير مسجل";
      ui.authBadge.className = "badge danger";
    }
  }

  function formToObject(form) {
    const data = new FormData(form);
    const result = {};
    for (const [key, value] of data.entries()) {
      const text = String(value).trim();
      if (text !== "") {
        result[key] = text;
      }
    }

    return result;
  }

  function toQueryString(params) {
    const query = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
      if (value === undefined || value === null || value === "") {
        return;
      }

      query.set(key, value);
    });
    const serialized = query.toString();
    return serialized ? `?${serialized}` : "";
  }

  async function apiCall(path, options) {
    const request = options || {};
    const method = request.method || "GET";
    const query = request.query || {};
    const headers = request.headers || {};
    const fullPath = path.startsWith("http://") || path.startsWith("https://")
      ? path
      : `${state.baseUrl}${path}${toQueryString(query)}`;

    if (!request.noAuth && state.accessToken) {
      headers.Authorization = `Bearer ${state.accessToken}`;
    }

    const fetchOptions = {
      method,
      headers
    };

    if (request.body !== undefined && request.body !== null) {
      headers["Content-Type"] = "application/json";
      fetchOptions.body = JSON.stringify(request.body);
    }

    if (request.formData) {
      fetchOptions.body = request.formData;
    }

    const response = await fetch(fullPath, fetchOptions);
    let payload = null;
    const contentType = response.headers.get("content-type") || "";
    if (response.status !== 204) {
      if (contentType.includes("application/json")) {
        payload = await response.json();
      } else {
        payload = await response.text();
      }
    }

    return { ok: response.ok, status: response.status, data: payload, response };
  }

  async function apiBlob(path) {
    const headers = {};
    if (state.accessToken) {
      headers.Authorization = `Bearer ${state.accessToken}`;
    }

    const response = await fetch(`${state.baseUrl}${path}`, { headers });
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }

    const blob = await response.blob();
    const url = URL.createObjectURL(blob);
    window.open(url, "_blank");
    setTimeout(() => URL.revokeObjectURL(url), 20000);
  }

  function writeJson(elementId, value) {
    const element = document.getElementById(elementId);
    element.textContent = typeof value === "string"
      ? value
      : JSON.stringify(value, null, 2);
  }

  function getItems(payload) {
    if (!payload) {
      return [];
    }

    if (Array.isArray(payload)) {
      return payload;
    }

    return payload.items || [];
  }

  function clearTableBody(tableId) {
    document.querySelector(`#${tableId} tbody`).innerHTML = "";
  }

  function appendTableRows(tableId, rowsHtml) {
    document.querySelector(`#${tableId} tbody`).innerHTML = rowsHtml;
  }

  function bindNavigation() {
    const buttons = document.querySelectorAll(".nav-btn");
    buttons.forEach((button) => {
      button.addEventListener("click", () => {
        buttons.forEach((item) => item.classList.remove("active"));
        button.classList.add("active");
        const target = button.dataset.section;
        document.querySelectorAll(".section").forEach((section) => {
          section.classList.toggle("active", section.id === `section-${target}`);
        });
      });
    });
  }

  async function loadDashboard() {
    const calls = [
      apiCall("/api/health", { noAuth: true }),
      apiCall("/api/customers", { query: { page: 1, pageSize: 1 } }),
      apiCall("/api/materials", { query: { page: 1, pageSize: 1 } }),
      apiCall("/api/bills/invoices", { query: { page: 1, pageSize: 1 } }),
      apiCall("/api/bills/vouchers", { query: { page: 1, pageSize: 1 } })
    ];

    const [health, customers, materials, invoices, vouchers] = await Promise.all(calls);
    document.getElementById("healthStatus").textContent = health.ok ? "OK" : `HTTP ${health.status}`;
    document.getElementById("customersCount").textContent = customers.data?.totalCount ?? "-";
    document.getElementById("materialsCount").textContent = materials.data?.totalCount ?? "-";
    document.getElementById("invoicesCount").textContent = invoices.data?.totalCount ?? "-";
    document.getElementById("vouchersCount").textContent = vouchers.data?.totalCount ?? "-";
    writeJson("dashboardRaw", {
      health: health.data,
      customers: customers.data,
      materials: materials.data,
      invoices: invoices.data,
      vouchers: vouchers.data
    });
  }

  function bindAuthActions() {
    document.getElementById("loginForm").addEventListener("submit", async (event) => {
      event.preventDefault();
      const payload = formToObject(event.currentTarget);
      const result = await apiCall("/api/auth/login", { method: "POST", body: payload, noAuth: true });
      writeJson("authRaw", result.data || { status: result.status });
      if (!result.ok) {
        notify("فشل تسجيل الدخول", true);
        return;
      }

      state.accessToken = result.data.accessToken || "";
      state.refreshToken = result.data.refreshToken || "";
      persistState();
      syncAuthWidgets();
      notify("تم تسجيل الدخول");
      loadDashboard().catch(() => {});
    });

    document.getElementById("refreshTokenBtn").addEventListener("click", async () => {
      const result = await apiCall("/api/auth/refresh", {
        method: "POST",
        body: { refreshToken: state.refreshToken },
        noAuth: true
      });
      writeJson("authRaw", result.data || { status: result.status });
      if (!result.ok) {
        notify("فشل تحديث التوكن", true);
        return;
      }

      state.accessToken = result.data.accessToken || "";
      state.refreshToken = result.data.refreshToken || state.refreshToken;
      persistState();
      syncAuthWidgets();
      notify("تم تحديث التوكن");
    });

    const meAction = async () => {
      const result = await apiCall("/api/auth/me");
      writeJson("authRaw", result.data || { status: result.status });
      if (!result.ok) {
        notify("تعذر جلب بيانات المستخدم", true);
      }
    };

    document.getElementById("meBtn").addEventListener("click", meAction);
    ui.meQuickBtn.addEventListener("click", meAction);

    document.getElementById("logoutBtn").addEventListener("click", async () => {
      const result = await apiCall("/api/auth/logout", {
        method: "POST",
        body: { refreshToken: state.refreshToken }
      });
      writeJson("authRaw", result.data || { status: result.status });
      state.accessToken = "";
      state.refreshToken = "";
      persistState();
      syncAuthWidgets();
      notify(result.ok ? "تم تسجيل الخروج" : "تم مسح الجلسة محليًا");
    });

    document.getElementById("changePasswordForm").addEventListener("submit", async (event) => {
      event.preventDefault();
      const payload = formToObject(event.currentTarget);
      const result = await apiCall("/api/auth/change-password", { method: "POST", body: payload });
      writeJson("authRaw", result.data || { status: result.status });
      notify(result.ok ? "تم تغيير كلمة المرور" : "فشل تغيير كلمة المرور", !result.ok);
    });

    document.getElementById("applyTokensBtn").addEventListener("click", () => {
      state.accessToken = ui.accessTokenBox.value.trim();
      state.refreshToken = ui.refreshTokenBox.value.trim();
      persistState();
      syncAuthWidgets();
      notify("تم حفظ التوكن يدويًا");
    });

    document.getElementById("clearTokensBtn").addEventListener("click", () => {
      state.accessToken = "";
      state.refreshToken = "";
      persistState();
      syncAuthWidgets();
      notify("تم مسح التوكن");
    });
  }

  function bindCustomers() {
    document.getElementById("customersForm").addEventListener("submit", async (event) => {
      event.preventDefault();
      const query = formToObject(event.currentTarget);
      const result = await apiCall("/api/customers", { query });
      writeJson("customerDetailsRaw", result.data || { status: result.status });
      if (!result.ok) {
        notify("تعذر تحميل الزبائن", true);
        return;
      }

      const rows = getItems(result.data).map((item) => `
        <tr>
          <td>${item.number ?? ""}</td>
          <td>${item.customerName ?? ""}</td>
          <td>${item.barCode ?? ""}</td>
          <td>${item.mobile ?? ""}</td>
          <td>${item.accountGuid ?? ""}</td>
          <td>${item.guid}</td>
          <td>
            <button type="button" class="secondary btn-customer-details" data-guid="${item.guid}">Details</button>
            <button type="button" class="btn-customer-select" data-guid="${item.guid}" data-account="${item.accountGuid ?? ""}">Select</button>
          </td>
        </tr>`).join("");
      appendTableRows("customersTable", rows);
    });

    document.querySelector("#customersTable tbody").addEventListener("click", async (event) => {
      const detailsBtn = event.target.closest(".btn-customer-details");
      if (detailsBtn) {
        const guid = detailsBtn.dataset.guid;
        const result = await apiCall(`/api/customers/${guid}`);
        writeJson("customerDetailsRaw", result.data || { status: result.status });
        return;
      }

      const selectBtn = event.target.closest(".btn-customer-select");
      if (selectBtn) {
        const customerGuid = selectBtn.dataset.guid || "";
        const accountGuid = selectBtn.dataset.account || "";
        document.querySelector('#accountSummaryForm input[name="customerGuid"]').value = customerGuid;
        document.querySelector('#accountSummaryForm input[name="accountGuid"]').value = accountGuid;
        document.querySelector('#accountStatementForm input[name="customerGuid"]').value = customerGuid;
        document.querySelector('#accountStatementForm input[name="accountGuid"]').value = accountGuid;
        notify("تم تمرير الزبون/الحساب إلى قسم الحسابات");
      }
    });
  }

  function bindAccounts() {
    document.getElementById("accountSummaryForm").addEventListener("submit", async (event) => {
      event.preventDefault();
      const query = formToObject(event.currentTarget);
      const result = await apiCall("/api/accounts/summary", { query });
      writeJson("accountSummaryRaw", result.data || { status: result.status });
      notify(result.ok ? "تم جلب الملخص" : "فشل جلب الملخص", !result.ok);
    });

    document.getElementById("accountStatementForm").addEventListener("submit", async (event) => {
      event.preventDefault();
      const query = formToObject(event.currentTarget);
      const result = await apiCall("/api/accounts/statement", { query });
      writeJson("statementRaw", result.data || { status: result.status });
      if (!result.ok) {
        clearTableBody("statementTable");
        notify("فشل جلب كشف الحساب", true);
        return;
      }

      const rows = (result.data?.entries || []).map((entry) => `
        <tr>
          <td>${entry.date ? new Date(entry.date).toLocaleDateString() : ""}</td>
          <td>${entry.number ?? ""}</td>
          <td>${entry.debit ?? ""}</td>
          <td>${entry.credit ?? ""}</td>
          <td>${entry.reasonType ?? ""}</td>
          <td>${entry.reasonDocumentType ?? ""}</td>
          <td>${entry.referenceNumber ?? ""}</td>
          <td>${entry.runningBalance ?? ""}</td>
        </tr>`).join("");
      appendTableRows("statementTable", rows);
      notify("تم جلب كشف الحساب");
    });
  }

  function bindMaterials() {
    document.getElementById("materialsForm").addEventListener("submit", async (event) => {
      event.preventDefault();
      const query = formToObject(event.currentTarget);
      const result = await apiCall("/api/materials", { query });
      writeJson("materialsRaw", result.data || { status: result.status });
      if (!result.ok) {
        notify("فشل تحميل المواد", true);
        return;
      }

      const rows = getItems(result.data).map((item) => `
        <tr>
          <td>${item.number ?? ""}</td>
          <td>${item.name ?? ""}</td>
          <td>${item.code ?? ""}</td>
          <td>${item.qty ?? ""}</td>
          <td>${item.pictureGuid ?? ""}</td>
          <td>${item.guid}</td>
          <td><button type="button" class="btn-material-images" data-guid="${item.guid}">صور المادة</button></td>
        </tr>`).join("");
      appendTableRows("materialsTable", rows);
    });

    document.getElementById("materialsFilterOptionsBtn").addEventListener("click", async () => {
      const result = await apiCall("/api/materials/filter-options");
      writeJson("materialsRaw", result.data || { status: result.status });
    });

    document.getElementById("materialImagesForm").addEventListener("submit", async (event) => {
      event.preventDefault();
      const query = formToObject(event.currentTarget);
      const result = await apiCall("/api/material-images", { query });
      writeJson("materialsRaw", result.data || { status: result.status });
      if (!result.ok) {
        notify("فشل تحميل الصور", true);
        return;
      }

      renderMaterialImages(result.data);
    });

    document.getElementById("materialImagesByMaterialForm").addEventListener("submit", async (event) => {
      event.preventDefault();
      const payload = formToObject(event.currentTarget);
      const result = await apiCall(`/api/materials/${payload.materialGuid}/images`);
      writeJson("materialsRaw", result.data || { status: result.status });
      if (!result.ok) {
        notify("تعذر تحميل صور المادة", true);
        return;
      }

      renderMaterialImages({ items: result.data, totalCount: result.data.length, page: 1, pageSize: result.data.length });
    });

    document.querySelector("#materialsTable tbody").addEventListener("click", async (event) => {
      const btn = event.target.closest(".btn-material-images");
      if (!btn) return;
      const guid = btn.dataset.guid;
      const result = await apiCall(`/api/materials/${guid}/images`);
      writeJson("materialsRaw", result.data || { status: result.status });
      if (result.ok) {
        renderMaterialImages({ items: result.data });
      }
    });

    document.querySelector("#materialImagesTable tbody").addEventListener("click", async (event) => {
      const actionBtn = event.target.closest("button[data-action]");
      if (!actionBtn) {
        return;
      }

      const imageId = actionBtn.dataset.id;
      try {
        if (actionBtn.dataset.action === "file") {
          await apiBlob(`/api/material-images/${imageId}/file`);
        } else {
          await apiBlob(`/api/material-images/${imageId}/thumbnail`);
        }
      } catch (error) {
        notify(`فشل جلب الصورة: ${error.message}`, true);
      }
    });
  }

  function renderMaterialImages(payload) {
    const rows = getItems(payload).map((item) => `
      <tr>
        <td>${item.name ?? ""}</td>
        <td>${item.materialGuid ?? ""}</td>
        <td>${item.guid}</td>
        <td class="row">
          <button type="button" data-action="file" data-id="${item.guid}" class="secondary">الملف</button>
          <button type="button" data-action="thumbnail" data-id="${item.guid}">المصغرة</button>
        </td>
      </tr>`).join("");
    appendTableRows("materialImagesTable", rows);
  }

  function bindBills() {
    document.getElementById("invoicesForm").addEventListener("submit", async (event) => {
      event.preventDefault();
      const query = formToObject(event.currentTarget);
      const result = await apiCall("/api/bills/invoices", { query });
      writeJson("billsRaw", result.data || { status: result.status });
      if (!result.ok) {
        notify("فشل تحميل الفواتير", true);
        return;
      }

      const rows = getItems(result.data).map((item) => `
        <tr>
          <td>${item.number ?? ""}</td>
          <td>${item.date ? new Date(item.date).toLocaleDateString() : ""}</td>
          <td>${item.typeName ?? item.typeCode ?? ""}</td>
          <td>${item.settlementTypeName ?? ""}</td>
          <td>${item.customerName ?? ""}</td>
          <td>${item.accountName ?? ""}</td>
          <td>${item.totalAmount ?? ""}</td>
          <td>${item.netAmount ?? ""}</td>
          <td><button type="button" class="btn-invoice-details" data-guid="${item.guid}">تفاصيل</button></td>
        </tr>`).join("");
      appendTableRows("invoicesTable", rows);
      clearTableBody("invoiceItemsTable");
    });

    document.getElementById("vouchersForm").addEventListener("submit", async (event) => {
      event.preventDefault();
      const query = formToObject(event.currentTarget);
      const result = await apiCall("/api/bills/vouchers", { query });
      writeJson("billsRaw", result.data || { status: result.status });
      if (!result.ok) {
        notify("فشل تحميل السندات", true);
        return;
      }

      const rows = getItems(result.data).map((item) => `
        <tr>
          <td>${item.number ?? ""}</td>
          <td>${item.date ? new Date(item.date).toLocaleDateString() : ""}</td>
          <td>${item.typeName ?? item.typeCode ?? ""}</td>
          <td>${item.settlementTypeName ?? ""}</td>
          <td>${item.customerName ?? ""}</td>
          <td>${item.accountName ?? ""}</td>
          <td>${item.totalAmount ?? ""}</td>
          <td>${item.netAmount ?? ""}</td>
          <td><button type="button" class="btn-voucher-details" data-guid="${item.guid}">تفاصيل</button></td>
        </tr>`).join("");
      appendTableRows("vouchersTable", rows);
    });

    document.getElementById("invoiceTypesBtn").addEventListener("click", async () => {
      const result = await apiCall("/api/bills/invoice-types");
      writeJson("billsRaw", result.data || { status: result.status });
    });

    document.getElementById("voucherTypesBtn").addEventListener("click", async () => {
      const result = await apiCall("/api/bills/voucher-types");
      writeJson("billsRaw", result.data || { status: result.status });
    });

    document.querySelector("#invoicesTable tbody").addEventListener("click", async (event) => {
      const btn = event.target.closest(".btn-invoice-details");
      if (!btn) return;
      const guid = btn.dataset.guid;
      const result = await apiCall(`/api/bills/invoices/${guid}`);
      writeJson("billsRaw", result.data || { status: result.status });
      if (!result.ok) {
        notify("فشل تحميل تفاصيل الفاتورة", true);
        return;
      }

      const rows = (result.data?.items || []).map((item) => `
        <tr>
          <td>${item.materialName ?? item.materialCode ?? item.materialGuid ?? ""}</td>
          <td>${item.quantity ?? ""}</td>
          <td>${item.price ?? ""}</td>
          <td>${item.discount ?? ""}</td>
          <td>${item.additions ?? ""}</td>
          <td>${item.lineTotal ?? ""}</td>
        </tr>`).join("");
      appendTableRows("invoiceItemsTable", rows);
    });

    document.querySelector("#vouchersTable tbody").addEventListener("click", async (event) => {
      const btn = event.target.closest(".btn-voucher-details");
      if (!btn) return;
      const guid = btn.dataset.guid;
      const result = await apiCall(`/api/bills/vouchers/${guid}`);
      writeJson("billsRaw", result.data || { status: result.status });
      notify(result.ok ? "تم تحميل تفاصيل السند" : "فشل تحميل تفاصيل السند", !result.ok);
    });
  }

  function bindExplorer() {
    document.getElementById("explorerForm").addEventListener("submit", async (event) => {
      event.preventDefault();
      const data = formToObject(event.currentTarget);
      let query = {};
      let body;
      try {
        query = data.query ? JSON.parse(data.query) : {};
        body = data.body ? JSON.parse(data.body) : undefined;
      } catch {
        notify("صيغة JSON غير صحيحة في query/body", true);
        return;
      }

      const result = await apiCall(data.path, { method: data.method, query, body });
      writeJson("explorerRaw", result.data || { status: result.status });
    });
  }

  function bindTopbar() {
    ui.baseUrlInput.value = state.baseUrl;
    ui.saveBaseUrlBtn.addEventListener("click", () => {
      state.baseUrl = normalizeBaseUrl(ui.baseUrlInput.value) || window.location.origin;
      persistState();
      notify("تم حفظ Base URL");
    });
  }

  async function bootstrap() {
    bindNavigation();
    bindTopbar();
    bindAuthActions();
    bindCustomers();
    bindAccounts();
    bindMaterials();
    bindBills();
    bindExplorer();
    syncAuthWidgets();

    document.getElementById("refreshDashboardBtn").addEventListener("click", () => {
      loadDashboard().catch((error) => {
        notify(`فشل تحديث اللوحة: ${error.message}`, true);
      });
    });

    loadDashboard().catch(() => {
      writeJson("dashboardRaw", { message: "Dashboard requires valid API URL and authentication for protected endpoints." });
    });
  }

  bootstrap();
})();
