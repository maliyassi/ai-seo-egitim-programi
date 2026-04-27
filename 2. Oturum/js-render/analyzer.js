#!/usr/bin/env node
/**
 * JS Render SEO Analyzer
 * Usage: node analyzer.js <URL>
 * Output: JSON with raw vs rendered SEO comparison + full line diff
 */

const { chromium } = require('playwright');
const cheerio = require('cheerio');
const Diff = require('diff');
const https = require('https');
const http = require('http');

const targetUrl = process.argv[2];

if (!targetUrl) {
  console.error(JSON.stringify({ error: 'Lütfen bir URL girin. Kullanım: node analyzer.js <URL>' }));
  process.exit(1);
}

// ─── Fetch raw HTML via plain HTTP ────────────────────────────
function fetchRawHtml(url, redirects = 0) {
  return new Promise((resolve, reject) => {
    if (redirects > 8) return reject(new Error('Çok fazla yönlendirme'));
    const lib = url.startsWith('https') ? https : http;
    const options = {
      headers: {
        'User-Agent': 'Mozilla/5.0 (compatible; SEO-RawFetcher/1.0)',
        'Accept': 'text/html,application/xhtml+xml',
        'Accept-Language': 'tr-TR,tr;q=0.9,en;q=0.8',
        'Accept-Encoding': 'identity',
      },
    };
    lib.get(url, options, (res) => {
      let data = '';
      if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
        const next = res.headers.location.startsWith('http')
          ? res.headers.location
          : new URL(res.headers.location, url).href;
        return fetchRawHtml(next, redirects + 1).then(resolve).catch(reject);
      }
      res.setEncoding('utf8');
      res.on('data', (chunk) => (data += chunk));
      res.on('end', () => resolve(data));
    }).on('error', reject);
  });
}

// ─── Extract SEO fields from a Cheerio instance ───────────────
function extractSEO($, baseUrl) {
  const title = $('title').first().text().trim() || '';
  const metaDesc = $('meta[name="description"]').attr('content') || '';
  const canonical = $('link[rel="canonical"]').attr('href') || '';
  const robots = $('meta[name="robots"]').attr('content') || '';
  const ogTitle = $('meta[property="og:title"]').attr('content') || '';
  const ogDesc = $('meta[property="og:description"]').attr('content') || '';
  const ogImage = $('meta[property="og:image"]').attr('content') || '';
  const twitterTitle = $('meta[name="twitter:title"]').attr('content') || '';
  const twitterDesc = $('meta[name="twitter:description"]').attr('content') || '';

  const h1Tags = [];
  const h2Tags = [];
  const h3Tags = [];
  $('h1').each((_, el) => h1Tags.push($(el).text().trim()));
  $('h2').each((_, el) => h2Tags.push($(el).text().trim()));
  $('h3').each((_, el) => h3Tags.push($(el).text().trim()));

  let internalLinks = 0, externalLinks = 0, nofollowLinks = 0;
  try {
    const internalHost = new URL(baseUrl).hostname;
    $('a[href]').each((_, el) => {
      const href = $(el).attr('href') || '';
      const rel = ($(el).attr('rel') || '').toLowerCase();
      if (rel.includes('nofollow')) nofollowLinks++;
      try {
        const u = new URL(href, baseUrl);
        if (u.hostname === internalHost) internalLinks++;
        else externalLinks++;
      } catch { }
    });
  } catch { }

  // Schema / Structured data – robust parsing
  const schemaTypes = [];
  const schemaErrors = [];
  $('script[type="application/ld+json"], script[type="application/LD+JSON"]').each((_, el) => {
    // Use .html() first, fallback to .text() for Cheerio compatibility
    const raw = ($(el).html() || $(el).text() || '').trim();
    if (!raw) return;
    try {
      const parsed = JSON.parse(raw);
      const items = Array.isArray(parsed) ? parsed : [parsed];
      items.forEach(item => {
        const t = item['@type'];
        if (t) {
          if (Array.isArray(t)) t.forEach(v => schemaTypes.push(v));
          else schemaTypes.push(t);
        }
        // Also handle @graph
        if (item['@graph'] && Array.isArray(item['@graph'])) {
          item['@graph'].forEach(g => {
            const gt = g['@type'];
            if (gt) {
              if (Array.isArray(gt)) gt.forEach(v => schemaTypes.push(v));
              else schemaTypes.push(gt);
            }
          });
        }
      });
    } catch (e) {
      schemaErrors.push(e.message.substring(0, 80));
    }
  });

  let imagesTotal = $('img').length;
  let imagesNoAlt = 0;
  $('img').each((_, el) => {
    const alt = $(el).attr('alt');
    if (alt === undefined || alt === '') imagesNoAlt++;
  });

  const wordCount = $('body').text().replace(/\s+/g, ' ').trim().split(' ').filter(Boolean).length;

  return {
    title, metaDesc, canonical, robots,
    ogTitle, ogDesc, ogImage, twitterTitle, twitterDesc,
    h1: h1Tags, h2: h2Tags, h3: h3Tags,
    internalLinks, externalLinks, nofollowLinks,
    schemaTypes, schemaErrors,
    imagesTotal, imagesNoAlt, wordCount,
  };
}

// ─── Diff two SEO objects ──────────────────────────────────────
function diffSEO(raw, rendered) {
  const fields = [
    { key: 'title', severity: 'critical' },
    { key: 'metaDesc', severity: 'critical' },
    { key: 'canonical', severity: 'critical' },
    { key: 'robots', severity: 'critical' },
    { key: 'ogTitle', severity: 'important' },
    { key: 'ogDesc', severity: 'important' },
    { key: 'ogImage', severity: 'important' },
    { key: 'twitterTitle', severity: 'important' },
    { key: 'twitterDesc', severity: 'important' },
    { key: 'h1', severity: 'important' },
    { key: 'h2', severity: 'important' },
    { key: 'h3', severity: 'important' },
    { key: 'internalLinks', severity: 'info' },
    { key: 'externalLinks', severity: 'info' },
    { key: 'wordCount', severity: 'info' },
    { key: 'schemaTypes', severity: 'important' },
    { key: 'imagesNoAlt', severity: 'info' },
  ];
  const changes = [];
  for (const { key, severity } of fields) {
    const r = Array.isArray(raw[key]) ? JSON.stringify(raw[key]) : String(raw[key] ?? '');
    const rd = Array.isArray(rendered[key]) ? JSON.stringify(rendered[key]) : String(rendered[key] ?? '');
    if (r !== rd) changes.push({ key, severity, raw: r, rendered: rd });
  }
  return changes;
}

// ─── Compute context diff (changed chunks + N surrounding lines) ──
function computeLineDiff(rawHtml, renderedHtml, context = 10) {
  const a = rawHtml.replace(/\r\n/g, '\n');
  const b = renderedHtml.replace(/\r\n/g, '\n');
  const lineDiffs = Diff.diffLines(a, b);

  // 1. Flatten to { type, line } array
  const flat = [];
  for (const part of lineDiffs) {
    const type = part.added ? 'add' : part.removed ? 'remove' : 'equal';
    const lines = part.value.split('\n');
    if (lines[lines.length - 1] === '') lines.pop();
    for (const line of lines) flat.push({ type, line });
  }

  // 2. Find indices of changed lines
  const changedIdx = new Set();
  flat.forEach((r, i) => {
    if (r.type !== 'equal') {
      for (let j = Math.max(0, i - context); j <= Math.min(flat.length - 1, i + context); j++) {
        changedIdx.add(j);
      }
    }
  });

  if (changedIdx.size === 0) return []; // No changes

  // 3. Build result with gap markers
  const result = [];
  let prev = -1;
  let changeLinesEmitted = 0;
  const MAX_CHANGE_LINES = 1200; // cap to keep JSON reasonable

  for (const i of [...changedIdx].sort((a, b) => a - b)) {
    if (changeLinesEmitted >= MAX_CHANGE_LINES) {
      result.push({ type: 'info', line: '... (çok fazla değişim var, kalan satırlar gizlendi)' });
      break;
    }
    if (prev !== -1 && i > prev + 1) {
      result.push({ type: 'separator', line: `@@ ... ${i - prev - 1} satır atlandı ...` });
    }
    result.push(flat[i]);
    if (flat[i].type !== 'equal') changeLinesEmitted++;
    prev = i;
  }

  return result;
}

// ─── Main ──────────────────────────────────────────────────────
(async () => {
  let rawHtml = '';
  let renderedHtml = '';
  let renderTimeMs = 0;

  // 1. Fetch Raw HTML
  try {
    rawHtml = await fetchRawHtml(targetUrl);
  } catch (e) {
    console.log(JSON.stringify({ error: 'Ham HTML alınamadı: ' + e.message }));
    process.exit(1);
  }

  // 2. Playwright render
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    extraHTTPHeaders: { 'Accept-Language': 'tr-TR,tr;q=0.9,en;q=0.8' },
  });
  const page = await context.newPage();
  try {
    const t0 = Date.now();
    await page.goto(targetUrl, { waitUntil: 'load', timeout: 60000 });
    // Extra wait for late JS injections (schema, canonical, dynamic meta tags, etc.)
    // 'networkidle' is intentionally avoided — many sites have continuous background requests.
    await page.waitForTimeout(3000);
    renderedHtml = await page.content();
    renderTimeMs = Date.now() - t0;
  } catch (e) {
    await browser.close();
    console.log(JSON.stringify({ error: 'Playwright render hatası: ' + e.message }));
    process.exit(1);
  }
  await browser.close();

  // 3. Parse with Cheerio
  const $raw = cheerio.load(rawHtml);
  const $ren = cheerio.load(renderedHtml);
  const rawSEO = extractSEO($raw, targetUrl);
  const renderedSEO = extractSEO($ren, targetUrl);

  // 4. SEO diff
  const changes = diffSEO(rawSEO, renderedSEO);

  // 5. Line-level HTML diff
  const htmlLineDiff = computeLineDiff(rawHtml, renderedHtml);

  // 6. Output
  const out = {
    url: targetUrl,
    timestamp: new Date().toISOString(),
    renderTimeMs,
    rawSEO,
    renderedSEO,
    changes,
    htmlLineDiff,
    rawHtmlLength: rawHtml.length,
    renderedHtmlLength: renderedHtml.length,
  };

  console.log(JSON.stringify(out));
})();
