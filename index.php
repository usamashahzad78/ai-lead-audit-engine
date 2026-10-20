<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║       AI Lead & Audit Engine  —  index.php                  ║
 * ║  Single-file • Login • Smart Filter • AI Pitch Generator    ║
 * ╚══════════════════════════════════════════════════════════════╝
 */
session_start();
set_time_limit(120);

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  ★ CONFIGURATION  —  Edit ONLY these 4 variables
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
$ADMIN_USER     = "usama";
$ADMIN_PASS     = "Spada@2026#Secure";
$SERPER_API_KEY = "PASTE_SERPER_KEY_HERE";
$OPENAI_API_KEY = "PASTE_OPENAI_KEY_HERE";
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

define('MAX_LEADS',            15);
define('MAX_FAILED_ATTEMPTS',   5);
define('LOCKOUT_SECONDS',     900);  // 15 minutes

// ══════════════════════════════════════════════════════════════
//  HANDLE ALL POST REQUESTS (must be before any HTML output)
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── 1. Login ──────────────────────────────────────────────
    if (isset($_POST['login_submit'])) {
        $attempts = $_SESSION['failed_attempts'] ?? 0;
        $lockedAt = $_SESSION['locked_at']       ?? null;
        if ($attempts >= MAX_FAILED_ATTEMPTS && $lockedAt) {
            $elapsed = time() - $lockedAt;
            if ($elapsed < LOCKOUT_SECONDS) {
                $rem = ceil((LOCKOUT_SECONDS - $elapsed) / 60);
                $_SESSION['login_error'] = "Too many failed attempts. Try again in {$rem} minute(s).";
                header('Location: index.php'); exit;
            }
            $_SESSION['failed_attempts'] = 0;
            $_SESSION['locked_at']       = null;
        }
        $iu = trim($_POST['username'] ?? '');
        $ip = trim($_POST['password'] ?? '');
        if (hash_equals($ADMIN_USER, $iu) && hash_equals($ADMIN_PASS, $ip)) {
            session_regenerate_id(true);
            $_SESSION['logged_in']       = true;
            $_SESSION['failed_attempts'] = 0;
            $_SESSION['locked_at']       = null;
            unset($_SESSION['login_error']);
            header('Location: index.php'); exit;
        }
        $_SESSION['failed_attempts'] = ($attempts + 1);
        if ($_SESSION['failed_attempts'] >= MAX_FAILED_ATTEMPTS) {
            $_SESSION['locked_at']   = time();
            $_SESSION['login_error'] = "Account locked for 15 minutes after too many failed attempts.";
        } else {
            $left = MAX_FAILED_ATTEMPTS - $_SESSION['failed_attempts'];
            $_SESSION['login_error'] = "Invalid credentials. {$left} attempt(s) remaining before lockout.";
        }
        header('Location: index.php'); exit;
    }

    // ── 2. Logout ─────────────────────────────────────────────
    if (isset($_POST['logout_submit'])) {
        session_destroy();
        header('Location: index.php'); exit;
    }

    // ── 3. AJAX Lead Search ───────────────────────────────────
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_SESSION['logged_in'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized. Please log in.']); exit;
    }

    $raw        = json_decode(file_get_contents('php://input'), true) ?? [];
    $query      = trim($raw['query']       ?? '');
    $market     = trim($raw['market']      ?? '');
    $offer      = trim($raw['offer']       ?? 'Fast Modern Website + 24/7 AI WhatsApp Booking Bot');
    $filterMode = trim($raw['filter_mode'] ?? 'strict');
    $page       = max(1, (int)($raw['page'] ?? 1));

    if (!$query) {
        http_response_code(400);
        echo json_encode(['error' => 'Search query is required.']); exit;
    }

    // ── Helper: cURL POST ──────────────────────────────────────
    function curlPost(string $url, array $headers, string $body): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS     => $body, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 25, CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        return ['error' => $err ?: null, 'data' => $resp];
    }

    // ── Helper: Is social / fake site? ────────────────────────
    function isSocialOrNoSite(?string $url): bool {
        if (empty(trim((string)$url))) return true;
        return (bool) preg_match(
            '/facebook\.com|instagram\.com|twitter\.com|x\.com|linkedin\.com|youtube\.com|tiktok\.com|wa\.me|whatsapp\.com|maps\.google/i',
            $url
        );
    }

    // ── Helper: Quick cURL site audit ─────────────────────────
    function quickAudit(string $url): array {
        if (!preg_match('/^https?:\/\//i', $url)) $url = 'http://' . $url;
        $isHttps = strtolower(substr($url, 0, 8)) === 'https://';
        $t0  = microtime(true);
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; LeadBot/1.0)',
        ]);
        $html     = curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $lt = round(microtime(true) - $t0, 2);

        $issues = [];
        if ($curlErr || !$html || $httpCode >= 400) {
            $issues[] = 'Website unreachable or broken (HTTP ' . ($httpCode ?: 'error') . ')';
        } else {
            if (!$isHttps) $issues[] = 'No HTTPS / SSL (Google ranking penalty)';
            if ($lt > 3)   $issues[] = 'Slow load (' . $lt . 's) — hurts conversions';
            if (!preg_match('/<meta[^>]+name=["\']viewport["\'][^>]*>/i', $html))
                $issues[] = 'Missing mobile viewport meta tag';
            $hasChat = (bool) preg_match('/wa\.me|whatsapp|tawk\.to|tidio|intercom|chatbot/i', $html);
            if (!$hasChat) $issues[] = 'No WhatsApp / live-chat widget';
        }
        return ['issues' => $issues, 'has_issues' => !empty($issues), 'load_time' => $lt];
    }

    // ── Helper: Market pricing ─────────────────────────────────
    function getMarket(string $m): array {
        if (stripos($m, 'oman')     !== false)
            return ['price' => '150 OMR',    'payment' => 'local Oman bank transfer'];
        if (stripos($m, 'pakistan') !== false)
            return ['price' => '45,000 PKR', 'payment' => 'local Pakistani bank transfer'];
        return ['price' => '$450 USD', 'payment' => 'local US ACH bank transfer'];
    }

    // ── Helper: OpenAI pitch ───────────────────────────────────
    function generatePitch(string $key, array $lead, string $offer, array $mkt): string {
        $badge  = $lead['badge'];
        $name   = $lead['name'];
        $cat    = $lead['category'];
        $rating = $lead['rating']       ?? 'N/A';
        $rc     = $lead['rating_count'] ?? 0;
        $issues = $lead['issues']       ?? [];

        if ($badge === 'established') {
            $sys = "You are a warm, concise digital agency outreach specialist. Write a WhatsApp message under 85 words. Be human and friendly, NOT salesy. Structure: (1) Say you saw their impressive Google Maps reviews ({$rating} stars, {$rc} reviews) and noticed they don't have an official website or WhatsApp booking system yet. (2) Briefly pitch the service. (3) Mention easy local bank payment — no international fees. (4) Soft CTA: do they want a 2-min demo link? Max 2 emojis.";
            $usr = "Business: {$name} ({$cat})\nGoogle rating: {$rating} stars from {$rc} reviews\nOffer: {$offer}\nPrice: {$mkt['price']}\nPayment: {$mkt['payment']}\nWrite the WhatsApp message now.";
        } elseif ($badge === 'new') {
            $sys = "You are a warm, concise digital agency outreach specialist. Write a WhatsApp message under 85 words. Be encouraging and friendly, NOT salesy. Structure: (1) Congratulate them on listing their business on Google Maps. (2) Mention their profile doesn't have a website yet, so they're likely losing potential customers who want to book online. (3) Pitch the starter Website + AI WhatsApp Bot package at a fixed price. (4) Mention local bank payment. (5) Soft CTA: want a 2-min demo link? Max 2 emojis.";
            $usr = "Business: {$name} ({$cat})\nOffer: {$offer}\nPrice: {$mkt['price']}\nPayment: {$mkt['payment']}\nWrite the WhatsApp message now.";
        } else {
            $iss = implode('; ', array_slice($issues, 0, 2));
            $sys = "You are a warm, concise digital agency outreach specialist. Write a WhatsApp message under 85 words. Be human, NOT salesy. Mention the specific website issue in the first sentence, pitch the fix at a flat price, mention local bank payment, end with a soft CTA asking if they want a 2-min demo link. Max 2 emojis.";
            $usr = "Business: {$name} ({$cat})\nDetected issues: {$iss}\nOffer: {$offer}\nPrice: {$mkt['price']}\nPayment: {$mkt['payment']}\nWrite the WhatsApp message now.";
        }

        $payload = json_encode([
            'model' => 'gpt-4o-mini', 'max_tokens' => 180,
            'messages' => [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $usr]],
        ]);
        $res = curlPost('https://api.openai.com/v1/chat/completions',
            ['Authorization: Bearer ' . $key, 'Content-Type: application/json'], $payload);
        if ($res['error']) return "Hi {$name}! We noticed you don't have a website yet. We offer {$offer} for {$mkt['price']} via {$mkt['payment']}. Want a 2-min demo link? 🚀";
        $d = json_decode($res['data'], true);
        return trim($d['choices'][0]['message']['content'] ?? "Hi {$name}! We'd love to help with {$offer} for {$mkt['price']} via {$mkt['payment']}. Want a quick demo? 👋");
    }

    // ── STEP A: Serper.dev Places ──────────────────────────────
    $sr = curlPost(
        'https://google.serper.dev/places',
        ['X-API-KEY: ' . $SERPER_API_KEY, 'Content-Type: application/json'],
        json_encode(['q' => $query, 'num' => 20, 'page' => $page])
    );
    if ($sr['error']) {
        http_response_code(502);
        echo json_encode(['error' => 'Serper.dev error: ' . $sr['error']]); exit;
    }
    $sd     = json_decode($sr['data'], true);
    $places = $sd['places'] ?? [];
    if (empty($places)) {
        echo json_encode(['leads' => [], 'total' => 0, 'page' => $page, 'query' => $query,
            'message' => $page > 1 ? 'No more results on page ' . $page . '. Try a different query.' : 'No results found.']);
        exit;
    }

    // ── STEP B: Filter — discard leads with no phone ───────────
    $withPhone = array_values(array_filter($places, fn($p) => !empty(trim((string)($p['phoneNumber'] ?? '')))));

    // ── STEP C: Categorise leads ───────────────────────────────
    $mktDetails    = getMarket($market);
    $noWebLeads    = [];
    $brokenLeads   = [];

    foreach ($withPhone as $p) {
        $name    = trim($p['title']       ?? 'Unknown Business');
        $phone   = trim($p['phoneNumber'] ?? '');
        $website = trim($p['website']     ?? '');
        $rating  = $p['rating']           ?? null;
        $rc      = (int)($p['ratingCount'] ?? ($p['reviewsCount'] ?? ($p['reviews'] ?? 0)));
        $cat     = trim($p['type']        ?? ($p['category'] ?? 'Business'));
        $addr    = trim($p['address']     ?? '');
        $base    = compact('name','phone','website','rating','cat','addr') + ['category' => $cat, 'address' => $addr, 'rating_count' => $rc];

        if (isSocialOrNoSite($website)) {
            // ── No real website ──
            if ($rc >= 5) {
                $badge = 'established';
                $label = '🔥 Established Business';
                $sub   = 'Has Reviews · No Website';
            } else {
                $badge = 'new';
                $label = '🆕 Newly Listed Business';
                $sub   = 'Fresh on Maps · No Website';
            }
            $noWebLeads[] = $base + [
                'badge'       => $badge,
                'badge_label' => $label,
                'badge_sub'   => $sub,
                'issues'      => ['No Website listed on Google Maps (losing search clients)'],
                'audit'       => null,
            ];
        } elseif (!empty($website) && $filterMode === '90percent') {
            // ── Has real website — quick audit ──
            $audit = quickAudit($website);
            if ($audit['has_issues']) {
                $brokenLeads[] = $base + [
                    'badge'       => 'broken',
                    'badge_label' => '⚠️ Website Has Issues',
                    'badge_sub'   => implode(' · ', array_slice($audit['issues'], 0, 2)),
                    'issues'      => $audit['issues'],
                    'audit'       => $audit,
                ];
            }
        }
        // Strict mode: leads with good websites are silently discarded (saves credits)
    }

    // ── STEP D: Assemble final list (90% no-website, 10% broken) ──
    $maxBroken  = ($filterMode === '90percent') ? (int) ceil(MAX_LEADS * 0.10) : 0;
    $maxNoWeb   = MAX_LEADS - $maxBroken;
    $finalRaw   = array_merge(
        array_slice($noWebLeads,  0, $maxNoWeb),
        array_slice($brokenLeads, 0, $maxBroken)
    );

    if (empty($finalRaw)) {
        echo json_encode(['leads' => [], 'total' => 0, 'page' => $page, 'query' => $query,
            'raw_count' => count($places), 'qualified_count' => 0,
            'message' => 'No qualifying leads on this page (all had websites or no phone). Try "Load Next Page".']);
        exit;
    }

    // ── STEP E: Generate AI pitches ───────────────────────────
    $finalLeads = [];
    foreach ($finalRaw as $lead) {
        $pitch        = generatePitch($OPENAI_API_KEY, $lead, $offer, $mktDetails);
        $finalLeads[] = $lead + ['pitch' => $pitch];
    }

    echo json_encode([
        'leads'           => $finalLeads,
        'total'           => count($finalLeads),
        'page'            => $page,
        'query'           => $query,
        'market'          => $mktDetails,
        'filter_mode'     => $filterMode,
        'raw_count'       => count($places),
        'with_phone'      => count($withPhone),
        'qualified_count' => count($finalRaw),
    ]);
    exit;

} // end POST

// ══════════════════════════════════════════════════════════════
//  AUTH GATE — Show login if not authenticated
// ══════════════════════════════════════════════════════════════
if (!isset($_SESSION['logged_in'])) {
    $loginErr = $_SESSION['login_error'] ?? null;
    unset($_SESSION['login_error']);
    $attempts  = $_SESSION['failed_attempts'] ?? 0;
    $isLocked  = ($attempts >= MAX_FAILED_ATTEMPTS && isset($_SESSION['locked_at']));
    $remMin    = 0;
    if ($isLocked) {
        $elapsed  = time() - $_SESSION['locked_at'];
        $isLocked = $elapsed < LOCKOUT_SECONDS;
        $remMin   = $isLocked ? ceil((LOCKOUT_SECONDS - $elapsed) / 60) : 0;
    }
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Login — AI Lead &amp; Audit Engine</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<style>
*{box-sizing:border-box}
body{font-family:'Inter',sans-serif;background:#060b18;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1rem}
.card{background:rgba(13,20,40,.85);backdrop-filter:blur(20px);border:1px solid rgba(56,189,248,.14);border-radius:1.25rem;padding:2.5rem;width:100%;max-width:420px}
.gt{background:linear-gradient(135deg,#38bdf8,#818cf8,#c084fc);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.inp{display:block;width:100%;background:rgba(30,41,59,.85);border:1px solid rgba(56,189,248,.2);color:#e2e8f0;border-radius:.75rem;padding:.75rem 1rem;font-size:.875rem;transition:all .2s;font-family:inherit}
.inp:focus{outline:none;border-color:#38bdf8;box-shadow:0 0 0 3px rgba(56,189,248,.15)}
.inp::placeholder{color:#475569}
.btn-primary{width:100%;padding:.875rem;border-radius:.75rem;font-weight:700;font-size:.875rem;color:#fff;background:linear-gradient(135deg,#0ea5e9,#2563eb,#7c3aed);border:none;cursor:pointer;transition:all .2s;font-family:inherit}
.btn-primary:hover{filter:brightness(1.1);transform:translateY(-1px)}
.btn-primary:disabled{opacity:.4;cursor:not-allowed;transform:none}
.alert-err{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:#fca5a5;border-radius:.75rem;padding:.875rem 1rem;font-size:.875rem;margin-bottom:1.25rem;display:flex;align-items:flex-start;gap:.625rem}
.alert-lock{background:rgba(234,179,8,.12);border:1px solid rgba(234,179,8,.3);color:#fde047;border-radius:.75rem;padding:.875rem 1rem;font-size:.875rem;margin-bottom:1.25rem;text-align:center}
.lbl{display:block;font-size:.75rem;font-weight:500;color:#94a3b8;margin-bottom:.375rem}
.field{margin-bottom:1.25rem;position:relative}
.eye-btn{position:absolute;right:.75rem;top:50%;transform:translateY(-50%);background:none;border:none;color:#475569;cursor:pointer;padding:.25rem;line-height:0}
.eye-btn:hover{color:#94a3b8}
@keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-6px)}}.float{animation:float 3.5s ease-in-out infinite}
.glow1{position:fixed;top:-100px;left:50%;transform:translateX(-50%);width:600px;height:300px;background:radial-gradient(ellipse,rgba(56,189,248,.09),transparent 70%);pointer-events:none}
.glow2{position:fixed;bottom:-50px;right:0;width:300px;height:300px;background:radial-gradient(ellipse,rgba(124,58,237,.07),transparent 70%);pointer-events:none}
</style>
</head>
<body>
<div class="glow1"></div><div class="glow2"></div>
<div class="card" style="animation:fadeUp .5s ease-out" id="loginCard">
  <div style="text-align:center;margin-bottom:2rem">
    <div class="float" style="display:inline-flex;align-items:center;justify-content:center;width:3.5rem;height:3.5rem;border-radius:1rem;background:linear-gradient(135deg,#0ea5e9,#7c3aed);margin-bottom:1rem">
      <svg width="24" height="24" fill="none" stroke="white" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
    </div>
    <h1 style="font-size:1.75rem;font-weight:800;margin:0 0 .25rem" class="gt">AI Lead &amp; Audit Engine</h1>
    <p style="color:#64748b;font-size:.875rem;margin:0">Secure access — sign in to continue</p>
  </div>

  <?php if($loginErr):?>
  <div class="alert-err">
    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:2px"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    <?=htmlspecialchars($loginErr)?>
  </div>
  <?php endif;?>
  <?php if($isLocked):?>
  <div class="alert-lock">🔒 Account locked. Try again in <strong><?=$remMin?> minute(s)</strong>.</div>
  <?php endif;?>

  <form method="POST" action="index.php">
    <input type="hidden" name="login_submit" value="1"/>
    <div class="field">
      <label class="lbl" for="un">Username</label>
      <input class="inp" id="un" name="username" type="text" required placeholder="Enter username" autocomplete="username" <?=$isLocked?'disabled':''?>/>
    </div>
    <div class="field">
      <label class="lbl" for="pw">Password</label>
      <input class="inp" id="pw" name="password" type="password" required placeholder="Enter password" autocomplete="current-password" style="padding-right:2.75rem" <?=$isLocked?'disabled':''?>/>
      <button type="button" class="eye-btn" onclick="var e=document.getElementById('pw');e.type=e.type==='password'?'text':'password'">
        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
      </button>
    </div>
    <button type="submit" class="btn-primary" <?=$isLocked?'disabled':''?>>Sign In to Dashboard →</button>
  </form>
  <p style="text-align:center;color:#334155;font-size:.7rem;margin-top:1.25rem">Session auth + brute-force lockout enabled</p>
</div>
<style>@keyframes fadeUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}</style>
</body>
</html>
<?php exit; } // end auth gate
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>AI Lead &amp; Audit Engine — Dashboard</title>
<meta name="description" content="AI-powered lead scraping, website auditing and outreach pitch generator."/>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<style>
*{box-sizing:border-box}
body{font-family:'Inter',sans-serif;background:#060b18;color:#e2e8f0;min-height:100vh}
.glass{background:rgba(13,20,40,.8);backdrop-filter:blur(18px);border:1px solid rgba(56,189,248,.13)}
.gt{background:linear-gradient(135deg,#38bdf8,#818cf8,#c084fc);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.inp{background:rgba(30,41,59,.85);border:1px solid rgba(56,189,248,.2);color:#e2e8f0;transition:all .2s;font-family:inherit}
.inp:focus{outline:none;border-color:#38bdf8;box-shadow:0 0 0 3px rgba(56,189,248,.15)}
.inp::placeholder{color:#475569}
select.inp option{background:#1e293b}
/* Badges */
.badge-est{background:rgba(251,146,60,.15);color:#fb923c;border:1px solid rgba(251,146,60,.35)}
.badge-new{background:rgba(56,189,248,.12);color:#38bdf8;border:1px solid rgba(56,189,248,.3)}
.badge-brk{background:rgba(234,179,8,.12);color:#facc15;border:1px solid rgba(234,179,8,.3)}
.badge-ok {background:rgba(34,197,94,.12);color:#4ade80;border:1px solid rgba(34,197,94,.3)}
/* Cards */
.card{background:rgba(13,20,40,.9);border:1px solid rgba(56,189,248,.1);border-radius:1.25rem;padding:1.5rem;transition:border-color .2s,transform .2s;animation:slideUp .45s ease-out both}
.card:hover{border-color:rgba(56,189,248,.3);transform:translateY(-2px)}
.card.contacted{border-color:rgba(34,197,94,.4)!important;background:rgba(34,197,94,.04)!important}
/* Spinner */
.spin{width:44px;height:44px;border:3px solid rgba(56,189,248,.2);border-top-color:#38bdf8;border-radius:50%;animation:sp .8s linear infinite}
@keyframes sp{to{transform:rotate(360deg)}}
.prog{height:3px;background:linear-gradient(90deg,#38bdf8,#818cf8,#c084fc);animation:pp 2s ease-in-out infinite}
@keyframes pp{0%,100%{opacity:.5}50%{opacity:1}}
@keyframes slideUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
/* Pitch textarea */
textarea.pt{background:rgba(30,41,59,.65);border:1px solid rgba(56,189,248,.15);color:#cbd5e1;resize:vertical;font-size:.84rem;line-height:1.65;transition:border-color .2s;font-family:inherit}
textarea.pt:focus{outline:none;border-color:#38bdf8;box-shadow:0 0 0 2px rgba(56,189,248,.12)}
/* Scrollbar */
::-webkit-scrollbar{width:5px}::-webkit-scrollbar-track{background:#0f172a}::-webkit-scrollbar-thumb{background:#334155;border-radius:3px}
/* Buttons */
.btn{transition:all .18s;cursor:pointer;font-weight:500;font-family:inherit;border:none}
.btn:hover{filter:brightness(1.12);transform:translateY(-1px)}
.btn:active{transform:translateY(0)}
.btn-wa{background:rgba(34,197,94,.15);border:1px solid rgba(34,197,94,.35);color:#4ade80}
.btn-wa:hover{background:rgba(34,197,94,.25)!important}
.btn-cp{background:rgba(139,92,246,.15);border:1px solid rgba(139,92,246,.35);color:#a78bfa}
.btn-cp:hover{background:rgba(139,92,246,.25)!important}
.btn-next{background:linear-gradient(135deg,rgba(14,165,233,.15),rgba(124,58,237,.15));border:1px solid rgba(56,189,248,.3);color:#7dd3fc}
.btn-next:hover{background:linear-gradient(135deg,rgba(14,165,233,.25),rgba(124,58,237,.25))!important}
/* Phone pill */
.phone-pill{background:rgba(56,189,248,.1);border:1px solid rgba(56,189,248,.25);color:#7dd3fc;font-size:.75rem;font-weight:600;padding:.2rem .65rem;border-radius:999px;font-family:monospace}
/* Toast */
#toast{position:fixed;bottom:1.5rem;right:1.5rem;background:rgba(34,197,94,.95);color:#fff;padding:.625rem 1.25rem;border-radius:.625rem;font-size:.875rem;font-weight:600;transform:translateY(60px);opacity:0;transition:all .3s;z-index:9999}
#toast.show{transform:translateY(0);opacity:1}
/* Glow orbs */
.orb1{position:fixed;top:-120px;left:50%;transform:translateX(-50%);width:800px;height:400px;background:radial-gradient(ellipse,rgba(56,189,248,.08),transparent 70%);pointer-events:none}
.orb2{position:fixed;bottom:0;right:0;width:400px;height:400px;background:radial-gradient(ellipse,rgba(124,58,237,.06),transparent 70%);pointer-events:none}
/* Stats pill */
.stat-pill{background:rgba(56,189,248,.08);border:1px solid rgba(56,189,248,.18);color:#7dd3fc;font-size:.75rem;font-weight:600;padding:.25rem .75rem;border-radius:999px}
</style>
</head>
<body>
<div class="orb1"></div><div class="orb2"></div>
<div style="max-width:1400px;margin:0 auto;padding:2.5rem 1rem">

  <!-- ── Header ── -->
  <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:2.5rem;flex-wrap:wrap">
    <div>
      <div style="display:inline-flex;align-items:center;gap:.5rem;background:rgba(56,189,248,.08);border:1px solid rgba(56,189,248,.2);border-radius:999px;padding:.3rem .9rem;font-size:.72rem;font-weight:600;color:#38bdf8;margin-bottom:.75rem">
        <span style="width:6px;height:6px;border-radius:50%;background:#38bdf8;display:inline-block;animation:pp 2s infinite"></span>AI-Powered Lead Intelligence
      </div>
      <h1 style="font-size:clamp(1.75rem,4vw,3rem);font-weight:800;margin:0 0 .25rem;line-height:1.1" class="gt">AI Lead &amp; Audit Engine</h1>
      <p style="color:#475569;font-size:.8rem;margin:0">Logged in as <span style="color:#38bdf8;font-weight:600"><?=htmlspecialchars($ADMIN_USER)?></span></p>
    </div>
    <form method="POST" action="index.php" style="margin-top:.25rem">
      <input type="hidden" name="logout_submit" value="1"/>
      <button type="submit" class="btn glass" style="display:flex;align-items:center;gap:.5rem;padding:.625rem 1.125rem;border-radius:.75rem;font-size:.8rem;color:#f87171;border:1px solid rgba(239,68,68,.25)">
        <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
        Logout
      </button>
    </form>
  </div>

  <!-- ── Search Panel ── -->
  <div class="glass" style="border-radius:1.25rem;padding:1.75rem;margin-bottom:1.75rem">
    <h2 style="font-size:.9rem;font-weight:600;color:#cbd5e1;margin:0 0 1.25rem;display:flex;align-items:center;gap:.5rem">
      <svg width="16" height="16" fill="none" stroke="#38bdf8" viewBox="0 0 24 24" stroke-width="2"><circle cx="11" cy="11" r="8"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35"/></svg>
      Search &amp; Filter
    </h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1rem;margin-bottom:1rem">
      <div>
        <label style="display:block;font-size:.72rem;font-weight:500;color:#94a3b8;margin-bottom:.375rem">Business Niche &amp; City</label>
        <input id="sq" type="text" placeholder="e.g. Dental Clinics in Muscat, Oman" class="inp" style="width:100%;border-radius:.75rem;padding:.7rem 1rem;font-size:.875rem"/>
      </div>
      <div>
        <label style="display:block;font-size:.72rem;font-weight:500;color:#94a3b8;margin-bottom:.375rem">Target Market &amp; Pricing</label>
        <select id="ms" class="inp" style="width:100%;border-radius:.75rem;padding:.7rem 1rem;font-size:.875rem;cursor:pointer">
          <option value="Oman (150 OMR - Local Oman Bank Transfer)">🇴🇲 Oman — 150 OMR (Local Oman Bank Transfer)</option>
          <option value="US ($450 USD - Local US ACH Bank Transfer)">🇺🇸 US — $450 USD (Local US ACH Bank Transfer)</option>
          <option value="Pakistan (45,000 PKR - Local Bank Transfer)">🇵🇰 Pakistan — 45,000 PKR (Local Bank Transfer)</option>
        </select>
      </div>
      <div>
        <label style="display:block;font-size:.72rem;font-weight:500;color:#94a3b8;margin-bottom:.375rem">Service Offer</label>
        <input id="oi" type="text" value="Fast Modern Website + 24/7 AI WhatsApp Booking Bot" class="inp" style="width:100%;border-radius:.75rem;padding:.7rem 1rem;font-size:.875rem"/>
      </div>
      <div>
        <label style="display:block;font-size:.72rem;font-weight:500;color:#94a3b8;margin-bottom:.375rem">🎯 Filter Mode (Credit Saver)</label>
        <select id="fm" class="inp" style="width:100%;border-radius:.75rem;padding:.7rem 1rem;font-size:.875rem;cursor:pointer">
          <option value="strict">Strict: Only "No Website" Leads ★ Saves Most Credits</option>
          <option value="90percent">90% No Website + 10% Broken/Slow Sites</option>
        </select>
      </div>
    </div>
    <button id="fb" onclick="startSearch()" class="btn" style="width:100%;padding:.9rem;border-radius:.875rem;font-size:.9rem;font-weight:700;color:#fff;background:linear-gradient(135deg,#0ea5e9,#2563eb,#7c3aed);display:flex;align-items:center;justify-content:center;gap:.5rem;box-shadow:0 8px 32px rgba(14,165,233,.2)">
      <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><circle cx="11" cy="11" r="8"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35"/></svg>
      Find &amp; Audit Leads
    </button>
  </div>

  <!-- ── Loading ── -->
  <div id="ld" style="display:none" class="glass" style="border-radius:1.25rem">
    <div style="padding:3rem;text-align:center;border-radius:1.25rem" class="glass">
      <div style="display:flex;justify-content:center;margin-bottom:1.25rem"><div class="spin"></div></div>
      <h3 style="font-size:1.1rem;font-weight:600;color:#e2e8f0;margin:0 0 .5rem">Scanning &amp; Auditing Leads…</h3>
      <p style="color:#475569;font-size:.85rem;margin:0 0 1.25rem">Filtering Google Maps results, discarding good websites, generating AI pitches — ~30–60s</p>
      <div style="width:100%;max-width:360px;margin:0 auto;background:#1e293b;border-radius:999px;height:3px;overflow:hidden"><div class="prog" style="width:100%"></div></div>
    </div>
  </div>

  <!-- ── Error ── -->
  <div id="eb" style="display:none;margin-bottom:1.25rem" class="glass">
    <div style="padding:1rem 1.25rem;border-radius:1.25rem;border:1px solid rgba(239,68,68,.3);background:rgba(239,68,68,.08);color:#fca5a5;font-size:.875rem;display:flex;align-items:flex-start;gap:.75rem">
      <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" style="flex-shrink:0;margin-top:2px"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      <span id="et"></span>
    </div>
  </div>

  <!-- ── Results ── -->
  <div id="rs" style="display:none">
    <!-- Stats bar -->
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;margin-bottom:1.25rem">
      <div>
        <h2 style="font-size:1.2rem;font-weight:700;color:#f1f5f9;margin:0 0 .35rem" id="rt">0 Leads Found</h2>
        <div style="display:flex;flex-wrap:wrap;gap:.4rem" id="stat-pills"></div>
      </div>
      <button onclick="exportCSV()" class="btn glass" style="display:flex;align-items:center;gap:.5rem;padding:.6rem 1.125rem;border-radius:.75rem;font-size:.8rem;color:#7dd3fc;border:1px solid rgba(56,189,248,.3)">
        <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3M3 17V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
        Export CSV
      </button>
    </div>

    <!-- Lead grid -->
    <div id="lg" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(480px,1fr));gap:1.25rem"></div>

    <!-- Load Next Page -->
    <div style="margin-top:2rem;text-align:center">
      <button id="npb" onclick="loadNextPage()" class="btn btn-next" style="padding:.875rem 2.5rem;border-radius:.875rem;font-size:.875rem;font-weight:600;display:inline-flex;align-items:center;gap:.5rem">
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
        Load Next Page of Leads
      </button>
      <p style="color:#334155;font-size:.75rem;margin:.5rem 0 0">Fetches the next batch from Google Maps without repeating previous results</p>
    </div>
  </div>

</div><!-- /container -->
<div id="toast">✅ Copied to clipboard!</div>

<script>
// ── State ──────────────────────────────────────────────────────
var leadsData  = [];   // all leads accumulated across pages
var globalIdx  = 0;    // global card index (never resets between pages)
var currentPage = 0;

// ── localStorage persistence ───────────────────────────────────
var LS = { q:'ae_q', m:'ae_m', o:'ae_o', f:'ae_f' };
(function(){
  var q=localStorage.getItem(LS.q); if(q) document.getElementById('sq').value=q;
  var o=localStorage.getItem(LS.o); if(o) document.getElementById('oi').value=o;
  var m=localStorage.getItem(LS.m); if(m){var s=document.getElementById('ms');for(var i=0;i<s.options.length;i++)if(s.options[i].value===m){s.value=m;break;}}
  var f=localStorage.getItem(LS.f); if(f){var sf=document.getElementById('fm');for(var j=0;j<sf.options.length;j++)if(sf.options[j].value===f){sf.value=f;break;}}
})();
function save(){
  localStorage.setItem(LS.q,document.getElementById('sq').value.trim());
  localStorage.setItem(LS.o,document.getElementById('oi').value.trim());
  localStorage.setItem(LS.m,document.getElementById('ms').value);
  localStorage.setItem(LS.f,document.getElementById('fm').value);
}

// ── Contacted tracking ────────────────────────────────────────
function cKey(phone){ return 'ae_c_'+(phone||'').replace(/\D/g,''); }
function isContacted(phone){ return localStorage.getItem(cKey(phone))==='1'; }
function markContacted(phone){ if(phone) localStorage.setItem(cKey(phone),'1'); }

// ── Search ────────────────────────────────────────────────────
function startSearch(){
  var q=document.getElementById('sq').value.trim();
  if(!q) return showErr('Please enter a business niche and city.');
  save();
  leadsData=[];globalIdx=0;currentPage=0;
  document.getElementById('lg').innerHTML='';
  document.getElementById('rs').style.display='none';
  hideErr();fetchPage(1);
}
function loadNextPage(){ if(currentPage>0) fetchPage(currentPage+1); }

async function fetchPage(page){
  var query=document.getElementById('sq').value.trim();
  var market=document.getElementById('ms').value;
  var offer=document.getElementById('oi').value.trim();
  var filterMode=document.getElementById('fm').value;
  setLoad(true);hideErr();
  try{
    var resp=await fetch('index.php',{
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({query,market,offer,filter_mode:filterMode,page})
    });
    var data=await resp.json();
    if(!resp.ok||data.error) throw new Error(data.error||'Server error.');
    currentPage=data.page||page;
    if(!data.leads||data.leads.length===0){
      showErr(data.message||'No qualifying leads on this page. Try "Load Next Page".');
      if(page===1) return;
    } else {
      data.leads.forEach(function(l){ leadsData.push(l); });
      renderResults(data, page>1);
    }
  }catch(e){ showErr('Error: '+(e.message||'Unknown error.')); }
  finally{ setLoad(false); }
}

function renderResults(data, append){
  var rt=document.getElementById('rt');
  rt.textContent=leadsData.length+' Lead'+(leadsData.length!==1?'s':'')+' Found';
  // Stats pills
  var sp=document.getElementById('stat-pills');
  sp.innerHTML='';
  function pill(t){var d=document.createElement('span');d.className='stat-pill';d.textContent=t;sp.appendChild(d);}
  pill('Page '+data.page);
  if(data.raw_count)       pill(data.raw_count+' raw results');
  if(data.with_phone)      pill(data.with_phone+' have phone');
  if(data.qualified_count) pill(data.qualified_count+' qualified');
  pill(data.filter_mode==='strict'?'Strict mode':'90% mode');

  var grid=document.getElementById('lg');
  if(!append) grid.innerHTML='';
  var startI=append?(leadsData.length-data.leads.length):0;
  data.leads.forEach(function(lead,i){
    var gi=startI+i;
    grid.appendChild(buildCard(lead,gi));
  });
  document.getElementById('rs').style.display='block';
  document.getElementById('npb').textContent='\u25bc Load Next Page (Page '+(data.page+1)+')';
}

// ── Build lead card ───────────────────────────────────────────
function buildCard(lead,idx){
  var card=document.createElement('div');
  card.className='card';
  card.id='c'+idx;
  card.style.animationDelay=(idx%8*55)+'ms';
  if(isContacted(lead.phone)) card.classList.add('contacted');

  var badge=lead.badge;
  var bc= badge==='established'?'badge-est': badge==='new'?'badge-new': badge==='broken'?'badge-brk':'badge-ok';
  var bl=lead.badge_label||'Lead';
  var bs=lead.badge_sub||'';

  var starsHtml='';
  if(lead.rating){
    var f=Math.floor(lead.rating),e=5-f;
    for(var i=0;i<f;i++) starsHtml+='<span style="color:#facc15">&#9733;</span>';
    for(var j=0;j<e;j++) starsHtml+='<span style="color:#374151">&#9733;</span>';
  }

  var issHtml='';
  if(lead.issues&&lead.issues.length){
    issHtml='<ul style="margin:.5rem 0 0;padding:0;list-style:none;display:flex;flex-direction:column;gap:.3rem">';
    lead.issues.forEach(function(iss){
      issHtml+='<li style="display:flex;align-items:flex-start;gap:.5rem;font-size:.77rem;color:#94a3b8"><span style="color:#f87171;flex-shrink:0">&#9888;</span>'+esc(iss)+'</li>';
    });
    issHtml+='</ul>';
  }

  var waDisabled=!lead.phone||lead.phone.length<5;
  var contActed=isContacted(lead.phone);

  card.innerHTML=
    '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:.75rem;margin-bottom:.875rem">'+
      '<div style="flex:1;min-width:0">'+
        '<h3 style="font-size:1rem;font-weight:700;color:#f1f5f9;margin:0 0 .2rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">'+esc(lead.name)+'</h3>'+
        '<p style="font-size:.77rem;color:#64748b;margin:0">'+esc(lead.category||'')+(lead.address?' &middot; '+esc(lead.address):'')+'</p>'+
        '<div style="display:flex;align-items:center;gap:.5rem;margin-top:.4rem">'+
          (lead.rating?'<span style="display:flex;gap:1px;font-size:.75rem">'+starsHtml+'</span><span style="font-size:.75rem;color:#94a3b8">'+lead.rating+(lead.rating_count?' ('+lead.rating_count+')':'')+'</span>':'')+
          (lead.phone?'<span class="phone-pill">'+esc(lead.phone)+'</span>':'')+
        '</div>'+
      '</div>'+
      '<div style="display:flex;flex-direction:column;align-items:flex-end;gap:.35rem;flex-shrink:0">'+
        '<span class="'+bc+'" style="padding:.25rem .7rem;border-radius:999px;font-size:.7rem;font-weight:700;white-space:nowrap">'+bl+'</span>'+
        '<span style="font-size:.65rem;color:#64748b;text-align:right">'+esc(bs)+'</span>'+
      '</div>'+
    '</div>'+

    (issHtml?
      '<div style="background:rgba(30,41,59,.6);border:1px solid rgba(56,189,248,.1);border-radius:.75rem;padding:.75rem;margin-bottom:.875rem">'+
        '<p style="font-size:.72rem;font-weight:600;color:#64748b;margin:0">Detected Issues</p>'+issHtml+
      '</div>':'')+

    '<div style="margin-bottom:.875rem">'+
      '<label style="display:block;font-size:.72rem;font-weight:500;color:#64748b;margin-bottom:.35rem">&#10024; AI-Generated Pitch <span style="color:#334155">(editable)</span></label>'+
      '<textarea id="pt'+idx+'" class="pt" rows="5" style="width:100%;border-radius:.75rem;padding:.75rem;border-radius:.75rem">'+esc(lead.pitch)+'</textarea>'+
    '</div>'+

    '<div style="display:flex;gap:.5rem;flex-wrap:wrap">'+
      (!waDisabled?
        '<button onclick="sendWA('+idx+')" id="wa'+idx+'" class="btn btn-wa" style="flex:1;padding:.65rem .75rem;border-radius:.75rem;font-size:.78rem;display:flex;align-items:center;justify-content:center;gap:.4rem;min-width:140px">'+
          '<svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>'+
          'Send WhatsApp'+
        '</button>'
       :'<span style="flex:1;padding:.65rem .75rem;border-radius:.75rem;font-size:.78rem;display:flex;align-items:center;justify-content:center;background:rgba(30,41,59,.5);color:#334155;min-width:140px">No Phone Found</span>')+
      '<button onclick="cpPitch('+idx+')" class="btn btn-cp" style="padding:.65rem .875rem;border-radius:.75rem;font-size:.78rem;display:flex;align-items:center;gap:.35rem">'+
        '<svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/></svg>'+
        'Copy'+
      '</button>'+
    '</div>'+

    '<div id="ct'+idx+'" style="display:'+(contActed?'flex':'none')+';margin-top:.75rem;align-items:center;gap:.4rem;color:#4ade80;font-size:.78rem;font-weight:600">'+
      '<svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>'+
      'Contacted &#10003;&#10003; — Saved in browser so you never message twice'+
    '</div>';

  return card;
}

// ── Actions ───────────────────────────────────────────────────
function sendWA(idx){
  var lead=leadsData[idx];
  var phone=(lead.phone||'').replace(/[^0-9+]/g,'');
  if(!phone){alert('No phone number for this lead.');return;}
  var pitch=document.getElementById('pt'+idx).value;
  window.open('https://wa.me/'+phone+'?text='+encodeURIComponent(pitch),'_blank');
  markContacted(lead.phone);
  // Mark UI
  document.getElementById('c'+idx).classList.add('contacted');
  document.getElementById('ct'+idx).style.display='flex';
  var wb=document.getElementById('wa'+idx);
  if(wb){wb.textContent='✅ Message Sent';wb.disabled=true;wb.style.opacity='.5';wb.style.cursor='not-allowed';}
}
function cpPitch(idx){
  var t=document.getElementById('pt'+idx).value;
  navigator.clipboard.writeText(t).then(function(){
    var x=document.getElementById('toast');x.classList.add('show');setTimeout(function(){x.classList.remove('show');},2500);
  }).catch(function(){});
}

// ── CSV Export ────────────────────────────────────────────────
function exportCSV(){
  if(!leadsData.length)return;
  var h=['Name','Category','Phone','Rating','Reviews','Badge','Issues','Address','Website','Pitch'];
  var rows=leadsData.map(function(l,i){
    return [l.name,l.category,l.phone||'',l.rating||'',l.rating_count||'',l.badge_label||'',
      (l.issues||[]).join(' | '),l.address||'',l.website||'',
      (document.getElementById('pt'+i)||{value:l.pitch}).value
    ].map(function(v){return '"'+String(v||'').replace(/"/g,'""')+'"';}).join(',');
  });
  var csv=[h.join(',')].concat(rows).join('\n');
  var a=document.createElement('a');
  a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv'}));
  a.download='leads_'+new Date().toISOString().slice(0,10)+'.csv';
  a.click();
}

// ── UI Utils ──────────────────────────────────────────────────
function setLoad(v){
  document.getElementById('ld').style.display=v?'block':'none';
  var b=document.getElementById('fb');
  b.disabled=v;
  b.innerHTML=v?'<svg width="16" height="16" fill="none" viewBox="0 0 24 24" style="animation:sp .8s linear infinite"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" stroke-dasharray="60" stroke-dashoffset="20" opacity=".3"/><path d="M12 2a10 10 0 010 20" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg> Auditing Leads\u2026':'<svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><circle cx="11" cy="11" r="8"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35"/></svg> Find &amp; Audit Leads';
}
function showErr(m){document.getElementById('et').textContent=m;document.getElementById('eb').style.display='block';}
function hideErr(){document.getElementById('eb').style.display='none';}
function esc(s){if(!s)return'';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

// ── Enter key ─────────────────────────────────────────────────
document.getElementById('sq').addEventListener('keydown',function(e){if(e.key==='Enter')startSearch();});
</script>
</body>
</html>
