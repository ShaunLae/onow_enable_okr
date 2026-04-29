<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';
requireLogin();
requireRole(['Admin']);
$user = getCurrentUser(); $currentPage = 'users';
$db = getDB(); $message = ''; $msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf($_POST['csrf_token'] ?? '')) { $message='Invalid CSRF token.'; $msgType='danger'; }
    else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create_user') {
            $name=$_POST['full_name']??''; $email=trim($_POST['email']??''); $pass=$_POST['password']??'';
            $role=$_POST['role']??'Member'; $dept=sanitize($_POST['department']??''); $title=sanitize($_POST['job_title']??''); $phone=sanitize($_POST['phone']??'');
            if (!$name||!$email||!$pass) { $message='Name, email and password required.'; $msgType='danger'; }
            elseif (!filter_var($email,FILTER_VALIDATE_EMAIL)) { $message='Please enter a valid email address.'; $msgType='danger'; }
            elseif (strlen($pass)<8) { $message='Password must be at least 8 characters.'; $msgType='danger'; }
            else {
                try {
                    $colors=['#2563EB','#059669','#7c3aed','#dc2626','#d97706','#0891b2'];
                    $db->prepare("INSERT INTO users(full_name,email,password_hash,role,department,job_title,phone,avatar_color) VALUES(?,?,?,?,?,?,?,?)")->execute([sanitize($name),$email,password_hash($pass,PASSWORD_BCRYPT,['cost'=>12]),$role,$dept,$title,$phone,$colors[array_rand($colors)]]);
                    logActivity($user['id'],'CREATE_USER','user',$db->lastInsertId(),"Created user: $name");
                    $message="User '$name' created successfully.";
                } catch(PDOException $e){ $message='Email already exists or error occurred.'; $msgType='danger'; }
            }
        }
        if ($action === 'update_user') {
            $uid=(int)$_POST['user_id'];
            $uName=trim($_POST['full_name']??''); $uEmail=trim($_POST['email']??'');
            if (!$uName||!$uEmail) { $message='Name and email are required.'; $msgType='danger'; }
            elseif (!filter_var($uEmail,FILTER_VALIDATE_EMAIL)) { $message='Please enter a valid email address.'; $msgType='danger'; }
            else {
                try {
                    $db->prepare("UPDATE users SET full_name=?,email=?,role=?,department=?,job_title=?,phone=?,updated_at=NOW() WHERE id=?")->execute([sanitize($uName),$uEmail,$_POST['role']??'Member',sanitize($_POST['department']??''),sanitize($_POST['job_title']??''),sanitize($_POST['phone']??''),$uid]);
                    logActivity($user['id'],'UPDATE_USER','user',$uid,"Updated user #$uid");
                    $message='User updated.';
                } catch(PDOException $e){ $message='Update failed. Email may be taken.'; $msgType='danger'; }
            }
        }
        if ($action === 'reset_password') {
            $uid=(int)$_POST['user_id']; $pass=$_POST['new_password']??'';
            if (strlen($pass)<8) { $message='Password must be at least 8 characters.'; $msgType='danger'; }
            else {
                $db->prepare("UPDATE users SET password_hash=?,updated_at=NOW() WHERE id=?")->execute([password_hash($pass,PASSWORD_BCRYPT,['cost'=>12]),$uid]);
                logActivity($user['id'],'RESET_PASSWORD','user',$uid,"Reset password for user #$uid");
                $message='Password reset successfully.';
            }
        }
        if ($action === 'toggle_active') {
            $uid=(int)$_POST['user_id'];
            if ($uid===$user['id']) { $message='Cannot deactivate your own account.'; $msgType='danger'; }
            else { $db->prepare("UPDATE users SET is_active=NOT is_active WHERE id=?")->execute([$uid]); $message='User status updated.'; }
        }
    }
}

$users = $db->query("SELECT * FROM users ORDER BY role,full_name")->fetchAll();
$roleCounts = ['Admin'=>0,'Manager'=>0,'Member'=>0,'Active'=>0];
foreach ($users as $u) { $roleCounts[$u['role']]++; if ($u['is_active']) $roleCounts['Active']++; }
$csrf = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>User Management – ONOW Enable OKR</title>
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
    <div><div class="page-title">User Management</div><div class="page-breadcrumb">Manage system access, roles, and departmental assignments.</div></div>
    <div class="header-actions"><button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createModal"><i class="bi bi-person-plus me-1"></i>New User</button></div>
  </header>
  <div class="page-body">
    <?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?> alert-dismissible fade show">
      <i class="bi bi-<?= $msgType==='success'?'check-circle':'exclamation-circle' ?> me-2"></i><?= htmlspecialchars($message) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
      <?php foreach ([['Total Users',count($users),'bi-people','var(--primary)','var(--primary-lt)'],['Active',$roleCounts['Active'],'bi-person-check','var(--success)','var(--success-lt)'],['Managers',$roleCounts['Manager'],'bi-person-gear','var(--info)','var(--info-lt)'],['Members',$roleCounts['Member'],'bi-person','var(--secondary)','#ede9fe']] as [$lbl,$val,$ico,$col,$bg]): ?>
      <div class="col-6 col-lg-3"><div class="stat-card">
        <div class="stat-card-accent" style="background:<?= $col ?>"></div>
        <div class="stat-icon" style="background:<?= $bg ?>;color:<?= $col ?>"><i class="bi <?= $ico ?>"></i></div>
        <div class="stat-label"><?= $lbl ?></div>
        <div class="stat-value" style="color:<?= $col ?>"><?= $val ?></div>
      </div></div>
      <?php endforeach; ?>
    </div>

    <div class="card">
      <div class="card-header"><i class="bi bi-people text-primary"></i><span class="card-title">All Users</span>
        <input type="text" id="srch" class="form-control form-control-sm ms-auto" placeholder="Search…" style="max-width:200px" oninput="filterUsers()">
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table mb-0" id="usersTable">
            <thead><tr><th>User</th><th>Email</th><th>Role</th><th>Department</th><th>Last Login</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): $ini=initials($u['full_name']); ?>
            <tr id="ur-<?= $u['id'] ?>">
              <td>
                <div class="d-flex align-items-center gap-2">
                  <div class="user-avatar" style="background:<?= htmlspecialchars($u['avatar_color']) ?>;width:34px;height:34px;font-size:.75rem"><?= $ini ?></div>
                  <div><div class="fw-700" style="font-size:.87rem"><?= htmlspecialchars($u['full_name']) ?></div><div class="text-muted" style="font-size:.75rem"><?= htmlspecialchars($u['job_title']??'—') ?></div></div>
                </div>
              </td>
              <td style="font-size:.85rem"><?= htmlspecialchars($u['email']) ?></td>
              <td><span class="role-badge role-<?= strtolower($u['role']) ?>"><?= $u['role'] ?></span></td>
              <td style="font-size:.85rem"><?= htmlspecialchars($u['department']??'—') ?></td>
              <td style="font-size:.8rem;color:var(--muted)"><?= $u['last_login']?date('d M Y, H:i',strtotime($u['last_login'])):'Never' ?></td>
              <td><span class="badge <?= $u['is_active']?'bg-success':'bg-secondary' ?>"><?= $u['is_active']?'Active':'Inactive' ?></span></td>
              <td>
                <div class="d-flex gap-1">
                  <button class="btn btn-sm btn-outline-primary btn-icon" onclick="editUser(<?= htmlspecialchars(json_encode($u)) ?>)" title="Edit"><i class="bi bi-pencil"></i></button>
                  <button class="btn btn-sm btn-outline-warning btn-icon" onclick="resetPwd(<?= $u['id'] ?>,'<?= htmlspecialchars(addslashes($u['full_name'])) ?>')" title="Reset Password"><i class="bi bi-key"></i></button>
                  <?php if ($u['id']!==$user['id']): ?>
                  <button class="btn btn-sm <?= $u['is_active']?'btn-outline-danger':'btn-outline-success' ?> btn-icon" onclick="toggleActive(<?= $u['id'] ?>,<?= $u['is_active'] ?>)" title="<?= $u['is_active']?'Deactivate':'Activate' ?>"><i class="bi bi-person-<?= $u['is_active']?'x':'check' ?>"></i></button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</main>
</div>

<!-- Create User Modal -->
<div class="modal fade" id="createModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Create New User</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="create_user">
    <div class="modal-body"><div class="row g-3">
      <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-1">
          <label class="form-label mb-0">Full Name <span class="text-danger">*</span></label>
          <small class="text-muted" id="cNameCount">0 / 100</small>
        </div>
        <input type="text" name="full_name" id="cName" class="form-control" required maxlength="100" placeholder="e.g. Jane Smith" oninput="userCharCount('cName','cNameCount',100)">
      </div>
      <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-1">
          <label class="form-label mb-0">Email <span class="text-danger">*</span></label>
          <small class="text-muted" id="cEmailCount">0 / 150</small>
        </div>
        <input type="email" name="email" id="cEmail" class="form-control" required maxlength="150" placeholder="e.g. jane@example.com" oninput="userCharCount('cEmail','cEmailCount',150)">
      </div>
      <div class="col-md-6"><label class="form-label">Role</label><select name="role" class="form-select"><option value="Member">Member</option><option value="Manager">Manager</option><option value="Admin">Admin</option></select></div>
      <div class="col-md-6">
        <div class="d-flex justify-content-between align-items-center mb-1">
          <label class="form-label mb-0">Department</label>
          <small class="text-muted" id="cDeptCount">0 / 100</small>
        </div>
        <input type="text" name="department" id="cDept" class="form-control" maxlength="100" oninput="userCharCount('cDept','cDeptCount',100)">
      </div>
      <div class="col-md-6">
        <div class="d-flex justify-content-between align-items-center mb-1">
          <label class="form-label mb-0">Job Title</label>
          <small class="text-muted" id="cTitleCount">0 / 100</small>
        </div>
        <input type="text" name="job_title" id="cTitle" class="form-control" maxlength="100" oninput="userCharCount('cTitle','cTitleCount',100)">
      </div>
      <div class="col-md-6">
        <div class="d-flex justify-content-between align-items-center mb-1">
          <label class="form-label mb-0">Phone</label>
          <small class="text-muted" id="cPhoneCount">0 / 30</small>
        </div>
        <input type="text" name="phone" id="cPhone" class="form-control" maxlength="30" oninput="userCharCount('cPhone','cPhoneCount',30)">
      </div>
      <div class="col-12"><label class="form-label">Password <span class="text-danger">*</span></label>
        <div class="input-group"><input type="password" name="password" id="createPwd" class="form-control" minlength="8" required placeholder="Min 8 characters"><button type="button" class="btn btn-outline-secondary" onclick="togglePwd('createPwd','cpEye')"><i class="bi bi-eye" id="cpEye"></i></button></div>
        <div class="form-text"><i class="bi bi-info-circle me-1"></i>Minimum 8 characters.</div>
      </div>
    </div></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary" onclick="return validateCreateUser()"><i class="bi bi-person-plus me-1"></i>Create User</button></div>
    </form>
  </div></div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit User</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="update_user"><input type="hidden" name="user_id" id="euId">
    <div class="modal-body"><div class="row g-3">
      <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-1">
          <label class="form-label mb-0">Full Name <span class="text-danger">*</span></label>
          <small class="text-muted" id="euNameCount">0 / 100</small>
        </div>
        <input type="text" name="full_name" id="euName" class="form-control" required maxlength="100" oninput="userCharCount('euName','euNameCount',100)">
      </div>
      <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-1">
          <label class="form-label mb-0">Email <span class="text-danger">*</span></label>
          <small class="text-muted" id="euEmailCount">0 / 150</small>
        </div>
        <input type="email" name="email" id="euEmail" class="form-control" required maxlength="150" oninput="userCharCount('euEmail','euEmailCount',150)">
      </div>
      <div class="col-md-6"><label class="form-label">Role</label><select name="role" id="euRole" class="form-select"><option value="Member">Member</option><option value="Manager">Manager</option><option value="Admin">Admin</option></select></div>
      <div class="col-md-6">
        <div class="d-flex justify-content-between align-items-center mb-1">
          <label class="form-label mb-0">Department</label>
          <small class="text-muted" id="euDeptCount">0 / 100</small>
        </div>
        <input type="text" name="department" id="euDept" class="form-control" maxlength="100" oninput="userCharCount('euDept','euDeptCount',100)">
      </div>
      <div class="col-md-6">
        <div class="d-flex justify-content-between align-items-center mb-1">
          <label class="form-label mb-0">Job Title</label>
          <small class="text-muted" id="euTitleCount">0 / 100</small>
        </div>
        <input type="text" name="job_title" id="euTitle" class="form-control" maxlength="100" oninput="userCharCount('euTitle','euTitleCount',100)">
      </div>
      <div class="col-md-6">
        <div class="d-flex justify-content-between align-items-center mb-1">
          <label class="form-label mb-0">Phone</label>
          <small class="text-muted" id="euPhoneCount">0 / 30</small>
        </div>
        <input type="text" name="phone" id="euPhone" class="form-control" maxlength="30" oninput="userCharCount('euPhone','euPhoneCount',30)">
      </div>
    </div></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary" onclick="return validateEditUser()"><i class="bi bi-check me-1"></i>Save Changes</button></div>
    </form>
  </div></div>
</div>

<!-- Reset Password Modal (FR 5.2) -->
<div class="modal fade" id="resetModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="bi bi-key me-2"></i>Reset Password</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="user_id" id="rpId">
    <div class="modal-body">
      <p class="text-muted small mb-3">Resetting password for: <strong id="rpName"></strong></p>
      <label class="form-label">New Password <span class="text-danger">*</span></label>
      <div class="input-group"><input type="password" name="new_password" id="rpPwd" class="form-control" minlength="8" required placeholder="Min 8 characters"><button type="button" class="btn btn-outline-secondary" onclick="togglePwd('rpPwd','rpEye')"><i class="bi bi-eye" id="rpEye"></i></button></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-warning"><i class="bi bi-key me-1"></i>Reset Password</button></div>
    </form>
  </div></div>
</div>

<form id="toggleForm" method="POST" style="display:none">
  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
  <input type="hidden" name="action" value="toggle_active">
  <input type="hidden" name="user_id" id="toggleUid">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ── Password visibility toggle ── */
function togglePwd(id,iconId){const i=document.getElementById(id),ic=document.getElementById(iconId);i.type=i.type==='password'?'text':'password';ic.className='bi bi-eye'+(i.type==='text'?'-slash':'');}

/* ── Table search ── */
function filterUsers(){const q=document.getElementById('srch').value.toLowerCase();document.querySelectorAll('#usersTable tbody tr').forEach(r=>r.style.display=r.textContent.toLowerCase().includes(q)?'':'none');}

/* ── Character counter (matches KR modal style) ── */
function userCharCount(inputId, counterId, max) {
  const el  = document.getElementById(inputId);
  const ctr = document.getElementById(counterId);
  if (!el || !ctr) return;
  const len = el.value.length;
  ctr.textContent = `${len} / ${max}`;
  ctr.style.color = len >= max ? 'var(--danger)' : len >= max * 0.9 ? 'var(--warning)' : '';
  el.setCustomValidity(len > max ? `Cannot exceed ${max} characters.` : '');
}

/* ── Email format validation ── */
function isValidEmail(email) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim());
}

/* ── Create User validation ── */
function validateCreateUser() {
  const name  = document.getElementById('cName');
  const email = document.getElementById('cEmail');
  const pwd   = document.getElementById('createPwd');
  let valid = true;

  if (!name.value.trim()) {
    name.setCustomValidity('Full name is required.');
    valid = false;
  } else { name.setCustomValidity(''); }

  if (!email.value.trim()) {
    email.setCustomValidity('Email is required.');
    valid = false;
  } else if (!isValidEmail(email.value)) {
    email.setCustomValidity('Please enter a valid email address.');
    valid = false;
  } else { email.setCustomValidity(''); }

  if (pwd.value.length < 8) {
    pwd.setCustomValidity('Password must be at least 8 characters.');
    valid = false;
  } else { pwd.setCustomValidity(''); }

  if (!valid) {
    document.getElementById('createModal').querySelector('form').reportValidity();
    return false;
  }
  return true;
}

/* ── Edit User validation ── */
function validateEditUser() {
  const name  = document.getElementById('euName');
  const email = document.getElementById('euEmail');
  let valid = true;

  if (!name.value.trim()) {
    name.setCustomValidity('Full name is required.');
    valid = false;
  } else { name.setCustomValidity(''); }

  if (!email.value.trim()) {
    email.setCustomValidity('Email is required.');
    valid = false;
  } else if (!isValidEmail(email.value)) {
    email.setCustomValidity('Please enter a valid email address.');
    valid = false;
  } else { email.setCustomValidity(''); }

  if (!valid) {
    document.getElementById('editModal').querySelector('form').reportValidity();
    return false;
  }
  return true;
}

/* ── Edit User — populate modal + sync counters ── */
function editUser(u) {
  const safe = (v) => v || '';
  document.getElementById('euId').value    = u.id;
  document.getElementById('euName').value  = safe(u.full_name);
  document.getElementById('euEmail').value = safe(u.email);
  document.getElementById('euRole').value  = safe(u.role);
  document.getElementById('euDept').value  = safe(u.department);
  document.getElementById('euTitle').value = safe(u.job_title);
  document.getElementById('euPhone').value = safe(u.phone);
  // Sync all counters to reflect pre-filled values
  userCharCount('euName',  'euNameCount',  100);
  userCharCount('euEmail', 'euEmailCount', 150);
  userCharCount('euDept',  'euDeptCount',  100);
  userCharCount('euTitle', 'euTitleCount', 100);
  userCharCount('euPhone', 'euPhoneCount',  30);
  new bootstrap.Modal(document.getElementById('editModal')).show();
}

/* ── Reset create modal counters on open ── */
document.getElementById('createModal').addEventListener('show.bs.modal', () => {
  ['cName','cEmail','cDept','cTitle','cPhone'].forEach(id => {
    const limits = {cName:100,cEmail:150,cDept:100,cTitle:100,cPhone:30};
    const el = document.getElementById(id);
    if (el) el.value = '';
    userCharCount(id, id+'Count', limits[id]);
  });
  document.getElementById('createPwd').value = '';
});

function resetPwd(id,name){document.getElementById('rpId').value=id;document.getElementById('rpName').textContent=name;new bootstrap.Modal(document.getElementById('resetModal')).show();}
function toggleActive(id,current){if(!confirm(`${current?'Deactivate':'Activate'} this user?`))return;document.getElementById('toggleUid').value=id;document.getElementById('toggleForm').submit();}
</script>
</body></html>
