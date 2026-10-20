<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║       AI Lead & Audit Engine  —  index.php  v3              ║
 * ║  Dynamic query builder · Smart scraper · AI dynamic pricing  ║
 * ╚══════════════════════════════════════════════════════════════╝
 */
session_start();
set_time_limit(120);

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  ★ CONFIGURATION — Edit only these variables on Hostinger
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
$ADMIN_USER     = "usama";
$ADMIN_PASS     = "Spada@2026#Secure";
$SERPER_API_KEY = "PASTE_SERPER_KEY_HERE";
$OPENAI_API_KEY = "PASTE_OPENAI_KEY_HERE";
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

define('MAX_LEADS',           15);
define('MAX_FAILED_ATTEMPTS',  5);
define('LOCKOUT_SECONDS',    900);

// ══════════════════════════════════════════════════════════════
//  ALL POST REQUESTS — handled before any HTML output
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── Login ─────────────────────────────────────────────────
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
            $_SESSION['failed_attempts'] = 0; $_SESSION['locked_at'] = null;
        }
        $iu = trim($_POST['username'] ?? '');
        $ip = trim($_POST['password'] ?? '');
        if (hash_equals($ADMIN_USER, $iu) && hash_equals($ADMIN_PASS, $ip)) {
            session_regenerate_id(true);
            $_SESSION['logged_in'] = true; $_SESSION['failed_attempts'] = 0;
            $_SESSION['locked_at'] = null; unset($_SESSION['login_error']);
            header('Location: index.php'); exit;
        }
        $_SESSION['failed_attempts'] = $attempts + 1;
        if ($_SESSION['failed_attempts'] >= MAX_FAILED_ATTEMPTS) {
            $_SESSION['locked_at']   = time();
            $_SESSION['login_error'] = "Account locked 15 min — too many failed attempts.";
        } else {
            $left = MAX_FAILED_ATTEMPTS - $_SESSION['failed_attempts'];
            $_SESSION['login_error'] = "Invalid credentials. {$left} attempt(s) remaining.";
        }
        header('Location: index.php'); exit;
    }

    // ── Logout ────────────────────────────────────────────────
    if (isset($_POST['logout_submit'])) { session_destroy(); header('Location: index.php'); exit; }

    // ── AJAX Search ───────────────────────────────────────────
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_SESSION['logged_in'])) {
        http_response_code(401); echo json_encode(['error' => 'Unauthorized.']); exit;
    }

    $raw        = json_decode(file_get_contents('php://input'), true) ?? [];
    $niche      = trim($raw['niche']       ?? '');
    $city       = trim($raw['city']        ?? '');
    $country    = trim($raw['country']     ?? 'Oman');
    $offer      = trim($raw['offer']       ?? 'Fast Modern Website + 24/7 AI WhatsApp Booking Bot');
    $filterMode = trim($raw['filter_mode'] ?? 'strict');
    $page       = max(1, (int)($raw['page'] ?? 1));

    if (!$niche) { http_response_code(400); echo json_encode(['error' => 'Business niche is required.']); exit; }

    // ── Build smart query ─────────────────────────────────────
    $cityPart    = $city ? " in {$city}" : '';
    $searchQuery = "{$niche}{$cityPart}, {$country}";

    // ── cURL POST helper ──────────────────────────────────────
    function curlPost(string $url, array $headers, string $body): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 25, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_FOLLOWLOCATION => true,
        ]);
        $resp = curl_exec($ch); $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ['error' => $err ?: null, 'data' => $resp, 'http_code' => $code];
    }

    // ── Social / empty site detector ─────────────────────────
    function isSocialOrEmpty(?string $url): bool {
        $url = trim((string)$url);
        if ($url === '') return true;
        return (bool) preg_match(
            '/^https?:\/\/(www\.)?(facebook|instagram|twitter|x\.com|linkedin|youtube|tiktok|wa\.me|whatsapp|t\.me|maps\.google|google\.com\/maps)/i',
            $url
        );
    }

    // ── Quick site audit via cURL ─────────────────────────────
    function quickAudit(string $url): array {
        if (!preg_match('/^https?:\/\//i', $url)) $url = 'http://' . $url;
        $isHttps = stripos($url, 'https://') === 0;
        $t0 = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; LeadBot/1.0)',
        ]);
        $html = curl_exec($ch); $curlErr = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $lt = round(microtime(true) - $t0, 2);
        $issues = [];
        if ($curlErr || !$html || $httpCode >= 400) {
            $issues[] = 'Website unreachable or broken (HTTP ' . ($httpCode ?: 'error') . ')';
        } else {
            if (!$isHttps) $issues[] = 'No HTTPS / SSL (Google ranking penalty)';
            if ($lt > 3)   $issues[] = "Slow load ({$lt}s) — hurts conversions";
            if (!preg_match('/<meta[^>]+name=["\']viewport["\'][^>]*>/i', $html)) $issues[] = 'Missing mobile viewport tag';
            if (!preg_match('/wa\.me|whatsapp|tawk\.to|tidio|intercom|chatbot/i', $html)) $issues[] = 'No WhatsApp / live-chat widget';
        }
        return ['issues' => $issues, 'has_issues' => !empty($issues), 'load_time' => $lt];
    }

    // ── Market config (no hardcoded price — dynamic via GPT) ──
    function getMarket(string $country): array {
        $c = strtolower($country);
        if (strpos($c, 'oman') !== false) return [
            'country'       => 'Oman',
            'currency'      => 'OMR',
            'payment'       => 'local Oman bank transfer (no international fees)',
            'price_range'   => '120 to 180 OMR',
            'price_guidance'=> 'Pick 120 OMR for a basic 5-page informational site, 150 OMR if they need a booking form, and 170-180 OMR for full WhatsApp AI automation. State the exact figure you chose.',
        ];
        if (strpos($c, 'pakistan') !== false) return [
            'country'       => 'Pakistan',
            'currency'      => 'PKR',
            'payment'       => 'local Pakistani bank transfer (EasyPaisa / bank)',
            'price_range'   => '35,000 to 55,000 PKR',
            'price_guidance'=> 'Pick 35,000 PKR for a basic site, 45,000 PKR with booking form, 50,000-55,000 PKR for AI WhatsApp automation. State the exact figure you chose.',
        ];
        return [
            'country'       => 'United States',
            'currency'      => 'USD',
            'payment'       => 'local US ACH bank transfer',
            'price_range'   => '$400 to $650 USD',
            'price_guidance'=> 'Pick $400 for a basic 5-page site, $500 with booking form/OpenTable integration, $600-$650 for AI chat automation. State the exact figure you chose.',
        ];
    }

    // ── OpenAI pitch with dynamic pricing ─────────────────────
    function generatePitch(string $key, array $lead, string $offer, array $mkt): string {
        $badge  = $lead['badge'];
        $name   = $lead['name'];
        $cat    = $lead['category'];
        $rating = $lead['rating']       ?? null;
        $rc     = $lead['rating_count'] ?? 0;
        $issues = $lead['issues']       ?? [];
        $priceGuidance = $mkt['price_guidance'];
        $priceRange    = $mkt['price_range'];
        $payment       = $mkt['payment'];
        $currency      = $mkt['currency'];

        $pricingInstruction = "PRICING: Analyze this business (name, category, their Google rating, and website issue), then dynamically pick the best price from the range ({$priceRange} {$currency}) for this specific client. {$priceGuidance} Always state the chosen price as a clear, flat quote in the message.";

        if ($badge === 'established') {
            $ratingStr = $rating ? "{$rating} stars from {$rc} reviews" : "{$rc} reviews";
            $sys = "You are a warm, concise digital agency outreach specialist. Write a WhatsApp message under 90 words. Be human and friendly, NOT salesy. Structure: (1) Mention you found their business on Google Maps — they have impressive reviews ({$ratingStr}) but no official website or WhatsApp booking system yet. (2) Briefly pitch the service offer. (3) {$pricingInstruction} (4) Mention easy {$payment} — no international fees. (5) Soft CTA: ask if they want a 2-min live demo link. Max 2 emojis.";
            $usr = "Business: {$name}\nCategory: {$cat}\nGoogle: {$ratingStr}\nOffer: {$offer}\nWrite the WhatsApp pitch now.";
        } elseif ($badge === 'new') {
            $sys = "You are a warm, encouraging digital agency outreach specialist. Write a WhatsApp message under 90 words. Be friendly and motivating, NOT salesy. Structure: (1) Congratulate them on listing on Google Maps. (2) Mention their profile doesn't have a website yet — they may be losing customers who can't find info or book online. (3) Pitch the starter package. (4) {$pricingInstruction} (5) Mention {$payment}. (6) Soft CTA: 2-min demo link. Max 2 emojis.";
            $usr = "Business: {$name}\nCategory: {$cat}\nOffer: {$offer}\nWrite the WhatsApp pitch now.";
        } else {
            $issStr = implode('; ', array_slice($issues, 0, 2));
            $sys = "You are a warm, concise digital agency outreach specialist. Write a WhatsApp message under 90 words. Be human, NOT salesy. Structure: (1) Open with the specific website issue you detected ({$issStr}). (2) Explain how it's hurting their business. (3) Pitch the fix. (4) {$pricingInstruction} (5) Mention {$payment}. (6) Soft CTA: 2-min demo link. Max 2 emojis.";
            $usr = "Business: {$name}\nCategory: {$cat}\nRating: " . ($rating ?? 'N/A') . "\nDetected issues: {$issStr}\nOffer: {$offer}\nWrite the WhatsApp pitch now.";
        }

        $payload = json_encode([
            'model'       => 'gpt-4o-mini',
            'max_tokens'  => 200,
            'temperature' => 0.75,
            'messages'    => [
                ['role' => 'system', 'content' => $sys],
                ['role' => 'user',   'content' => $usr],
            ],
        ]);
        $res = curlPost('https://api.openai.com/v1/chat/completions',
            ['Authorization: Bearer ' . $key, 'Content-Type: application/json'], $payload);
        if ($res['error']) return "Hi {$name}! We noticed you don't have a website yet. We'd love to help — flat price via {$payment}. Want a 2-min demo? 🚀";
        $d = json_decode($res['data'], true);
        $msg = $d['choices'][0]['message']['content'] ?? null;
        if (!$msg) {
            $apiErr = $d['error']['message'] ?? 'OpenAI returned an unexpected response.';
            return "[OpenAI Error] {$apiErr}";
        }
        return trim($msg);
    }

    // ── STEP A: Serper.dev Places ─────────────────────────────
    $sr = curlPost(
        'https://google.serper.dev/places',
        ['X-API-KEY: ' . $SERPER_API_KEY, 'Content-Type: application/json'],
        json_encode(['q' => $searchQuery, 'num' => 25, 'page' => $page])
    );

    if ($sr['error']) {
        http_response_code(502);
        echo json_encode(['error' => 'Network error contacting Serper: ' . $sr['error']]); exit;
    }

    $sd = json_decode($sr['data'], true);

    // Surface Serper API-level errors (bad key, quota exceeded, etc.)
    if (isset($sd['error']) || ($sr['http_code'] >= 400)) {
        $serperMsg = $sd['message'] ?? ($sd['error'] ?? ('Serper HTTP ' . $sr['http_code']));
        http_response_code(502);
        echo json_encode(['error' => "Serper API error: {$serperMsg}"]); exit;
    }

    // Accept places OR organic results as fallback
    $places = [];
    if (!empty($sd['places']) && is_array($sd['places'])) {
        $places = $sd['places'];
    } elseif (!empty($sd['organic']) && is_array($sd['organic'])) {
        // Normalize organic results to places format
        foreach ($sd['organic'] as $o) {
            $places[] = [
                'title'       => $o['title'] ?? '',
                'phoneNumber' => $o['phoneNumber'] ?? ($o['phone'] ?? null),
                'website'     => $o['link'] ?? null,
                'rating'      => $o['rating'] ?? null,
                'ratingCount' => $o['ratingCount'] ?? 0,
                'type'        => $o['category'] ?? 'Business',
                'address'     => $o['address'] ?? null,
            ];
        }
    }

    if (empty($places)) {
        echo json_encode([
            'leads' => [], 'total' => 0, 'page' => $page,
            'query' => $searchQuery,
            'message' => $page > 1
                ? "No more results on page {$page}."
                : "No results returned by Serper for \"{$searchQuery}\". Try a different niche or city.",
        ]); exit;
    }

    // ── STEP B: Filter by phone & categorise ─────────────────
    $mktDetails  = getMarket($country);
    $noWebLeads  = [];
    $brokenLeads = [];

    foreach ($places as $p) {
        $name    = trim($p['title']       ?? 'Unknown Business');
        $phone   = trim($p['phoneNumber'] ?? ($p['phone'] ?? ''));
        $website = trim($p['website']     ?? '');
        $rating  = isset($p['rating']) && $p['rating'] !== '' ? (float)$p['rating'] : null;
        $rc      = (int)($p['ratingCount'] ?? ($p['reviewsCount'] ?? ($p['reviews'] ?? 0)));
        $cat     = trim($p['type']    ?? ($p['category'] ?? 'Business'));
        $addr    = trim($p['address'] ?? '');

        // MUST have a phone number for WhatsApp outreach
        if ($phone === '') continue;

        $base = [
            'name'         => $name,
            'phone'        => $phone,
            'website'      => $website,
            'rating'       => $rating,
            'rating_count' => $rc,
            'category'     => $cat,
            'address'      => $addr,
        ];

        if (isSocialOrEmpty($website)) {
            // ── No real website ──
            $badge = ($rc >= 5) ? 'established' : 'new';
            $noWebLeads[] = $base + [
                'badge'       => $badge,
                'badge_label' => $badge === 'established' ? '🔥 Established — No Website' : '🆕 New Business — No Website',
                'badge_sub'   => $badge === 'established' ? "{$rc} reviews · No website" : 'Fresh on Maps · No website',
                'issues'      => ['No official website found on Google Maps (losing online customers)'],
            ];
        } else {
            // Has a real website — only audit in 90% mode
            if ($filterMode === '90percent') {
                $audit = quickAudit($website);
                if ($audit['has_issues']) {
                    $brokenLeads[] = $base + [
                        'badge'       => 'broken',
                        'badge_label' => '⚠️ Website Has Issues',
                        'badge_sub'   => implode(' · ', array_slice($audit['issues'], 0, 2)),
                        'issues'      => $audit['issues'],
                    ];
                }
                // Good website → silently discard (saves OpenAI credits)
            }
        }
    }

    // ── STEP C: Assemble final list ───────────────────────────
    $maxBroken = ($filterMode === '90percent') ? (int) ceil(MAX_LEADS * 0.10) : 0;
    $finalRaw  = array_merge(
        array_slice($noWebLeads,  0, MAX_LEADS - $maxBroken),
        array_slice($brokenLeads, 0, $maxBroken)
    );

    if (empty($finalRaw)) {
        echo json_encode([
            'leads' => [], 'total' => 0, 'page' => $page,
            'query' => $searchQuery,
            'raw_count'    => count($places),
            'with_phone'   => count($noWebLeads) + count($brokenLeads),
            'message'      => 'All leads on this page either had good websites or no phone number. Try "Load Next Page" or switch to "90% mode" to also catch businesses with bad websites.',
        ]); exit;
    }

    // ── STEP D: Generate AI pitches ───────────────────────────
    $finalLeads = [];
    foreach ($finalRaw as $lead) {
        $finalLeads[] = $lead + ['pitch' => generatePitch($OPENAI_API_KEY, $lead, $offer, $mktDetails)];
    }

    echo json_encode([
        'leads'           => $finalLeads,
        'total'           => count($finalLeads),
        'page'            => $page,
        'query'           => $searchQuery,
        'country'         => $mktDetails['country'],
        'currency'        => $mktDetails['currency'],
        'filter_mode'     => $filterMode,
        'raw_count'       => count($places),
        'qualified_count' => count($finalRaw),
    ]);
    exit;
} // end POST

// ══════════════════════════════════════════════════════════════
//  AUTH GATE
// ══════════════════════════════════════════════════════════════
if (!isset($_SESSION['logged_in'])) {
    $loginErr = $_SESSION['login_error'] ?? null; unset($_SESSION['login_error']);
    $attempts  = $_SESSION['failed_attempts'] ?? 0;
    $isLocked  = ($attempts >= MAX_FAILED_ATTEMPTS && isset($_SESSION['locked_at']));
    $remMin    = 0;
    if ($isLocked) { $e = time()-$_SESSION['locked_at']; $isLocked = $e < LOCKOUT_SECONDS; $remMin = $isLocked ? ceil((LOCKOUT_SECONDS-$e)/60) : 0; }
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Login — AI Lead &amp; Audit Engine</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<style>
*{box-sizing:border-box}body{font-family:'Inter',sans-serif;background:#060b18;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1rem}
.card{background:rgba(13,20,40,.88);backdrop-filter:blur(20px);border:1px solid rgba(56,189,248,.15);border-radius:1.25rem;padding:2.5rem;width:100%;max-width:420px}
.gt{background:linear-gradient(135deg,#38bdf8,#818cf8,#c084fc);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.inp{display:block;width:100%;background:rgba(30,41,59,.85);border:1px solid rgba(56,189,248,.2);color:#e2e8f0;border-radius:.75rem;padding:.75rem 1rem;font-size:.875rem;transition:all .2s;font-family:inherit}
.inp:focus{outline:none;border-color:#38bdf8;box-shadow:0 0 0 3px rgba(56,189,248,.15)}
.inp::placeholder{color:#475569}
.btn-p{width:100%;padding:.9rem;border-radius:.75rem;font-weight:700;font-size:.875rem;color:#fff;background:linear-gradient(135deg,#0ea5e9,#2563eb,#7c3aed);border:none;cursor:pointer;transition:all .2s;font-family:inherit}
.btn-p:hover{filter:brightness(1.08);transform:translateY(-1px)}.btn-p:disabled{opacity:.4;cursor:not-allowed;transform:none}
.err{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:#fca5a5;border-radius:.75rem;padding:.875rem;font-size:.84rem;margin-bottom:1.25rem}
.lck{background:rgba(234,179,8,.12);border:1px solid rgba(234,179,8,.3);color:#fde047;border-radius:.75rem;padding:.875rem;font-size:.84rem;margin-bottom:1.25rem;text-align:center}
.lbl{display:block;font-size:.72rem;font-weight:500;color:#94a3b8;margin-bottom:.375rem}
.fld{margin-bottom:1.25rem;position:relative}
.eye{position:absolute;right:.75rem;top:50%;transform:translateY(-50%);background:none;border:none;color:#475569;cursor:pointer;padding:.25rem;line-height:0}.eye:hover{color:#94a3b8}
@keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-6px)}}.flt{animation:float 3.5s ease-in-out infinite}
@keyframes fadeUp{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:translateY(0)}}.fu{animation:fadeUp .45s ease-out}
.orb1{position:fixed;top:-80px;left:50%;transform:translateX(-50%);width:600px;height:280px;background:radial-gradient(ellipse,rgba(56,189,248,.09),transparent 70%);pointer-events:none}
.orb2{position:fixed;bottom:-40px;right:0;width:300px;height:300px;background:radial-gradient(ellipse,rgba(124,58,237,.07),transparent 70%);pointer-events:none}
</style>
</head>
<body>
<div class="orb1"></div><div class="orb2"></div>
<div class="card fu">
  <div style="text-align:center;margin-bottom:2rem">
    <div class="flt" style="display:inline-flex;align-items:center;justify-content:center;width:3.5rem;height:3.5rem;border-radius:1rem;background:linear-gradient(135deg,#0ea5e9,#7c3aed);margin-bottom:1rem">
      <svg width="24" height="24" fill="none" stroke="white" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
    </div>
    <h1 style="font-size:1.6rem;font-weight:800;margin:0 0 .25rem" class="gt">AI Lead &amp; Audit Engine</h1>
    <p style="color:#64748b;font-size:.84rem;margin:0">Secure access — sign in to continue</p>
  </div>
  <?php if($loginErr):?><div class="err"><?=htmlspecialchars($loginErr)?></div><?php endif;?>
  <?php if($isLocked):?><div class="lck">🔒 Locked. Try again in <strong><?=$remMin?> min</strong>.</div><?php endif;?>
  <form method="POST" action="index.php">
    <input type="hidden" name="login_submit" value="1"/>
    <div class="fld"><label class="lbl" for="un">Username</label><input class="inp" id="un" name="username" type="text" required placeholder="Username" <?=$isLocked?'disabled':''?>/></div>
    <div class="fld"><label class="lbl" for="pw">Password</label>
      <input class="inp" id="pw" name="password" type="password" required placeholder="Password" style="padding-right:2.75rem" <?=$isLocked?'disabled':''?>/>
      <button type="button" class="eye" onclick="var e=document.getElementById('pw');e.type=e.type==='password'?'text':'password'">
        <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
      </button>
    </div>
    <button type="submit" class="btn-p" <?=$isLocked?'disabled':''?>>Sign In to Dashboard →</button>
  </form>
  <p style="text-align:center;color:#1e293b;font-size:.68rem;margin-top:1rem">Session auth + brute-force lockout</p>
</div>
</body></html>
<?php exit; } // end auth gate ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>AI Lead &amp; Audit Engine — Dashboard</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<style>
*{box-sizing:border-box}body{font-family:'Inter',sans-serif;background:#060b18;color:#e2e8f0;min-height:100vh}
.glass{background:rgba(13,20,40,.82);backdrop-filter:blur(18px);border:1px solid rgba(56,189,248,.13)}
.gt{background:linear-gradient(135deg,#38bdf8,#818cf8,#c084fc);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.inp{background:rgba(30,41,59,.85);border:1px solid rgba(56,189,248,.2);color:#e2e8f0;transition:border-color .2s;font-family:inherit}
.inp:focus{outline:none;border-color:#38bdf8;box-shadow:0 0 0 3px rgba(56,189,248,.15)}
.inp::placeholder{color:#475569}
select.inp option{background:#1e293b;color:#e2e8f0}
/* Quick-city buttons */
.city-btn{background:rgba(56,189,248,.08);border:1px solid rgba(56,189,248,.2);color:#7dd3fc;font-size:.72rem;font-weight:600;padding:.25rem .65rem;border-radius:999px;cursor:pointer;transition:all .18s;font-family:inherit}
.city-btn:hover,.city-btn.active{background:rgba(56,189,248,.22);border-color:#38bdf8;color:#e0f2fe}
/* Filter toggle */
.toggle-wrap{display:flex;background:rgba(30,41,59,.7);border:1px solid rgba(56,189,248,.15);border-radius:.625rem;overflow:hidden}
.toggle-opt{flex:1;padding:.55rem .5rem;font-size:.76rem;font-weight:600;text-align:center;cursor:pointer;transition:all .2s;font-family:inherit;border:none;color:#64748b;background:transparent}
.toggle-opt.on{background:linear-gradient(135deg,rgba(14,165,233,.25),rgba(124,58,237,.2));color:#7dd3fc;border-radius:.5rem}
/* Badges */
.b-est{background:rgba(251,146,60,.14);color:#fb923c;border:1px solid rgba(251,146,60,.3)}
.b-new{background:rgba(56,189,248,.12);color:#38bdf8;border:1px solid rgba(56,189,248,.28)}
.b-brk{background:rgba(234,179,8,.12);color:#facc15;border:1px solid rgba(234,179,8,.28)}
/* Cards */
.card{background:rgba(13,20,40,.92);border:1px solid rgba(56,189,248,.1);border-radius:1.1rem;padding:1.4rem;transition:border-color .2s,transform .18s;animation:su .4s ease-out both}
.card:hover{border-color:rgba(56,189,248,.28);transform:translateY(-2px)}
.card.contacted{border-color:rgba(34,197,94,.38)!important;background:rgba(34,197,94,.04)!important}
/* Loader */
.spin{width:42px;height:42px;border:3px solid rgba(56,189,248,.18);border-top-color:#38bdf8;border-radius:50%;animation:sp .75s linear infinite}
@keyframes sp{to{transform:rotate(360deg)}}
.pgbar{height:3px;background:linear-gradient(90deg,#38bdf8,#818cf8,#c084fc);animation:pp 1.8s ease-in-out infinite}
@keyframes pp{0%,100%{opacity:.45}50%{opacity:1}}
@keyframes su{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
/* Pitch area */
textarea.pt{background:rgba(22,32,52,.8);border:1px solid rgba(56,189,248,.14);color:#cbd5e1;resize:vertical;font-size:.83rem;line-height:1.7;transition:border-color .2s;font-family:inherit}
textarea.pt:focus{outline:none;border-color:#38bdf8;box-shadow:0 0 0 2px rgba(56,189,248,.12)}
/* Scrollbar */
::-webkit-scrollbar{width:5px}::-webkit-scrollbar-track{background:#0a1020}::-webkit-scrollbar-thumb{background:#1e2d45;border-radius:3px}
/* Buttons */
.btn{transition:all .17s;cursor:pointer;font-family:inherit;border:none}
.btn:hover{filter:brightness(1.12);transform:translateY(-1px)}.btn:active{transform:translateY(0)}
.btn-wa{background:rgba(34,197,94,.15);border:1px solid rgba(34,197,94,.32);color:#4ade80;font-weight:600}
.btn-wa:hover{background:rgba(34,197,94,.24)!important}
.btn-cp{background:rgba(139,92,246,.15);border:1px solid rgba(139,92,246,.32);color:#a78bfa;font-weight:600}
.btn-cp:hover{background:rgba(139,92,246,.24)!important}
.btn-find{width:100%;padding:.9rem;border-radius:.875rem;font-size:.9rem;font-weight:700;color:#fff;background:linear-gradient(135deg,#0ea5e9,#2563eb,#7c3aed);border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:.5rem;transition:all .2s;font-family:inherit;box-shadow:0 6px 28px rgba(14,165,233,.2)}
.btn-find:hover{filter:brightness(1.08);transform:translateY(-1px)}.btn-find:disabled{opacity:.5;cursor:not-allowed;transform:none}
.btn-next{background:linear-gradient(135deg,rgba(14,165,233,.12),rgba(124,58,237,.12));border:1px solid rgba(56,189,248,.28);color:#7dd3fc;font-weight:600;font-size:.84rem}
.btn-next:hover{background:linear-gradient(135deg,rgba(14,165,233,.22),rgba(124,58,237,.22))!important}
/* Phone chip */
.ph-chip{background:rgba(56,189,248,.1);border:1px solid rgba(56,189,248,.22);color:#7dd3fc;font-size:.72rem;font-weight:700;padding:.18rem .6rem;border-radius:999px;font-family:monospace}
/* Toast */
#toast{position:fixed;bottom:1.5rem;right:1.5rem;background:rgba(34,197,94,.95);color:#fff;padding:.6rem 1.2rem;border-radius:.6rem;font-size:.875rem;font-weight:600;transform:translateY(60px);opacity:0;transition:all .28s;z-index:9999}
#toast.show{transform:translateY(0);opacity:1}
/* Stat pill */
.spill{background:rgba(56,189,248,.08);border:1px solid rgba(56,189,248,.16);color:#7dd3fc;font-size:.7rem;font-weight:600;padding:.2rem .65rem;border-radius:999px}
/* Orbs */
.orb1{position:fixed;top:-100px;left:50%;transform:translateX(-50%);width:800px;height:380px;background:radial-gradient(ellipse,rgba(56,189,248,.08),transparent 70%);pointer-events:none;z-index:0}
.orb2{position:fixed;bottom:0;right:0;width:400px;height:400px;background:radial-gradient(ellipse,rgba(124,58,237,.06),transparent 70%);pointer-events:none;z-index:0}
/* Section label */
.sec-lbl{font-size:.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.4rem}
</style>
</head>
<body>
<div class="orb1"></div><div class="orb2"></div>
<div style="max-width:1400px;margin:0 auto;padding:2rem 1rem;position:relative;z-index:1">

  <!-- Header -->
  <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:2rem;flex-wrap:wrap">
    <div>
      <div style="display:inline-flex;align-items:center;gap:.45rem;background:rgba(56,189,248,.08);border:1px solid rgba(56,189,248,.18);border-radius:999px;padding:.28rem .85rem;font-size:.7rem;font-weight:700;color:#38bdf8;margin-bottom:.65rem;letter-spacing:.03em">
        <span style="width:5px;height:5px;border-radius:50%;background:#38bdf8;display:inline-block;animation:pp 2s infinite"></span>AI-POWERED LEAD INTELLIGENCE
      </div>
      <h1 style="font-size:clamp(1.6rem,4vw,2.8rem);font-weight:800;margin:0 0 .2rem;line-height:1.1" class="gt">AI Lead &amp; Audit Engine</h1>
      <p style="color:#334155;font-size:.78rem;margin:0">Logged in as <span style="color:#38bdf8;font-weight:600"><?=htmlspecialchars($ADMIN_USER)?></span></p>
    </div>
    <form method="POST" action="index.php">
      <input type="hidden" name="logout_submit" value="1"/>
      <button type="submit" class="btn glass" style="display:flex;align-items:center;gap:.45rem;padding:.55rem 1rem;border-radius:.7rem;font-size:.78rem;color:#f87171;border:1px solid rgba(239,68,68,.22)">
        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>Logout
      </button>
    </form>
  </div>

  <!-- Search Panel -->
  <div class="glass" style="border-radius:1.25rem;padding:1.75rem;margin-bottom:1.5rem">
    <h2 style="font-size:.88rem;font-weight:700;color:#94a3b8;margin:0 0 1.25rem;display:flex;align-items:center;gap:.45rem;text-transform:uppercase;letter-spacing:.05em">
      <svg width="15" height="15" fill="none" stroke="#38bdf8" viewBox="0 0 24 24" stroke-width="2"><circle cx="11" cy="11" r="8"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35"/></svg>Search Configuration
    </h2>

    <!-- Row 1: Niche + Country + City -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:1rem">

      <!-- Niche -->
      <div>
        <div class="sec-lbl">1 · Business Niche</div>
        <input id="niche" type="text" placeholder="e.g. Dental Clinic, Car Rental…" class="inp" style="width:100%;border-radius:.75rem;padding:.7rem 1rem;font-size:.875rem"/>
      </div>

      <!-- Country -->
      <div>
        <div class="sec-lbl">2 · Target Country</div>
        <select id="country" class="inp" onchange="updateCities()" style="width:100%;border-radius:.75rem;padding:.7rem 1rem;font-size:.875rem;cursor:pointer">
          <option value="Oman">🇴🇲 Oman (OMR · Local Bank)</option>
          <option value="United States">🇺🇸 United States (USD · ACH)</option>
          <option value="Pakistan">🇵🇰 Pakistan (PKR · Local Bank)</option>
        </select>
      </div>

      <!-- City -->
      <div>
        <div class="sec-lbl">3 · City / Region</div>
        <input id="city" type="text" placeholder="e.g. Muscat" class="inp" style="width:100%;border-radius:.75rem;padding:.7rem 1rem;font-size:.875rem"/>
        <!-- Quick city chips -->
        <div id="city-chips" style="display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.5rem"></div>
      </div>

    </div>

    <!-- Row 2: Service Offer + Filter Mode -->
    <div style="display:grid;grid-template-columns:1fr auto;gap:1rem;margin-bottom:1.25rem;align-items:end">
      <div>
        <div class="sec-lbl">Service Offer</div>
        <input id="oi" type="text" value="Fast Modern Website + 24/7 AI WhatsApp Booking Bot" class="inp" style="width:100%;border-radius:.75rem;padding:.7rem 1rem;font-size:.875rem"/>
      </div>
      <div style="min-width:260px">
        <div class="sec-lbl">🎯 Filter Mode</div>
        <div class="toggle-wrap">
          <button id="f-strict" class="toggle-opt on" onclick="setFilter('strict')">Only No-Website Leads ★</button>
          <button id="f-90" class="toggle-opt" onclick="setFilter('90percent')">+ Include Bad Websites</button>
        </div>
      </div>
    </div>

    <button id="fb" onclick="startSearch()" class="btn-find">
      <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><circle cx="11" cy="11" r="8"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35"/></svg>Find &amp; Audit Leads
    </button>
  </div>

  <!-- Loading -->
  <div id="ld" style="display:none;margin-bottom:1.5rem">
    <div class="glass" style="border-radius:1.25rem;padding:3rem;text-align:center">
      <div style="display:flex;justify-content:center;margin-bottom:1.25rem"><div class="spin"></div></div>
      <h3 style="font-size:1.05rem;font-weight:600;color:#e2e8f0;margin:0 0 .4rem">Scanning &amp; Generating AI Pitches…</h3>
      <p style="color:#334155;font-size:.83rem;margin:0 0 1.25rem">Filtering leads · Discarding good websites · Writing custom proposals — ~30–60s</p>
      <div style="max-width:340px;margin:0 auto;background:#0f1a2e;border-radius:999px;height:3px;overflow:hidden"><div class="pgbar" style="width:100%"></div></div>
    </div>
  </div>

  <!-- Error -->
  <div id="eb" style="display:none;margin-bottom:1.25rem">
    <div style="padding:1rem 1.25rem;border-radius:1rem;border:1px solid rgba(239,68,68,.3);background:rgba(239,68,68,.08);color:#fca5a5;font-size:.875rem;display:flex;align-items:flex-start;gap:.75rem">
      <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" style="flex-shrink:0;margin-top:1px"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      <span id="et"></span>
    </div>
  </div>

  <!-- Results -->
  <div id="rs" style="display:none">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;margin-bottom:1.25rem">
      <div>
        <h2 style="font-size:1.15rem;font-weight:700;color:#f1f5f9;margin:0 0 .4rem" id="rt">0 Leads Found</h2>
        <div style="display:flex;flex-wrap:wrap;gap:.35rem" id="spills"></div>
      </div>
      <button onclick="exportCSV()" class="btn glass" style="display:flex;align-items:center;gap:.45rem;padding:.55rem 1rem;border-radius:.75rem;font-size:.78rem;color:#7dd3fc;border:1px solid rgba(56,189,248,.28)">
        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3M3 17V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>Export CSV
      </button>
    </div>

    <div id="lg" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(460px,1fr));gap:1.1rem"></div>

    <div style="margin-top:2rem;text-align:center">
      <button id="npb" onclick="loadNextPage()" class="btn btn-next" style="padding:.8rem 2.25rem;border-radius:.875rem;display:inline-flex;align-items:center;gap:.5rem">
        <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>Load Next Page of Leads
      </button>
      <p style="color:#1e2d45;font-size:.72rem;margin:.45rem 0 0">Fetches next batch from Google Maps — never repeats previous results</p>
    </div>
  </div>

</div>
<div id="toast">✅ Copied!</div>

<script>
// ── Config per country ─────────────────────────────────────────
var CITIES = {
  'Oman':          ['Muscat','Salalah','Sohar','Nizwa','Sur','Barka','Ibri'],
  'United States': ['New York NY','Los Angeles CA','Houston TX','Austin TX','Miami FL','Dallas TX','Chicago IL'],
  'Pakistan':      ['Karachi','Lahore','Islamabad','Rawalpindi','Faisalabad','Peshawar','Quetta'],
};

// ── State ──────────────────────────────────────────────────────
var leadsData   = [];
var currentPage = 0;
var filterMode  = 'strict';

// ── Restore saved values ───────────────────────────────────────
(function(){
  var n=localStorage.getItem('ae_niche');   if(n) document.getElementById('niche').value=n;
  var o=localStorage.getItem('ae_offer');   if(o) document.getElementById('oi').value=o;
  var ct=localStorage.getItem('ae_city');   if(ct) document.getElementById('city').value=ct;
  var co=localStorage.getItem('ae_country');
  if(co){var s=document.getElementById('country');for(var i=0;i<s.options.length;i++)if(s.options[i].value===co){s.value=co;break;}}
  var f=localStorage.getItem('ae_filter'); if(f) setFilter(f,true);
  updateCities();
})();

function save(){
  localStorage.setItem('ae_niche',   document.getElementById('niche').value.trim());
  localStorage.setItem('ae_offer',   document.getElementById('oi').value.trim());
  localStorage.setItem('ae_city',    document.getElementById('city').value.trim());
  localStorage.setItem('ae_country', document.getElementById('country').value);
  localStorage.setItem('ae_filter',  filterMode);
}

// ── City quick-select chips ────────────────────────────────────
function updateCities(){
  var country = document.getElementById('country').value;
  var cities  = CITIES[country] || [];
  var wrap    = document.getElementById('city-chips');
  wrap.innerHTML = '';
  cities.forEach(function(c){
    var b=document.createElement('button');
    b.type='button'; b.className='city-btn'; b.textContent=c;
    b.onclick=function(){
      document.getElementById('city').value=c;
      document.querySelectorAll('.city-btn').forEach(function(x){x.classList.remove('active');});
      b.classList.add('active');
    };
    wrap.appendChild(b);
  });
  // Auto-highlight if city already set
  var curCity=document.getElementById('city').value;
  if(curCity){ document.querySelectorAll('.city-btn').forEach(function(b){ if(b.textContent===curCity) b.classList.add('active'); }); }
}

// ── Filter toggle ──────────────────────────────────────────────
function setFilter(mode, silent){
  filterMode=mode;
  document.getElementById('f-strict').classList.toggle('on', mode==='strict');
  document.getElementById('f-90').classList.toggle('on', mode==='90percent');
  if(!silent) localStorage.setItem('ae_filter', mode);
}

// ── Contacted tracking ─────────────────────────────────────────
function cKey(p){ return 'ae_c_'+(p||'').replace(/\D/g,''); }
function isContacted(p){ return localStorage.getItem(cKey(p))==='1'; }
function setContacted(p){ if(p) localStorage.setItem(cKey(p),'1'); }

// ── Search ─────────────────────────────────────────────────────
function startSearch(){
  var niche=document.getElementById('niche').value.trim();
  if(!niche){showErr('Please enter a business niche (e.g. "Dental Clinic").'); return;}
  save();
  leadsData=[]; currentPage=0;
  document.getElementById('lg').innerHTML='';
  document.getElementById('rs').style.display='none';
  hideErr(); fetchPage(1);
}
function loadNextPage(){ if(currentPage>0) fetchPage(currentPage+1); }

async function fetchPage(page){
  setLoad(true); hideErr();
  try{
    var resp=await fetch('index.php',{
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({
        niche:       document.getElementById('niche').value.trim(),
        city:        document.getElementById('city').value.trim(),
        country:     document.getElementById('country').value,
        offer:       document.getElementById('oi').value.trim(),
        filter_mode: filterMode,
        page:        page,
      })
    });
    var data=await resp.json();
    if(!resp.ok||data.error) throw new Error(data.error||'Server error.');
    currentPage=data.page||page;
    if(!data.leads||data.leads.length===0){
      showErr(data.message||'No qualifying leads on this page. Try "Load Next Page" or switch filter mode.');
    } else {
      data.leads.forEach(function(l){ leadsData.push(l); });
      renderResults(data, page>1);
    }
  } catch(e){ showErr(e.message||'Unknown error.'); }
  finally{ setLoad(false); }
}

// ── Render results ─────────────────────────────────────────────
function renderResults(data, append){
  document.getElementById('rt').textContent = leadsData.length+' Lead'+(leadsData.length!==1?'s':'')+' Found';

  var sp=document.getElementById('spills'); sp.innerHTML='';
  function pill(t){ var d=document.createElement('span'); d.className='spill'; d.textContent=t; sp.appendChild(d); }
  pill(data.query);
  if(data.raw_count)       pill(data.raw_count+' raw results');
  if(data.qualified_count) pill(data.qualified_count+' qualifying');
  pill('Page '+data.page);
  pill(data.filter_mode==='strict'?'Strict mode':'90/10 mode');

  var grid=document.getElementById('lg');
  if(!append) grid.innerHTML='';
  var startI = append ? (leadsData.length - data.leads.length) : 0;
  data.leads.forEach(function(lead, i){ grid.appendChild(buildCard(lead, startI+i)); });
  document.getElementById('rs').style.display='block';
  document.getElementById('npb').innerHTML='<svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg> Load Next Page (Page '+(data.page+1)+')';
}

// ── Build card ─────────────────────────────────────────────────
function buildCard(lead, idx){
  var card=document.createElement('div');
  card.className='card'; card.id='c'+idx;
  card.style.animationDelay=(idx%8*50)+'ms';
  if(isContacted(lead.phone)) card.classList.add('contacted');

  var bc = lead.badge==='established'?'b-est': lead.badge==='new'?'b-new': 'b-brk';

  // Stars
  var starsHtml='';
  if(lead.rating){ var f=Math.floor(lead.rating),e=5-f; for(var i=0;i<f;i++) starsHtml+='<span style="color:#facc15">&#9733;</span>'; for(var j=0;j<e;j++) starsHtml+='<span style="color:#1e293b">&#9733;</span>'; }

  // Issues
  var issHtml='';
  if(lead.issues&&lead.issues.length){
    issHtml='<div style="background:rgba(15,23,42,.7);border:1px solid rgba(56,189,248,.1);border-radius:.625rem;padding:.65rem .75rem;margin-bottom:.875rem"><p style="font-size:.68rem;font-weight:700;color:#475569;margin:0 0 .35rem;text-transform:uppercase;letter-spacing:.05em">Detected Issues</p><ul style="margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:.25rem">';
    lead.issues.forEach(function(iss){ issHtml+='<li style="display:flex;align-items:flex-start;gap:.4rem;font-size:.76rem;color:#94a3b8"><span style="color:#f87171;flex-shrink:0;margin-top:1px">&#9888;</span>'+esc(iss)+'</li>'; });
    issHtml+='</ul></div>';
  }

  var noPhone=!lead.phone||lead.phone.length<5;
  var contacted=isContacted(lead.phone);

  card.innerHTML=
    // Top row: name + badge
    '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:.75rem;margin-bottom:.75rem">'+
      '<div style="flex:1;min-width:0">'+
        '<h3 style="font-size:.98rem;font-weight:700;color:#f1f5f9;margin:0 0 .18rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">'+esc(lead.name)+'</h3>'+
        '<p style="font-size:.75rem;color:#475569;margin:0 0 .4rem">'+esc(lead.category||'')+(lead.address?' &middot; '+esc(lead.address):'')+'</p>'+
        '<div style="display:flex;align-items:center;flex-wrap:wrap;gap:.4rem">'+
          (lead.rating?'<span style="display:flex;gap:1px;font-size:.72rem">'+starsHtml+'</span><span style="font-size:.72rem;color:#64748b">'+lead.rating+(lead.rating_count?' ('+lead.rating_count+' reviews)':'')+'</span>':'<span style="font-size:.72rem;color:#334155">No rating yet</span>')+
          (lead.phone?'<span class="ph-chip">'+esc(lead.phone)+'</span>':'')+
        '</div>'+
      '</div>'+
      '<div style="display:flex;flex-direction:column;align-items:flex-end;gap:.3rem;flex-shrink:0">'+
        '<span class="'+bc+'" style="padding:.22rem .65rem;border-radius:999px;font-size:.68rem;font-weight:700;white-space:nowrap">'+esc(lead.badge_label||'Lead')+'</span>'+
        '<span style="font-size:.63rem;color:#334155;text-align:right">'+esc(lead.badge_sub||'')+'</span>'+
      '</div>'+
    '</div>'+

    // Issues
    issHtml+

    // AI Pitch
    '<div style="margin-bottom:.875rem">'+
      '<label style="display:block;font-size:.68rem;font-weight:700;color:#334155;margin-bottom:.35rem;text-transform:uppercase;letter-spacing:.05em">✨ AI Proposal (editable)</label>'+
      '<textarea id="pt'+idx+'" class="pt" rows="5" style="width:100%;border-radius:.75rem;padding:.75rem">'+esc(lead.pitch)+'</textarea>'+
    '</div>'+

    // Buttons
    '<div style="display:flex;gap:.45rem;flex-wrap:wrap">'+
      (!noPhone
        ?'<button onclick="sendWA('+idx+')" id="wa'+idx+'" class="btn btn-wa" style="flex:1;padding:.6rem .75rem;border-radius:.7rem;font-size:.77rem;display:flex;align-items:center;justify-content:center;gap:.35rem;min-width:130px">'+
            '<svg width="13" height="13" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>'+
            'Send WhatsApp'+
          '</button>'
        :'<span style="flex:1;padding:.6rem .75rem;border-radius:.7rem;font-size:.77rem;display:flex;align-items:center;justify-content:center;background:rgba(15,23,42,.6);color:#1e293b;min-width:130px">No Phone Found</span>')+
      '<button onclick="cpPitch('+idx+')" class="btn btn-cp" style="padding:.6rem .875rem;border-radius:.7rem;font-size:.77rem;display:flex;align-items:center;gap:.3rem">'+
        '<svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/></svg>Copy'+
      '</button>'+
    '</div>'+

    // Contacted badge
    '<div id="ct'+idx+'" style="display:'+(contacted?'flex':'none')+';margin-top:.625rem;align-items:center;gap:.35rem;color:#4ade80;font-size:.75rem;font-weight:600">'+
      '<svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>'+
      'Contacted &#10003; — Saved in browser (won\'t message twice)'+
    '</div>';

  return card;
}

// ── Actions ─────────────────────────────────────────────────────
function sendWA(idx){
  var lead=leadsData[idx];
  var phone=(lead.phone||'').replace(/[^0-9+]/g,'');
  if(!phone){alert('No valid phone number for this lead.');return;}
  window.open('https://wa.me/'+phone+'?text='+encodeURIComponent(document.getElementById('pt'+idx).value),'_blank');
  setContacted(lead.phone);
  document.getElementById('c'+idx).classList.add('contacted');
  document.getElementById('ct'+idx).style.display='flex';
  var wb=document.getElementById('wa'+idx);
  if(wb){wb.textContent='✅ Message Sent';wb.disabled=true;wb.style.opacity='.5';wb.style.cursor='not-allowed';}
}
function cpPitch(idx){
  navigator.clipboard.writeText(document.getElementById('pt'+idx).value).then(function(){
    var t=document.getElementById('toast');t.classList.add('show');setTimeout(function(){t.classList.remove('show');},2400);
  });
}
function exportCSV(){
  if(!leadsData.length)return;
  var h=['Name','Category','Phone','Rating','Reviews','Badge','Issues','Address','Website','Pitch'];
  var rows=leadsData.map(function(l,i){
    return [l.name,l.category,l.phone||'',l.rating||'',l.rating_count||'',l.badge_label||'',
      (l.issues||[]).join(' | '),l.address||'',l.website||'',
      (document.getElementById('pt'+i)||{}).value||l.pitch
    ].map(function(v){return'"'+String(v||'').replace(/"/g,'""')+'"';}).join(',');
  });
  var a=document.createElement('a');
  a.href=URL.createObjectURL(new Blob([[h.join(',')].concat(rows).join('\n')],{type:'text/csv'}));
  a.download='leads_'+new Date().toISOString().slice(0,10)+'.csv'; a.click();
}

// ── Utilities ────────────────────────────────────────────────────
function setLoad(v){
  document.getElementById('ld').style.display=v?'block':'none';
  var b=document.getElementById('fb'); b.disabled=v;
  b.innerHTML=v
    ?'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" style="animation:sp .7s linear infinite"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity=".25"/><path d="M12 2a10 10 0 010 20" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg> Auditing Leads\u2026'
    :'<svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><circle cx="11" cy="11" r="8"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35"/></svg> Find &amp; Audit Leads';
}
function showErr(m){document.getElementById('et').textContent=m;document.getElementById('eb').style.display='block';window.scrollTo({top:document.getElementById('eb').offsetTop-80,behavior:'smooth'});}
function hideErr(){document.getElementById('eb').style.display='none';}
function esc(s){if(!s)return'';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
document.getElementById('niche').addEventListener('keydown',function(e){if(e.key==='Enter')startSearch();});
document.getElementById('city').addEventListener('keydown',function(e){if(e.key==='Enter')startSearch();});
</script>
</body>
</html>
