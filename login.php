<?php
require_once 'includes/auth.php';
if (isLoggedIn()) { header('Location: dashboard.php'); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!validateCsrf($_POST['csrf_token']??'')) { $error='Invalid request.'; }
    else {
        $r = loginUser($_POST['email']??'', $_POST['password']??'');
        if ($r['success']) { header('Location: dashboard.php'); exit; }
        else $error = $r['message'];
    }
}
$timeout = isset($_GET['timeout']) ? 'Your session has expired. Please log in again.' : '';
$csrf = getCsrfToken();
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login – ONOW Enable OKR</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link href="css/style.css" rel="stylesheet">
<style>
body{background:var(--bg);min-height:100vh;display:flex;align-items:center}
.login-wrap{max-width:440px;width:100%;margin:0 auto;padding:2rem 1rem}
.login-card{background:var(--surface);border-radius:20px;border:1px solid var(--border);box-shadow:0 20px 60px rgba(0,0,0,.08);padding:2.5rem}
</style>
</head><body>
<div class="login-wrap">
  <div class="login-card">
    <div class="d-flex align-items-center gap-3 mb-4">
      <div class="brand-icon" style="width:48px;height:48px;border-radius:14px">
        <svg width="26" height="26" fill="none" stroke="white" stroke-width="2.2" viewBox="0 0 24 24">
          <path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/>
        </svg>
      </div>
      <div>
        <div style="font-size:1.1rem;font-weight:800;color:var(--text)">ONOW Enable</div>
        <div style="font-size:.7rem;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px">OKR Management</div>
      </div>
    </div>
    <h1 style="font-size:1.4rem;font-weight:800;margin-bottom:.3rem">Welcome back</h1>
    <p class="text-muted small mb-4">Sign in to your account to continue.</p>
    <?php if($timeout): ?><div class="alert alert-warning py-2 small"><i class="bi bi-clock me-2"></i><?= $timeout ?></div><?php endif; ?>
    <?php if($error): ?><div class="alert alert-danger py-2 small"><i class="bi bi-exclamation-circle me-2"></i><?= sanitize($error) ?></div><?php endif; ?>
    <div style="background:var(--primary-lt);border:1px solid rgba(37,99,235,.2);border-radius:10px;padding:.75rem 1rem;margin-bottom:1.25rem;font-size:.82rem;color:var(--primary)">
      <i class="bi bi-info-circle me-1"></i><strong>Demo:</strong> admin@onow-enable.org / Admin@1234
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <div class="mb-3">
        <label class="form-label">Email address</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-envelope text-muted"></i></span>
          <input type="email" name="email" class="form-control" required autofocus value="<?= sanitize($_POST['email']??'') ?>">
        </div>
      </div>
      <div class="mb-4">
        <label class="form-label">Password</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-lock text-muted"></i></span>
          <input type="password" name="password" id="pwd" class="form-control" required placeholder="••••••••">
          <button type="button" class="btn btn-outline-secondary" onclick="togglePwd('pwd','eye0')"><i class="bi bi-eye" id="eye0"></i></button>
        </div>
      </div>
      <button type="submit" class="btn btn-primary w-100 py-2 fw-700"><i class="bi bi-arrow-right-circle me-2"></i>Sign In</button>
    </form>
    <p class="text-center text-muted small mt-3">Contact your administrator for access.</p>
  </div>
</div>
<script>
function togglePwd(id,iconId){const i=document.getElementById(id),ic=document.getElementById(iconId);i.type=i.type==='password'?'text':'password';ic.className='bi bi-eye'+(i.type==='text'?'-slash':'');}
</script>
</body></html>
