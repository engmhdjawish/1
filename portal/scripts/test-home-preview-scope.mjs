#!/usr/bin/env node
import { createServer } from 'node:http';
import { readFileSync, existsSync } from 'node:fs';
import { join, extname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const rootDir = join(fileURLToPath(new URL('.', import.meta.url)), '..');
const publicDir = join(rootDir, 'public');

const mime = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'application/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
};

function startServer() {
  return new Promise((resolve) => {
    const server = createServer((req, res) => {
      const urlPath = req.url?.split('?')[0] || '/';
      const filePath = join(publicDir, urlPath === '/' ? '/dev-test/home-preview-scope.html' : urlPath);
      if (!filePath.startsWith(publicDir) || !existsSync(filePath)) {
        res.writeHead(404);
        res.end('Not found');
        return;
      }
      const ext = extname(filePath);
      res.writeHead(200, { 'Content-Type': mime[ext] || 'application/octet-stream' });
      res.end(readFileSync(filePath));
    });
    server.listen(0, '127.0.0.1', () => resolve(server));
  });
}

async function run() {
  const server = await startServer();
  const port = server.address().port;
  const baseUrl = `http://127.0.0.1:${port}/dev-test/home-preview-scope.html`;

  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();

  try {
    await page.goto(baseUrl, { waitUntil: 'domcontentloaded' });

    await page.locator('#section-a [data-store-product-preview]').first().click();
    await page.waitForSelector('#storeProductPreview:not([hidden])');

    const title = () => page.locator('#storeProductPreviewTitle').textContent();
    const nextBtn = page.locator('[data-preview-next]');

    if ((await title()) !== 'منتج أ-1') {
      throw new Error(`Expected first item "منتج أ-1", got "${await title()}"`);
    }

    await nextBtn.click();
    if ((await title()) !== 'منتج أ-2') {
      throw new Error(`Expected second item "منتج أ-2", got "${await title()}"`);
    }

    const counter = await page.locator('#storeProductPreviewCounter').textContent();
    if (counter?.trim() !== '2 / 2') {
      throw new Error(`Expected counter "2 / 2", got "${counter?.trim()}"`);
    }

    if (!(await nextBtn.isDisabled())) {
      throw new Error('Next button should be disabled at last item in section');
    }

    // Ensure we do not leak into section B when pressing the key anyway.
    await page.keyboard.press('ArrowLeft');
    if ((await title()) !== 'منتج أ-2') {
      throw new Error(`Keyboard next should not leave section A, got "${await title()}"`);
    }

    console.log('PASS: homepage preview navigation stays within the same section');
  } finally {
    await browser.close();
    server.close();
  }
}

run().catch((error) => {
  console.error('FAIL:', error.message || error);
  process.exit(1);
});
