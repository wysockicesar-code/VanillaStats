<?php
@session_start();
if (!isset($_SESSION['logged_in'])) { header('Location: index.php'); exit; }

$config  = require __DIR__ . '/config.php';
$success = false;
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cur  = $_POST['current_password'] ?? '';
    $new  = $_POST['new_password']     ?? '';
    $conf = $_POST['confirm_password'] ?? '';
    if (!password_verify($cur, $config['password_hash']))
        $error = 'Current password is incorrect.';
    elseif (strlen($new) < 6)
        $error = 'New password must be at least 6 characters.';
    elseif ($new !== $conf)
        $error = 'New passwords do not match.';
    else {
        if (file_put_contents($config['password_file'], password_hash($new, PASSWORD_DEFAULT)))
            $success = true;
        else
            $error = 'Failed to write password file. Check file permissions.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Change Password — VanillaStats</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@300;400;500;600&display=swap');
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
  --bg:#0a0a0a;--su:#111;--su2:#161616;
  --b1:#1e1e1e;--b2:#2a2a2a;--b3:#383838;
  --tx:#e8e8e8;--mu:#555;--mu2:#888;
  --ac:#e8ff47;--ac-d:rgba(232,255,71,.06);
  --err:#ff4757;--err-d:rgba(255,71,87,.08);
  --ok:#2ed573;
  --mono:'IBM Plex Mono',monospace;--sans:'IBM Plex Sans',sans-serif;
}
body{background:var(--bg);color:var(--tx);font-family:var(--sans);min-height:100vh;line-height:1.5;}
body::before{content:'';position:fixed;inset:0;background-image:linear-gradient(rgba(255,255,255,.012) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.012) 1px,transparent 1px);background-size:40px 40px;pointer-events:none;z-index:0;}

.hdr{position:sticky;top:0;z-index:50;background:rgba(10,10,10,.93);backdrop-filter:blur(12px);border-bottom:1px solid var(--b1);padding:0 32px;height:56px;display:flex;align-items:center;justify-content:space-between;}
.logo{font-family:var(--mono);font-size:16px;font-weight:600;color:var(--tx);text-decoration:none;}
.logo em{color:var(--ac);font-style:normal;}
.hbtn{background:none;border:1px solid var(--b2);color:var(--mu2);font-family:var(--mono);font-size:10px;letter-spacing:.1em;text-transform:uppercase;padding:6px 14px;cursor:pointer;transition:all .14s;text-decoration:none;display:inline-flex;align-items:center;}
.hbtn:hover{color:var(--tx);border-color:var(--b3);}

.main{position:relative;z-index:1;max-width:520px;margin:0 auto;padding:40px 32px 64px;}
.sec-t{font-family:var(--mono);font-size:11px;letter-spacing:.15em;text-transform:uppercase;color:var(--mu2);margin-bottom:24px;}

.card{background:var(--su);border:1px solid var(--b1);padding:28px;}
.field{margin-bottom:18px;}
.field label{display:block;font-family:var(--mono);font-size:9px;letter-spacing:.12em;text-transform:uppercase;color:var(--mu);margin-bottom:8px;}
.field input{width:100%;background:var(--bg);border:1px solid var(--b2);color:var(--tx);font-family:var(--mono);font-size:13px;padding:11px 14px;outline:none;transition:border-color .14s;}
.field input:focus{border-color:var(--ac);background:var(--ac-d);}
.field-hint{font-family:var(--mono);font-size:10px;color:var(--mu);margin-top:6px;}
.actions{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:6px;flex-wrap:wrap;}
.sbtn{background:var(--ac);border:none;color:#000;font-family:var(--mono);font-size:11px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;padding:11px 22px;cursor:pointer;transition:opacity .14s;}
.sbtn:hover{opacity:.86;}
.cancel{font-family:var(--mono);font-size:10px;color:var(--mu2);text-decoration:none;transition:color .14s;letter-spacing:.06em;}
.cancel:hover{color:var(--tx);}

.alert{font-family:var(--mono);font-size:11px;padding:10px 14px;margin-bottom:20px;}
.alert-err{color:var(--err);background:var(--err-d);border:1px solid rgba(255,71,87,.25);}
.alert-ok{color:var(--ok);background:rgba(46,213,115,.08);border:1px solid rgba(46,213,115,.25);}

.back-btn{display:inline-flex;align-items:center;background:var(--ac);border:none;color:#000;font-family:var(--mono);font-size:11px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;padding:11px 22px;cursor:pointer;text-decoration:none;transition:opacity .14s;margin-top:4px;}
.back-btn:hover{opacity:.86;}

@media(max-width:640px){
  .hdr{padding:0 16px;}
  .main{padding:28px 16px 48px;}
}
</style>
</head>
<body>

<header class="hdr">
  <a class="logo" href="index.php">Vanilla<em>Stats</em></a>
  <a class="hbtn" href="index.php">← Dashboard</a>
</header>

<main class="main">
  <div class="sec-t">Change Password</div>

  <?php if ($success): ?>
    <div class="alert alert-ok">Password updated successfully. Use your new password on next login.</div>
    <a class="back-btn" href="index.php">← Back to Dashboard</a>

  <?php else: ?>
    <?php if ($error): ?>
      <div class="alert alert-err"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="card">
      <form method="POST">
        <div class="field">
          <label>Current Password</label>
          <input type="password" name="current_password" required autofocus autocomplete="current-password">
        </div>
        <div class="field">
          <label>New Password</label>
          <input type="password" name="new_password" required minlength="6" autocomplete="new-password">
          <div class="field-hint">Minimum 6 characters</div>
        </div>
        <div class="field">
          <label>Confirm New Password</label>
          <input type="password" name="confirm_password" required minlength="6" autocomplete="new-password">
        </div>
        <div class="actions">
          <button type="submit" class="sbtn">Update Password</button>
          <a class="cancel" href="index.php">Cancel</a>
        </div>
      </form>
    </div>
  <?php endif; ?>
</main>

</body>
</html>
