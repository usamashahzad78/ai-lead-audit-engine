<?php
/**
 * AI Lead & Audit Engine — api.php
 * Backend: Serper.dev scraper + Website Auditor + OpenAI Pitch Generator
 * Hostinger-ready, zero external dependencies (PHP + cURL only)
 */

// ── Headers ──────────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

// ── Input ─────────────────────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);

$serperKey  = trim($body['serper_key']  ?? '');
$openaiKey  = trim($body['openai_key']  ?? '');
$query      = trim($body['query']       ?? '');
$market     = trim($body['market']      ?? '');
$offer      = trim($body['offer']       ?? 'Fast Modern Website + 24/7 AI WhatsApp Booking Bot');

if (!$serperKey || !$openaiKey || !$query) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields: serper_key, openai_key, query.']);
    exit;
}

// ── Helper: cURL wrapper ──────────────────────────────────────────────────────
function curlPost(string $url, array $headers, string $body): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) return ['error' => $err, 'data' => null];
    return ['error' => null, 'data' => $resp];
}

// ── Step A: Serper.dev Places API ─────────────────────────────────────────────
$serperPayload = json_encode(['q' => $query, 'num' => 20]);
$serperHeaders = [
    'X-API-KEY: ' . $serperKey,
    'Content-Type: application/json',
];

$serperResult = curlPost('https://google.serper.dev/places', $serperHeaders, $serperPayload);

if ($serperResult['error']) {
    http_response_code(502);
    echo json_encode(['error' => 'Serper.dev request failed: ' . $serperResult['error']]);
    exit;
}

$serperData = json_decode($serperResult['data'], true);
$places     = $serperData['places'] ?? [];

if (empty($places)) {
    echo json_encode(['leads' => [], 'message' => 'No results found for your query.']);
    exit;
}

// ── Step B: Website Audit ─────────────────────────────────────────────────────
function auditWebsite(?string $url): array {
    $issues     = [];
    $email      = null;
    $statusBadge = 'good';
    $loadTime   = null;

    if (empty($url)) {
        return [
            'issues'       => ['No Website listed on Google Maps (losing search clients)'],
            'email'        => null,
            'badge'        => 'danger',
            'load_time'    => null,
            'https'        => false,
            'has_chat'     => false,
            'has_viewport' => false,
        ];
    }

    if (!preg_match('/^https?:\/\//i', $url)) {
        $url = 'http://' . $url;
    }

    $isHttps = (strtolower(substr($url, 0, 8)) === 'https://');
    if (!$isHttps) {
        $issues[]    = 'No HTTPS / SSL Certificate (insecure, Google ranking penalty)';
        $statusBadge = 'danger';
    }

    $startTime = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; LeadAuditBot/1.0)',
        CURLOPT_HTTPHEADER     => ['Accept-Encoding: gzip, deflate'],
    ]);
    $html     = curl_exec($ch);
    $curlErr  = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $loadTime = round(microtime(true) - $startTime, 2);

    if ($curlErr || !$html || $httpCode >= 400) {
        return [
            'issues'       => ['Website appears broken or unreachable (HTTP ' . ($httpCode ?: 'error') . ')'],
            'email'        => null,
            'badge'        => 'danger',
            'load_time'    => $loadTime,
            'https'        => $isHttps,
            'has_chat'     => false,
            'has_viewport' => false,
        ];
    }

    if ($loadTime > 3) {
        $issues[]    = 'Slow page load (' . $loadTime . 's) — hurts SEO & conversions';
        $statusBadge = ($statusBadge === 'danger') ? 'danger' : 'warning';
    }

    $hasViewport = (bool) preg_match('/<meta[^>]+name=["\']viewport["\'][^>]*>/i', $html);
    if (!$hasViewport) {
        $issues[]    = 'Missing mobile viewport meta tag (poor mobile experience)';
        $statusBadge = ($statusBadge === 'danger') ? 'danger' : 'warning';
    }

    $chatPatterns = ['wa\.me', 'whatsapp', 'tawk\.to', 'tidio', 'intercom', 'chatbot', 'crisp\.chat', 'freshchat'];
    $hasChat      = false;
    foreach ($chatPatterns as $pattern) {
        if (preg_match('/' . $pattern . '/i', $html)) {
            $hasChat = true;
            break;
        }
    }
    if (!$hasChat) {
        $issues[]    = 'No WhatsApp / AI Chat widget found (missing instant-reply leads)';
        $statusBadge = ($statusBadge === 'danger') ? 'danger' : 'warning';
    }

    if (preg_match('/\b[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}\b/', $html, $emailMatch)) {
        $e = strtolower($emailMatch[0]);
        if (!preg_match('/(example|placeholder|noreply|no-reply|wordpress|schema|sentry|png|jpg|svg|gif)/i', $e)) {
            $email = $e;
        }
    }

    if (empty($issues)) {
        $statusBadge = 'good';
    }

    return [
        'issues'       => $issues,
        'email'        => $email,
        'badge'        => $statusBadge,
        'load_time'    => $loadTime,
        'https'        => $isHttps,
        'has_chat'     => $hasChat,
        'has_viewport' => $hasViewport,
    ];
}

// ── Market pricing map ────────────────────────────────────────────────────────
function getMarketDetails(string $market): array {
    if (stripos($market, 'oman') !== false) {
        return ['price' => '150 OMR', 'payment' => 'local Oman bank transfer', 'currency_note' => 'OMR'];
    }
    if (stripos($market, 'pakistan') !== false) {
        return ['price' => '45,000 PKR', 'payment' => 'local Pakistani bank transfer', 'currency_note' => 'PKR'];
    }
    return ['price' => '$450 USD', 'payment' => 'local US ACH bank transfer', 'currency_note' => 'USD'];
}

// ── Step C: OpenAI Pitch Generator ────────────────────────────────────────────
function generatePitch(
    string $openaiKey,
    string $businessName,
    string $category,
    array  $issues,
    string $offer,
    array  $marketDetails
): string {
    $firstIssue = $issues[0] ?? 'your website needs improvement';

    $systemPrompt = "You are a friendly, concise digital agency sales rep. Write a WhatsApp/email outreach message that is under 80 words, sounds human and casual (not salesy), mentions the exact detected issue in the first sentence, pitches the fixed-price package, mentions local bank payment, and ends with a soft call-to-action asking if they want a 2-minute demo link. Use max 2 emojis.";

    $userPrompt = "Business: {$businessName} ({$category})\n"
        . "Detected issue: {$firstIssue}\n"
        . "Our offer: {$offer}\n"
        . "Fixed price: {$marketDetails['price']}\n"
        . "Payment method: {$marketDetails['payment']}\n"
        . "Write the outreach message now.";

    $payload = json_encode([
        'model'      => 'gpt-4o-mini',
        'max_tokens' => 200,
        'messages'   => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userPrompt],
        ],
    ]);

    $headers = [
        'Authorization: Bearer ' . $openaiKey,
        'Content-Type: application/json',
    ];

    $result = curlPost('https://api.openai.com/v1/chat/completions', $headers, $payload);

    if ($result['error']) {
        return "Hi! I noticed {$firstIssue}. We can fix this with our {$offer} for just {$marketDetails['price']} via {$marketDetails['payment']}. Want a 2-minute demo link?";
    }

    $decoded = json_decode($result['data'], true);
    return $decoded['choices'][0]['message']['content']
        ?? "Hi! We noticed {$firstIssue}. We offer {$offer} for {$marketDetails['price']} payable via {$marketDetails['payment']}. Want a quick 2-min demo?";
}

// ── Main pipeline ─────────────────────────────────────────────────────────────
$marketDetails = getMarketDetails($market);
$leads         = [];

foreach (array_slice($places, 0, 20) as $place) {
    $name     = $place['title']       ?? 'Unknown Business';
    $phone    = $place['phoneNumber'] ?? null;
    $website  = $place['website']     ?? null;
    $rating   = $place['rating']      ?? null;
    $category = $place['type']        ?? ($place['category'] ?? 'Business');
    $address  = $place['address']     ?? null;

    $audit = auditWebsite($website);

    $pitch = generatePitch(
        $openaiKey,
        $name,
        $category,
        $audit['issues'],
        $offer,
        $marketDetails
    );

    $leads[] = [
        'name'      => $name,
        'phone'     => $phone,
        'website'   => $website,
        'rating'    => $rating,
        'category'  => $category,
        'address'   => $address,
        'audit'     => $audit,
        'pitch'     => trim($pitch),
        'email'     => $audit['email'],
        'contacted' => false,
    ];
}

echo json_encode([
    'leads'   => $leads,
    'market'  => $marketDetails,
    'total'   => count($leads),
    'query'   => $query,
]);
