<?php
$config   = require __DIR__ . '/config.php';
$siteToken = $config['site_token'] ?? '';
$scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$baseUrl  = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($basePath === '/' ? '' : $basePath);

function formatDuration($s) {
    $s = max(0, (int)round((float)$s));
    $m = intdiv($s, 60); $sec = $s % 60;
    if ($m <= 0) return $sec . 's';
    $h = intdiv($m, 60); $m2 = $m % 60;
    return $h > 0 ? $h . 'h ' . $m2 . 'm' : $m . 'm ' . $sec . 's';
}

@session_start();

if (isset($_POST['password'])) {
    if (password_verify($_POST['password'], $config['password_hash'])) {
        $_SESSION['logged_in'] = true;
        $_SESSION['last_activity'] = time();
    } else { $loginError = 'Invalid password.'; }
}
if (isset($_SESSION['logged_in'], $_SESSION['last_activity'])) {
    if (time() - $_SESSION['last_activity'] > $config['session_timeout']) {
        session_destroy(); header('Location: index.php'); exit;
    }
    $_SESSION['last_activity'] = time();
}
if (isset($_GET['logout'])) { session_destroy(); header('Location: index.php'); exit; }

// ── JSON API mode (fetch requests from JS) ───────────────────────────────────
if (isset($_GET['json']) && isset($_SESSION['logged_in'])) {
    header('Content-Type: application/json');

    $db = new SQLite3($config['database']);
    $db->busyTimeout(2000);
    $db->exec("PRAGMA journal_mode=WAL;");

    $days = isset($_GET['days']) ? (int)$_GET['days'] : 7;
    $dsql = "datetime('now', '-{$days} days')";

    function getP($key) {
        if (!isset($_GET[$key]) || !is_array($_GET[$key])) return [];
        return array_values(array_filter(array_map('strval', $_GET[$key])));
    }
    $fp = getP('fp'); // page filter
    $fr = getP('fr'); // referrer filter
    $fc = getP('fc'); // country filter

    function excl($db, $col, $vals) {
        if (empty($vals)) return '';
        $parts = [];
        foreach ($vals as $v) $parts[] = "$col != '" . SQLite3::escapeString($v) . "'";
        return ' AND ' . implode(' AND ', $parts);
    }
    $xp = excl($db, "site_url || page_url", $fp);
    $xr = excl($db, 'referrer',  $fr);
    $xc = excl($db, 'country',   $fc);
    $xa = $xp . $xr . $xc;

    function qRows($db, $sql) {
        $rows = []; $r = $db->query($sql);
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
        return $rows;
    }

    $isoMap = ['US'=>'United States','CA'=>'Canada','GB'=>'United Kingdom','AU'=>'Australia','PH'=>'Philippines','PL'=>'Poland','DE'=>'Germany','FR'=>'France','ES'=>'Spain','IT'=>'Italy','NL'=>'Netherlands','SE'=>'Sweden','NO'=>'Norway','DK'=>'Denmark','FI'=>'Finland','IE'=>'Ireland','CH'=>'Switzerland','AT'=>'Austria','BE'=>'Belgium','BR'=>'Brazil','MX'=>'Mexico','AR'=>'Argentina','CL'=>'Chile','CO'=>'Colombia','IN'=>'India','ID'=>'Indonesia','MY'=>'Malaysia','SG'=>'Singapore','TH'=>'Thailand','VN'=>'Vietnam','JP'=>'Japan','KR'=>'South Korea','CN'=>'China','HK'=>'Hong Kong','TW'=>'Taiwan','AE'=>'United Arab Emirates','SA'=>'Saudi Arabia','TR'=>'Turkey','ZA'=>'South Africa','NG'=>'Nigeria','KE'=>'Kenya','EG'=>'Egypt','RU'=>'Russia','UA'=>'Ukraine','CZ'=>'Czechia','SK'=>'Slovakia','HU'=>'Hungary','RO'=>'Romania','BG'=>'Bulgaria','GR'=>'Greece','PT'=>'Portugal','NZ'=>'New Zealand','IL'=>'Israel','PK'=>'Pakistan','BD'=>'Bangladesh','HR'=>'Croatia','SI'=>'Slovenia','RS'=>'Serbia','LT'=>'Lithuania','LV'=>'Latvia','EE'=>'Estonia','IS'=>'Iceland','LU'=>'Luxembourg','MT'=>'Malta','CY'=>'Cyprus','PE'=>'Peru','VE'=>'Venezuela','EC'=>'Ecuador','BO'=>'Bolivia','PY'=>'Paraguay','UY'=>'Uruguay','CR'=>'Costa Rica','PA'=>'Panama','GT'=>'Guatemala','HN'=>'Honduras','SV'=>'El Salvador','NI'=>'Nicaragua','CU'=>'Cuba','DO'=>'Dominican Republic','JM'=>'Jamaica'];

    function resolveCountry($val, $isoMap) {
        $v = trim((string)$val);
        if ($v === '' || strtoupper($v) === 'XX' || strtoupper($v) === 'ZZ') return 'Unknown';
        if (preg_match('/^[A-Z]{2}$/', $v) && isset($isoMap[$v])) return $isoMap[$v];
        return $v;
    }

    // Stats — all filters
    $pageViews      = (int)$db->querySingle("SELECT COUNT(*) FROM visits WHERE created_at >= $dsql$xa");
    $uniqueVisitors = (int)$db->querySingle("SELECT COUNT(DISTINCT ip_hash) FROM visits WHERE created_at >= $dsql$xa");
    $avgDur         = (float)($db->querySingle("SELECT AVG(s.duration_seconds) FROM sessions s WHERE s.started_at >= $dsql AND EXISTS (SELECT 1 FROM visits v WHERE v.ip_hash = s.ip_hash AND v.created_at >= $dsql$xa)") ?? 0);
    $totalSess      = (int)$db->querySingle("SELECT COUNT(*) FROM visit_sessions WHERE started_at >= $dsql");
    $bouncedSess    = (int)$db->querySingle("SELECT COUNT(*) FROM visit_sessions WHERE started_at >= $dsql AND pageviews = 1");
    $bounceRate     = $totalSess > 0 ? round(($bouncedSess / $totalSess) * 100, 1) : 0;
    $activeUsers    = (int)$db->querySingle("SELECT COUNT(DISTINCT ip_hash) FROM sessions WHERE last_seen >= datetime('now','-5 minutes')");

    // Table rows
    $pagesRows   = qRows($db, "SELECT site_url || page_url as label, COUNT(*) as count FROM visits WHERE created_at >= $dsql{$xr}{$xc} GROUP BY site_url || page_url ORDER BY count DESC LIMIT 10");
    $refRows     = qRows($db, "SELECT referrer as label, COUNT(*) as count FROM visits WHERE created_at >= $dsql{$xp}{$xc} GROUP BY referrer ORDER BY count DESC LIMIT 10");
    $rawCountry  = qRows($db, "SELECT country as label, COUNT(*) as count FROM visits WHERE created_at >= $dsql{$xp}{$xr} GROUP BY country ORDER BY count DESC LIMIT 10");
    $countryRows = array_map(function($r) use ($isoMap) { $r['label'] = resolveCountry($r['label'], $isoMap); return $r; }, $rawCountry);

    $browserRows = qRows($db, "SELECT browser as label, COUNT(*) as count FROM visits WHERE created_at >= $dsql$xa GROUP BY browser ORDER BY count DESC");
    $osRows      = qRows($db, "SELECT os as label, COUNT(*) as count FROM visits WHERE created_at >= $dsql$xa GROUP BY os ORDER BY count DESC");
    $deviceRows  = qRows($db, "SELECT device as label, COUNT(*) as count FROM visits WHERE created_at >= $dsql$xa GROUP BY device ORDER BY count DESC");
    $dayRows     = qRows($db, "SELECT DATE(created_at) as date, COUNT(*) as count FROM visits WHERE created_at >= $dsql$xa GROUP BY DATE(created_at) ORDER BY date ASC");

    $db->close();

    echo json_encode([
        'stats' => [
            'activeNow'     => $activeUsers,
            'uniqueVisitors'=> $uniqueVisitors,
            'pageViews'     => $pageViews,
            'avgDuration'   => formatDuration($avgDur),
            'bounceRate'    => $bounceRate,
            'bouncedSess'   => $bouncedSess,
            'totalSess'     => $totalSess,
        ],
        'pages'    => $pagesRows,
        'refs'     => $refRows,
        'countries'=> $countryRows,
        'browsers' => $browserRows,
        'os'       => $osRows,
        'devices'  => $deviceRows,
        'days'     => $dayRows,
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VanillaStats<?= isset($_SESSION['logged_in']) ? ' — Dashboard' : '' ?></title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
@import url('https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@300;400;500;600&display=swap');
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
  --bg:#0a0a0a;--su:#111;--su2:#161616;--su3:#1d1d1d;
  --b1:#1e1e1e;--b2:#2a2a2a;--b3:#383838;
  --tx:#e8e8e8;--mu:#555;--mu2:#888;
  --ac:#e8ff47;--ac-d:rgba(232,255,71,.06);--ac-m:rgba(232,255,71,.16);
  --err:#ff4757;--err-d:rgba(255,71,87,.08);
  --ok:#2ed573;--warn:#ffa502;
  --mono:'IBM Plex Mono',monospace;--sans:'IBM Plex Sans',sans-serif;
}
html{scroll-behavior:smooth;}
body{background:var(--bg);color:var(--tx);font-family:var(--sans);min-height:100vh;line-height:1.5;}
body::before{content:'';position:fixed;inset:0;background-image:linear-gradient(rgba(255,255,255,.012) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.012) 1px,transparent 1px);background-size:40px 40px;pointer-events:none;z-index:0;}

/* ── LOGIN ── */
.login-wrap{position:relative;z-index:1;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;}
.login-inner{width:100%;max-width:380px;}
.brand{margin-bottom:40px;}
.brand-tag{font-family:var(--mono);font-size:10px;letter-spacing:.2em;text-transform:uppercase;color:var(--mu);margin-bottom:8px;}
.brand-name{font-family:var(--mono);font-size:22px;font-weight:600;letter-spacing:-.02em;}
.brand-name span{color:var(--ac);}
.login-card{background:var(--su);border:1px solid var(--b1);padding:32px;}
.login-card-title{font-family:var(--mono);font-size:11px;letter-spacing:.15em;text-transform:uppercase;color:var(--mu);margin-bottom:28px;padding-bottom:16px;border-bottom:1px solid var(--b1);}
.field{margin-bottom:16px;}
.field label{display:block;font-family:var(--mono);font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:var(--mu);margin-bottom:8px;}
.field input{width:100%;background:var(--bg);border:1px solid var(--b2);color:var(--tx);font-family:var(--mono);font-size:13px;padding:11px 14px;outline:none;transition:border-color .15s;}
.field input:focus{border-color:var(--ac);background:var(--ac-d);}
.login-err{font-family:var(--mono);font-size:11px;color:var(--err);background:var(--err-d);border:1px solid rgba(255,71,87,.25);padding:10px 14px;margin-bottom:18px;}
.login-btn{width:100%;background:var(--ac);border:none;color:#000;font-family:var(--mono);font-size:11px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;padding:13px;cursor:pointer;transition:opacity .14s;margin-top:6px;}
.login-btn:hover{opacity:.86;}
.login-hint{font-family:var(--mono);font-size:10px;color:var(--mu);text-align:center;margin-top:20px;}
.login-hint span{color:var(--mu2);}

/* ── HEADER ── */
.hdr{position:sticky;top:0;z-index:50;background:rgba(10,10,10,.93);backdrop-filter:blur(12px);border-bottom:1px solid var(--b1);padding:0 32px;height:56px;display:flex;align-items:center;justify-content:space-between;}
.hdr-l{display:flex;align-items:center;gap:14px;}
.logo{font-family:var(--mono);font-size:16px;font-weight:600;color:var(--tx);text-decoration:none;}
.logo em{color:var(--ac);font-style:normal;}
.hdr-r{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.hbtn{background:none;border:1px solid var(--b2);color:var(--mu2);font-family:var(--mono);font-size:10px;letter-spacing:.1em;text-transform:uppercase;padding:6px 14px;cursor:pointer;transition:all .14s;text-decoration:none;display:inline-flex;align-items:center;white-space:nowrap;}
.hbtn:hover{color:var(--tx);border-color:var(--b3);}
.hbtn.danger:hover{color:var(--err);border-color:var(--err);}
.range-sel{background:var(--bg);border:1px solid var(--b2);color:var(--mu2);font-family:var(--mono);font-size:10px;padding:6px 12px;outline:none;cursor:pointer;transition:border-color .14s;-webkit-appearance:none;}
.range-sel:focus{border-color:var(--ac);}

/* Export dropdown */
.dd-wrap{position:relative;}
.dd-menu{position:absolute;right:0;top:calc(100% + 6px);background:var(--su2);border:1px solid var(--b2);min-width:140px;display:none;z-index:100;}
.dd-menu.open{display:block;}
.dd-item{display:block;font-family:var(--mono);font-size:10px;letter-spacing:.08em;text-transform:uppercase;padding:10px 14px;color:var(--mu2);text-decoration:none;transition:all .12s;border-bottom:1px solid var(--b1);}
.dd-item:last-child{border-bottom:none;}
.dd-item:hover{background:var(--su3);color:var(--tx);}

/* ── MAIN ── */
.main{position:relative;z-index:1;max-width:1200px;margin:0 auto;padding:32px 32px 64px;}

/* Stat cards */
.stats-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:1px;background:var(--b1);margin-bottom:32px;}
.stat{background:var(--su);padding:20px 22px;transition:background .14s;}
.stat:hover{background:var(--su2);}
.stat-lbl{font-family:var(--mono);font-size:9px;letter-spacing:.14em;text-transform:uppercase;color:var(--mu);margin-bottom:10px;display:flex;align-items:center;justify-content:space-between;}
.stat-val{font-family:var(--mono);font-size:2rem;font-weight:600;color:var(--tx);line-height:1;margin-bottom:4px;}
.stat-sub{font-family:var(--mono);font-size:9px;color:var(--mu);}
.pulse{display:inline-block;width:6px;height:6px;border-radius:50%;background:var(--ok);position:relative;}
.pulse::before{content:'';position:absolute;inset:-2px;border-radius:50%;background:var(--ok);opacity:.4;animation:ping 1.5s infinite;}
@keyframes ping{0%,100%{transform:scale(1);opacity:.4;}50%{transform:scale(1.9);opacity:0;}}

/* Chart panel */
.panel{background:var(--su);border:1px solid var(--b1);padding:24px;margin-bottom:32px;}
.panel-t{font-family:var(--mono);font-size:10px;letter-spacing:.14em;text-transform:uppercase;color:var(--mu2);margin-bottom:20px;}

/* Table panels */
.tbl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:1px;background:var(--b1);margin-bottom:32px;}
.tbl-panel{background:var(--su);padding:24px;}
.tbl-t{font-family:var(--mono);font-size:10px;letter-spacing:.14em;text-transform:uppercase;color:var(--mu2);margin-bottom:16px;}
.tbl-row{display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid var(--b1);cursor:pointer;transition:opacity .15s,background .12s;}
.tbl-row:last-child{border-bottom:none;}
.tbl-row:hover{background:var(--su2);}
.tbl-row.filtered-out{opacity:.22;}
.tbl-row.filtered-out .tbl-name{text-decoration:line-through;color:var(--mu);}
.tbl-name{font-size:12px;color:var(--tx);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;min-width:0;}
.tbl-bar-wrap{width:56px;height:2px;background:var(--b2);flex-shrink:0;}
.tbl-bar{height:100%;background:var(--ac);opacity:.5;transition:width .3s;}
.tbl-count{font-family:var(--mono);font-size:11px;color:var(--mu2);white-space:nowrap;}
.tbl-empty{font-family:var(--mono);font-size:11px;color:var(--mu);padding:8px 0;}

/* Filter chips */
.filter-chips{display:flex;flex-wrap:wrap;gap:5px;margin-bottom:10px;min-height:0;}
.filter-chip{display:inline-flex;align-items:center;gap:4px;font-family:var(--mono);font-size:9px;letter-spacing:.06em;background:var(--ac-d);border:1px solid rgba(232,255,71,.22);color:var(--ac);padding:3px 8px 3px 9px;cursor:pointer;transition:background .12s;}
.filter-chip:hover{background:var(--ac-m);}
.filter-chip .chip-x{font-size:11px;line-height:1;opacity:.7;margin-left:1px;}

/* Chart panels */
.chart-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1px;background:var(--b1);margin-bottom:32px;}
.chart-panel{background:var(--su);padding:24px;}

/* Snippet */
.snippet{background:var(--su);border:1px solid var(--b1);padding:24px;}
.snippet-t{font-family:var(--mono);font-size:10px;letter-spacing:.14em;text-transform:uppercase;color:var(--mu2);margin-bottom:10px;}
.snippet-desc{font-size:13px;color:var(--mu2);line-height:1.7;margin-bottom:14px;}
.snippet-code{background:var(--bg);border:1px solid var(--b2);padding:14px 16px;font-family:var(--mono);font-size:11px;color:var(--ac);overflow-x:auto;white-space:nowrap;cursor:pointer;transition:border-color .14s;display:block;}
.snippet-code:hover{border-color:var(--b3);}
.snippet-note{font-family:var(--mono);font-size:10px;color:var(--mu);margin-top:10px;line-height:1.7;}
.snippet-note code{color:var(--ac);}

/* Toast */
#toast{position:fixed;bottom:20px;right:20px;z-index:9999;font-family:var(--mono);font-size:11px;padding:10px 16px;border:1px solid;opacity:0;transition:opacity .25s;pointer-events:none;max-width:340px;}
#toast.show{opacity:1;}
#toast.inf{background:var(--ac-d);border-color:rgba(232,255,71,.3);color:var(--ac);}
#toast.ok{background:rgba(46,213,115,.1);border-color:rgba(46,213,115,.4);color:var(--ok);}

/* Responsive */
@media(max-width:900px){
  .hdr{padding:0 20px;height:auto;min-height:56px;padding-top:10px;padding-bottom:10px;flex-wrap:wrap;gap:8px;}
  .main{padding:24px 20px 48px;}
}
@media(max-width:640px){
  .stats-grid{grid-template-columns:1fr 1fr;}
  .tbl-grid,.chart-grid{grid-template-columns:1fr;}
  .hdr-r{gap:6px;}
  .hbtn{font-size:9px;padding:5px 10px;}
}
@media(max-width:380px){
  .stats-grid{grid-template-columns:1fr;}
}
</style>
</head>
<body>

<?php if (!isset($_SESSION['logged_in'])): ?>

<!-- ═══════════════════════ LOGIN ═══════════════════════ -->
<div class="login-wrap">
  <div class="login-inner">
    <div class="brand">
      <div class="brand-tag">vanillastats.app</div>
      <div class="brand-name">Vanilla<span>Stats</span></div>
    </div>
    <div class="login-card">
      <div class="login-card-title">Sign in to continue</div>
      <?php if (isset($loginError)): ?>
        <div class="login-err"><?= htmlspecialchars($loginError) ?></div>
      <?php endif; ?>
      <form method="POST">
        <div class="field">
          <label>Password</label>
          <input type="password" name="password" autofocus autocomplete="current-password">
        </div>
        <button type="submit" class="login-btn">Sign In →</button>
      </form>
    </div>
    <div class="login-hint">Default password: <span>admin123</span></div>
  </div>
</div>

<?php else:

$db = new SQLite3($config['database']);
$db->exec('CREATE TABLE IF NOT EXISTS visits (id INTEGER PRIMARY KEY AUTOINCREMENT,site_url TEXT,page_url TEXT,referrer TEXT,user_agent TEXT,browser TEXT,os TEXT,device TEXT,ip_hash TEXT,country TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
$db->exec('CREATE TABLE IF NOT EXISTS sessions (session_id TEXT PRIMARY KEY,site_url TEXT,page_url TEXT,ip_hash TEXT,started_at DATETIME,last_seen DATETIME,duration_seconds INTEGER DEFAULT 0)');
$db->exec('CREATE TABLE IF NOT EXISTS visit_sessions (visit_session_id TEXT PRIMARY KEY,visitor_id TEXT,site_url TEXT,ip_hash TEXT,country TEXT,started_at DATETIME,last_seen DATETIME,pageviews INTEGER DEFAULT 0)');

$days = isset($_GET['days']) ? (int)$_GET['days'] : 7;
$dsql = "datetime('now', '-{$days} days')";

function qRows($db, $sql) {
    $rows = []; $r = $db->query($sql);
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
    return $rows;
}

// Initial load — no filters active
$pageViews      = $db->querySingle("SELECT COUNT(*) FROM visits WHERE created_at >= $dsql");
$uniqueVisitors = $db->querySingle("SELECT COUNT(DISTINCT ip_hash) FROM visits WHERE created_at >= $dsql");
$avgDur         = $db->querySingle("SELECT AVG(duration_seconds) FROM sessions WHERE started_at >= $dsql") ?? 0;
$totalSess      = $db->querySingle("SELECT COUNT(*) FROM visit_sessions WHERE started_at >= $dsql");
$bouncedSess    = $db->querySingle("SELECT COUNT(*) FROM visit_sessions WHERE started_at >= $dsql AND pageviews = 1");
$bounceRate     = $totalSess > 0 ? round(($bouncedSess / $totalSess) * 100, 1) : 0;
$activeUsers    = $db->querySingle("SELECT COUNT(DISTINCT ip_hash) FROM sessions WHERE last_seen >= datetime('now','-5 minutes')");

$pagesRows   = qRows($db, "SELECT site_url || page_url as label, COUNT(*) as count FROM visits WHERE created_at >= $dsql GROUP BY site_url || page_url ORDER BY count DESC LIMIT 10");
$refRows     = qRows($db, "SELECT referrer as label, COUNT(*) as count FROM visits WHERE created_at >= $dsql GROUP BY referrer ORDER BY count DESC LIMIT 10");
$countryRows = qRows($db, "SELECT country as label, COUNT(*) as count FROM visits WHERE created_at >= $dsql GROUP BY country ORDER BY count DESC LIMIT 10");
$browserRows = qRows($db, "SELECT browser as label, COUNT(*) as count FROM visits WHERE created_at >= $dsql GROUP BY browser ORDER BY count DESC");
$osRows      = qRows($db, "SELECT os as label, COUNT(*) as count FROM visits WHERE created_at >= $dsql GROUP BY os ORDER BY count DESC");
$deviceRows  = qRows($db, "SELECT device as label, COUNT(*) as count FROM visits WHERE created_at >= $dsql GROUP BY device ORDER BY count DESC");
$dayRows     = qRows($db, "SELECT DATE(created_at) as date, COUNT(*) as count FROM visits WHERE created_at >= $dsql GROUP BY DATE(created_at) ORDER BY date ASC");

$isoMap = ['US'=>'United States','CA'=>'Canada','GB'=>'United Kingdom','AU'=>'Australia','PH'=>'Philippines','PL'=>'Poland','DE'=>'Germany','FR'=>'France','ES'=>'Spain','IT'=>'Italy','NL'=>'Netherlands','SE'=>'Sweden','NO'=>'Norway','DK'=>'Denmark','FI'=>'Finland','IE'=>'Ireland','CH'=>'Switzerland','AT'=>'Austria','BE'=>'Belgium','BR'=>'Brazil','MX'=>'Mexico','AR'=>'Argentina','CL'=>'Chile','CO'=>'Colombia','IN'=>'India','ID'=>'Indonesia','MY'=>'Malaysia','SG'=>'Singapore','TH'=>'Thailand','VN'=>'Vietnam','JP'=>'Japan','KR'=>'South Korea','CN'=>'China','HK'=>'Hong Kong','TW'=>'Taiwan','AE'=>'United Arab Emirates','SA'=>'Saudi Arabia','TR'=>'Turkey','ZA'=>'South Africa','NG'=>'Nigeria','KE'=>'Kenya','EG'=>'Egypt','RU'=>'Russia','UA'=>'Ukraine','CZ'=>'Czechia','SK'=>'Slovakia','HU'=>'Hungary','RO'=>'Romania','BG'=>'Bulgaria','GR'=>'Greece','PT'=>'Portugal','NZ'=>'New Zealand','IL'=>'Israel','PK'=>'Pakistan','BD'=>'Bangladesh','HR'=>'Croatia','SI'=>'Slovenia','RS'=>'Serbia','LT'=>'Lithuania','LV'=>'Latvia','EE'=>'Estonia','IS'=>'Iceland','LU'=>'Luxembourg','MT'=>'Malta','CY'=>'Cyprus','PE'=>'Peru','VE'=>'Venezuela','EC'=>'Ecuador','BO'=>'Bolivia','PY'=>'Paraguay','UY'=>'Uruguay','CR'=>'Costa Rica','PA'=>'Panama','GT'=>'Guatemala','HN'=>'Honduras','SV'=>'El Salvador','NI'=>'Nicaragua','CU'=>'Cuba','DO'=>'Dominican Republic','JM'=>'Jamaica'];

foreach ($countryRows as &$row) {
    $v = trim((string)($row['label'] ?? ''));
    if ($v === '' || strtoupper($v) === 'XX' || strtoupper($v) === 'ZZ') $row['label'] = 'Unknown';
    elseif (preg_match('/^[A-Z]{2}$/', $v) && isset($isoMap[$v])) $row['label'] = $isoMap[$v];
}
unset($row);

$db->close();
?>

<!-- ═══════════════════════ DASHBOARD ═══════════════════════ -->
<header class="hdr">
  <div class="hdr-l">
    <a class="logo" href="index.php">Vanilla<em>Stats</em></a>
  </div>
  <div class="hdr-r">
    <select class="range-sel" id="rangeSelect">
      <option value="1"  <?= $days==1  ?'selected':'' ?>>24 hours</option>
      <option value="7"  <?= $days==7  ?'selected':'' ?>>7 days</option>
      <option value="30" <?= $days==30 ?'selected':'' ?>>30 days</option>
      <option value="90" <?= $days==90 ?'selected':'' ?>>90 days</option>
      <option value="180" <?= $days==180 ?'selected':'' ?>>180 days</option>
      <option value="365" <?= $days==365 ?'selected':'' ?>>12 months</option>
    </select>
    <div class="dd-wrap">
      <button class="hbtn" onclick="toggleDD(event)">Export</button>
      <div class="dd-menu" id="ddMenu">
        <a class="dd-item" id="exportCsv" href="export.php?format=csv&days=<?= $days ?>">CSV</a>
        <a class="dd-item" id="exportJson" href="export.php?format=json&days=<?= $days ?>">JSON</a>
      </div>
    </div>
    <a class="hbtn" href="change-password.php">Password</a>
    <a class="hbtn danger" href="?logout=1">Logout</a>
  </div>
</header>

<main class="main">

  <!-- Stats -->
  <div class="stats-grid">
    <div class="stat">
      <div class="stat-lbl">Active Now <span class="pulse"></span></div>
      <div class="stat-val" id="sActiveNow"><?= number_format($activeUsers) ?></div>
      <div class="stat-sub">Last 5 minutes</div>
    </div>
    <div class="stat">
      <div class="stat-lbl">Unique Visitors</div>
      <div class="stat-val" id="sUniqueVisitors"><?= number_format($uniqueVisitors) ?></div>
    </div>
    <div class="stat">
      <div class="stat-lbl">Page Views</div>
      <div class="stat-val" id="sPageViews"><?= number_format($pageViews) ?></div>
    </div>
    <div class="stat">
      <div class="stat-lbl">Avg Duration</div>
      <div class="stat-val" id="sAvgDuration"><?= htmlspecialchars(formatDuration($avgDur)) ?></div>
      <div class="stat-sub">Estimated</div>
    </div>
    <div class="stat">
      <div class="stat-lbl">Bounce Rate</div>
      <div class="stat-val" id="sBounceRate"><?= $bounceRate ?>%</div>
      <div class="stat-sub" id="sBounceDetail"><?= number_format((int)$bouncedSess) ?> / <?= number_format((int)$totalSess) ?> sessions</div>
    </div>
  </div>

  <!-- Chart -->
  <div class="panel">
    <div class="panel-t">Visits Over Time</div>
    <canvas id="visitsChart" height="70"></canvas>
  </div>

  <!-- Tables -->
  <div class="tbl-grid">
    <div class="tbl-panel">
      <div class="tbl-t">Top Pages</div>
      <div class="filter-chips" id="chips-pages"></div>
      <div id="list-pages">
        <?php foreach ($pagesRows as $r): $val = $r['label'] ?: 'Unknown'; $max = max(1, $pagesRows[0]['count']); ?>
          <div class="tbl-row" data-section="pages" data-val="<?= htmlspecialchars($val, ENT_QUOTES) ?>" onclick="toggleFilter('pages',this)">
            <div class="tbl-name"><?= htmlspecialchars($val) ?></div>
            <div class="tbl-bar-wrap"><div class="tbl-bar" style="width:<?= round($r['count']/$max*100) ?>%"></div></div>
            <div class="tbl-count"><?= number_format($r['count']) ?></div>
          </div>
        <?php endforeach; ?>
        <?php if (empty($pagesRows)): ?><div class="tbl-empty">No data yet</div><?php endif; ?>
      </div>
    </div>
    <div class="tbl-panel">
      <div class="tbl-t">Top Referrers</div>
      <div class="filter-chips" id="chips-refs"></div>
      <div id="list-refs">
        <?php foreach ($refRows as $r): $val = $r['label']; $max = max(1, $refRows[0]['count']); ?>
          <div class="tbl-row" data-section="refs" data-val="<?= htmlspecialchars($val, ENT_QUOTES) ?>" onclick="toggleFilter('refs',this)">
            <div class="tbl-name"><?= htmlspecialchars($val) ?></div>
            <div class="tbl-bar-wrap"><div class="tbl-bar" style="width:<?= round($r['count']/$max*100) ?>%"></div></div>
            <div class="tbl-count"><?= number_format($r['count']) ?></div>
          </div>
        <?php endforeach; ?>
        <?php if (empty($refRows)): ?><div class="tbl-empty">No referrer data yet</div><?php endif; ?>
      </div>
    </div>
    <div class="tbl-panel">
      <div class="tbl-t">Locations</div>
      <div class="filter-chips" id="chips-countries"></div>
      <div id="list-countries">
        <?php foreach ($countryRows as $r): $val = $r['label'] ?: 'Unknown'; $max = max(1, $countryRows[0]['count']); ?>
          <div class="tbl-row" data-section="countries" data-val="<?= htmlspecialchars($val, ENT_QUOTES) ?>" onclick="toggleFilter('countries',this)">
            <div class="tbl-name"><?= htmlspecialchars($val) ?></div>
            <div class="tbl-bar-wrap"><div class="tbl-bar" style="width:<?= round($r['count']/$max*100) ?>%"></div></div>
            <div class="tbl-count"><?= number_format($r['count']) ?></div>
          </div>
        <?php endforeach; ?>
        <?php if (empty($countryRows)): ?><div class="tbl-empty">No location data yet</div><?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Doughnut charts -->
  <div class="chart-grid">
    <div class="chart-panel"><div class="tbl-t">Browsers</div><canvas id="browsersChart"></canvas></div>
    <div class="chart-panel"><div class="tbl-t">Operating Systems</div><canvas id="osChart"></canvas></div>
    <div class="chart-panel"><div class="tbl-t">Devices</div><canvas id="devicesChart"></canvas></div>
  </div>

  <!-- Tracking snippet -->
  <div class="snippet">
    <div class="snippet-t">Tracking Snippet</div>
    <div class="snippet-desc">Add this before the closing <code style="font-family:var(--mono);color:var(--ac);font-size:12px;">&lt;/body&gt;</code> tag on every page you want to track:</div>
    <div class="snippet-code" onclick="copySnippet(this)" title="Click to copy">&lt;script src=&quot;<?= htmlspecialchars($baseUrl) ?>/tracker.js?v=1&quot; data-site=&quot;<?= htmlspecialchars($siteToken) ?>&quot; defer&gt;&lt;/script&gt;</div>
    <div class="snippet-note">Click to copy &nbsp;·&nbsp; Bump <code>v=1</code> to <code>v=2</code> after updating tracker.js to refresh browser caches.</div>
  </div>

</main>
<div id="toast"></div>

<script>
const PIE  = ['#e8ff47','#2ed573','#3b82f6','#ff4757','#a78bfa','#f59e0b','#ec4899'];
const GRID = 'rgba(255,255,255,.04)';
const TICK = '#555';
const FONT = {family:'IBM Plex Mono', size:10};

// ── State ────────────────────────────────────────────────────────────────────
let days = <?= $days ?>;
const filters = { pages: new Set(), refs: new Set(), countries: new Set() };
// section → URL param key
const paramKey = { pages: 'fp', refs: 'fr', countries: 'fc' };

// ── Charts (created once, updated in place) ───────────────────────────────────
const vData = <?php $d=[];$c=[];foreach($dayRows as $r){$d[]=$r['date'];$c[]=$r['count'];}echo json_encode(['dates'=>$d,'counts'=>$c]); ?>;
const bData = <?php $l=[];$v=[];foreach($browserRows as $r){$l[]=$r['label'];$v[]=$r['count'];}echo json_encode(['labels'=>$l,'data'=>$v]); ?>;
const oData = <?php $l=[];$v=[];foreach($osRows as $r){$l[]=$r['label'];$v[]=$r['count'];}echo json_encode(['labels'=>$l,'data'=>$v]); ?>;
const dData = <?php $l=[];$v=[];foreach($deviceRows as $r){$l[]=$r['label'];$v[]=$r['count'];}echo json_encode(['labels'=>$l,'data'=>$v]); ?>;

const lineChart = new Chart(document.getElementById('visitsChart'), {
  type: 'line',
  data: { labels: vData.dates, datasets: [{ label:'Visits', data: vData.counts, borderColor:'#e8ff47', backgroundColor:'rgba(232,255,71,.06)', borderWidth:1.5, tension:0.4, fill:true, pointRadius:3, pointBackgroundColor:'#e8ff47', pointBorderColor:'#0a0a0a', pointBorderWidth:1 }] },
  options: { responsive:true, maintainAspectRatio:true, plugins:{legend:{display:false}}, scales:{ y:{beginAtZero:true,ticks:{precision:0,color:TICK,font:FONT},grid:{color:GRID},border:{color:'transparent'}}, x:{ticks:{color:TICK,font:FONT},grid:{color:GRID},border:{color:'transparent'}} } }
});

function makeDonut(id, data) {
  return new Chart(document.getElementById(id), {
    type: 'doughnut',
    data: { labels: data.labels, datasets: [{ data: data.data, backgroundColor: PIE, borderWidth: 0 }] },
    options: { responsive:true, maintainAspectRatio:true, plugins:{ legend:{ position:'bottom', labels:{color:TICK,font:FONT,padding:12,boxWidth:10} } } }
  });
}
const bChart = makeDonut('browsersChart', bData);
const oChart = makeDonut('osChart', oData);
const dChart = makeDonut('devicesChart', dData);

function updateDonut(chart, data) {
  chart.data.labels = data.labels;
  chart.data.datasets[0].data = data.data;
  chart.update();
}

// ── Fetch + apply data ────────────────────────────────────────────────────────
function buildApiUrl() {
  const p = new URLSearchParams();
  p.set('json', '1');
  p.set('days', days);
  filters.pages.forEach(v => p.append('fp[]', v));
  filters.refs.forEach(v => p.append('fr[]', v));
  filters.countries.forEach(v => p.append('fc[]', v));
  return 'index.php?' + p.toString();
}

function fmt(n) { return Number(n).toLocaleString(); }

function applyData(d) {
  // Stats
  document.getElementById('sActiveNow').textContent      = fmt(d.stats.activeNow);
  document.getElementById('sUniqueVisitors').textContent = fmt(d.stats.uniqueVisitors);
  document.getElementById('sPageViews').textContent      = fmt(d.stats.pageViews);
  document.getElementById('sAvgDuration').textContent    = d.stats.avgDuration;
  document.getElementById('sBounceRate').textContent     = d.stats.bounceRate + '%';
  document.getElementById('sBounceDetail').textContent   = fmt(d.stats.bouncedSess) + ' / ' + fmt(d.stats.totalSess) + ' sessions';

  // Line chart
  lineChart.data.labels = d.days.map(r => r.date);
  lineChart.data.datasets[0].data = d.days.map(r => r.count);
  lineChart.update();

  // Doughnuts
  updateDonut(bChart, { labels: d.browsers.map(r=>r.label), data: d.browsers.map(r=>r.count) });
  updateDonut(oChart,  { labels: d.os.map(r=>r.label),       data: d.os.map(r=>r.count) });
  updateDonut(dChart,  { labels: d.devices.map(r=>r.label),  data: d.devices.map(r=>r.count) });

  // Tables
  renderList('pages',     d.pages,     filters.pages);
  renderList('refs',      d.refs,      filters.refs);
  renderList('countries', d.countries, filters.countries);
}

function renderList(section, rows, activeFilters) {
  const el = document.getElementById('list-' + section);
  if (!rows || rows.length === 0) {
    el.innerHTML = '<div class="tbl-empty">No data yet</div>';
    return;
  }
  const max = Math.max(1, rows[0].count);
  el.innerHTML = rows.map(r => {
    const val = r.label || 'Unknown';
    const isF = activeFilters.has(val);
    const w = Math.round(r.count / max * 100);
    const esc = val.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    return `<div class="tbl-row${isF?' filtered-out':''}" data-section="${section}" data-val="${esc}" onclick="toggleFilter('${section}',this)">
      <div class="tbl-name">${esc}</div>
      <div class="tbl-bar-wrap"><div class="tbl-bar" style="width:${w}%"></div></div>
      <div class="tbl-count">${Number(r.count).toLocaleString()}</div>
    </div>`;
  }).join('');
}

function renderChips(section) {
  const el = document.getElementById('chips-' + section);
  el.innerHTML = '';
  filters[section].forEach(val => {
    const chip = document.createElement('span');
    chip.className = 'filter-chip';
    chip.innerHTML = `<span>${val.replace(/&/g,'&amp;').replace(/</g,'&lt;')}</span><span class="chip-x">✕</span>`;
    chip.onclick = () => { filters[section].delete(val); renderChips(section); fetchData(); };
    el.appendChild(chip);
  });
}

function toggleFilter(section, rowEl) {
  const val = rowEl.dataset.val;
  if (filters[section].has(val)) {
    filters[section].delete(val);
  } else {
    filters[section].add(val);
  }
  renderChips(section);
  fetchData();
}

let fetchTimer = null;
function fetchData() {
  clearTimeout(fetchTimer);
  fetchTimer = setTimeout(() => {
    fetch(buildApiUrl())
      .then(r => r.json())
      .then(applyData)
      .catch(() => {});
  }, 80); // small debounce for rapid clicks
}

// ── Range selector ────────────────────────────────────────────────────────────
document.getElementById('rangeSelect').addEventListener('change', function() {
  days = parseInt(this.value);
  // clear filters on range change (different period = fresh start)
  filters.pages.clear(); filters.refs.clear(); filters.countries.clear();
  ['pages','refs','countries'].forEach(s => renderChips(s));
  // update export links
  document.getElementById('exportCsv').href  = 'export.php?format=csv&days='  + days;
  document.getElementById('exportJson').href = 'export.php?format=json&days=' + days;
  fetchData();
});

// ── Auto-refresh active count every 30s ──────────────────────────────────────
setInterval(fetchData, 30000);

// ── Export dropdown ───────────────────────────────────────────────────────────
function toggleDD(e) {
  e.stopPropagation();
  document.getElementById('ddMenu').classList.toggle('open');
}
document.addEventListener('click', () => document.getElementById('ddMenu').classList.remove('open'));

// ── Copy snippet ──────────────────────────────────────────────────────────────
function copySnippet(el) {
  const t = el.textContent.trim()
    .replace(/&lt;/g,'<').replace(/&gt;/g,'>').replace(/&quot;/g,'"').replace(/&amp;/g,'&');
  navigator.clipboard.writeText(t).then(() => showToast('Snippet copied', 'inf'));
}

// ── Toast ─────────────────────────────────────────────────────────────────────
function showToast(msg, type='inf') {
  const t = document.getElementById('toast');
  t.textContent = msg; t.className = type + ' show';
  setTimeout(() => t.className = type, 2400);
}
</script>

<?php $db->close(); endif; ?>
</body>
</html>
