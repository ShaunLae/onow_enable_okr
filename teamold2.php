<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';
requireLogin();
requireRole(['Admin','Manager']);
$user = getCurrentUser();
if ($user['role'] === 'Admin') { header('Location: team_management.php'); exit; }
$currentPage = 'team';
$userId      = $user['id'];
$myTeams     = getUserTeams($userId);
$db          = getDB();

function pbClass(string $s): string {
    return match($s) {
        'On Track'          => 'pb-on-track',
        'Completed'         => 'pb-completed',
        'At Risk', 'Behind' => 'pb-at-risk',
        default             => ''
    };
}

// ── Team health query — identical logic to MG-Q1 in dashboard.php ────────────
// Counts only objectives where the member is directly involved:
//   owner_id = u.id            (they own/created it)
//   OR in objective_members    (they were explicitly assigned to it)
// This intentionally EXCLUDES public Organisational objectives that the member
// can merely VIEW — matching the dashboard Team Health widget exactly.
$stmt = $db->prepare("
    SELECT u.id, u.full_name, u.avatar_color, u.role,
           u.job_title, u.department, u.email,
           COUNT(DISTINCT o.id)                                              AS obj_count,
           COALESCE(AVG(o.progress), 0)                                     AS avg_progress,
           SUM(CASE WHEN o.status = 'On Track'              THEN 1 ELSE 0 END) AS on_track_count,
           SUM(CASE WHEN o.status IN('At Risk','Behind')     THEN 1 ELSE 0 END) AS at_risk_count,
           SUM(CASE WHEN o.status = 'Behind'                THEN 1 ELSE 0 END) AS behind_count,
           SUM(CASE WHEN o.status = 'Completed'             THEN 1 ELSE 0 END) AS completed_count,
           SUM(CASE WHEN o.status = 'Not Started'           THEN 1 ELSE 0 END) AS not_started_count
    FROM   users u
    JOIN   team_members tm ON u.id = tm.user_id
    LEFT   JOIN objectives o ON (
               o.owner_id = u.id
            OR EXISTS (
                   SELECT 1 FROM objective_members om2
                   WHERE  om2.objective_id = o.id AND om2.user_id = u.id
               )
           )
           AND o.deleted_at IS NULL
    WHERE  tm.team_id IN (SELECT team_id FROM team_members WHERE user_id = :uid)
      AND  u.id    != :uid2
      AND  u.role  != 'Admin'
      AND  u.is_active = 1
    GROUP  BY u.id, u.full_name, u.avatar_color, u.role, u.job_title, u.department, u.email
    ORDER  BY avg_progress ASC
");
$stmt->execute([':uid' => $userId, ':uid2' => $userId]);
$teamHealth = $stmt->fetchAll();

// ── Per-member recent objectives (direct involvement only) ─────────────────
// Fetched separately so we can show recent objective titles on each card.
// Same WHERE condition: owner_id = u.id OR in objective_members.
$memberObjectives = [];
if (!empty($teamHealth)) {
    $memberIds = array_column($teamHealth, 'id');
    $ph = implode(',', array_fill(0, count($memberIds), '?'));
    $objStmt = $db->prepare("
        SELECT o.id, o.title, o.type, o.status, o.progress,
               o.owner_id,
               om2.user_id AS assigned_user_id
        FROM   objectives o
        LEFT   JOIN objective_members om2 ON o.id = om2.objective_id
                    AND om2.user_id IN ($ph)
        WHERE  o.deleted_at IS NULL
          AND  (o.owner_id IN ($ph) OR om2.user_id IS NOT NULL)
        GROUP  BY o.id, o.title, o.type, o.status, o.progress, o.owner_id,
                  om2.user_id
        ORDER  BY o.created_at DESC
    ");
    // Pass member IDs twice: once for om2.user_id filter, once for owner_id filter
    $params = array_merge($memberIds, $memberIds);
    $objStmt->execute($params);
    $allObjs = $objStmt->fetchAll();

    // Group objectives by member — each obj belongs to a member if they own it
    // or are in objective_members
    foreach ($teamHealth as $m) {
        $mid = (int)$m['id'];
        $memberObjectives[$mid] = array_filter($allObjs, function($o) use ($mid) {
            return (int)$o['owner_id'] === $mid
                || (int)($o['assigned_user_id'] ?? 0) === $mid;
        });
        $memberObjectives[$mid] = array_values($memberObjectives[$mid]);
    }
}
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
<style>
.stat-mini{text-align:center;padding:.5rem .25rem}
.stat-mini .val{font-size:1.4rem;font-weight:800;line-height:1.1}
.stat-mini .lbl{font-size:.68rem;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-top:.15rem}
</style>
</head>
<body>
<div class="app-wrapper">
<?php include 'includes/nav.php'; ?>
<main class="main-content">
  <header class="top-header">
    <button class="btn btn-sm btn-outline-secondary d-md-none"
            onclick="document.getElementById('sidebar').classList.toggle('open')">
      <i class="bi bi-list"></i>
    </button>
    <div>
      <div class="page-title">Team Overview</div>
      <div class="page-breadcrumb">Direct involvement only — owned or assigned objectives</div>
    </div>
    <a href="dashboard.php" class="btn btn-sm btn-outline-secondary ms-auto">
      <i class="bi bi-arrow-left me-1"></i>Dashboard
    </a>
  </header>

  <div class="page-body">

    <?php if (!empty($myTeams)): ?>
    <div class="mb-3 d-flex align-items-center gap-2 flex-wrap">
      <span class="text-muted small fw-600">Your teams:</span>
      <?php foreach ($myTeams as $t): ?>
      <span style="background:#ede9fe;color:#7c3aed;border-radius:20px;padding:.2rem .75rem;font-size:.78rem;font-weight:700">
        <i class="bi bi-diagram-3 me-1"></i><?= htmlspecialchars($t['name']) ?>
      </span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($teamHealth)): ?>
    <div class="empty-state card"><div class="card-body">
      <i class="bi bi-people"></i>
      <h5>No Teammates Found</h5>
      <p class="small">You haven't been assigned to a team yet, or your team has no other members. Contact your administrator.</p>
    </div></div>

    <?php else: ?>

    <!-- Team summary bar -->
    <?php
    $totalMembers  = count($teamHealth);
    $teamAvg       = $totalMembers > 0
        ? round(array_sum(array_column($teamHealth,'avg_progress')) / $totalMembers, 1)
        : 0;
    $teamAtRisk    = array_sum(array_column($teamHealth,'at_risk_count'));
    $teamOnTrack   = array_sum(array_column($teamHealth,'on_track_count'));
    $teamCompleted = array_sum(array_column($teamHealth,'completed_count'));
    $teamObjTotal  = array_sum(array_column($teamHealth,'obj_count'));
    ?>
    <div class="card mb-3">
      <div class="card-body py-3">
        <div class="row g-3 text-center align-items-center">
          <div class="col-6 col-md-2 border-end">
            <div class="stat-mini">
              <div class="val" style="color:var(--primary)"><?= $totalMembers ?></div>
              <div class="lbl">Members</div>
            </div>
          </div>
          <div class="col-6 col-md-2 border-end">
            <div class="stat-mini">
              <div class="val" style="color:var(--text2)"><?= $teamObjTotal ?></div>
              <div class="lbl">Total Objectives</div>
            </div>
          </div>
          <div class="col-6 col-md-3 border-end">
            <div class="stat-mini">
              <div class="val" style="color:var(--primary)"><?= $teamAvg ?>%</div>
              <div class="lbl">Team Avg Progress</div>
            </div>
          </div>
          <div class="col-6 col-md-2 border-end">
            <div class="stat-mini">
              <div class="val" style="color:var(--success)"><?= $teamOnTrack ?></div>
              <div class="lbl">On Track</div>
            </div>
          </div>
          <div class="col-6 col-md-1 border-end">
            <div class="stat-mini">
              <div class="val" style="color:var(--warning)"><?= $teamAtRisk ?></div>
              <div class="lbl">At Risk</div>
            </div>
          </div>
          <div class="col-6 col-md-2">
            <div class="stat-mini">
              <div class="val" style="color:#3b82f6"><?= $teamCompleted ?></div>
              <div class="lbl">Completed</div>
            </div>
          </div>
        </div>
        <!-- Team-wide progress bar -->
        <div class="mt-3 px-1">
          <div class="d-flex justify-content-between mb-1">
            <small class="text-muted fw-600">Team Overall Progress</small>
            <small class="fw-700"><?= $teamAvg ?>%</small>
          </div>
          <div class="progress" style="height:8px">
            <div class="progress-bar <?= $teamAvg>=70?'pb-on-track':($teamAvg>=40?'pb-at-risk':'pb-behind') ?>"
                 style="width:<?= $teamAvg ?>%"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Member cards -->
    <div class="row g-3">
    <?php foreach ($teamHealth as $m):
      $mid      = (int)$m['id'];
      $ini      = initials($m['full_name']);
      $prog     = round((float)$m['avg_progress'], 1);
      $objCount = (int)$m['obj_count'];
      $onT      = (int)$m['on_track_count'];
      $atR      = (int)$m['at_risk_count'];
      $beh      = (int)$m['behind_count'];
      $comp     = (int)$m['completed_count'];
      $notS     = (int)$m['not_started_count'];
      $pbc      = $prog>=70?'pb-on-track':($prog>=40?'pb-at-risk':'pb-behind');
      $recentObjs = array_slice($memberObjectives[$mid] ?? [], 0, 3);
    ?>
    <div class="col-md-6 col-xl-4">
      <div class="card h-100">
        <div class="card-body">

          <!-- Member identity -->
          <div class="d-flex align-items-center gap-3 mb-3">
            <div class="user-avatar"
                 style="background:<?= htmlspecialchars($m['avatar_color']) ?>;width:48px;height:48px;font-size:1rem;border-radius:14px;flex-shrink:0">
              <?= $ini ?>
            </div>
            <div class="flex-grow-1">
              <div class="fw-800" style="font-size:.95rem"><?= htmlspecialchars($m['full_name']) ?></div>
              <div style="font-size:.75rem;color:var(--muted)">
                <?= htmlspecialchars($m['job_title'] ?? ($m['department'] ?? '—')) ?>
              </div>
              <span class="role-badge role-<?= strtolower($m['role']) ?>"><?= $m['role'] ?></span>
            </div>
            <?php if ($atR > 0 || $beh > 0): ?>
            <span class="status-badge status-at-risk flex-shrink-0">
              <i class="bi bi-exclamation-triangle"></i>
              <?= $atR + $beh ?> at risk
            </span>
            <?php endif; ?>
          </div>

          <!-- Overall progress bar -->
          <div class="d-flex justify-content-between mb-1">
            <span style="font-size:.78rem;color:var(--muted);font-weight:600">Overall Progress</span>
            <span style="font-size:.82rem;font-weight:700"><?= $prog ?>%</span>
          </div>
          <div class="progress mb-3">
            <div class="progress-bar <?= $pbc ?>" style="width:<?= $prog ?>%"></div>
          </div>

          <!-- Stat counters — matches dashboard Team Health exactly -->
          <div class="row g-2 mb-3">
            <div class="col-3">
              <div class="stat-mini" style="background:var(--primary-lt);border-radius:8px">
                <div class="val" style="color:var(--primary)"><?= $objCount ?></div>
                <div class="lbl">Objectives</div>
              </div>
            </div>
            <div class="col-3">
              <div class="stat-mini" style="background:var(--success-lt);border-radius:8px">
                <div class="val" style="color:var(--success)"><?= $onT ?></div>
                <div class="lbl">On Track</div>
              </div>
            </div>
            <div class="col-3">
              <div class="stat-mini" style="background:var(--warning-lt);border-radius:8px">
                <div class="val" style="color:var(--warning)"><?= $atR ?></div>
                <div class="lbl">At Risk</div>
              </div>
            </div>
            <div class="col-3">
              <div class="stat-mini" style="background:var(--danger-lt);border-radius:8px">
                <div class="val" style="color:var(--danger)"><?= $beh ?></div>
                <div class="lbl">Behind</div>
              </div>
            </div>
          </div>

          <!-- Sub-stats row -->
          <div class="d-flex gap-3 mb-3" style="font-size:.75rem;color:var(--muted)">
            <span><i class="bi bi-check-circle me-1" style="color:#3b82f6"></i><?= $comp ?> completed</span>
            <span><i class="bi bi-dash-circle me-1"></i><?= $notS ?> not started</span>
          </div>

          <!-- Recent objectives -->
          <?php if (!empty($recentObjs)): ?>
          <div class="border-top pt-3">
            <div class="text-muted mb-2" style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px">
              Recent Objectives
            </div>
            <?php foreach ($recentObjs as $o): ?>
            <div class="d-flex align-items-center justify-content-between mb-1 gap-2">
              <span class="type-badge type-<?= strtolower($o['type']) ?>" style="flex-shrink:0">
                <?= substr($o['type'],0,1) ?>
              </span>
              <span style="font-size:.78rem;font-weight:600;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                <?= htmlspecialchars($o['title']) ?>
              </span>
              <span class="status-badge <?= statusClass($o['status']) ?>">
                <?= round($o['progress']) ?>%
              </span>
            </div>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
          <div class="text-muted text-center py-2" style="font-size:.8rem">
            <i class="bi bi-inbox me-1"></i>No objectives yet
          </div>
          <?php endif; ?>

        </div>
      </div>
    </div>
    <?php endforeach; ?>
    </div>

    <?php endif; /* end empty check */ ?>
  </div>
</main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
