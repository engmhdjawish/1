# Stitch designs curation and API binding

This document is the cleaned handoff for the two exported Stitch bundles:

- `designs/stitch-designs1.zip` (mostly admin/dashboard iterations)
- `designs/stitch-designs2.zip` (customer + accountant + newer mixed iterations)

Goal: remove duplicate confusion, select one canonical screen per function, and define a direct binding plan to the current API and portal backend.

---

## 1) Canonical screen set (selected)

### Admin panel (final picks)

| Function | Canonical source file | Notes |
|---|---|---|
| Admin dashboard | `stitch-designs1/stitch_/_1/code.html` | Best KPI + action queue structure |
| Customers management | `stitch-designs1/stitch_/_13/code.html` | Includes tabs + actions + drawer pattern |
| Orders management | `stitch-designs1/stitch_/_4/code.html` | Has details panel and operational workflow |
| Share links management | `stitch-designs1/stitch_/_11/code.html` | Most complete CRUD + KPI state |
| Home content management | `stitch-designs1/stitch_/_10/code.html` | Best sections + banners composition |
| Users and roles | `stitch-designs1/stitch_/_12/code.html` | Clean roles matrix + user table |
| Settings | `stitch-designs1/stitch_/_6/code.html` | Best tabbed settings baseline |

### Customer-facing portal (final picks)

| Function | Canonical source file | Notes |
|---|---|---|
| Public home | `stitch-designs2/stitch_/_15/code.html` | Best hero + sections + CTA |
| Store/catalog | `stitch-designs2/stitch_/_10/code.html` | Filters + grouping visual + chips |
| Login | `stitch-designs2/stitch_/_14/code.html` | Clean auth layout |
| Register | `stitch-designs2/stitch_/_7/code.html` | Registration form in same style |
| Account states (pending/rejected/suspended) | `stitch-designs2/stitch_/_5/code.html` | Matches approval workflow |
| My account | `stitch-designs2/stitch_/_6/code.html` | Profile + security tabs |
| My orders | `stitch-designs2/stitch_/_2/code.html` | List + statuses |
| Order details | `stitch-designs2/stitch_/_3/code.html` | Detail/timeline structure |
| Cart | `stitch-designs2/stitch_/_11/code.html` | Cart + order summary |
| Checkout | `stitch-designs2/stitch_/_4/code.html` | Confirmation flow |
| Shared link page (`/l/{token}`) | `stitch-designs2/stitch_/_1/code.html` | Includes invalid/expired state |

### Accountant views (final picks)

| Function | Canonical source file | Notes |
|---|---|---|
| Accountant dashboard | `stitch-designs2/stitch_/_12/code.html` | Financial KPI composition |
| Sync queue with Amine | `stitch-designs2/stitch_/_13/code.html` | Queue + retry style |
| Financial reports | `stitch-designs2/stitch_/_16/code.html` | Best report/filter layout |
| Customer statement | `stitch-designs2/stitch_/_17/code.html` | Statement + movement details |

---

## 2) Duplicate screens intentionally deprioritized

These files are valid iterations but not selected as canonical baseline:

- `stitch-designs1/stitch_/_9/code.html`, `stitch-designs1/stitch_/_7/code.html` (alternate dashboard variants)
- `stitch-designs1/stitch_/_2/code.html` (older orders variant)
- `stitch-designs1/stitch_/_8/code.html` (alternate home-content composition)
- `stitch-designs1/stitch_/_3/code.html`, `stitch-designs1/stitch_/_14/code.html` (older share/customers variants)
- `stitch-designs2/stitch_/_8/code.html`, `stitch-designs2/stitch_/_9/code.html` (alternate admin/settings variants)

Reason: selected set has better operational completeness and clearer flow-to-flow consistency.

---

## 3) Unified route linking plan (portal + admin)

### Admin routes

- `/dashboard/index.php` -> admin dashboard
- `/dashboard/customers.php` -> customers management
- `/dashboard/orders.php` -> orders management
- `/dashboard/share-links.php` -> share links
- `/dashboard/home-sections.php` -> homepage content
- `/dashboard/users.php` -> users and roles
- `/dashboard/settings.php` -> settings
- `/dashboard/accounting.php` -> accountant dashboard
- `/dashboard/accounting-sync.php` -> sync queue
- `/dashboard/accounting-reports.php` -> financial reports
- `/dashboard/accounting-statement.php` -> customer statement

### Customer routes

- `/index.php` -> public home
- `/store.php` -> catalog
- `/login.php` -> login
- `/register.php` -> register
- `/account/index.php` -> account profile
- `/account/orders.php` -> my orders
- `/account/orders/{id}` (or query param) -> order details
- `/cart.php` -> cart
- `/checkout.php` -> checkout
- `/l/{token}` -> shared link storefront

---

## 4) API/Backend integration matrix

## 4.1 Ready now (can bind directly)

### Materials-driven screens

- Store/catalog
- Home sections preview
- Shared link product listing

Use:

- `GET /api/materials`
  - `includeResultFilters=true`
  - `groupBy=...` (optional)
  - `sort=...` (multi-key)
- `GET /api/materials/{guid}`
- `GET /api/materials/filter-options` (global options only)

### Account statement screens

- Accountant customer statement
- Customer account statement summary blocks

Use:

- `GET /api/accounts/summary`
- `GET /api/accounts/statement`
- `GET /api/accounts/general-ledger` (when source drill-down is needed)

### Base health/auth/admin

- `GET /api/health`
- `/api/auth/*`
- `/api/admin/*` (users/roles/permissions)

## 4.2 Partial / blocked (needs backend completion)

The current `ExistingDb.Api` branch does **not** expose order-management/sync endpoints for portal orders.
So these screens should bind to portal backend service first (PostgreSQL `portal_db`) and later bridge to Amine:

- Admin orders management
- Share-link operational order actions
- Accountant sync queue
- Financial reports based on portal orders

Needed backend contracts (to implement):

- `GET /portal/orders`, `GET /portal/orders/{id}`
- `PATCH /portal/orders/{id}/status`
- `POST /portal/orders/{id}/sync`
- `GET /portal/orders/sync-queue`
- `GET /portal/reports/financial`

Plus service-to-service job/handler that writes:

- `amine_bill_guid`
- `amine_sync_status` (`none|pending|synced|failed`)
- `amine_synced_at`
- `amine_sync_error`

---

## 5) Required cleanup before coding UI pages

1. Replace mixed English labels in selected HTML references with Arabic-only UI copy.
2. Normalize sidebar labels and route names across all selected pages.
3. Keep one status vocabulary across admin/customer/accounting:
   - `new`, `in_review`, `confirmed`, `cancelled`
   - sync: `none`, `pending`, `synced`, `failed`
4. Standardize call-to-action colors:
   - Primary action: red (`#D81921`)
   - Success: green
   - Warning/pending: yellow
   - Destructive: red light/error container
5. Enforce one source of truth for product list interaction:
   - filters <- `resultFilters`
   - grouping <- `grouping`
   - sorting <- `sort`

---

## 6) Implementation order (recommended)

1. Shared admin layout + route shell
2. Customers admin page (portal DB)
3. Orders page (portal DB)
4. Share links page
5. Home sections + banners page
6. Users/roles page
7. Settings page
8. Customer pages (home/store/cart/checkout/account)
9. Accountant pages wired to portal DB + statement from API

