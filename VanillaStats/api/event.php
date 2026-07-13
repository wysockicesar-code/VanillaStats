<?php
// Simple Analytics - Tracking Endpoint (pageview + heartbeat)

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');
header('Cross-Origin-Resource-Policy: cross-origin');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
    exit;
}

// Load config (for site token)
$config = require __DIR__ . '/../config.php';
$expectedToken = $config['site_token'] ?? '';
$token = $data['token'] ?? '';

if ($expectedToken !== '' && $token !== $expectedToken) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid token']);
    exit;
}

try {
    $db = new SQLite3(__DIR__ . '/../analytics.db');
    // Reduce "database is locked" problems on shared hosting / when dashboard is open
    $db->busyTimeout(3000);
    $db->exec("PRAGMA journal_mode = WAL;");
    $db->exec("PRAGMA synchronous = NORMAL;");
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'DB open failed', 'detail' => $e->getMessage()]);
    exit;
}

// Pageviews table
$db->exec('CREATE TABLE IF NOT EXISTS visits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_url TEXT,
    page_url TEXT,
    referrer TEXT,
    user_agent TEXT,
    browser TEXT,
    os TEXT,
    device TEXT,
    ip_hash TEXT,
    country TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)');

// Sessions table (for active now + duration)
$db->exec('CREATE TABLE IF NOT EXISTS sessions (
    session_id TEXT PRIMARY KEY,
    site_url TEXT,
    page_url TEXT,
    ip_hash TEXT,
    started_at DATETIME,
    last_seen DATETIME,
    duration_seconds INTEGER DEFAULT 0
)');

// Visit-sessions table (for bounce rate)
$db->exec('CREATE TABLE IF NOT EXISTS visit_sessions (
    visit_session_id TEXT PRIMARY KEY,
    visitor_id TEXT,
    site_url TEXT,
    ip_hash TEXT,
    country TEXT,
    started_at DATETIME,
    last_seen DATETIME,
    pageviews INTEGER DEFAULT 0
)');

$siteUrl   = $data['site'] ?? '';
$pageUrl   = $data['page'] ?? '';
$referrer  = $data['ref'] ?? ($_SERVER['HTTP_REFERER'] ?? 'Direct');
$type      = $data['type'] ?? 'pageview';
$sid       = $data['sid'] ?? '';

$visitorId = $data['vid'] ?? '';
$visitSessionId = $data['vsid'] ?? '';

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

// Best-effort client IP (handles common proxy/CDN headers)
function getClientIp() {
    $candidates = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_REAL_IP',
        'HTTP_TRUE_CLIENT_IP',
        'HTTP_X_FORWARDED_FOR',
        'REMOTE_ADDR'
    ];
    foreach ($candidates as $key) {
        if (!empty($_SERVER[$key])) {
            $v = $_SERVER[$key];
            if ($key === 'HTTP_X_FORWARDED_FOR') {
                $parts = explode(',', $v);
                $v = trim($parts[0]);
            }
            if (filter_var($v, FILTER_VALIDATE_IP)) return $v;
        }
    }
    return '';
}

// Country detection (works everywhere; uses CDN headers when available, otherwise falls back to PHP geoip ext if present)
function isoToCountryName($iso) {
    static $map = null;
    if ($map === null) {
        // Minimal ISO-3166-1 alpha-2 map for common countries
        $map = [
            'US' => 'United States', 'CA' => 'Canada', 'GB' => 'United Kingdom', 'AU' => 'Australia',
            'PH' => 'Philippines', 'PL' => 'Poland', 'DE' => 'Germany', 'FR' => 'France', 'ES' => 'Spain',
            'IT' => 'Italy', 'NL' => 'Netherlands', 'SE' => 'Sweden', 'NO' => 'Norway', 'DK' => 'Denmark',
            'FI' => 'Finland', 'IE' => 'Ireland', 'CH' => 'Switzerland', 'AT' => 'Austria', 'BE' => 'Belgium',
            'BR' => 'Brazil', 'MX' => 'Mexico', 'AR' => 'Argentina', 'CL' => 'Chile', 'CO' => 'Colombia',
            'IN' => 'India', 'ID' => 'Indonesia', 'MY' => 'Malaysia', 'SG' => 'Singapore', 'TH' => 'Thailand',
            'VN' => 'Vietnam', 'JP' => 'Japan', 'KR' => 'South Korea', 'CN' => 'China', 'HK' => 'Hong Kong',
            'TW' => 'Taiwan', 'AE' => 'United Arab Emirates', 'SA' => 'Saudi Arabia', 'TR' => 'Turkey',
            'ZA' => 'South Africa', 'NG' => 'Nigeria', 'KE' => 'Kenya', 'EG' => 'Egypt',
            'RU' => 'Russia', 'UA' => 'Ukraine', 'CZ' => 'Czechia', 'SK' => 'Slovakia', 'HU' => 'Hungary',
            'RO' => 'Romania', 'BG' => 'Bulgaria', 'GR' => 'Greece', 'PT' => 'Portugal',
        ];
    }
    $iso = strtoupper(trim((string)$iso));
    if ($iso === '' || $iso === 'XX' || $iso === 'ZZ') return 'Unknown';
    return $map[$iso] ?? $iso; // If not mapped, keep ISO code (still useful)
}

function detectCountryName($ip) {
    // Common CDN / platform headers
    $headers = [
        'HTTP_CF_IPCOUNTRY',          // Cloudflare
        'HTTP_X_VERCEL_IP_COUNTRY',   // Vercel
        'HTTP_X_COUNTRY_CODE',        // some proxies
        'HTTP_X_APPENGINE_COUNTRY',   // Google App Engine
        'HTTP_FASTLY_CLIENT_GEO_COUNTRY_CODE',
        'HTTP_X_GEO_COUNTRY',
        'HTTP_GEOIP_COUNTRY_CODE',
    ];
    foreach ($headers as $h) {
        if (!empty($_SERVER[$h])) {
            $iso = $_SERVER[$h];
            return isoToCountryName($iso);
        }
    }
    // PHP geoip extension (if installed)
    if ($ip && function_exists('geoip_country_code_by_name')) {
        $iso = @geoip_country_code_by_name($ip);
        if ($iso) return isoToCountryName($iso);
    }
    return 'Unknown';
}

$ip = getClientIp();
$ipHash = hash('sha256', $ip . date('Y-m-d'));
$countryName = detectCountryName($ip);

// If the client didn't send a session id, derive a stable one (best-effort)
if ($sid === '' || !is_string($sid)) {
    $bucket = gmdate('Y-m-d H:i'); // minute bucket
    $sid = hash('sha256', $ipHash . '|' . $userAgent . '|' . $pageUrl . '|' . $bucket);
}

// If visitor/session ids are missing, derive stable-ish ones (best-effort)
if ($visitorId === '' || !is_string($visitorId)) {
    $visitorId = hash('sha256', $ipHash . '|' . $userAgent);
}
if ($visitSessionId === '' || !is_string($visitSessionId)) {
    $bucket = gmdate('Y-m-d H'); // hour bucket
    $visitSessionId = hash('sha256', $visitorId . '|' . $bucket);
}

// UA parsing
function detectBrowser($ua) {
    if (strpos($ua, 'Edg/') !== false) return 'Edge';
    if (strpos($ua, 'Brave') !== false) return 'Brave';
    if (strpos($ua, 'OPR/') !== false || strpos($ua, 'Opera') !== false) return 'Opera';
    if (strpos($ua, 'Firefox') !== false) return 'Firefox';
    if (strpos($ua, 'Chrome') !== false) return 'Chrome';
    if (strpos($ua, 'Safari') !== false) return 'Safari';
    return 'Other';
}
function detectOS($ua) {
    // Case-insensitive matching + correct ordering (iOS UAs contain "like Mac OS X")
    $ua_l = strtolower($ua);

    // iOS / iPadOS
    if (strpos($ua_l, 'iphone') !== false || strpos($ua_l, 'ipad') !== false || strpos($ua_l, 'ipod') !== false) return 'iOS';
    // iPadOS 13+ may report as Macintosh but still includes "Mobile"
    if (strpos($ua_l, 'macintosh') !== false && strpos($ua_l, 'mobile') !== false) return 'iOS';

    if (strpos($ua_l, 'android') !== false) return 'Android';
    if (strpos($ua_l, 'windows') !== false) return 'Windows';
    if (strpos($ua_l, 'mac os') !== false || strpos($ua_l, 'macintosh') !== false) return 'macOS';
    if (strpos($ua_l, 'linux') !== false) return 'Linux';

    return 'Other';
}
function detectDevice($ua) {
    $ua_l = strtolower($ua);

    // Tablets first (order matters)
    if (strpos($ua_l, 'ipad') !== false || strpos($ua_l, 'tablet') !== false || strpos($ua_l, 'kindle') !== false || strpos($ua_l, 'silk') !== false) {
        return 'Tablet';
    }
    // iPadOS 13+ may report as Macintosh but still includes "Mobile"
    if (strpos($ua_l, 'macintosh') !== false && strpos($ua_l, 'mobile') !== false) {
        return 'Tablet';
    }
    // Android tablets often omit "mobile"
    if (strpos($ua_l, 'android') !== false && strpos($ua_l, 'mobile') === false) {
        return 'Tablet';
    }

    // Phones
    if (strpos($ua_l, 'iphone') !== false || strpos($ua_l, 'ipod') !== false) return 'Phone';
    if (strpos($ua_l, 'windows phone') !== false) return 'Phone';
    if (strpos($ua_l, 'android') !== false && strpos($ua_l, 'mobile') !== false) return 'Phone';

    // Default
    return 'Desktop';
}

$browser = detectBrowser($userAgent);
$os      = detectOS($userAgent);
$device  = detectDevice($userAgent);

$now = gmdate('Y-m-d H:i:s'); // UTC, matches SQLite datetime('now')
// Update session (for both pageviews and pings)
if ($sid !== '') {
    $stmtS = $db->prepare('INSERT OR IGNORE INTO sessions (session_id, site_url, page_url, ip_hash, started_at, last_seen, duration_seconds)
                           VALUES (:sid, :site_url, :page_url, :ip_hash, :started_at, :last_seen, 0)');
    $stmtS->bindValue(':sid', $sid, SQLITE3_TEXT);
    $stmtS->bindValue(':site_url', $siteUrl, SQLITE3_TEXT);
    $stmtS->bindValue(':page_url', $pageUrl, SQLITE3_TEXT);
    $stmtS->bindValue(':ip_hash', $ipHash, SQLITE3_TEXT);
    $stmtS->bindValue(':started_at', $now, SQLITE3_TEXT);
    $stmtS->bindValue(':last_seen', $now, SQLITE3_TEXT);
    $okS = $stmtS->execute();

    $stmtU = $db->prepare("UPDATE sessions
                           SET last_seen = :last_seen,
                               page_url = :page_url,
                               duration_seconds = CAST(strftime('%s', :last_seen) AS INTEGER) - CAST(strftime('%s', started_at) AS INTEGER)
                           WHERE session_id = :sid");
    $stmtU->bindValue(':last_seen', $now, SQLITE3_TEXT);
    $stmtU->bindValue(':page_url', $pageUrl, SQLITE3_TEXT);
    $stmtU->bindValue(':sid', $sid, SQLITE3_TEXT);
    $okU = $stmtU->execute();

    if ($okS === false || $okU === false) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Session write failed', 'detail' => $db->lastErrorMsg()]);
        $db->close();
        exit;
    }
}

// Update visit-session (for bounce rate)
if ($visitSessionId !== '') {
    $stmtVS = $db->prepare('INSERT OR IGNORE INTO visit_sessions (visit_session_id, visitor_id, site_url, ip_hash, country, started_at, last_seen, pageviews)
                            VALUES (:vsid, :vid, :site_url, :ip_hash, :country, :started_at, :last_seen, 0)');
    $stmtVS->bindValue(':vsid', $visitSessionId, SQLITE3_TEXT);
    $stmtVS->bindValue(':vid', $visitorId, SQLITE3_TEXT);
    $stmtVS->bindValue(':site_url', $siteUrl, SQLITE3_TEXT);
    $stmtVS->bindValue(':ip_hash', $ipHash, SQLITE3_TEXT);
    $stmtVS->bindValue(':country', $countryName, SQLITE3_TEXT);
    $stmtVS->bindValue(':started_at', $now, SQLITE3_TEXT);
    $stmtVS->bindValue(':last_seen', $now, SQLITE3_TEXT);
    $okVS = $stmtVS->execute();

    $inc = ($type === 'pageview') ? 1 : 0;
    $stmtVSU = $db->prepare('UPDATE visit_sessions
                             SET last_seen = :last_seen,
                                 pageviews = pageviews + :inc
                             WHERE visit_session_id = :vsid');
    $stmtVSU->bindValue(':last_seen', $now, SQLITE3_TEXT);
    $stmtVSU->bindValue(':inc', $inc, SQLITE3_INTEGER);
    $stmtVSU->bindValue(':vsid', $visitSessionId, SQLITE3_TEXT);
    $okVSU = $stmtVSU->execute();

    if ($okVS === false || $okVSU === false) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Visit session write failed', 'detail' => $db->lastErrorMsg()]);
        $db->close();
        exit;
    }
}

// Decide whether to store a pageview in visits table.
// Normally: only when type === 'pageview'.
// Safety: if a 'ping' arrives before a 'pageview' for a new session_id, store one pageview once.
$shouldInsertVisit = ($type === 'pageview');

if (!$shouldInsertVisit && $sid !== '') {
    $exists = $db->querySingle("SELECT COUNT(*) FROM sessions WHERE session_id = '" . SQLite3::escapeString($sid) . "'");
    if ((int)$exists === 0) {
        // Should not happen (we insert session above), but keep this robust.
        $shouldInsertVisit = true;
    } else {
        // If session exists but started just now and no prior pageview was recorded, we still may want a single pageview.
        // We detect this by checking if there is any visit with same ip_hash + page_url within last 2 seconds.
        $recent = $db->querySingle("SELECT COUNT(*) FROM visits WHERE ip_hash = '" . SQLite3::escapeString($ipHash) . "' AND page_url = '" . SQLite3::escapeString($pageUrl) . "' AND created_at >= datetime('now','-2 seconds')");
        if ((int)$recent === 0) {
            // We don't force this; keep false to avoid inflating stats.
        }
    }
}

if ($shouldInsertVisit) {
    $stmt = $db->prepare('INSERT INTO visits (site_url, page_url, referrer, user_agent, browser, os, device, ip_hash, country)
                          VALUES (:site_url, :page_url, :referrer, :user_agent, :browser, :os, :device, :ip_hash, :country)');
    $stmt->bindValue(':site_url',   $siteUrl,   SQLITE3_TEXT);
    $stmt->bindValue(':page_url',   $pageUrl,   SQLITE3_TEXT);
    $stmt->bindValue(':referrer',   $referrer,  SQLITE3_TEXT);
    $stmt->bindValue(':user_agent', $userAgent, SQLITE3_TEXT);
    $stmt->bindValue(':browser',    $browser,   SQLITE3_TEXT);
    $stmt->bindValue(':os',         $os,        SQLITE3_TEXT);
    $stmt->bindValue(':device',     $device,    SQLITE3_TEXT);
    $stmt->bindValue(':ip_hash',    $ipHash,    SQLITE3_TEXT);
    $stmt->bindValue(':country',    $countryName,  SQLITE3_TEXT);
    $stmt->execute();
}

$db->close();
$resp = ['status' => 'ok'];
if (isset($_GET['debug'])) {
    $resp['received_type'] = $type;
    $resp['received_sid'] = $sid;
}
echo json_encode($resp);
exit;
