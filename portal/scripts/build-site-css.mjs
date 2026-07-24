#!/usr/bin/env node
/**
 * Concatenate layout CSS into bundles for fewer requests and deferred loading.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const cssDir = path.join(root, 'public', 'css');

const bundles = {
  'site-critical.bundle.css': [
    'material-image-frame.css',
    'site-brand.css',
    'site-header.css',
  ],
  'site-deferred.bundle.css': [
    'site-footer.css',
    'pwa-install.css',
    'site-page-loading.css',
    'notifications.css',
    'site-onboarding.css',
  ],
  'site-store.bundle.css': [
    'store-ui.css',
    'store-cart.css',
  ],
};

for (const [outFile, sources] of Object.entries(bundles)) {
  const parts = sources.map((file) => {
    const fullPath = path.join(cssDir, file);
    if (!fs.existsSync(fullPath)) {
      throw new Error(`Missing CSS source: ${file}`);
    }

    return `/* ${file} */\n${fs.readFileSync(fullPath, 'utf8').trim()}\n`;
  });

  fs.writeFileSync(path.join(cssDir, outFile), parts.join('\n'));
  console.log(`Wrote ${outFile} (${sources.length} files)`);
}
