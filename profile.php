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
        $phone = sanitize($_POST['phone']       ?? '');
        $dept  = sanitize($_POST['department']  ?? '');
        $title = sanitize($_POST['job_title']   ?? '');
        if (!$name) { echo json_encode(['success'=>false,'message'=>'Name is required.']); exit; }
        $db->prepare("UPDATE users SET full_name=?,phone=?,department=?,job_title=?,updated_at=NOW() WHERE id=?")->execute([$name,$phone,$dept,$title,$userId]);
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
        echo json_encode(['success'=>true,'message'=>'Password changed successfully.']); exit;
    }
    echo json_encode(['success'=>false,'message'=>'Invalid action']); exit;
}

$user    = getCurrentUser(); // Refresh after potential update
$stats   = getDashboardStats($userId, $user['role']);
$myTeams = $user['role'] !== 'Admin' ? getUserTeams($userId) : [];
$ini     = initials($user['full_name']);
$csrf    = getCsrfToken();
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
        <!-- Edit Profile (FR 1.5) -->
        <div class="card mb-3">
          <div class="card-header"><i class="bi bi-person-gear text-primary"></i><span class="card-title">Edit Profile</span></div>
          <div class="card-body">
            <form id="profileForm">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="update_profile">
              <input type="hidden" name="ajax" value="1">
              <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Full Name <span class="text-danger">*</span></label><input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($user['full_name']) ?>" required></div>
                <div class="col-md-6"><label class="form-label">Email Address</label><input type="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" disabled style="background:var(--surface2)"><div class="form-text">Contact admin to change email.</div></div>
                <div class="col-md-6"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($user['phone']??'') ?>"></div>
                <div class="col-md-6"><label class="form-label">Department</label><input type="text" name="department" class="form-control" value="<?= htmlspecialchars($user['department']??'') ?>"></div>
                <div class="col-12"><label class="form-label">Job Title</label><input type="text" name="job_title" class="form-control" value="<?= htmlspecialchars($user['job_title']??'') ?>"></div>
                <div class="col-12"><button type="button" class="btn btn-primary" onclick="submitProfile()"><i class="bi bi-check-circle me-1"></i>Save Profile</button></div>
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
              <input type="hidden" name="action" value="change_password">
              <input type="hidden" name="ajax" value="1">
              <div class="row g-3">
                <div class="col-12">
                  <label class="form-label">Current Password <span class="text-danger">*</span></label>
                  <div class="input-group">
                    <input type="password" name="current_password" id="pwd0" class="form-control" required placeholder="Enter current password">
                    <button type="button" class="btn btn-outline-secondary" onclick="togglePwd('pwd0','eye0')"><i class="bi bi-eye" id="eye0"></i></button>
                  </div>
                </div>
                <div class="col-md-6">
                  <label class="form-label">New Password <span class="text-danger">*</span></label>
                  <div class="input-group">
                    <input type="password" name="new_password" id="pwd1" class="form-control" minlength="8" required placeholder="Min 8 characters">
                    <button type="button" class="btn btn-outline-secondary" onclick="togglePwd('pwd1','eye1')"><i class="bi bi-eye" id="eye1"></i></button>
                  </div>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Confirm New Password <span class="text-danger">*</span></label>
                  <div class="input-group">
                    <input type="password" name="confirm_password" id="pwd2" class="form-control" minlength="8" required placeholder="Repeat new password">
                    <button type="button" class="btn btn-outline-secondary" onclick="togglePwd('pwd2','eye2')"><i class="bi bi-eye" id="eye2"></i></button>
                  </div>
                </div>
                <div class="col-12"><button type="button" class="btn btn-warning" onclick="submitPassword()"><i class="bi bi-key me-1"></i>Change Password</button></div>
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
function togglePwd(id,iconId){const i=document.getElementById(id),ic=document.getElementById(iconId);i.type=i.type==='password'?'text':'password';ic.className='bi bi-eye'+(i.type==='text'?'-slash':'');}

function toast(msg,type='success'){
  const icons={success:'check-circle',error:'x-circle',warning:'exclamation-triangle'};
  const t=document.createElement('div');t.className=`toast-msg ${type}`;
  t.innerHTML=`<i class="bi bi-${icons[type]||'info-circle'}"></i> ${msg}`;
  document.getElementById('toastContainer').appendChild(t);setTimeout(()=>t.remove(),4000);
}

function submitProfile(){
  const form=document.getElementById('profileForm');
  if(!form.checkValidity()){form.reportValidity();return;}
  fetch('profile.php',{method:'POST',body:new FormData(form)}).then(r=>r.json()).then(res=>toast(res.message,res.success?'success':'error')).catch(()=>toast('Network error','error'));
}

function submitPassword(){
  const form=document.getElementById('passwordForm');
  if(!form.checkValidity()){form.reportValidity();return;}
  const n=document.getElementById('pwd1').value,c=document.getElementById('pwd2').value;
  if(n!==c){toast('Passwords do not match.','error');return;}
  fetch('profile.php',{method:'POST',body:new FormData(form)}).then(r=>r.json()).then(res=>{
    toast(res.message,res.success?'success':'error');
    if(res.success)form.reset();
  }).catch(()=>toast('Network error','error'));
}
</script>
</body></html>
