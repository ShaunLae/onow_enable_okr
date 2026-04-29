<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';
requireLogin();
$user = getCurrentUser(); $currentPage = 'profile';
$db   = getDB(); $userId = $user['id'];

// AJAX handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    if (!validateCsrf($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid CSRF token']); exit; }
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name  = sanitize($_POST['full_name']  ?? '');
        $phone = sanitize($_POST['phone']      ?? '');
        $dept  = sanitize($_POST['department'] ?? '');
        $title = sanitize($_POST['job_title']  ?? '');
        if (!$name) { echo json_encode(['success'=>false,'message'=>'Name is required.']); exit; }
        // Admin can also update their email
        if ($user['role'] === 'Admin') {
            $email = trim($_POST['email'] ?? '');
            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success'=>false,'message'=>'A valid email address is required.']); exit;
            }
            try {
                $db->prepare("UPDATE users SET full_name=?,email=?,phone=?,department=?,job_title=?,updated_at=NOW() WHERE id=?")
                   ->execute([$name,$email,$phone,$dept,$title,$userId]);
            } catch(PDOException $e) {
                echo json_encode(['success'=>false,'message'=>'Email already in use by another account.']); exit;
            }
        } else {
            $db->prepare("UPDATE users SET full_name=?,phone=?,department=?,job_title=?,updated_at=NOW() WHERE id=?")
               ->execute([$name,$phone,$dept,$title,$userId]);
        }
        logActivity($userId,'UPDATE_PROFILE','user',$userId,'Updated profile');
        echo json_encode(['success'=>true,'message'=>'Profile updated successfully.']); exit;
    }

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $row = $db->prepare("SELECT password_hash FROM users WHERE id=?"); $row->execute([$userId]); $row = $row->fetch();
        if (!password_verify($current, $row['password_hash'])) { echo json_encode(['success'=>false,'message'=>'Current password is incorrect.']); exit; }
        if (strlen($new) < 8)  { echo json_encode(['success'=>false,'message'=>'New password must be at least 8 characters.']); exit; }
        if ($new !== $confirm) { echo json_encode(['success'=>false,'message'=>'Passwords do not match.']); exit; }
        $db->prepare("UPDATE users SET password_hash=?,updated_at=NOW() WHERE id=?")->execute([password_hash($new, PASSWORD_BCRYPT, ['cost'=>12]), $userId]);
        logActivity($userId,'CHANGE_PASSWORD','user',$userId,'Changed own password');
        // Return redirect flag — JS will destroy session via logout.php
        echo json_encode(['success'=>true,'message'=>'Password changed successfully. Redirecting to login…','redirect'=>true]); exit;
    }

    echo json_encode(['success'=>false,'message'=>'Invalid action']); exit;
}

$user    = getCurrentUser(); // Refresh after potential update
$stats   = getDashboardStats($userId, $user['role']);
$myTeams = $user['role'] !== 'Admin' ? getUserTeams($userId) : [];
$ini     = initials($user['full_name']);
$csrf    = getCsrfToken();
$isAdmin = $user['role'] === 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>My Profile – ONOW Enable OKR</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="css/style.css" rel="stylesheet">
<style>
/* Password strength bar */
.pwd-strength-wrap { margin-top: .45rem; }
.pwd-strength-bar  { height: 5px; border-radius: 3px; transition: width .25s, background .25s; }
.pwd-strength-label{ font-size: .72rem; font-weight: 600; margin-top: .2rem; }
</style>
</head>
<body>
<div class="app-wrapper">
<?php include 'includes/nav.php'; ?>
<main class="main-content">
  <header class="top-header">
    <button class="btn btn-sm btn-outline-secondary d-md-none" onclick="document.getElementById('sidebar').classList.toggle('open')"><i class="bi bi-list"></i></button>
    <div><div class="page-title">My Profile</div><div class="page-breadcrumb">Manage your account information</div></div>
  </header>
  <div class="page-body">
    <div class="row g-3">

      <!-- Left: avatar card -->
      <div class="col-lg-4">
        <div class="card text-center mb-3">
          <div class="card-body py-4">
            <div class="user-avatar mx-auto mb-3" style="background:<?= htmlspecialchars($user['avatar_color']) ?>;width:72px;height:72px;font-size:1.5rem;border-radius:20px"><?= $ini ?></div>
            <h5 class="fw-800 mb-0"><?= htmlspecialchars($user['full_name']) ?></h5>
            <div class="text-muted small mb-2"><?= htmlspecialchars($user['email']) ?></div>
            <span class="role-badge role-<?= strtolower($user['role']) ?>"><?= $user['role'] ?></span>
            <?php if ($user['job_title']): ?><div class="mt-2 text-muted small"><?= htmlspecialchars($user['job_title']) ?></div><?php endif; ?>
            <?php if ($user['department']): ?><div class="text-muted small"><?= htmlspecialchars($user['department']) ?></div><?php endif; ?>
            <?php if ($user['last_login']): ?><div class="text-muted small mt-2"><i class="bi bi-clock me-1"></i>Last login: <?= date('d M Y, H:i',strtotime($user['last_login'])) ?></div><?php endif; ?>
          </div>
        </div>

        <?php if (!empty($myTeams)): ?>
        <div class="card mb-3">
          <div class="card-header"><i class="bi bi-diagram-3 text-primary"></i><span class="card-title">My Teams</span></div>
          <div class="card-body">
            <?php foreach ($myTeams as $t): ?>
            <div class="d-flex align-items-center gap-2 mb-2">
              <div style="width:28px;height:28px;background:#ede9fe;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="bi bi-diagram-3" style="color:#7c3aed;font-size:.78rem"></i></div>
              <span style="font-size:.85rem;font-weight:600"><?= htmlspecialchars($t['name']) ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($user['role'] !== 'Admin'): ?>
        <div class="card">
          <div class="card-header"><i class="bi bi-bar-chart text-primary"></i><span class="card-title">OKR Summary</span></div>
          <div class="card-body">
            <div class="row g-2 text-center">
              <?php foreach ([[$stats['total_objectives'],'Objectives','var(--primary)','var(--primary-lt)'],[$stats['on_track'],'On Track','var(--success)','var(--success-lt)'],[$stats['at_risk'],'At Risk','var(--warning)','var(--warning-lt)'],[$stats['avg_progress'].'%','Avg Progress','var(--secondary)','#ede9fe']] as [$v,$l,$c,$bg]): ?>
              <div class="col-6"><div class="p-2 rounded" style="background:<?= $bg ?>">
                <div style="font-size:1.4rem;font-weight:800;color:<?= $c ?>"><?= $v ?></div>
                <div style="font-size:.72rem;color:var(--muted);font-weight:600"><?= $l ?></div>
              </div></div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Right: edit forms -->
      <div class="col-lg-8">

        <!-- Edit Profile -->
        <div class="card mb-3">
          <div class="card-header"><i class="bi bi-person-gear text-primary"></i><span class="card-title">Edit Profile</span></div>
          <div class="card-body">
            <form id="profileForm">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <input type="hidden" name="action"     value="update_profile">
              <input type="hidden" name="ajax"       value="1">
              <div class="row g-3">

                <!-- Full Name -->
                <div class="col-md-6">
                  <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label mb-0">Full Name <span class="text-danger">*</span></label>
                    <small class="text-muted" id="pfNameCount">0 / 100</small>
                  </div>
                  <input type="text" name="full_name" id="pfName" class="form-control" required
                         maxlength="100" value="<?= htmlspecialchars($user['full_name']) ?>"
                         oninput="profileCharCount('pfName','pfNameCount',100)">
                </div>

                <!-- Email — editable for Admin, disabled for others -->
                <div class="col-md-6">
                  <?php if ($isAdmin): ?>
                  <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label mb-0">Email Address <span class="text-danger">*</span></label>
                    <small class="text-muted" id="pfEmailCount">0 / 150</small>
                  </div>
                  <input type="email" name="email" id="pfEmail" class="form-control" required
                         maxlength="150" value="<?= htmlspecialchars($user['email']) ?>"
                         oninput="profileCharCount('pfEmail','pfEmailCount',150)">
                  <div class="form-text"><i class="bi bi-info-circle me-1"></i>As Admin you can update your own email.</div>
                  <?php else: ?>
                  <label class="form-label">Email Address</label>
                  <input type="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>"
                         disabled style="background:var(--surface2)">
                  <div class="form-text"><i class="bi bi-lock me-1"></i>Contact admin to change email.</div>
                  <?php endif; ?>
                </div>

                <!-- Phone -->
                <div class="col-md-6">
                  <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label mb-0">Phone</label>
                    <small class="text-muted" id="pfPhoneCount">0 / 30</small>
                  </div>
                  <input type="text" name="phone" id="pfPhone" class="form-control"
                         maxlength="30" value="<?= htmlspecialchars($user['phone']??'') ?>"
                         oninput="profileCharCount('pfPhone','pfPhoneCount',30)">
                </div>

                <!-- Department -->
                <div class="col-md-6">
                  <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label mb-0">Department</label>
                    <small class="text-muted" id="pfDeptCount">0 / 100</small>
                  </div>
                  <input type="text" name="department" id="pfDept" class="form-control"
                         maxlength="100" value="<?= htmlspecialchars($user['department']??'') ?>"
                         oninput="profileCharCount('pfDept','pfDeptCount',100)">
                </div>

                <!-- Job Title -->
                <div class="col-12">
                  <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label mb-0">Job Title</label>
                    <small class="text-muted" id="pfTitleCount">0 / 100</small>
                  </div>
                  <input type="text" name="job_title" id="pfTitle" class="form-control"
                         maxlength="100" value="<?= htmlspecialchars($user['job_title']??'') ?>"
                         oninput="profileCharCount('pfTitle','pfTitleCount',100)">
                </div>

                <div class="col-12">
                  <button type="button" class="btn btn-primary" onclick="submitProfile()">
                    <i class="bi bi-check-circle me-1"></i>Save Profile
                  </button>
                </div>
              </div>
            </form>
          </div>
        </div>

        <!-- Change Password -->
        <div class="card">
          <div class="card-header"><i class="bi bi-shield-lock text-primary"></i><span class="card-title">Change Password</span></div>
          <div class="card-body">
            <form id="passwordForm">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <input type="hidden" name="action"     value="change_password">
              <input type="hidden" name="ajax"       value="1">
              <div class="row g-3">

                <!-- Current Password -->
                <div class="col-12">
                  <label class="form-label">Current Password <span class="text-danger">*</span></label>
                  <div class="input-group">
                    <input type="password" name="current_password" id="pwd0" class="form-control"
                           required placeholder="Enter current password">
                    <button type="button" class="btn btn-outline-secondary" onclick="togglePwd('pwd0','eye0')"><i class="bi bi-eye" id="eye0"></i></button>
                  </div>
                </div>

                <!-- New Password + strength meter -->
                <div class="col-md-6">
                  <label class="form-label">New Password <span class="text-danger">*</span></label>
                  <div class="input-group">
                    <input type="password" name="new_password" id="pwd1" class="form-control"
                           minlength="8" required placeholder="Min 8 characters"
                           oninput="checkStrength()">
                    <button type="button" class="btn btn-outline-secondary" onclick="togglePwd('pwd1','eye1')"><i class="bi bi-eye" id="eye1"></i></button>
                  </div>
                  <!-- Strength indicator -->
                  <div class="pwd-strength-wrap" id="strengthWrap" style="display:none">
                    <div style="background:var(--border);border-radius:3px;height:5px">
                      <div class="pwd-strength-bar" id="strengthBar" style="width:0%"></div>
                    </div>
                    <div class="pwd-strength-label" id="strengthLabel"></div>
                  </div>
                  <div class="form-text mt-1" id="pwdHints" style="font-size:.72rem;line-height:1.6">
                    <span id="hint-len"  class="me-2">✗ 8+ characters</span>
                    <span id="hint-upper" class="me-2">✗ Uppercase</span>
                    <span id="hint-num"   class="me-2">✗ Number</span>
                    <span id="hint-sym">✗ Symbol</span>
                  </div>
                </div>

                <!-- Confirm Password + match indicator -->
                <div class="col-md-6">
                  <label class="form-label">Confirm New Password <span class="text-danger">*</span></label>
                  <div class="input-group">
                    <input type="password" name="confirm_password" id="pwd2" class="form-control"
                           minlength="8" required placeholder="Repeat new password"
                           oninput="checkMatch()">
                    <button type="button" class="btn btn-outline-secondary" onclick="togglePwd('pwd2','eye2')"><i class="bi bi-eye" id="eye2"></i></button>
                  </div>
                  <div class="form-text mt-1" id="matchFeedback" style="font-size:.72rem;font-weight:600"></div>
                </div>

                <div class="col-12">
                  <button type="button" class="btn btn-warning" onclick="submitPassword()">
                    <i class="bi bi-key me-1"></i>Change Password
                  </button>
                  <div class="form-text mt-2">
                    <i class="bi bi-info-circle me-1"></i>You will be signed out immediately and asked to log in with your new password.
                  </div>
                </div>

              </div>
            </form>
          </div>
        </div>

      </div>
    </div>
  </div>
</main>
</div>
<div id="toastContainer"></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>

/* ── Password visibility toggle ── */
function togglePwd(id, iconId) {
  const i = document.getElementById(id), ic = document.getElementById(iconId);
  i.type = i.type === 'password' ? 'text' : 'password';
  ic.className = 'bi bi-eye' + (i.type === 'text' ? '-slash' : '');
}

/* ── Toast ── */
function toast(msg, type = 'success') {
  const icons = { success: 'check-circle', error: 'x-circle', warning: 'exclamation-triangle' };
  const t = document.createElement('div');
  t.className = `toast-msg ${type}`;
  t.innerHTML = `<i class="bi bi-${icons[type] || 'info-circle'}"></i> ${msg}`;
  document.getElementById('toastContainer').appendChild(t);
  setTimeout(() => t.remove(), 4000);
}

/* ── Character counter (same pattern as KR / users modals) ── */
function profileCharCount(inputId, counterId, max) {
  const el  = document.getElementById(inputId);
  const ctr = document.getElementById(counterId);
  if (!el || !ctr) return;
  const len = el.value.length;
  ctr.textContent = `${len} / ${max}`;
  ctr.style.color = len >= max ? 'var(--danger)' : len >= max * 0.9 ? 'var(--warning)' : '';
  el.setCustomValidity(len > max ? `Cannot exceed ${max} characters.` : '');
}

/* ── Initialise counters on page load with pre-filled values ── */
document.addEventListener('DOMContentLoaded', () => {
  profileCharCount('pfName',  'pfNameCount',  100);
  profileCharCount('pfPhone', 'pfPhoneCount',  30);
  profileCharCount('pfDept',  'pfDeptCount',  100);
  profileCharCount('pfTitle', 'pfTitleCount', 100);
  <?php if ($isAdmin): ?>
  profileCharCount('pfEmail', 'pfEmailCount', 150);
  <?php endif; ?>
});

/* ── Password strength meter ── */
function checkStrength() {
  const pwd   = document.getElementById('pwd1').value;
  const wrap  = document.getElementById('strengthWrap');
  const bar   = document.getElementById('strengthBar');
  const label = document.getElementById('strengthLabel');

  // Hint checks
  const hasLen   = pwd.length >= 8;
  const hasUpper = /[A-Z]/.test(pwd);
  const hasNum   = /[0-9]/.test(pwd);
  const hasSym   = /[^A-Za-z0-9]/.test(pwd);

  // Update hint indicators
  const setHint = (id, pass) => {
    const el = document.getElementById(id);
    el.textContent = (pass ? '✓ ' : '✗ ') + el.textContent.slice(2);
    el.style.color = pass ? 'var(--success)' : 'var(--muted)';
  };
  setHint('hint-len',   hasLen);
  setHint('hint-upper', hasUpper);
  setHint('hint-num',   hasNum);
  setHint('hint-sym',   hasSym);

  if (!pwd) { wrap.style.display = 'none'; return; }
  wrap.style.display = '';

  const score = [hasLen, hasUpper, hasNum, hasSym].filter(Boolean).length;
  const levels = [
    { w: '25%', bg: '#ef4444', text: 'Weak',      color: '#ef4444' },
    { w: '50%', bg: '#f97316', text: 'Fair',      color: '#f97316' },
    { w: '75%', bg: '#eab308', text: 'Good',      color: '#eab308' },
    { w: '100%',bg: '#22c55e', text: 'Strong',    color: '#22c55e' },
  ];
  const lvl = levels[score - 1] || levels[0];
  bar.style.width      = lvl.w;
  bar.style.background = lvl.bg;
  label.textContent    = lvl.text;
  label.style.color    = lvl.color;

  // Also re-run match check if confirm field has a value
  if (document.getElementById('pwd2').value) checkMatch();
}

/* ── Password match indicator ── */
function checkMatch() {
  const p1 = document.getElementById('pwd1').value;
  const p2 = document.getElementById('pwd2').value;
  const fb = document.getElementById('matchFeedback');
  if (!p2) { fb.textContent = ''; return; }
  if (p1 === p2) {
    fb.textContent = '✓ Passwords match';
    fb.style.color = 'var(--success)';
  } else {
    fb.textContent = '✗ Passwords do not match';
    fb.style.color = 'var(--danger)';
  }
}

/* ── Submit profile ── */
function submitProfile() {
  const form = document.getElementById('profileForm');
  if (!form.checkValidity()) { form.reportValidity(); return; }
  fetch('profile.php', { method: 'POST', body: new FormData(form) })
    .then(r => r.json())
    .then(res => toast(res.message, res.success ? 'success' : 'error'))
    .catch(() => toast('Network error', 'error'));
}

/* ── Submit password — redirects to logout on success ── */
function submitPassword() {
  const form = document.getElementById('passwordForm');
  if (!form.checkValidity()) { form.reportValidity(); return; }

  const p1 = document.getElementById('pwd1').value;
  const p2 = document.getElementById('pwd2').value;
  if (p1 !== p2) { toast('Passwords do not match.', 'error'); return; }
  if (p1.length < 8) { toast('New password must be at least 8 characters.', 'error'); return; }

  fetch('profile.php', { method: 'POST', body: new FormData(form) })
    .then(r => r.json())
    .then(res => {
      toast(res.message, res.success ? 'success' : 'error');
      if (res.success && res.redirect) {
        // Disable the button to prevent double-submit
        document.querySelector('#passwordForm button[onclick]').disabled = true;
        // Wait for toast to be readable, then force logout
        setTimeout(() => { window.location.href = 'logout.php'; }, 2500);
      }
    })
    .catch(() => toast('Network error', 'error'));
}
</script>
</body></html>
