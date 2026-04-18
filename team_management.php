<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';
requireLogin();
requireRole(['Admin']);
$user = getCurrentUser(); $currentPage = 'team_management';
$message = ''; $msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf($_POST['csrf_token'] ?? '')) { $message='Invalid CSRF token.'; $msgType='danger'; }
    else {
        $action = $_POST['action'] ?? '';
        if ($action==='create_team'){ $r=createTeam($_POST['name']??'',$_POST['description']??'',$user['id']); $message=$r['success']?'Team created.':$r['message']; if(!$r['success'])$msgType='danger'; }
        if ($action==='update_team'){ $r=updateTeam((int)$_POST['team_id'],$_POST['name']??'',$_POST['description']??'',$user['id']); $message=$r['success']?'Team updated.':$r['message']; if(!$r['success'])$msgType='danger'; }
        if ($action==='delete_team'){ $r=deleteTeam((int)$_POST['team_id'],$user['id']); $message=$r['success']?'Team deleted.':$r['message']; if(!$r['success'])$msgType='danger'; }
        if ($action==='update_members'){ setTeamMembers((int)$_POST['team_id'],$_POST['user_ids']??[],$user['id']); $message='Team members updated.'; }
    }
}

$teams    = getAllTeams();
$allUsers = getAllUsers();
$csrf     = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Team Management – ONOW Enable OKR</title>
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
    <div><div class="page-title">Team Management</div><div class="page-breadcrumb">Create teams · Assign Managers &amp; Members</div></div>
    <div class="header-actions"><button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createTeamModal"><i class="bi bi-plus-circle me-1"></i>New Team</button></div>
  </header>
  <div class="page-body">
    <?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?> alert-dismissible fade show"><i class="bi bi-<?= $msgType==='success'?'check-circle':'exclamation-circle' ?> me-2"></i><?= htmlspecialchars($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <?php if (empty($teams)): ?>
    <div class="empty-state card"><div class="card-body"><i class="bi bi-diagram-3"></i><h5>No Teams Yet</h5><p class="small">Create your first team and assign Managers and Members to it.</p><button class="btn btn-primary btn-sm mt-2" data-bs-toggle="modal" data-bs-target="#createTeamModal">Create Team</button></div></div>
    <?php else: ?>
    <div class="row g-3">
      <?php foreach ($teams as $team): $members=getTeamMembers($team['id']); $mgrs=array_filter($members,fn($m)=>$m['role']==='Manager'); $mems=array_filter($members,fn($m)=>$m['role']==='Member'); ?>
      <div class="col-lg-6">
        <div class="card">
          <div class="card-header">
            <div style="width:34px;height:34px;background:#ede9fe;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="bi bi-diagram-3" style="color:#7c3aed"></i></div>
            <div class="flex-grow-1">
              <div class="card-title"><?= htmlspecialchars($team['name']) ?></div>
              <?php if ($team['description']): ?><div class="text-muted" style="font-size:.75rem"><?= htmlspecialchars($team['description']) ?></div><?php endif; ?>
            </div>
            <div class="d-flex gap-1">
              <button class="btn btn-sm btn-outline-primary btn-icon" onclick="editTeam(<?= htmlspecialchars(json_encode($team)) ?>)" title="Edit"><i class="bi bi-pencil"></i></button>
              <button class="btn btn-sm btn-outline-success btn-icon" onclick="manageMembers(<?= $team['id'] ?>,'<?= htmlspecialchars(addslashes($team['name'])) ?>',<?= htmlspecialchars(json_encode(array_column($members,'id'))) ?>)" title="Manage Members"><i class="bi bi-people"></i></button>
              <button class="btn btn-sm btn-outline-danger btn-icon" onclick="deleteTeam(<?= $team['id'] ?>,'<?= htmlspecialchars(addslashes($team['name'])) ?>')" title="Delete"><i class="bi bi-trash"></i></button>
            </div>
          </div>
          <div class="card-body">
            <?php if (empty($members)): ?>
            <div class="text-muted small text-center py-2">No members assigned. Click <i class="bi bi-people"></i> to assign.</div>
            <?php else: ?>
              <?php if (!empty($mgrs)): ?>
              <div class="mb-2"><div class="text-muted mb-1" style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px">Managers</div>
              <?php foreach ($mgrs as $m): $ini=initials($m['full_name']); ?>
              <div class="d-flex align-items-center gap-2 mb-1">
                <div class="user-avatar" style="background:<?= htmlspecialchars($m['avatar_color']) ?>;width:28px;height:28px;font-size:.65rem;border-radius:8px"><?= $ini ?></div>
                <span style="font-size:.85rem;font-weight:600"><?= htmlspecialchars($m['full_name']) ?></span>
                <?php if ($m['job_title']): ?><span class="text-muted" style="font-size:.75rem"><?= htmlspecialchars($m['job_title']) ?></span><?php endif; ?>
              </div>
              <?php endforeach; ?>
              </div>
              <?php endif; ?>
              <?php if (!empty($mems)): ?>
              <div><div class="text-muted mb-1" style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px">Members</div>
              <div class="d-flex flex-wrap gap-1">
              <?php foreach ($mems as $m): $ini=initials($m['full_name']); ?>
              <div class="d-flex align-items-center gap-1" style="background:var(--primary-lt);border-radius:20px;padding:.2rem .6rem .2rem .3rem">
                <div class="user-avatar" style="background:<?= htmlspecialchars($m['avatar_color']) ?>;width:20px;height:20px;font-size:.55rem;border-radius:50%"><?= $ini ?></div>
                <span style="font-size:.75rem;font-weight:600;color:var(--primary)"><?= htmlspecialchars($m['full_name']) ?></span>
              </div>
              <?php endforeach; ?>
              </div></div>
              <?php endif; ?>
            <?php endif; ?>
            <div class="mt-2 text-muted" style="font-size:.75rem"><i class="bi bi-people me-1"></i><?= count($members) ?> member<?= count($members)!==1?'s':'' ?> · <i class="bi bi-clock me-1"></i>Created <?= date('d M Y',strtotime($team['created_at'])) ?></div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</main>
</div>

<!-- Create Team Modal -->
<div class="modal fade" id="createTeamModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Create New Team</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="create_team">
    <div class="modal-body">
      <div class="mb-3"><label class="form-label">Team Name <span class="text-danger">*</span></label><input type="text" name="name" class="form-control" required placeholder="e.g. Programme Management"></div>
      <div class="mb-3"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="3" placeholder="What does this team do?"></textarea></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Create Team</button></div>
    </form>
  </div></div>
</div>

<!-- Edit Team Modal -->
<div class="modal fade" id="editTeamModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Team</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="update_team"><input type="hidden" name="team_id" id="etId">
    <div class="modal-body">
      <div class="mb-3"><label class="form-label">Team Name</label><input type="text" name="name" id="etName" class="form-control" required></div>
      <div class="mb-3"><label class="form-label">Description</label><textarea name="description" id="etDesc" class="form-control" rows="3"></textarea></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save Changes</button></div>
    </form>
  </div></div>
</div>

<!-- Manage Members Modal -->
<div class="modal fade" id="membersModal" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="bi bi-people me-2"></i>Manage Members — <span id="mmTeamName"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="update_members"><input type="hidden" name="team_id" id="mmTeamId">
    <div class="modal-body">
      <p class="text-muted small mb-3">Select users to assign. Managers and Members can belong to multiple teams.</p>
      <div class="row g-2">
        <?php foreach ($allUsers as $u): if($u['role']==='Admin')continue; $ini=initials($u['full_name']); ?>
        <div class="col-md-6">
          <label class="d-flex align-items-center gap-2 p-2 rounded border user-select-none" style="cursor:pointer" onmouseover="this.style.background='var(--bg)'" onmouseout="this.style.background=''">
            <input type="checkbox" name="user_ids[]" value="<?= $u['id'] ?>" class="member-assign-cb" data-uid="<?= $u['id'] ?>">
            <div class="user-avatar" style="background:<?= htmlspecialchars($u['avatar_color']) ?>;width:30px;height:30px;font-size:.7rem;border-radius:8px;flex-shrink:0"><?= $ini ?></div>
            <div class="flex-grow-1">
              <div style="font-size:.85rem;font-weight:700"><?= htmlspecialchars($u['full_name']) ?></div>
              <div style="font-size:.72rem;color:var(--muted)"><?= $u['role'] ?><?= $u['department']?' · '.htmlspecialchars($u['department']):'' ?></div>
            </div>
            <span class="role-badge role-<?= strtolower($u['role']) ?>"><?= $u['role'] ?></span>
          </label>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>Save Members</button></div>
    </form>
  </div></div>
</div>

<form id="deleteTeamForm" method="POST" style="display:none">
  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
  <input type="hidden" name="action" value="delete_team">
  <input type="hidden" name="team_id" id="dtId">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function editTeam(t){document.getElementById('etId').value=t.id;document.getElementById('etName').value=t.name;document.getElementById('etDesc').value=t.description||'';new bootstrap.Modal(document.getElementById('editTeamModal')).show();}
function manageMembers(id,name,currentIds){document.getElementById('mmTeamId').value=id;document.getElementById('mmTeamName').textContent=name;document.querySelectorAll('.member-assign-cb').forEach(cb=>{cb.checked=currentIds.includes(parseInt(cb.dataset.uid));});new bootstrap.Modal(document.getElementById('membersModal')).show();}
function deleteTeam(id,name){if(!confirm(`Delete team "${name}"? This will not delete the users, only the team.`))return;document.getElementById('dtId').value=id;document.getElementById('deleteTeamForm').submit();}
</script>
</body></html>
