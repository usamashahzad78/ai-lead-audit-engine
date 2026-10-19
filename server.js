/**
 * AI Lead & Audit Engine — Local Dev Server (Node.js)
 * Replicates api.php logic using only Node's built-in modules.
 * Run: node server.js
 * Then open: http://localhost:8080
 */

const http  = require('http');
const https = require('https');
const fs    = require('fs');
const path  = require('path');
const url   = require('url');

const PORT = 8080;

// ── MIME types ────────────────────────────────────────────────────────────────
const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.php':  'text/html; charset=utf-8',
  '.js':   'application/javascript',
  '.css':  'text/css',
  '.json': 'application/json',
  '.png':  'image/png',
  '.svg':  'image/svg+xml',
};

// ── Helper: HTTPS POST (replaces cURL) ───────────────────────────────────────
function httpsPost(hostName, pathStr, headers, body) {
  return new Promise((resolve, reject) => {
    const opts = {
      hostname: hostName,
      path:     pathStr,
      method:   'POST',
      headers:  { ...headers, 'Content-Length': Buffer.byteLength(body) },
      timeout:  20000,
    };
    const req = https.request(opts, (res) => {
      let data = '';
      res.on('data', c => data += c);
      res.on('end',  () => resolve({ status: res.statusCode, body: data }));
    });
    req.on('error',   reject);
    req.on('timeout', () => { req.destroy(); reject(new Error('Request timed out')); });
    req.write(body);
    req.end();
  });
}

// ── Helper: HTTPS GET (replaces cURL website fetch) ──────────────────────────
function httpsGet(targetUrl) {
  return new Promise((resolve) => {
    const startMs = Date.now();
    const parsed  = new url.URL(targetUrl);
    const lib     = parsed.protocol === 'https:' ? https : http;

    const opts = {
      hostname: parsed.hostname,
      path:     parsed.pathname + parsed.search,
      method:   'GET',
      headers:  { 'User-Agent': 'Mozilla/5.0 (compatible; LeadAuditBot/1.0)' },
      timeout:  6000,
    };

    const req = lib.request(opts, (res) => {
      // Follow single redirect
      if ([301, 302, 303, 307, 308].includes(res.statusCode) && res.headers.location) {
        const redirectUrl = res.headers.location.startsWith('http')
          ? res.headers.location
          : parsed.origin + res.headers.location;
        return httpsGet(redirectUrl).then(resolve).catch(() =>
          resolve({ ok: false, html: '', loadTime: (Date.now() - startMs) / 1000, status: res.statusCode })
        );
      }

      let html = '';
      res.on('data', c => { if (html.length < 500_000) html += c; }); // cap at 500KB
      res.on('end',  () => resolve({
        ok:       res.statusCode < 400,
        html,
        loadTime: parseFloat(((Date.now() - startMs) / 1000).toFixed(2)),
        status:   res.statusCode,
      }));
    });

    req.on('error',   () => resolve({ ok: false, html: '', loadTime: (Date.now() - startMs) / 1000, status: 0 }));
    req.on('timeout', () => { req.destroy(); resolve({ ok: false, html: '', loadTime: 6, status: 0 }); });
    req.end();
  });
}

// ── Step A: Serper.dev ────────────────────────────────────────────────────────
async function fetchPlaces(serperKey, query) {
  const body = JSON.stringify({ q: query, num: 20 });
  const res  = await httpsPost('google.serper.dev', '/places', {
    'X-API-KEY':    serperKey,
    'Content-Type': 'application/json',
  }, body);
  const data = JSON.parse(res.body);
  return data.places || [];
}

// ── Step B: Website Audit ─────────────────────────────────────────────────────
async function auditWebsite(siteUrl) {
  if (!siteUrl) {
    return {
      issues:      ['No Website listed on Google Maps (losing search clients)'],
      email:       null,
      badge:       'danger',
      load_time:   null,
      https:       false,
      has_chat:    false,
      has_viewport: false,
    };
  }

  let targetUrl = siteUrl;
  if (!/^https?:\/\//i.test(targetUrl)) targetUrl = 'http://' + targetUrl;

  const isHttps   = targetUrl.toLowerCase().startsWith('https://');
  const issues    = [];
  let   badge     = 'good';

  if (!isHttps) {
    issues.push('No HTTPS / SSL Certificate (insecure, Google ranking penalty)');
    badge = 'danger';
  }

  let result;
  try { result = await httpsGet(targetUrl); }
  catch (e) { result = { ok: false, html: '', loadTime: 6, status: 0 }; }

  if (!result.ok) {
    return {
      issues:      ['Website appears broken or unreachable (HTTP ' + (result.status || 'error') + ')'],
      email:       null,
      badge:       'danger',
      load_time:   result.loadTime,
      https:       isHttps,
      has_chat:    false,
      has_viewport: false,
    };
  }

  const { html, loadTime } = result;

  if (loadTime > 3) {
    issues.push(`Slow page load (${loadTime}s) — hurts SEO & conversions`);
    badge = badge === 'danger' ? 'danger' : 'warning';
  }

  const hasViewport = /<meta[^>]+name=["']viewport["'][^>]*>/i.test(html);
  if (!hasViewport) {
    issues.push('Missing mobile viewport meta tag (poor mobile experience)');
    badge = badge === 'danger' ? 'danger' : 'warning';
  }

  const chatPatterns = [/wa\.me/i, /whatsapp/i, /tawk\.to/i, /tidio/i, /intercom/i, /chatbot/i, /crisp\.chat/i, /freshchat/i];
  const hasChat = chatPatterns.some(p => p.test(html));
  if (!hasChat) {
    issues.push('No WhatsApp / AI Chat widget found (missing instant-reply leads)');
    badge = badge === 'danger' ? 'danger' : 'warning';
  }

  // Email scrape
  let email = null;
  const emailMatch = html.match(/\b[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}\b/);
  if (emailMatch) {
    const e = emailMatch[0].toLowerCase();
    if (!/example|placeholder|noreply|no-reply|wordpress|schema|sentry|\.png|\.jpg|\.svg/i.test(e)) {
      email = e;
    }
  }

  return {
    issues,
    email,
    badge:        issues.length === 0 ? 'good' : badge,
    load_time:    loadTime,
    https:        isHttps,
    has_chat:     hasChat,
    has_viewport: hasViewport,
  };
}

// ── Market map ────────────────────────────────────────────────────────────────
function getMarketDetails(market) {
  if (/oman/i.test(market))    return { price: '150 OMR',    payment: 'local Oman bank transfer' };
  if (/pakistan/i.test(market)) return { price: '45,000 PKR', payment: 'local Pakistani bank transfer' };
  return                               { price: '$450 USD',   payment: 'local US ACH bank transfer' };
}

// ── Step C: OpenAI Pitch ──────────────────────────────────────────────────────
async function generatePitch(openaiKey, name, category, issues, offer, market) {
  const firstIssue = issues[0] || 'your website needs improvement';
  const payload = JSON.stringify({
    model:      'gpt-4o-mini',
    max_tokens: 200,
    messages: [
      {
        role:    'system',
        content: 'You are a friendly, concise digital agency sales rep. Write a WhatsApp/email outreach message that is under 80 words, sounds human and casual (not salesy), mentions the exact detected issue in the first sentence, pitches the fixed-price package, mentions local bank payment, and ends with a soft call-to-action asking if they want a 2-minute demo link. Use max 2 emojis.',
      },
      {
        role:    'user',
        content: `Business: ${name} (${category})\nDetected issue: ${firstIssue}\nOur offer: ${offer}\nFixed price: ${market.price}\nPayment method: ${market.payment}\nWrite the outreach message now.`,
      },
    ],
  });

  try {
    const res  = await httpsPost('api.openai.com', '/v1/chat/completions', {
      'Authorization': 'Bearer ' + openaiKey,
      'Content-Type':  'application/json',
    }, payload);
    const data = JSON.parse(res.body);
    return data.choices?.[0]?.message?.content?.trim()
      ?? `Hi! We noticed ${firstIssue}. We offer ${offer} for ${market.price} via ${market.payment}. Want a quick 2-min demo?`;
  } catch (e) {
    return `Hi! I noticed ${firstIssue}. We can fix this with our ${offer} package at ${market.price} via ${market.payment}. Would you like a 2-minute demo link?`;
  }
}

// ── Serve index.php as HTML ───────────────────────────────────────────────────
function serveIndex(res) {
  const indexPath = path.join(__dirname, 'index.php');
  fs.readFile(indexPath, 'utf8', (err, html) => {
    if (err) {
      res.writeHead(404);
      res.end('index.php not found');
      return;
    }
    // Rewrite fetch URL so it hits /api on this Node server
    const patched = html.replace(
      "fetch('api.php'",
      "fetch('/api'"
    );
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end(patched);
  });
}

// ── Main API handler ──────────────────────────────────────────────────────────
async function handleApi(reqBody, res) {
  const { serper_key, openai_key, query, market, offer } = reqBody;

  if (!serper_key || !openai_key || !query) {
    res.writeHead(400, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ error: 'Missing required fields.' }));
    return;
  }

  let places;
  try {
    places = await fetchPlaces(serper_key, query);
  } catch (e) {
    res.writeHead(502, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ error: 'Serper.dev error: ' + e.message }));
    return;
  }

  if (!places.length) {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ leads: [], message: 'No results found.' }));
    return;
  }

  const marketDetails = getMarketDetails(market || '');
  const offerText     = offer || 'Fast Modern Website + 24/7 AI WhatsApp Booking Bot';
  const leads         = [];

  for (const place of places.slice(0, 20)) {
    const name     = place.title       || 'Unknown Business';
    const phone    = place.phoneNumber || null;
    const website  = place.website     || null;
    const rating   = place.rating      || null;
    const category = place.type        || place.category || 'Business';
    const address  = place.address     || null;

    const audit = await auditWebsite(website);
    const pitch = await generatePitch(openai_key, name, category, audit.issues, offerText, marketDetails);

    leads.push({ name, phone, website, rating, category, address, audit, pitch, email: audit.email, contacted: false });
  }

  res.writeHead(200, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify({ leads, market: marketDetails, total: leads.length, query }));
}

// ── HTTP Server ───────────────────────────────────────────────────────────────
const server = http.createServer((req, res) => {
  const parsedUrl = url.parse(req.url);
  const pathname  = parsedUrl.pathname;

  // CORS
  res.setHeader('Access-Control-Allow-Origin',  '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

  if (req.method === 'OPTIONS') { res.writeHead(204); res.end(); return; }

  // ── Route: GET / → serve index.php as HTML
  if (req.method === 'GET' && (pathname === '/' || pathname === '/index.php')) {
    return serveIndex(res);
  }

  // ── Route: POST /api → backend pipeline
  if (req.method === 'POST' && (pathname === '/api' || pathname === '/api.php')) {
    let body = '';
    req.on('data', chunk => body += chunk);
    req.on('end', () => {
      let parsed;
      try { parsed = JSON.parse(body); } catch { parsed = {}; }
      handleApi(parsed, res).catch(err => {
        res.writeHead(500, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: err.message || 'Internal server error' }));
      });
    });
    return;
  }

  // ── 404
  res.writeHead(404);
  res.end('Not found');
});

server.listen(PORT, '127.0.0.1', () => {
  console.log('\n╔══════════════════════════════════════════════════╗');
  console.log('║   AI Lead & Audit Engine — Local Dev Server      ║');
  console.log('╠══════════════════════════════════════════════════╣');
  console.log(`║   🌐  http://localhost:${PORT}                        ║`);
  console.log('║   Press Ctrl+C to stop                           ║');
  console.log('╚══════════════════════════════════════════════════╝\n');
});

server.on('error', (e) => {
  if (e.code === 'EADDRINUSE') {
    console.error(`\n❌  Port ${PORT} is already in use. Try: node server.js --port 8081`);
  } else {
    console.error('Server error:', e.message);
  }
  process.exit(1);
});
