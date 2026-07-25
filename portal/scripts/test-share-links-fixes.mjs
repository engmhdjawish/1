/**
 * Pre-merge checks for share link AJAX / filter fixes.
 * Run: node portal/scripts/test-share-links-fixes.mjs
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const publicRoot = path.resolve(__dirname, '../public');

function loadScript(relativePath) {
  const fullPath = path.join(publicRoot, relativePath);
  return fs.readFileSync(fullPath, 'utf8');
}

function makeDashboardDom() {
  return new JSDOM(`<!DOCTYPE html><html><body class="dashboard-app">
    <main data-dashboard-main data-dashboard-page-assets="share-links-form"></main>
  </body></html>`, {
    url: 'https://example.test/dashboard/share-links.php?new=1',
    runScripts: 'outside-only',
  });
}

function makeShareFormDom() {
  return new JSDOM(`<!DOCTYPE html><html><body>
    <div data-share-link-form-panel>
      <form data-store-filters-form id="share-link-form">
        <div data-store-filters-root data-store-filters-static="1">
          <div data-filter-list="materialTypes">
            <input type="search" data-filter-search="materialTypes" />
            <label class="store-filter-option store-filter-pill">
              <input type="checkbox" name="forced_material_types[]" value="A" />
              <span class="store-filter-option-text">A</span>
            </label>
          </div>
        </div>
        <div data-store-filters-root data-store-filters-static="1">
          <div data-filter-list="visibleFilters">
            <input type="search" data-filter-search="visibleFilters" />
            <label class="store-filter-option store-filter-pill">
              <input type="checkbox" name="option_visible_client_filters[]" value="search" checked />
              <span class="store-filter-option-text">بحث</span>
            </label>
          </div>
        </div>
      </form>
    </div>
  </body></html>`, {
    url: 'https://example.test/dashboard/share-links.php?new=1',
    runScripts: 'outside-only',
  });
}

test('share-links-form does not mark shells initialized when store-filters is missing', () => {
  const dom = makeShareFormDom();
  const { window } = dom;
  const script = loadScript('assets/dashboard/share-links-form.js');
  window.eval(script);

  window.portalShareLinksFormInit(window.document);

  const shells = window.document.querySelectorAll('[data-store-filters-root]');
  assert.equal(shells.length, 2);
  shells.forEach((shell) => {
    assert.notEqual(shell.dataset.shareLinkFiltersInit, '1', 'shell must stay uninitialized without store-filters');
  });
});

test('share-links-form initializes every shell once store-filters is available', () => {
  const dom = makeShareFormDom();
  const { window } = dom;
  const inits = [];

  window.portalStoreFiltersInit = (root) => {
    inits.push(root.getAttribute('data-filter-list')
      ? root.closest('[data-store-filters-root]')
      : root);
  };

  window.eval(loadScript('assets/dashboard/share-links-form.js'));
  window.portalShareLinksFormInit(window.document);

  assert.equal(inits.length, 2);
  assert.equal(
    window.document.querySelectorAll('[data-store-filters-root][data-share-link-filters-init="1"]').length,
    2,
  );
});

test('share-links-form can recover after store-filters loads late (AJAX race)', () => {
  const dom = makeShareFormDom();
  const { window } = dom;
  const inits = [];

  window.eval(loadScript('assets/dashboard/share-links-form.js'));

  window.portalShareLinksFormInit(window.document);
  assert.equal(
    window.document.querySelectorAll('[data-store-filters-root][data-share-link-filters-init="1"]').length,
    0,
  );

  window.portalStoreFiltersInit = (root) => {
    inits.push(root);
  };
  window.portalShareLinksFormInit(window.document);

  assert.equal(inits.length, 2);
  assert.equal(
    window.document.querySelectorAll('[data-store-filters-root][data-share-link-filters-init="1"]').length,
    2,
  );
});

test('store-filters bootstraps all filter shells on the page', () => {
  const dom = makeShareFormDom();
  const { window } = dom;
  const touched = new Set();

  window.portalStoreFiltersInit = (root) => {
    const shell = root.matches('[data-store-filters-root]')
      ? root
      : root.querySelector('[data-store-filters-root]');
    if (shell) {
      touched.add(shell.querySelector('[data-filter-list]')?.getAttribute('data-filter-list') || 'root');
    }
  };

  const bootstrap = `
    document.querySelectorAll('[data-store-filters-root], [data-store-catalog-root]').forEach((root) => {
      window.portalStoreFiltersInit(root);
    });
  `;
  window.eval(bootstrap);

  assert.deepEqual([...touched].sort(), ['materialTypes', 'visibleFilters']);
});

test('dashboard ensurePageAssets loads share-links scripts sequentially', () => {
  const source = loadScript('assets/dashboard/dashboard.js');
  assert.match(source, /'share-links-form':\s*\{[\s\S]*?scripts:\s*\[[\s\S]*?'\/assets\/store-filters\.js'[\s\S]*?'\/assets\/dashboard\/share-links-form\.js'/);
  assert.match(source, /for \(const href of bundle\.styles \|\| \[\]\)/);
  assert.match(source, /for \(const src of bundle\.scripts \|\| \[\]\)/);
  assert.doesNotMatch(source, /Promise\.all\([\s\S]*bundle\.scripts/);
});

test('save form markup no longer forces reload after submit', () => {
  const panelPath = path.resolve(__dirname, '../views/dashboard/partials/share-link-form-panel.php');
  const markup = fs.readFileSync(panelPath, 'utf8');
  assert.match(markup, /data-dashboard-ajax/);
  assert.doesNotMatch(markup, /id="share-link-form"[\s\S]*?data-dashboard-reload/);
  assert.match(markup, /data-dashboard-redirect="\/dashboard\/share-links\.php\?deleted=1"/);
});

test('share link load defaults keep sorting disabled without ghost sort fields', () => {
  const servicePath = path.resolve(__dirname, '../src/Services/ShareLinkService.php');
  const source = fs.readFileSync(servicePath, 'utf8');
  assert.match(source, /'allow_sorting' => \(bool\) \$defaultOptions\['allow_sorting'\]/);
  assert.match(source, /'client_sort_fields' => \$defaultOptions\['client_sort_fields'\]/);
  assert.match(source, /if \(empty\(\$result\['options'\]\['allow_sorting'\]\)\)/);
  assert.doesNotMatch(source, /if \(!\$hasClientSortFields && !empty\(\$result\['options'\]\['allow_sorting'\]\)\)/);
});

test('share add-to-cart forms use ajax hook', () => {
  const formPath = path.resolve(__dirname, '../views/partials/store-add-to-cart-form.php');
  const markup = fs.readFileSync(formPath, 'utf8');
  assert.match(markup, /data-store-add-cart="1"/);
  assert.doesNotMatch(markup, /shareTokenActive \? '' : 'data-store-add-cart/);
});

test('share-links save JSON response redirects to list', () => {
  const controller = fs.readFileSync(
    path.resolve(__dirname, '../public/dashboard/share-links.php'),
    'utf8',
  );
  assert.match(controller, /'redirect' => '\/dashboard\/share-links\.php\?saved=1'/);
  assert.match(controller, /'redirect' => '\/dashboard\/share-links\.php\?deleted=1'/);
  assert.match(controller, /if \(!\$allowSorting\) \{\s*\$clientSortFields = \[\];/);
});

test('share page keeps all link-configured visible filters in the sidebar', () => {
  const catalogView = fs.readFileSync(
    path.resolve(__dirname, '../views/store-catalog.php'),
    'utf8',
  );
  assert.match(catalogView, /if \(\$shareContext !== null\) \{\s*return true;\s*\}/);
  assert.match(catalogView, /\$renderEmptyFilterGroups = \$filtersDeferred \|\| \$shareContext !== null;/);
  assert.match(catalogView, /filterOptions\['groups'\]/);
});

test('share store options prefer link defaults over policy visible filters', () => {
  const servicePath = path.resolve(__dirname, '../src/Services/ShareLinkService.php');
  const source = fs.readFileSync(servicePath, 'utf8');
  assert.match(source, /Prefer link defaults over policy/);
  assert.doesNotMatch(source, /elseif \(\$policyVisible !== \[\]\)/);
});

test('share page scopes string filter options to merged link/policy rules', () => {
  const sharePage = fs.readFileSync(
    path.resolve(__dirname, '../public/share.php'),
    'utf8',
  );
  assert.match(sharePage, /\$applyScopedShareFilterOptions\(\$filterOptions\)/);
  assert.match(sharePage, /\$scopeStringOptionList\(\$options\['manufacturers'\]/);
});

test('share pages use the same cart drawer as the store', () => {
  const layout = fs.readFileSync(path.resolve(__dirname, '../views/layout.php'), 'utf8');
  const header = fs.readFileSync(path.resolve(__dirname, '../views/partials/site-header.php'), 'utf8');
  const cartJs = fs.readFileSync(path.resolve(__dirname, '../public/assets/store-cart.js'), 'utf8');
  assert.match(layout, /require __DIR__ \. '\/partials\/store-cart-drawer\.php'/);
  assert.doesNotMatch(layout, /if \(\$shareCartUrl === ''\)/);
  assert.doesNotMatch(header, /href=<\?= h\(\$shareCartUrl\)/);
  assert.match(header, /data-store-cart-open[\s\S]*aria-controls="store-cart-drawer"/);
  assert.match(cartJs, /bootstrap\?\.share_token[\s\S]*params\.set\('token'/);
});

test('share cart API returns full drawer payload and supports token reconcile', () => {
  const api = fs.readFileSync(path.resolve(__dirname, '../public/api/store-cart.php'), 'utf8');
  const service = fs.readFileSync(path.resolve(__dirname, '../src/Support/StoreCartApi.php'), 'utf8');
  assert.match(api, /shareState\(\$shareToken, \$reconcile\)/);
  assert.match(service, /private static function sharePayload\(/);
  assert.match(service, /private static function submitShareOrder\(/);
  assert.match(service, /private static function dispatchShare\(/);
});

test('share cart persists across product page navigation via browse context', () => {
  const productPage = fs.readFileSync(path.resolve(__dirname, '../public/product.php'), 'utf8');
  const shareAccess = fs.readFileSync(path.resolve(__dirname, '../src/Support/SharePageAccess.php'), 'utf8');
  const shareCart = fs.readFileSync(path.resolve(__dirname, '../src/Services/ShareCartService.php'), 'utf8');
  const helpers = fs.readFileSync(path.resolve(__dirname, '../views/helpers.php'), 'utf8');
  assert.match(productPage, /SharePageAccess::resolveShareBrowseContext/);
  assert.match(productPage, /ShareCartService::rememberActiveToken/);
  assert.match(productPage, /'share_token' => \$shareToken/);
  assert.match(shareAccess, /resolveShareBrowseContext/);
  assert.match(shareAccess, /ShareCartService::activeToken/);
  assert.match(shareCart, /rememberActiveToken/);
  assert.match(shareCart, /clearActiveToken/);
  assert.match(helpers, /function share_token_from_return_url/);
  assert.match(helpers, /params\['token'\] = \$shareToken/);
});

test('share page remembers active token and store clears it', () => {
  const sharePage = fs.readFileSync(path.resolve(__dirname, '../public/share.php'), 'utf8');
  const storePage = fs.readFileSync(path.resolve(__dirname, '../public/store.php'), 'utf8');
  assert.match(sharePage, /ShareCartService::rememberActiveToken\(\$shareToken\)/);
  assert.match(storePage, /ShareCartService::clearActiveToken\(\)/);
});

test('share cart drawer prefetches full payload and scopes cross-tab sync', () => {
  const cartJs = fs.readFileSync(path.resolve(__dirname, '../public/assets/store-cart.js'), 'utf8');
  const previewJs = fs.readFileSync(path.resolve(__dirname, '../public/assets/store-product-preview.js'), 'utf8');
  assert.match(cartJs, /bootstrap\?\.share_token[\s\S]*lastCartData = data/);
  assert.match(cartJs, /jawish-share-cart-sync:/);
  assert.match(previewJs, /path !== '\/share\.php'/);
});
