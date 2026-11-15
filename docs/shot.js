const { chromium } = require('playwright');

const EXE = 'C:/Users/lenovo/AppData/Local/ms-playwright/chromium-1248/chrome-win64/chrome.exe';
const BASE = 'http://127.0.0.1:8848/';
const OUT = 'C:/Users/lenovo/WorkBuddy/spaces/2026_11_15_19-10-58/vnav/docs/';

(async () => {
  const browser = await chromium.launch({ executablePath: EXE });
  const ctx = await browser.newContext({
    viewport: { width: 1440, height: 900 },
    deviceScaleFactor: 1.5,
    locale: 'zh-CN',
  });
  const page = await ctx.newPage();

  await page.goto(BASE, { waitUntil: 'networkidle' });
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(800);

  const html = await page.evaluate(() => document.documentElement.outerHTML.slice(0, 300));
  console.log('html attrs:', html.match(/<html[^>]*>/)?.[0]);

  await page.screenshot({ path: OUT + 'home-atlas.png' });
  console.log('atlas ok');

  await page.evaluate(() => {
    document.documentElement.dataset.theme = 'frame';
    document.documentElement.dataset.layout = 'card';
  });
  await page.waitForTimeout(700);
  await page.screenshot({ path: OUT + 'home-frame.png' });
  console.log('frame ok');

  await page.evaluate(() => {
    document.documentElement.dataset.theme = 'atlas-dark';
  });
  await page.waitForTimeout(700);
  await page.screenshot({ path: OUT + 'home-dark.png' });
  console.log('dark ok');

  await browser.close();
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
