// Screenshots every page at 360, 768 and 1280px and reports horizontal overflow.
// Usage: node scripts/check.mjs [baseUrl] [outDir]   (serve dist/ first, e.g. php -S 127.0.0.1:8080 -t dist)
import { chromium } from 'playwright-core';
import { mkdirSync } from 'node:fs';

const base = process.argv[2] || 'http://127.0.0.1:8080';
const out = process.argv[3] || 'screenshots';
const exe = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
mkdirSync(out, { recursive: true });

const pages = [['home', '/'], ['privacy', '/privacy/'], ['thank-you', '/thank-you/'], ['404', '/404.html']];
const widths = [360, 768, 1280];
const browser = await chromium.launch({ executablePath: exe });
let problems = 0;

for (const w of widths) {
  const ctx = await browser.newContext({ viewport: { width: w, height: w < 700 ? 780 : 900 }, deviceScaleFactor: w < 700 ? 2 : 1, reducedMotion: 'reduce' });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));
  for (const [name, path] of pages) {
    await page.goto(base + path, { waitUntil: 'networkidle' });
    await page.evaluate(() => document.fonts.ready);
    // Scroll through so lazy images load, then return to the top.
    await page.evaluate(async () => {
      for (let y = 0; y < document.body.scrollHeight; y += 600) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 60)); }
      window.scrollTo(0, 0);
    });
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(300);
    const overflow = await page.evaluate(() => {
      const docW = document.documentElement.clientWidth;
      const wide = [...document.querySelectorAll('body *')]
        .filter((el) => { const r = el.getBoundingClientRect(); return r.width > 0 && (r.right > docW + 1 || r.left < -1) && getComputedStyle(el).position !== 'fixed' && !el.closest('.hp, dialog, .sr-only, .skip'); })
        .slice(0, 5).map((el) => `${el.tagName.toLowerCase()}.${[...el.classList].join('.')} (${Math.round(el.getBoundingClientRect().right)}px)`);
      return { scroll: document.documentElement.scrollWidth, docW, wide };
    });
    if (overflow.scroll > overflow.docW || overflow.wide.length) {
      problems++;
      console.log(`✗ ${name} @${w}: scrollWidth ${overflow.scroll} > ${overflow.docW}`, overflow.wide);
    } else console.log(`✓ ${name} @${w}: no horizontal scroll`);
    await page.screenshot({ path: `${out}/${name}-${w}-fold.png` });
    if (name === 'home' || w === 360) await page.screenshot({ path: `${out}/${name}-${w}-full.png`, fullPage: true });
  }
  if (errors.length) { problems++; console.log(`✗ console errors @${w}:`, errors); }
  await ctx.close();
}
await browser.close();
process.exit(problems ? 1 : 0);
