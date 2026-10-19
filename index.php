<!DOCTYPE html>
<html lang="en" class="dark">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>AI Lead & Audit Engine</title>
  <meta name="description" content="Automatically scrape, audit, and pitch local businesses with AI-generated personalised outreach. Powered by Serper.dev + OpenAI." />

  <!-- Tailwind CSS CDN -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          colors: {
            brand: {
              50:  '#f0f9ff',
              400: '#38bdf8',
              500: '#0ea5e9',
              600: '#0284c7',
              700: '#0369a1',
            },
            surface: {
              900: '#0a0f1e',
              800: '#0f172a',
              700: '#1e293b',
              600: '#263348',
            }
          },
          fontFamily: {
            sans: ['Inter', 'system-ui', 'sans-serif'],
          },
          animation: {
            'pulse-slow': 'pulse 3s cubic-bezier(0.4,0,0.6,1) infinite',
            'spin-slow': 'spin 2s linear infinite',
            'fade-in': 'fadeIn 0.4s ease-out',
            'slide-up': 'slideUp 0.5s ease-out',
          },
          keyframes: {
            fadeIn: { '0%': { opacity: 0 }, '100%': { opacity: 1 } },
            slideUp: { '0%': { opacity: 0, transform: 'translateY(16px)' }, '100%': { opacity: 1, transform: 'translateY(0)' } },
          }
        }
      }
    };
  </script>

  <!-- Google Fonts: Inter -->
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />

  <style>
    /* ── Global ── */
    * { box-sizing: border-box; }
    body { background: #0a0f1e; font-family: 'Inter', sans-serif; }

    /* ── Glassmorphism card ── */
    .glass {
      background: rgba(15, 23, 42, 0.7);
      backdrop-filter: blur(16px);
      border: 1px solid rgba(56, 189, 248, 0.12);
    }

    /* ── Gradient text ── */
    .gradient-text {
      background: linear-gradient(135deg, #38bdf8 0%, #818cf8 50%, #c084fc 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    /* ── Hero glow ── */
    .hero-glow {
      position: absolute;
      width: 600px; height: 600px;
      border-radius: 50%;
      background: radial-gradient(circle, rgba(56,189,248,0.12) 0%, transparent 70%);
      pointer-events: none;
      top: -200px; left: 50%;
      transform: translateX(-50%);
    }

    /* ── Input focus ring ── */
    .input-field {
      background: rgba(30, 41, 59, 0.8);
      border: 1px solid rgba(56, 189, 248, 0.2);
      color: #e2e8f0;
      transition: all 0.2s ease;
    }
    .input-field:focus {
      outline: none;
      border-color: #38bdf8;
      box-shadow: 0 0 0 3px rgba(56,189,248,0.15);
    }
    .input-field::placeholder { color: #64748b; }

    /* ── Badge ── */
    .badge-danger  { background: rgba(239,68,68,0.15);  color: #f87171; border: 1px solid rgba(239,68,68,0.3);  }
    .badge-warning { background: rgba(234,179,8,0.15);  color: #facc15; border: 1px solid rgba(234,179,8,0.3);  }
    .badge-good    { background: rgba(34,197,94,0.15);  color: #4ade80; border: 1px solid rgba(34,197,94,0.3);  }

    /* ── Lead card ── */
    .lead-card {
      background: rgba(15,23,42,0.85);
      border: 1px solid rgba(56,189,248,0.1);
      transition: border-color 0.2s, transform 0.2s;
      animation: slideUp 0.5s ease-out both;
    }
    .lead-card:hover { border-color: rgba(56,189,248,0.35); transform: translateY(-2px); }
    .lead-card.contacted { border-color: rgba(34,197,94,0.4); background: rgba(34,197,94,0.04); }

    /* ── Spinner ── */
    .spinner {
      width: 48px; height: 48px;
      border: 3px solid rgba(56,189,248,0.2);
      border-top-color: #38bdf8;
      border-radius: 50%;
      animation: spin 0.8s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    /* ── Progress bar ── */
    .progress-bar-inner {
      height: 3px;
      background: linear-gradient(90deg, #38bdf8, #818cf8, #c084fc);
      animation: progressPulse 2s ease-in-out infinite;
    }
    @keyframes progressPulse {
      0%,100% { opacity: 0.7; }
      50%      { opacity: 1; }
    }

    /* ── Textarea ── */
    textarea.pitch-box {
      background: rgba(30,41,59,0.6);
      border: 1px solid rgba(56,189,248,0.15);
      color: #cbd5e1;
      resize: vertical;
      font-size: 0.875rem;
      line-height: 1.6;
      transition: border-color 0.2s;
    }
    textarea.pitch-box:focus {
      outline: none;
      border-color: #38bdf8;
      box-shadow: 0 0 0 2px rgba(56,189,248,0.12);
    }

    /* ── Scrollbar ── */
    ::-webkit-scrollbar { width: 6px; height: 6px; }
    ::-webkit-scrollbar-track { background: #0f172a; }
    ::-webkit-scrollbar-thumb { background: #334155; border-radius: 3px; }

    /* ── Button base ── */
    .btn { transition: all 0.18s ease; cursor: pointer; font-weight: 500; }
    .btn:hover { filter: brightness(1.1); transform: translateY(-1px); }
    .btn:active { transform: translateY(0); }

    /* ── Stars ── */
    .star-filled { color: #facc15; }
    .star-empty  { color: #374151; }

    /* ── Copy toast ── */
    #copy-toast {
      position: fixed; bottom: 24px; right: 24px;
      background: rgba(34,197,94,0.9); color: white;
      padding: 10px 20px; border-radius: 8px;
      font-size: 0.875rem; font-weight: 500;
      transform: translateY(60px); opacity: 0;
      transition: all 0.3s ease;
      z-index: 9999;
    }
    #copy-toast.show { transform: translateY(0); opacity: 1; }
  </style>
</head>

<body class="min-h-screen text-slate-200">

<!-- ── Background decoration ── -->
<div class="hero-glow"></div>
<div class="fixed inset-0 overflow-hidden pointer-events-none">
  <div class="absolute top-1/3 right-0 w-96 h-96 bg-purple-500/5 rounded-full blur-3xl"></div>
  <div class="absolute bottom-0 left-1/4 w-80 h-80 bg-sky-500/5 rounded-full blur-3xl"></div>
</div>

<div class="relative z-10 max-w-7xl mx-auto px-4 py-10">

  <!-- ── Header ── -->
  <header class="text-center mb-12">
    <div class="inline-flex items-center gap-2 glass px-4 py-1.5 rounded-full text-xs font-medium text-sky-400 mb-6 border border-sky-500/20">
      <span class="w-1.5 h-1.5 rounded-full bg-sky-400 animate-pulse inline-block"></span>
      AI-Powered Lead Intelligence
    </div>
    <h1 class="text-4xl md:text-5xl lg:text-6xl font-extrabold mb-4 leading-tight">
      <span class="gradient-text">AI Lead &amp; Audit Engine</span>
    </h1>
    <p class="text-slate-400 text-lg max-w-2xl mx-auto">
      Scrape Google Maps leads, auto-audit their websites, and generate AI-powered outreach pitches — all in one click.
    </p>
  </header>

  <!-- ── Configuration Panel ── -->
  <section class="glass rounded-2xl p-6 md:p-8 mb-8">
    <h2 class="text-lg font-semibold text-slate-100 mb-6 flex items-center gap-2">
      <svg class="w-5 h-5 text-sky-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
      </svg>
      Configuration
    </h2>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
      <!-- Serper API Key -->
      <div>
        <label class="block text-xs font-medium text-slate-400 mb-1.5" for="serper-key">
          Serper.dev API Key
        </label>
        <input
          id="serper-key"
          type="password"
          placeholder="sk-xxxxxxxxxxxx"
          class="input-field w-full rounded-lg px-4 py-2.5 text-sm"
        />
      </div>
      <!-- OpenAI API Key -->
      <div>
        <label class="block text-xs font-medium text-slate-400 mb-1.5" for="openai-key">
          OpenAI API Key
        </label>
        <input
          id="openai-key"
          type="password"
          placeholder="sk-xxxxxxxxxxxx"
          class="input-field w-full rounded-lg px-4 py-2.5 text-sm"
        />
      </div>
    </div>

    <!-- Search + Market + Offer + Button -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
      <div>
        <label class="block text-xs font-medium text-slate-400 mb-1.5" for="search-query">
          Business Niche &amp; City
        </label>
        <input
          id="search-query"
          type="text"
          placeholder="e.g. Dental Clinics in Muscat, Oman"
          class="input-field w-full rounded-lg px-4 py-2.5 text-sm"
        />
      </div>
      <div>
        <label class="block text-xs font-medium text-slate-400 mb-1.5" for="market-select">
          Target Market &amp; Pricing
        </label>
        <select
          id="market-select"
          class="input-field w-full rounded-lg px-4 py-2.5 text-sm appearance-none cursor-pointer"
        >
          <option value="Oman (150 OMR - Local Oman Bank Transfer)">🇴🇲 Oman — 150 OMR (Local Oman Bank Transfer)</option>
          <option value="US ($450 USD - Local US ACH Bank Transfer)">🇺🇸 US — $450 USD (Local US ACH Bank Transfer)</option>
          <option value="Pakistan (45,000 PKR - Local Bank Transfer)">🇵🇰 Pakistan — 45,000 PKR (Local Bank Transfer)</option>
        </select>
      </div>
    </div>

    <div class="mb-5">
      <label class="block text-xs font-medium text-slate-400 mb-1.5" for="offer-input">
        Custom Service Offer
      </label>
      <input
        id="offer-input"
        type="text"
        value="Fast Modern Website + 24/7 AI WhatsApp Booking Bot"
        class="input-field w-full rounded-lg px-4 py-2.5 text-sm"
      />
    </div>

    <!-- CTA Button -->
    <button
      id="find-btn"
      onclick="startSearch()"
      class="btn w-full py-3.5 rounded-xl font-semibold text-sm text-white
             bg-gradient-to-r from-sky-500 via-blue-600 to-violet-600
             hover:from-sky-400 hover:via-blue-500 hover:to-violet-500
             shadow-lg shadow-sky-500/20 flex items-center justify-center gap-2"
    >
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
      </svg>
      Find &amp; Audit Leads
    </button>
  </section>

  <!-- ── Progress / Loading ── -->
  <div id="loading-state" class="hidden glass rounded-2xl p-10 mb-8 text-center">
    <div class="flex justify-center mb-5">
      <div class="spinner"></div>
    </div>
    <h3 class="text-lg font-semibold text-slate-200 mb-2" id="loading-step">Searching Google Maps…</h3>
    <p class="text-slate-500 text-sm mb-5">Auditing websites and generating AI pitches — this takes ~30–60 seconds.</p>
    <div class="w-full max-w-md mx-auto bg-slate-800 rounded-full h-0.5 overflow-hidden">
      <div class="progress-bar-inner w-full"></div>
    </div>
  </div>

  <!-- ── Error Banner ── -->
  <div id="error-banner" class="hidden mb-6 glass rounded-xl p-4 border border-red-500/30 text-red-400 text-sm flex items-start gap-3">
    <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    <span id="error-text"></span>
  </div>

  <!-- ── Results ── -->
  <div id="results-section" class="hidden">
    <!-- Summary bar -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
      <div>
        <h2 class="text-xl font-bold text-slate-100" id="results-title">0 Leads Found</h2>
        <p class="text-sm text-slate-500" id="results-subtitle"></p>
      </div>
      <button
        id="export-btn"
        onclick="exportCSV()"
        class="btn inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold
               glass border border-sky-500/30 text-sky-400 hover:border-sky-400 hover:bg-sky-500/10"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3M3 17V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>
        </svg>
        Export to CSV
      </button>
    </div>

    <!-- Lead Cards Grid -->
    <div id="leads-grid" class="grid grid-cols-1 xl:grid-cols-2 gap-5"></div>
  </div>

</div><!-- /container -->

<!-- ── Copy Toast ── -->
<div id="copy-toast">✅ Pitch copied to clipboard!</div>

<script>
/* ============================================================
   STATE
============================================================ */
let leadsData = [];

/* ============================================================
   LOCALSTORAGE PERSISTENCE
============================================================ */
const LS_KEYS = {
  serper: 'ae_serper_key',
  openai: 'ae_openai_key',
  query:  'ae_query',
  market: 'ae_market',
  offer:  'ae_offer',
};

function loadSavedValues() {
  const $ = (id) => document.getElementById(id);
  $('serper-key').value   = localStorage.getItem(LS_KEYS.serper)  || '';
  $('openai-key').value   = localStorage.getItem(LS_KEYS.openai)  || '';
  $('search-query').value = localStorage.getItem(LS_KEYS.query)   || '';
  $('offer-input').value  = localStorage.getItem(LS_KEYS.offer)   || 'Fast Modern Website + 24/7 AI WhatsApp Booking Bot';
  const savedMarket = localStorage.getItem(LS_KEYS.market);
  if (savedMarket) {
    const sel = $('market-select');
    for (let opt of sel.options) {
      if (opt.value === savedMarket) { sel.value = savedMarket; break; }
    }
  }
}

function saveValues() {
  localStorage.setItem(LS_KEYS.serper,  document.getElementById('serper-key').value.trim());
  localStorage.setItem(LS_KEYS.openai,  document.getElementById('openai-key').value.trim());
  localStorage.setItem(LS_KEYS.query,   document.getElementById('search-query').value.trim());
  localStorage.setItem(LS_KEYS.market,  document.getElementById('market-select').value);
  localStorage.setItem(LS_KEYS.offer,   document.getElementById('offer-input').value.trim());
}

/* ============================================================
   SEARCH / API CALL
============================================================ */
async function startSearch() {
  const serperKey = document.getElementById('serper-key').value.trim();
  const openaiKey = document.getElementById('openai-key').value.trim();
  const query     = document.getElementById('search-query').value.trim();
  const market    = document.getElementById('market-select').value;
  const offer     = document.getElementById('offer-input').value.trim();

  // Validation
  if (!serperKey) return showError('Please enter your Serper.dev API key.');
  if (!openaiKey) return showError('Please enter your OpenAI API key.');
  if (!query)     return showError('Please enter a business niche and city.');

  saveValues();
  hideError();
  showLoading(true);
  hideResults();

  try {
    const resp = await fetch('api.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ serper_key: serperKey, openai_key: openaiKey, query, market, offer }),
    });

    const data = await resp.json();

    if (!resp.ok || data.error) {
      throw new Error(data.error || 'Server returned an error.');
    }

    leadsData = data.leads || [];
    renderResults(data);

  } catch (err) {
    showError('Error: ' + (err.message || 'Unknown error occurred. Check your API keys and try again.'));
  } finally {
    showLoading(false);
  }
}

/* ============================================================
   RENDER
============================================================ */
function renderResults(data) {
  const { leads, query } = data;

  document.getElementById('results-title').textContent   = `${leads.length} Lead${leads.length !== 1 ? 's' : ''} Found`;
  document.getElementById('results-subtitle').textContent = `Search: "${query}" • ${leads.filter(l => l.audit.badge === 'danger').length} critical issues detected`;

  const grid = document.getElementById('leads-grid');
  grid.innerHTML = '';

  leads.forEach((lead, idx) => {
    grid.appendChild(buildLeadCard(lead, idx));
  });

  document.getElementById('results-section').classList.remove('hidden');
}

function buildLeadCard(lead, idx) {
  const card = document.createElement('div');
  card.className = 'lead-card rounded-2xl p-6';
  card.id = `lead-card-${idx}`;
  card.style.animationDelay = `${idx * 60}ms`;

  const badge = lead.audit.badge;
  const badgeClass = badge === 'danger' ? 'badge-danger' : badge === 'warning' ? 'badge-warning' : 'badge-good';
  const badgeText  = badge === 'danger' ? '🔴 Critical' : badge === 'warning' ? '🟡 Issues Found' : '🟢 Looks Good';

  const stars = renderStars(lead.rating);
  const phone = cleanPhone(lead.phone || '');
  const hasPhone = phone.length > 5;
  const hasEmail = !!lead.email;

  const issuesHTML = lead.audit.issues.length > 0
    ? `<ul class="space-y-1 mt-2">
        ${lead.audit.issues.map(i =>
          `<li class="flex items-start gap-2 text-xs text-slate-400">
            <span class="mt-0.5 text-red-400">⚠</span>
            <span>${escapeHTML(i)}</span>
           </li>`
        ).join('')}
       </ul>`
    : `<p class="text-xs text-green-400 mt-2">✅ No major issues detected.</p>`;

  card.innerHTML = `
    <!-- Header row -->
    <div class="flex items-start justify-between gap-3 mb-4">
      <div class="flex-1 min-w-0">
        <h3 class="font-bold text-slate-100 text-base leading-tight truncate">${escapeHTML(lead.name)}</h3>
        <p class="text-xs text-slate-500 mt-0.5">${escapeHTML(lead.category || '')}${lead.address ? ' · ' + escapeHTML(lead.address) : ''}</p>
        <div class="flex items-center gap-2 mt-1.5">
          ${stars}
          ${lead.rating ? `<span class="text-xs text-slate-400">${lead.rating}</span>` : ''}
        </div>
      </div>
      <span class="flex-shrink-0 px-3 py-1 rounded-full text-xs font-semibold ${badgeClass}">${badgeText}</span>
    </div>

    <!-- Website & Issues -->
    <div class="mb-4">
      <div class="flex items-center gap-2 mb-1">
        <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
        </svg>
        ${lead.website
          ? `<a href="${escapeHTML(lead.website)}" target="_blank" rel="noopener" class="text-xs text-sky-400 hover:underline truncate max-w-[220px]">${escapeHTML(lead.website)}</a>`
          : `<span class="text-xs text-slate-600 italic">No website</span>`
        }
        ${lead.audit.load_time ? `<span class="text-xs text-slate-600">· ${lead.audit.load_time}s</span>` : ''}
      </div>
      <div class="bg-slate-800/50 rounded-xl p-3 border border-slate-700/50">
        <p class="text-xs font-medium text-slate-300 mb-0.5">Detected Issues</p>
        ${issuesHTML}
      </div>
    </div>

    <!-- AI Pitch -->
    <div class="mb-4">
      <label class="block text-xs font-medium text-slate-400 mb-1.5">
        ✨ AI-Generated Outreach Pitch <span class="text-slate-600">(editable)</span>
      </label>
      <textarea
        id="pitch-${idx}"
        class="pitch-box w-full rounded-xl p-3 text-sm"
        rows="5"
      >${escapeHTML(lead.pitch)}</textarea>
    </div>

    <!-- Action Buttons -->
    <div class="flex flex-wrap gap-2">
      ${hasPhone ? `
        <button
          onclick="sendWhatsApp(${idx})"
          id="wa-btn-${idx}"
          class="btn flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2.5 rounded-xl text-xs font-semibold
                 bg-green-500/10 border border-green-500/30 text-green-400 hover:bg-green-500/20"
        >
          <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24">
            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/>
          </svg>
          Send WhatsApp
        </button>` : `
        <button disabled class="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2.5 rounded-xl text-xs font-semibold
               bg-slate-800/50 border border-slate-700/50 text-slate-600 cursor-not-allowed">
          No Phone Found
        </button>`
      }

      ${hasEmail ? `
        <button
          onclick="sendEmail(${idx})"
          class="btn flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2.5 rounded-xl text-xs font-semibold
                 bg-blue-500/10 border border-blue-500/30 text-blue-400 hover:bg-blue-500/20"
        >
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
          </svg>
          Send Email
        </button>` : `
        <button disabled class="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2.5 rounded-xl text-xs font-semibold
               bg-slate-800/50 border border-slate-700/50 text-slate-600 cursor-not-allowed">
          No Email Found
        </button>`
      }

      <button
        onclick="copyPitch(${idx})"
        class="btn inline-flex items-center justify-center gap-1.5 px-3 py-2.5 rounded-xl text-xs font-semibold
               bg-violet-500/10 border border-violet-500/30 text-violet-400 hover:bg-violet-500/20"
      >
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/>
        </svg>
        Copy Pitch
      </button>
    </div>

    <!-- Contacted badge (hidden by default) -->
    <div id="contacted-badge-${idx}" class="hidden mt-3 flex items-center gap-2 text-green-400 text-xs font-semibold">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
      </svg>
      Contacted ✅
    </div>
  `;

  return card;
}

/* ============================================================
   ACTIONS
============================================================ */
function sendWhatsApp(idx) {
  const lead  = leadsData[idx];
  const phone = cleanPhone(lead.phone || '');
  const pitch = document.getElementById(`pitch-${idx}`).value;

  if (!phone) return alert('No phone number available for this lead.');

  const url = `https://wa.me/${phone}?text=${encodeURIComponent(pitch)}`;
  window.open(url, '_blank');
  markContacted(idx);
}

function sendEmail(idx) {
  const lead  = leadsData[idx];
  const pitch = document.getElementById(`pitch-${idx}`).value;
  const subj  = `Quick question about ${lead.name}`;
  window.open(`mailto:${lead.email}?subject=${encodeURIComponent(subj)}&body=${encodeURIComponent(pitch)}`);
}

function copyPitch(idx) {
  const pitch = document.getElementById(`pitch-${idx}`).value;
  navigator.clipboard.writeText(pitch).then(() => {
    const toast = document.getElementById('copy-toast');
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 2500);
  }).catch(() => {
    // Fallback for older browsers
    const ta = document.createElement('textarea');
    ta.value = pitch;
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
    alert('Pitch copied!');
  });
}

function markContacted(idx) {
  const card  = document.getElementById(`lead-card-${idx}`);
  const badge = document.getElementById(`contacted-badge-${idx}`);
  const waBtn = document.getElementById(`wa-btn-${idx}`);
  card.classList.add('contacted');
  badge.classList.remove('hidden');
  if (waBtn) {
    waBtn.textContent = '✅ Contacted';
    waBtn.disabled = true;
    waBtn.classList.add('opacity-60', 'cursor-not-allowed');
  }
}

/* ============================================================
   EXPORT CSV
============================================================ */
function exportCSV() {
  if (!leadsData.length) return;

  const headers = ['Name','Category','Phone','Email','Website','Rating','Badge','Issues','Load Time (s)','Pitch'];
  const rows = leadsData.map(l => [
    l.name,
    l.category,
    l.phone || '',
    l.email || '',
    l.website || '',
    l.rating || '',
    l.audit.badge,
    l.audit.issues.join(' | '),
    l.audit.load_time || '',
    // get current (possibly edited) pitch from textarea
    document.getElementById(`pitch-${leadsData.indexOf(l)}`)?.value || l.pitch,
  ].map(csvEscape).join(','));

  const csv  = [headers.join(','), ...rows].join('\n');
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href     = url;
  a.download = `leads_${new Date().toISOString().slice(0,10)}.csv`;
  a.click();
  URL.revokeObjectURL(url);
}

function csvEscape(val) {
  const str = String(val ?? '').replace(/"/g, '""');
  return `"${str}"`;
}

/* ============================================================
   UTILITIES
============================================================ */
function cleanPhone(phone) {
  return phone.replace(/[^0-9+]/g, '').replace(/^\+?0+/, '').replace(/^/, '');
}

function renderStars(rating) {
  if (!rating) return '<span class="text-xs text-slate-600">No rating</span>';
  const full  = Math.floor(rating);
  const half  = rating % 1 >= 0.5;
  const empty = 5 - full - (half ? 1 : 0);
  let html = '';
  for (let i = 0; i < full;  i++) html += '<span class="star-filled text-xs">★</span>';
  if (half)                        html += '<span class="star-filled text-xs opacity-60">★</span>';
  for (let i = 0; i < empty; i++) html += '<span class="star-empty text-xs">★</span>';
  return `<span class="flex gap-0.5">${html}</span>`;
}

function escapeHTML(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function showLoading(show) {
  document.getElementById('loading-state').classList.toggle('hidden', !show);
  const btn = document.getElementById('find-btn');
  btn.disabled = show;
  btn.textContent = show ? 'Auditing…' : 'Find & Audit Leads';
  if (!show) {
    btn.innerHTML = `<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
    </svg> Find &amp; Audit Leads`;
  }
}

function hideResults() {
  document.getElementById('results-section').classList.add('hidden');
  document.getElementById('leads-grid').innerHTML = '';
}

function showError(msg) {
  document.getElementById('error-text').textContent = msg;
  document.getElementById('error-banner').classList.remove('hidden');
}

function hideError() {
  document.getElementById('error-banner').classList.add('hidden');
}

/* ============================================================
   ENTER KEY SUPPORT
============================================================ */
document.addEventListener('DOMContentLoaded', () => {
  loadSavedValues();
  document.getElementById('search-query').addEventListener('keydown', e => {
    if (e.key === 'Enter') startSearch();
  });
});
</script>
</body>
</html>
