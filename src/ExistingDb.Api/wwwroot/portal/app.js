(function () {
  const storageKeys = {
    baseUrl: "portal.baseUrl",
    accessToken: "portal.accessToken",
    refreshToken: "portal.refreshToken"
  };

  const state = {
    baseUrl: normalizeBaseUrl(localStorage.getItem(storageKeys.baseUrl) || window.location.origin),
    accessToken: localStorage.getItem(storageKeys.accessToken) || "",
    refreshToken: localStorage.getItem(storageKeys.refreshToken) || "",
    loadingCount: 0,
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
      types: [],
      selectedGuid: ""
    },
    materials: {
      page: 1,
      pageSize: 24,
      totalCount: 0,
      search: "",
      selectedGuid: "",
      currentItems: []
    },
    customers: {
      page: 1,
      pageSize: 40,
      totalCount: 0,
      search: "",
      selectedCustomerGuid: "",
      selectedAccountGuid: "",
      fromDate: "",
      toDate: ""
    }
  };

  const imageCache = new Map();
  const ui = {
    rawOutput: document.getElementById("rawOutput"),
    toast: document.getElementById("toast"),
    loadingOverlay: document.getElementById("loadingOverlay"),
    authStatePill: document.getElementById("authStatePill"),
    baseUrlInput: document.getElementById("baseUrlInput"),
    pageTitle: document.getElementById("pageTitle"),
    pageSubtitle: document.getElementById("pageSubtitle")
  };

  function normalizeBaseUrl(value) {
    return String(value || "").trim().replace(/\/+$/, "");
  }

  function persistState() {
    localStorage.setItem(storageKeys.baseUrl, state.baseUrl);
    localStorage.setItem(storageKeys.accessToken, state.accessToken);
    localStorage.setItem(storageKeys.refreshToken, state.refreshToken);
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
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) return "-";
    return parsed.toLocaleDateString("ar-SY");
  }

  function formatNumber(value) {
    if (value === null || value === undefined || value === "") return "-";
    const number = Number(value);
    if (Number.isNaN(number)) return String(value);
    return number.toLocaleString("ar-SY", { maximumFractionDigits: 2 });
  }

  function notify(message, isError) {
    ui.toast.textContent = message;
    ui.toast.style.borderColor = isError ? "#b91c1c" : "#16a34a";
    ui.toast.classList.add("show");
    clearTimeout(notify.timer);
    notify.timer = setTimeout(() => ui.toast.classList.remove("show"), 2600);
  }

  function writeRaw(value) {
    ui.rawOutput.textContent = typeof value === "string"
      ? value
      : JSON.stringify(value, null, 2);
  }

  function setLoading(flag) {
    state.loadingCount += flag ? 1 : -1;
    if (state.loadingCount < 0) {
      state.loadingCount = 0;
    }

    ui.loadingOverlay.classList.toggle("hidden", state.loadingCount === 0);
  }

  function getItems(payload) {
    if (!payload) return [];
    if (Array.isArray(payload)) return payload;
    return payload.items || [];
  }

  function toQueryString(query) {
    const searchParams = new URLSearchParams();
    Object.entries(query || {}).forEach(([key, value]) => {
      if (value === null || value === undefined || value === "") return;
      searchParams.set(key, String(value));
    });
    const encoded = searchParams.toString();
    return encoded ? `?${encoded}` : "";
  }

  async function apiCall(path, options) {
    const request = options || {};
    const headers = request.headers || {};
    if (!request.noAuth && state.accessToken) {
      headers.Authorization = `Bearer ${state.accessToken}`;
    }

    const method = request.method || "GET";
    const url = path.startsWith("http://") || path.startsWith("https://")
      ? path
      : `${state.baseUrl}${path}${toQueryString(request.query)}`;

    const fetchOptions = { method, headers };
    if (request.body !== undefined && request.body !== null) {
      headers["Content-Type"] = "application/json";
      fetchOptions.body = JSON.stringify(request.body);
    }

    setLoading(true);
    try {
      const response = await fetch(url, fetchOptions);
      let payload = null;
      if (response.status !== 204) {
        const contentType = response.headers.get("content-type") || "";
        payload = contentType.includes("application/json")
          ? await response.json()
          : await response.text();
      }

      return { ok: response.ok, status: response.status, data: payload };
    } catch (error) {
      return { ok: false, status: 0, data: { message: error.message } };
    } finally {
      setLoading(false);
    }
  }

  async function openFileInNewTab(path) {
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
      const objectUrl = URL.createObjectURL(blob);
      window.open(objectUrl, "_blank");
      setTimeout(() => URL.revokeObjectURL(objectUrl), 20000);
    } catch (error) {
      notify(`تعذر فتح الملف: ${error.message}`, true);
    } finally {
      setLoading(false);
    }
  }

  async function loadProtectedImage(path) {
    if (!path) {
      return "";
    }

    if (imageCache.has(path)) {
      return imageCache.get(path);
    }

    const headers = {};
    if (state.accessToken) {
      headers.Authorization = `Bearer ${state.accessToken}`;
    }

    try {
      const response = await fetch(`${state.baseUrl}${path}`, { headers });
      if (!response.ok) {
        return "";
      }

      const blob = await response.blob();
      const objectUrl = URL.createObjectURL(blob);
      imageCache.set(path, objectUrl);
      return objectUrl;
    } catch {
      return "";
    }
  }

  function setAuthPill(text, isSuccess) {
    ui.authStatePill.textContent = text;
    ui.authStatePill.className = `pill ${isSuccess ? "success" : "danger"}`;
  }

  function syncAuthUi() {
    setAuthPill(state.accessToken ? "متصل" : "غير مسجل", !!state.accessToken);
  }

  function bindNavigation() {
    const pagesMeta = {
      overview: {
        title: "لوحة التحكم",
        subtitle: "مؤشرات عامة لحالة النظام"
      },
      documents: {
        title: "الفواتير والسندات",
        subtitle: "تصفح سلس + تفاصيل كاملة + فلترة ديناميكية"
      },
      inventory: {
        title: "المواد والمخزون",
        subtitle: "كتالوج عملي مع صور وتفاصيل المادة"
      },
      customers: {
        title: "العملاء والحسابات",
        subtitle: "اختيار العميل واستعراض الملخص وكشف الحساب"
      },
      integration: {
        title: "مركز التكامل",
        subtitle: "مختبر API لتجربة أي endpoint"
      }
    };

    document.querySelectorAll(".menu-item").forEach((button) => {
      button.addEventListener("click", () => {
        document.querySelectorAll(".menu-item").forEach((item) => item.classList.remove("active"));
        button.classList.add("active");
        const targetPage = button.dataset.page;

        Object.entries(pagesMeta).forEach(([key, value]) => {
          const section = document.getElementById(`page-${key}`);
          section.classList.toggle("active", key === targetPage);
          if (key === targetPage) {
            ui.pageTitle.textContent = value.title;
            ui.pageSubtitle.textContent = value.subtitle;
          }
        });
      });
    });
  }

  function bindSessionControls() {
    ui.baseUrlInput.value = state.baseUrl;

    document.getElementById("saveBaseUrlBtn").addEventListener("click", () => {
      state.baseUrl = normalizeBaseUrl(ui.baseUrlInput.value) || window.location.origin;
      persistState();
      notify("تم حفظ رابط السيرفر");
    });

    document.getElementById("quickLoginForm").addEventListener("submit", async (event) => {
      event.preventDefault();
      const form = new FormData(event.currentTarget);
      const payload = {
        userName: String(form.get("userName") || "").trim(),
        password: String(form.get("password") || "").trim()
      };
      const result = await apiCall("/api/auth/login", { method: "POST", body: payload, noAuth: true });
      writeRaw(result.data || { status: result.status });
      if (!result.ok) {
        notify("فشل تسجيل الدخول", true);
        return;
      }

      state.accessToken = result.data.accessToken || "";
      state.refreshToken = result.data.refreshToken || "";
      persistState();
      syncAuthUi();
      notify(`مرحبًا ${result.data.displayName || result.data.userName || ""}`.trim());
      await loadOverview();
      await loadDocumentTypes();
    });

    document.getElementById("logoutBtn").addEventListener("click", async () => {
      if (state.refreshToken) {
        await apiCall("/api/auth/logout", {
          method: "POST",
          body: { refreshToken: state.refreshToken }
        });
      }

      state.accessToken = "";
      state.refreshToken = "";
      persistState();
      syncAuthUi();
      notify("تم تسجيل الخروج");
    });

    document.getElementById("clearRawBtn").addEventListener("click", () => {
      writeRaw({ message: "تم مسح المخرجات" });
    });
  }

  async function loadOverview() {
    const [health, customers, materials, invoices, vouchers] = await Promise.all([
      apiCall("/api/health", { noAuth: true }),
      apiCall("/api/customers", { query: { page: 1, pageSize: 1 } }),
      apiCall("/api/materials", { query: { page: 1, pageSize: 1 } }),
      apiCall("/api/bills/invoices", { query: { page: 1, pageSize: 5 } }),
      apiCall("/api/bills/vouchers", { query: { page: 1, pageSize: 5 } })
    ]);

    document.getElementById("kpiHealth").textContent = health.ok ? "متاح" : `HTTP ${health.status}`;
    document.getElementById("kpiCustomers").textContent = formatNumber(customers.data?.totalCount);
    document.getElementById("kpiMaterials").textContent = formatNumber(materials.data?.totalCount);
    document.getElementById("kpiInvoices").textContent = formatNumber(invoices.data?.totalCount);
    document.getElementById("kpiVouchers").textContent = formatNumber(vouchers.data?.totalCount);

    renderOverviewTable("overviewInvoicesTable", getItems(invoices.data), true);
    renderOverviewTable("overviewVouchersTable", getItems(vouchers.data), false);
    writeRaw({
      health: health.data,
      customers: customers.data,
      materials: materials.data,
      invoices: invoices.data,
      vouchers: vouchers.data
    });
  }

  function renderOverviewTable(tableId, items, isInvoice) {
    const tbody = document.querySelector(`#${tableId} tbody`);
    const accountOrCustomer = isInvoice ? "customerName" : "accountName";
    tbody.innerHTML = items.map((item) => `
      <tr>
        <td>${safeHtml(item.number ?? "-")}</td>
        <td>${safeHtml(formatDate(item.date))}</td>
        <td>${safeHtml(item.typeName || item.typeCode || "-")}</td>
        <td>${safeHtml(item[accountOrCustomer] || "-")}</td>
        <td>${safeHtml(formatNumber(item.netAmount))}</td>
      </tr>
    `).join("");
  }

  function bindOverviewActions() {
    document.getElementById("overviewReloadInvoicesBtn").addEventListener("click", loadOverview);
    document.getElementById("overviewReloadVouchersBtn").addEventListener("click", loadOverview);
  }

  function bindDocuments() {
    document.getElementById("docModeInvoicesBtn").addEventListener("click", async () => {
      switchDocumentMode("invoices");
      await loadDocumentTypes();
      await loadDocuments();
    });

    document.getElementById("docModeVouchersBtn").addEventListener("click", async () => {
      switchDocumentMode("vouchers");
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

    document.querySelector("#documentsTable tbody").addEventListener("click", async (event) => {
      const row = event.target.closest("tr[data-guid]");
      if (!row) return;
      const guid = row.dataset.guid;
      state.documents.selectedGuid = guid;
      highlightSelectedRow("#documentsTable", guid);
      await loadDocumentDetails(guid);
    });
  }

  function switchDocumentMode(mode) {
    state.documents.mode = mode;
    state.documents.page = 1;
    state.documents.typeGuid = "";
    state.documents.selectedGuid = "";
    document.getElementById("docModeInvoicesBtn").classList.toggle("active", mode === "invoices");
    document.getElementById("docModeVouchersBtn").classList.toggle("active", mode === "vouchers");
    document.getElementById("documentsTableTitle").textContent = mode === "invoices" ? "قائمة الفواتير" : "قائمة السندات";
    document.getElementById("documentDetailCard").innerHTML = "";
    document.querySelector("#documentItemsTable tbody").innerHTML = "";
    document.getElementById("documentDetailHint").textContent = "اختر مستندًا من القائمة";
  }

  async function loadDocumentTypes() {
    const endpoint = state.documents.mode === "invoices"
      ? "/api/bills/invoice-types"
      : "/api/bills/voucher-types";
    const result = await apiCall(endpoint);
    if (!result.ok) {
      state.documents.types = [];
      renderDocumentTypeChips();
      notify("تعذر تحميل أنواع المستندات", true);
      return;
    }

    state.documents.types = Array.isArray(result.data) ? result.data : [];
    renderDocumentTypeChips();
  }

  function renderDocumentTypeChips() {
    const container = document.getElementById("documentTypeChips");
    const allChipClass = state.documents.typeGuid ? "chip" : "chip active";
    const allChip = `<button class="${allChipClass}" data-type-guid="">الكل</button>`;
    const chips = state.documents.types.map((type) => {
      const activeClass = state.documents.typeGuid === String(type.typeGuid) ? "chip active" : "chip";
      const label = `${type.typeName || type.typeCode || "نوع"} (${type.documentsCount || 0})`;
      return `<button class="${activeClass}" data-type-guid="${type.typeGuid}">${safeHtml(label)}</button>`;
    }).join("");
    container.innerHTML = allChip + chips;

    container.querySelectorAll("button[data-type-guid]").forEach((button) => {
      button.addEventListener("click", async () => {
        state.documents.typeGuid = button.dataset.typeGuid || "";
        if (state.documents.typeGuid) {
          state.documents.type = "";
          const form = document.getElementById("documentsFilterForm");
          form.querySelector('input[name="type"]').value = "";
        }

        state.documents.page = 1;
        renderDocumentTypeChips();
        await loadDocuments();
      });
    });
  }

  async function loadDocuments() {
    const query = {
      page: state.documents.page,
      pageSize: state.documents.pageSize,
      search: state.documents.search,
      type: state.documents.type,
      fromDate: state.documents.fromDate,
      toDate: state.documents.toDate
    };
    if (state.documents.typeGuid) {
      query.typeGuid = state.documents.typeGuid;
    }

    const endpoint = state.documents.mode === "invoices" ? "/api/bills/invoices" : "/api/bills/vouchers";
    const result = await apiCall(endpoint, { query });
    writeRaw(result.data || { status: result.status, endpoint, query });
    if (!result.ok) {
      notify("فشل تحميل المستندات", true);
      renderDocumentsTable([]);
      return;
    }

    state.documents.totalCount = result.data.totalCount || 0;
    renderDocumentsTable(getItems(result.data));
    updateDocumentPager();
  }

  function renderDocumentsTable(items) {
    const tbody = document.querySelector("#documentsTable tbody");
    tbody.innerHTML = items.map((item) => `
      <tr data-guid="${item.guid}">
        <td>${safeHtml(item.number ?? "-")}</td>
        <td>${safeHtml(formatDate(item.date))}</td>
        <td>${safeHtml(item.typeName || item.typeCode || "-")}</td>
        <td>${safeHtml(item.settlementTypeName || "-")}</td>
        <td>${safeHtml(item.customerName || "-")}</td>
        <td>${safeHtml(item.accountName || "-")}</td>
        <td>${safeHtml(formatNumber(item.totalAmount))}</td>
        <td>${safeHtml(formatNumber(item.netAmount))}</td>
      </tr>
    `).join("");

    if (state.documents.selectedGuid) {
      highlightSelectedRow("#documentsTable", state.documents.selectedGuid);
    }
  }

  function updateDocumentPager() {
    const totalPages = Math.max(1, Math.ceil((state.documents.totalCount || 0) / state.documents.pageSize));
    document.getElementById("documentsPageInfo").textContent = `صفحة ${state.documents.page} من ${totalPages} (${state.documents.totalCount} سجل)`;
  }

  async function loadDocumentDetails(guid) {
    const endpoint = state.documents.mode === "invoices"
      ? `/api/bills/invoices/${guid}`
      : `/api/bills/vouchers/${guid}`;
    const result = await apiCall(endpoint);
    writeRaw(result.data || { status: result.status, endpoint });
    if (!result.ok) {
      notify("فشل جلب تفاصيل المستند", true);
      return;
    }

    const documentInfo = result.data.document || {};
    document.getElementById("documentDetailHint").textContent = `${state.documents.mode === "invoices" ? "فاتورة" : "سند"} رقم ${documentInfo.number ?? "-"}`;
    renderDocumentDetailCard(documentInfo);
    renderDocumentItems(result.data.items || []);
  }

  function renderDocumentDetailCard(documentInfo) {
    const card = document.getElementById("documentDetailCard");
    card.innerHTML = `
      <div class="doc-summary-grid">
        ${renderSummaryItem("النوع", documentInfo.typeName || documentInfo.typeCode || "-")}
        ${renderSummaryItem("التسوية", documentInfo.settlementTypeName || "-")}
        ${renderSummaryItem("العميل", documentInfo.customerName || "-")}
        ${renderSummaryItem("الحساب", documentInfo.accountName || "-")}
        ${renderSummaryItem("الإجمالي", formatNumber(documentInfo.totalAmount))}
        ${renderSummaryItem("الحسم", formatNumber(documentInfo.totalDiscount))}
        ${renderSummaryItem("الإضافات", formatNumber(documentInfo.totalAdditions))}
        ${renderSummaryItem("الصافي", formatNumber(documentInfo.netAmount))}
        ${renderSummaryItem("الملاحظة", documentInfo.notes || "-")}
      </div>
    `;
  }

  function renderSummaryItem(label, value) {
    return `<div class="doc-summary-item"><span>${safeHtml(label)}</span><strong>${safeHtml(value)}</strong></div>`;
  }

  function renderDocumentItems(items) {
    const tbody = document.querySelector("#documentItemsTable tbody");
    if (!items.length) {
      tbody.innerHTML = `<tr><td colspan="6" class="muted">لا توجد عناصر لهذا المستند.</td></tr>`;
      return;
    }

    tbody.innerHTML = items.map((item) => `
      <tr>
        <td>${safeHtml(item.materialName || item.materialCode || item.materialGuid || "-")}</td>
        <td>${safeHtml(formatNumber(item.quantity))}</td>
        <td>${safeHtml(formatNumber(item.price))}</td>
        <td>${safeHtml(formatNumber(item.discount))}</td>
        <td>${safeHtml(formatNumber(item.additions))}</td>
        <td>${safeHtml(formatNumber(item.lineTotal))}</td>
      </tr>
    `).join("");
  }

  function bindInventory() {
    document.getElementById("materialsFilterForm").addEventListener("submit", async (event) => {
      event.preventDefault();
      const form = new FormData(event.currentTarget);
      state.materials.search = String(form.get("search") || "").trim();
      state.materials.page = Number(form.get("page") || 1);
      state.materials.pageSize = Number(form.get("pageSize") || 24);
      await loadMaterials();
    });

    document.getElementById("materialsFilterOptionsBtn").addEventListener("click", async () => {
      const result = await apiCall("/api/materials/filter-options");
      writeRaw(result.data || { status: result.status });
      notify(result.ok ? "تم جلب خيارات الفلترة" : "تعذر جلب خيارات الفلترة", !result.ok);
    });

    document.getElementById("materialsGrid").addEventListener("click", async (event) => {
      const card = event.target.closest(".material-card[data-guid]");
      if (!card) return;
      const guid = card.dataset.guid;
      state.materials.selectedGuid = guid;
      highlightSelectedCard(guid);
      await loadMaterialDetails(guid);
    });

    document.querySelector("#materialImagesTable tbody").addEventListener("click", async (event) => {
      const button = event.target.closest("button[data-action]");
      if (!button) return;
      const imageGuid = button.dataset.id;
      const action = button.dataset.action;
      if (action === "file") {
        await openFileInNewTab(`/api/material-images/${imageGuid}/file`);
      } else {
        await openFileInNewTab(`/api/material-images/${imageGuid}/thumbnail`);
      }
    });
  }

  async function loadMaterials() {
    const query = {
      search: state.materials.search,
      page: state.materials.page,
      pageSize: state.materials.pageSize
    };
    const result = await apiCall("/api/materials", { query });
    writeRaw(result.data || { status: result.status, query });
    if (!result.ok) {
      notify("فشل تحميل المواد", true);
      document.getElementById("materialsGrid").innerHTML = "";
      return;
    }

    state.materials.totalCount = result.data.totalCount || 0;
    state.materials.currentItems = getItems(result.data);
    renderMaterialsGrid(state.materials.currentItems);
  }

  function renderMaterialsGrid(items) {
    const grid = document.getElementById("materialsGrid");
    grid.innerHTML = items.map((item) => {
      const selectedClass = state.materials.selectedGuid === item.guid ? "material-card selected" : "material-card";
      const imageToken = item.pictureGuid ? `img-${item.pictureGuid}` : "";
      return `
        <article class="${selectedClass}" data-guid="${item.guid}">
          <div class="material-thumb">
            ${item.pictureGuid
              ? `<img alt="${safeHtml(item.name || "material")}" data-image-token="${imageToken}" src="" />`
              : `<span class="muted">لا صورة</span>`
            }
          </div>
          <div class="material-info">
            <h4>${safeHtml(item.name || "-")}</h4>
            <p>${safeHtml(item.code || "-")} • ${safeHtml(formatNumber(item.qty))}</p>
          </div>
        </article>
      `;
    }).join("");

    hydrateMaterialThumbnails(items).catch(() => {});
  }

  async function hydrateMaterialThumbnails(items) {
    for (const material of items) {
      if (!material.pictureGuid) continue;
      const imagePath = `/api/material-images/${material.pictureGuid}/thumbnail`;
      const objectUrl = await loadProtectedImage(imagePath);
      if (!objectUrl) continue;
      const imageElement = document.querySelector(`img[data-image-token="img-${material.pictureGuid}"]`);
      if (imageElement) {
        imageElement.src = objectUrl;
      }
    }
  }

  function highlightSelectedCard(guid) {
    document.querySelectorAll(".material-card").forEach((card) => {
      card.classList.toggle("selected", card.dataset.guid === guid);
    });
  }

  async function loadMaterialDetails(materialGuid) {
    const [material, images] = await Promise.all([
      apiCall(`/api/materials/${materialGuid}`),
      apiCall(`/api/materials/${materialGuid}/images`)
    ]);
    writeRaw({ material: material.data, images: images.data });
    if (!material.ok) {
      notify("تعذر تحميل تفاصيل المادة", true);
      return;
    }

    renderMaterialDetailCard(material.data);
    renderMaterialImages(images.ok ? (images.data || []) : []);
  }

  function renderMaterialDetailCard(material) {
    const card = document.getElementById("materialDetailCard");
    document.getElementById("materialDetailHint").textContent = `${material.name || "-"} (${material.code || "-"})`;
    card.innerHTML = `
      <div class="doc-summary-grid">
        ${renderSummaryItem("رقم المادة", material.number ?? "-")}
        ${renderSummaryItem("الاسم", material.name || "-")}
        ${renderSummaryItem("الكود", material.code || "-")}
        ${renderSummaryItem("الكمية", formatNumber(material.qty))}
        ${renderSummaryItem("السعر جملة", formatNumber(material.whole))}
        ${renderSummaryItem("السعر نصف جملة", formatNumber(material.half))}
        ${renderSummaryItem("سعر شراء", formatNumber(material.endUser))}
        ${renderSummaryItem("المجموعة", material.groupGuid || "-")}
      </div>
    `;
  }

  function renderMaterialImages(images) {
    const tbody = document.querySelector("#materialImagesTable tbody");
    if (!images.length) {
      tbody.innerHTML = `<tr><td colspan="3" class="muted">لا توجد صور لهذه المادة.</td></tr>`;
      return;
    }

    tbody.innerHTML = images.map((image) => `
      <tr>
        <td>${safeHtml(image.name || "-")}</td>
        <td>${safeHtml(image.thumbnailPath || image.filePath || "-")}</td>
        <td class="row">
          <button class="btn ghost" data-action="thumbnail" data-id="${image.guid}">مصغرة</button>
          <button class="btn" data-action="file" data-id="${image.guid}">الملف</button>
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
      state.customers.pageSize = Number(form.get("pageSize") || 40);
      await loadCustomers();
    });

    document.querySelector("#customersTable tbody").addEventListener("click", async (event) => {
      const row = event.target.closest("tr[data-guid]");
      if (!row) return;
      const customerGuid = row.dataset.guid;
      const accountGuid = row.dataset.accountGuid || "";
      state.customers.selectedCustomerGuid = customerGuid;
      state.customers.selectedAccountGuid = accountGuid;
      highlightSelectedRow("#customersTable", customerGuid);

      const form = document.getElementById("customerAccountFilterForm");
      form.querySelector('input[name="customerGuid"]').value = customerGuid;
      form.querySelector('input[name="accountGuid"]').value = accountGuid;
      await loadCustomerAccountData();
    });

    document.getElementById("reloadCustomerAccountBtn").addEventListener("click", loadCustomerAccountData);
  }

  async function loadCustomers() {
    const query = {
      search: state.customers.search,
      page: state.customers.page,
      pageSize: state.customers.pageSize
    };
    const result = await apiCall("/api/customers", { query });
    writeRaw(result.data || { status: result.status, query });
    if (!result.ok) {
      notify("فشل تحميل العملاء", true);
      return;
    }

    state.customers.totalCount = result.data.totalCount || 0;
    const tbody = document.querySelector("#customersTable tbody");
    tbody.innerHTML = getItems(result.data).map((item) => `
      <tr data-guid="${item.guid}" data-account-guid="${item.accountGuid || ""}">
        <td>${safeHtml(item.number ?? "-")}</td>
        <td>${safeHtml(item.customerName || "-")}</td>
        <td>${safeHtml(item.mobile || "-")}</td>
        <td>${safeHtml(item.accountGuid || "-")}</td>
      </tr>
    `).join("");
  }

  async function loadCustomerAccountData() {
    const form = document.getElementById("customerAccountFilterForm");
    const formData = new FormData(form);
    const accountGuid = String(formData.get("accountGuid") || "").trim();
    const customerGuid = String(formData.get("customerGuid") || "").trim();
    const fromDate = String(formData.get("fromDate") || "").trim();
    const toDate = String(formData.get("toDate") || "").trim();
    if (!accountGuid && !customerGuid) {
      notify("اختر عميلًا أو املأ accountGuid", true);
      return;
    }

    const summaryQuery = { accountGuid, customerGuid };
    const statementQuery = {
      accountGuid,
      customerGuid,
      fromDate,
      toDate,
      page: 1,
      pageSize: 120
    };

    const [summary, statement] = await Promise.all([
      apiCall("/api/accounts/summary", { query: summaryQuery }),
      apiCall("/api/accounts/statement", { query: statementQuery })
    ]);
    writeRaw({ summary: summary.data, statement: statement.data });

    if (!summary.ok) {
      notify("فشل تحميل ملخص الحساب", true);
      return;
    }

    renderAccountSummary(summary.data);
    renderCustomerStatement(statement.ok ? (statement.data.entries || []) : []);
  }

  function renderAccountSummary(summary) {
    const card = document.getElementById("accountSummaryCard");
    card.innerHTML = `
      <div class="doc-summary-grid">
        ${renderSummaryItem("اسم العميل", summary.customerName || "-")}
        ${renderSummaryItem("اسم الحساب", summary.accountName || "-")}
        ${renderSummaryItem("كود الحساب", summary.accountCode || "-")}
        ${renderSummaryItem("مدين حالي", formatNumber(summary.currentDebit))}
        ${renderSummaryItem("دائن حالي", formatNumber(summary.currentCredit))}
        ${renderSummaryItem("الرصيد", formatNumber(summary.currentBalance))}
        ${renderSummaryItem("آخر دائن", summary.lastCreditorMovement?.reasonDocumentType || "-")}
        ${renderSummaryItem("آخر مدين", summary.lastDebtorMovement?.reasonDocumentType || "-")}
      </div>
    `;
  }

  function renderCustomerStatement(entries) {
    const tbody = document.querySelector("#customerStatementTable tbody");
    if (!entries.length) {
      tbody.innerHTML = `<tr><td colspan="7" class="muted">لا توجد حركات ضمن الفلاتر الحالية.</td></tr>`;
      return;
    }

    tbody.innerHTML = entries.map((entry) => `
      <tr>
        <td>${safeHtml(formatDate(entry.date))}</td>
        <td>${safeHtml(entry.number ?? "-")}</td>
        <td>${safeHtml(formatNumber(entry.debit))}</td>
        <td>${safeHtml(formatNumber(entry.credit))}</td>
        <td>${safeHtml(entry.reasonType || "-")}</td>
        <td>${safeHtml(entry.reasonDocumentType || "-")}</td>
        <td>${safeHtml(formatNumber(entry.runningBalance))}</td>
      </tr>
    `).join("");
  }

  function bindApiLab() {
    document.getElementById("apiLabForm").addEventListener("submit", async (event) => {
      event.preventDefault();
      const formData = new FormData(event.currentTarget);
      const method = String(formData.get("method") || "GET");
      const path = String(formData.get("path") || "").trim();
      if (!path) {
        notify("يجب تحديد المسار", true);
        return;
      }

      let query = {};
      let body = undefined;
      try {
        query = JSON.parse(String(formData.get("query") || "{}"));
        body = JSON.parse(String(formData.get("body") || "{}"));
      } catch {
        notify("تنسيق JSON غير صحيح", true);
        return;
      }

      if (method === "GET" || method === "DELETE") {
        body = undefined;
      }

      const result = await apiCall(path, { method, query, body });
      writeRaw(result.data || { status: result.status, path, method, query, body });
      notify(result.ok ? "تم تنفيذ الطلب" : "فشل تنفيذ الطلب", !result.ok);
    });
  }

  function highlightSelectedRow(tableSelector, guid) {
    document.querySelectorAll(`${tableSelector} tbody tr[data-guid]`).forEach((row) => {
      row.classList.toggle("selected", row.dataset.guid === guid);
    });
  }

  async function bootstrap() {
    bindNavigation();
    bindSessionControls();
    bindOverviewActions();
    bindDocuments();
    bindInventory();
    bindCustomers();
    bindApiLab();
    syncAuthUi();

    await loadOverview();
    await loadDocumentTypes();
    await loadDocuments();
    await loadMaterials();
    await loadCustomers();
  }

  bootstrap().catch((error) => {
    writeRaw({ message: "Startup error", error: error.message });
    notify("حدث خطأ عند تشغيل الواجهة", true);
  });
})();
