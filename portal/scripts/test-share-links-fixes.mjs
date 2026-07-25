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

test('share-links save JSON response redirects to list', () => {
  const controller = fs.readFileSync(
    path.resolve(__dirname, '../public/dashboard/share-links.php'),
    'utf8',
  );
  assert.match(controller, /'redirect' => '\/dashboard\/share-links\.php\?saved=1'/);
  assert.match(controller, /'redirect' => '\/dashboard\/share-links\.php\?deleted=1'/);
});
