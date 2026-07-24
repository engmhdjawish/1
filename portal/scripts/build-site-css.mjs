#!/usr/bin/env node
/**
 * Build one minified layout CSS bundle + inline critical subset.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import CleanCSS from 'clean-css';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const cssDir = path.join(root, 'public', 'css');

const criticalSources = [
  'material-image-frame.css',
  'site-brand.css',
  'site-header.css',
  'site-header-widgets-critical.css',
];

const deferredSources = [
  'site-footer.css',
  'pwa-install.css',
  'site-page-loading.css',
  'notifications.css',
  'site-onboarding.css',
];

const storeSources = [
  'store-ui.css',
  'store-cart.css',
];

const allSources = [...criticalSources, ...deferredSources, ...storeSources];

const readSources = (files) => files.map((file) => {
  const fullPath = path.join(cssDir, file);
  if (!fs.existsSync(fullPath)) {
    throw new Error(`Missing CSS source: ${file}`);
  }

  return `/* ${file} */\n${fs.readFileSync(fullPath, 'utf8').trim()}\n`;
});

const minifier = new CleanCSS({ level: 2 });

const layoutCss = readSources(allSources).join('\n');
const layoutMin = minifier.minify(layoutCss);
if (layoutMin.errors.length > 0) {
  throw new Error(layoutMin.errors.join('\n'));
}
fs.writeFileSync(path.join(cssDir, 'site-layout.min.css'), layoutMin.styles);
console.log(`Wrote site-layout.min.css (${allSources.length} files, ${layoutMin.styles.length} bytes)`);

const tailwindPath = path.join(cssDir, 'tailwind.css');
const tailwindCss = fs.existsSync(tailwindPath)
  ? fs.readFileSync(tailwindPath, 'utf8').trim()
  : '';
if (tailwindCss === '') {
  throw new Error('Missing tailwind.css — run tailwindcss before build-site-css.mjs');
}
const appCss = `/* tailwind.css */\n${tailwindCss}\n\n${layoutCss}`;
const appMin = minifier.minify(appCss);
if (appMin.errors.length > 0) {
  throw new Error(appMin.errors.join('\n'));
}
fs.writeFileSync(path.join(cssDir, 'site-app.min.css'), appMin.styles);
console.log(`Wrote site-app.min.css (${appMin.styles.length} bytes)`);

const criticalCss = readSources(criticalSources).join('\n');
const criticalMin = minifier.minify(criticalCss);
if (criticalMin.errors.length > 0) {
  throw new Error(criticalMin.errors.join('\n'));
}
fs.writeFileSync(path.join(cssDir, 'site-layout-critical.inline.css'), criticalMin.styles);
console.log(`Wrote site-layout-critical.inline.css (${criticalMin.styles.length} bytes)`);

const legacyBundles = [
  'site-critical.bundle.css',
  'site-deferred.bundle.css',
  'site-store.bundle.css',
];
for (const file of legacyBundles) {
  const fullPath = path.join(cssDir, file);
  if (fs.existsSync(fullPath)) {
    fs.unlinkSync(fullPath);
    console.log(`Removed legacy ${file}`);
  }
}
