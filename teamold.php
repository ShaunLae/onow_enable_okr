<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';
requireLogin();
requireRole(['Admin','Manager']);
$user = getCurrentUser();
if ($user['role'] === 'Admin') { header('Location: team_management.php'); exit; }
$currentPage = 'team';
$userId      = $user['id'];
$teammates   = getTeammates($userId);
$myTeams     = getUserTeams($userId);
function pbClass(string $s): string { return match($s){'On Track'=>'pb-on-track','Completed'=>'pb-completed','At Risk','Behind'=>'pb-at-risk',default=>''}; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Team Overview – ONOW Enable OKR</title>
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
    <div><div class="page-title">Team Overview</div><div class="page-breadcrumb">Monitor your team's OKR progress</div></div>
  </header>
  <div class="page-body">
    <?php if (!empty($myTeams)): ?>
    <div class="mb-3 d-flex align-items-center gap-2 flex-wrap">
      <span class="text-muted small fw-600">Your teams:</span>
      <?php foreach ($myTeams as $t): ?>
      <span style="background:#ede9fe;color:#7c3aed;border-radius:20px;padding:.2rem .75rem;font-size:.78rem;font-weight:700"><i class="bi bi-diagram-3 me-1"></i><?= htmlspecialchars($t['name']) ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($teammates)): ?>
    <div class="empty-state card"><div class="card-body">
      <i class="bi bi-people"></i><h5>No Teammates Found</h5>
      <p class="small">You haven't been assigned to a team yet, or your team has no members. Contact your administrator.</p>
    </div></div>
    <?php else: ?>
    <div class="row g-3">
      <?php foreach ($teammates as $m):
        $ini   = initials($m['full_name']);
        $mObjs = getObjectives($m['id'], $m['role']);
        $prog  = count($mObjs) ? round(array_sum(array_column($mObjs,'progress'))/count($mObjs),1) : 0;
        $onT   = count(array_filter($mObjs, fn($o) => $o['status']==='On Track'));
        $atR   = count(array_filter($mObjs, fn($o) => in_array($o['status'],['At Risk','Behind'])));
        $pbc   = $prog>=70?'pb-on-track':($prog>=40?'pb-at-risk':'pb-behind');
      ?>
      <div class="col-md-6 col-xl-4">
        <div class="card">
          <div class="card-body">
            <div class="d-flex align-items-center gap-3 mb-3">
              <div class="user-avatar" style="background:<?= htmlspecialchars($m['avatar_color']) ?>;width:48px;height:48px;font-size:1rem;border-radius:14px"><?= $ini ?></div>
              <div class="flex-grow-1">
                <div class="fw-800" style="font-size:.95rem"><?= htmlspecialchars($m['full_name']) ?></div>
                <div style="font-size:.75rem;color:var(--muted)"><?= htmlspecialchars($m['job_title']??($m['department']??'—')) ?></div>
                <span class="role-badge role-<?= strtolower($m['role']) ?>"><?= $m['role'] ?></span>
              </div>
            </div>
            <div class="d-flex justify-content-between mb-1">
              <span style="font-size:.78rem;color:var(--muted);font-weight:600">Overall Progress</span>
              <span style="font-size:.82rem;font-weight:700"><?= $prog ?>%</span>
            </div>
            <div class="progress mb-3"><div class="progress-bar <?= $pbc ?>" style="width:<?= $prog ?>%"></div></div>
            <div class="row g-2 text-center">
              <div class="col-4"><div style="font-size:1.3rem;font-weight:800;color:var(--primary)"><?= count($mObjs) ?></div><div style="font-size:.7rem;color:var(--muted);font-weight:600">Objectives</div></div>
              <div class="col-4"><div style="font-size:1.3rem;font-weight:800;color:var(--success)"><?= $onT ?></div><div style="font-size:.7rem;color:var(--muted);font-weight:600">On Track</div></div>
              <div class="col-4"><div style="font-size:1.3rem;font-weight:800;color:var(--warning)"><?= $atR ?></div><div style="font-size:.7rem;color:var(--muted);font-weight:600">At Risk</div></div>
            </div>
            <?php if (!empty($mObjs)): ?>
            <div class="mt-3 border-top pt-3">
              <div class="text-muted mb-2" style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px">Recent Objectives</div>
              <?php foreach (array_slice($mObjs,0,3) as $o): ?>
              <div class="d-flex align-items-center justify-content-between mb-1 gap-2">
                <span class="type-badge type-<?= strtolower($o['type']) ?>" style="flex-shrink:0"><?= substr($o['type'],0,1) ?></span>
                <span style="font-size:.78rem;font-weight:600;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($o['title']) ?></span>
                <span class="status-badge <?= statusClass($o['status']) ?>"><?= round($o['progress']) ?>%</span>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
