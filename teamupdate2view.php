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

// ── Per-team, per-member health query ────────────────────────────────────────
// Extends the original MG-Q1 logic to also group by team, so we can build
// one team summary card per team and drill into its members on click.
$stmt = $db->prepare("
    SELECT tm.team_id, t.name AS team_name,
           u.id, u.full_name, u.avatar_color, u.role,
           u.job_title, u.department, u.email,
           COUNT(DISTINCT o.id)                                               AS obj_count,
           COALESCE(AVG(o.progress), 0)                                       AS avg_progress,
           SUM(CASE WHEN o.status = 'On Track'           THEN 1 ELSE 0 END)   AS on_track_count,
           SUM(CASE WHEN o.status IN('At Risk','Behind')  THEN 1 ELSE 0 END)  AS at_risk_count,
           SUM(CASE WHEN o.status = 'Behind'             THEN 1 ELSE 0 END)   AS behind_count,
           SUM(CASE WHEN o.status = 'Completed'          THEN 1 ELSE 0 END)   AS completed_count,
           SUM(CASE WHEN o.status = 'Not Started'        THEN 1 ELSE 0 END)   AS not_started_count
    FROM   users u
    JOIN   team_members tm ON u.id = tm.user_id
    JOIN   teams t ON t.id = tm.team_id
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
    GROUP  BY tm.team_id, t.name, u.id, u.full_name, u.avatar_color, u.role,
              u.job_title, u.department, u.email
    ORDER  BY tm.team_id, avg_progress ASC
");
$stmt->execute([':uid' => $userId, ':uid2' => $userId]);
$rows = $stmt->fetchAll();

// ── Group rows into per-team structure ────────────────────────────────────────
$teamsData    = [];
$allMemberIds = [];
foreach ($rows as $row) {
    $tid = (int)$row['team_id'];
    if (!isset($teamsData[$tid])) {
        $teamsData[$tid] = ['id' => $tid, 'name' => $row['team_name'], 'members' => []];
    }
    $teamsData[$tid]['members'][] = $row;
    $allMemberIds[] = (int)$row['id'];
}
$allMemberIds = array_unique($allMemberIds);

// Compute per-team aggregate stats
foreach ($teamsData as &$team) {
    $ms = $team['members'];
    $cnt = count($ms);
    $team['member_count'] = $cnt;
    $team['avg_progress'] = $cnt > 0
        ? round(array_sum(array_column($ms, 'avg_progress')) / $cnt, 1)
        : 0;
    $team['obj_total']  = array_sum(array_column($ms, 'obj_count'));
    $team['on_track']   = array_sum(array_column($ms, 'on_track_count'));
    $team['at_risk']    = array_sum(array_column($ms, 'at_risk_count'));
    $team['behind']     = array_sum(array_column($ms, 'behind_count'));
    $team['completed']  = array_sum(array_column($ms, 'completed_count'));
}
unset($team);

// ── Per-member recent objectives (direct involvement only) ────────────────────
$memberObjectives = [];
if (!empty($allMemberIds)) {
    $ph = implode(',', array_fill(0, count($allMemberIds), '?'));
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
    $objStmt->execute(array_merge($allMemberIds, $allMemberIds));
    $allObjs = $objStmt->fetchAll();

    foreach ($allMemberIds as $mid) {
        $memberObjectives[$mid] = array_values(array_filter($allObjs, fn($o) =>
            (int)$o['owner_id'] === $mid || (int)($o['assigned_user_id'] ?? 0) === $mid
        ));
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
/* ── Shared mini-stat block ─────────────────────────────────────── */
.stat-mini{text-align:center;padding:.5rem .25rem}
.stat-mini .val{font-size:1.4rem;font-weight:800;line-height:1.1}
.stat-mini .lbl{font-size:.68rem;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-top:.15rem}

/* ── Team summary cards ─────────────────────────────────────────── */
.team-card{
  cursor:pointer;
  border:1.5px solid transparent;
  transition:border-color .18s,box-shadow .18s,transform .18s;
}
.team-card:hover{
  border-color:var(--primary);
  box-shadow:0 4px 24px rgba(109,76,255,.13);
  transform:translateY(-2px);
}
.team-card .team-icon{
  width:46px;height:46px;border-radius:13px;
  background:var(--primary-lt);
  display:flex;align-items:center;justify-content:center;
  font-size:1.2rem;color:var(--primary);flex-shrink:0;
}
.team-card .chevron{
  width:30px;height:30px;border-radius:50%;
  background:var(--primary-lt);
  display:flex;align-items:center;justify-content:center;
  color:var(--primary);font-size:.9rem;
  transition:background .15s,color .15s;
}
.team-card:hover .chevron{background:var(--primary);color:#fff;}

/* ── Members view ───────────────────────────────────────────────── */
.back-btn{
  display:inline-flex;align-items:center;gap:.4rem;
  font-size:.82rem;font-weight:700;color:var(--primary);
  cursor:pointer;padding:.3rem .6rem;border-radius:8px;
  transition:background .15s;
  border:none;background:transparent;
}
.back-btn:hover{background:var(--primary-lt);}

/* member cards */
.stat-mini{text-align:center;padding:.5rem .25rem}

/* ── View transitions ───────────────────────────────────────────── */
.view-panel{animation:fadeSlideIn .22s ease both}
@keyframes fadeSlideIn{
  from{opacity:0;transform:translateY(10px)}
  to  {opacity:1;transform:translateY(0)}
}
</style>
</head>
<body>
<div class="app-wrapper">
<?php include 'includes/nav.php'; ?>
<main class="main-content">

  <!-- ── Top header (shared) ─────────────────────────────────────── -->
  <header class="top-header">
    <button class="btn btn-sm btn-outline-secondary d-md-none"
            onclick="document.getElementById('sidebar').classList.toggle('open')">
      <i class="bi bi-list"></i>
    </button>
    <div>
      <div class="page-title" id="header-title">Team Overview</div>
      <div class="page-breadcrumb" id="header-sub">Direct involvement only — owned or assigned objectives</div>
    </div>
    <a href="dashboard.php" class="btn btn-sm btn-outline-secondary ms-auto">
      <i class="bi bi-arrow-left me-1"></i>Dashboard
    </a>
  </header>

  <div class="page-body">

    <?php if (empty($teamsData)): ?>
    <!-- ── Empty state ──────────────────────────────────────────── -->
    <div class="empty-state card"><div class="card-body">
      <i class="bi bi-people"></i>
      <h5>No Teammates Found</h5>
      <p class="small">You haven't been assigned to a team yet, or your team has no other members. Contact your administrator.</p>
    </div></div>

    <?php else: ?>

    <!-- ══════════════════════════════════════════════════════════════
         VIEW 1 — Team Summary Cards (default / landing view)
    ══════════════════════════════════════════════════════════════════ -->
    <div id="view-teams" class="view-panel">

      <p class="text-muted small mb-3">
        Select a team to view individual member progress.
      </p>

      <div class="row g-3">
      <?php foreach ($teamsData as $team):
        $tp  = $team['avg_progress'];
        $pbc = $tp >= 70 ? 'pb-on-track' : ($tp >= 40 ? 'pb-at-risk' : 'pb-behind');
        $atRiskTotal = $team['at_risk'] + $team['behind'];
      ?>
      <div class="col-md-6 col-xl-4">
        <div class="card team-card h-100" onclick="showTeam(<?= $team['id'] ?>, <?= htmlspecialchars(json_encode($team['name'])) ?>)">
          <div class="card-body">

            <!-- Team header -->
            <div class="d-flex align-items-center gap-3 mb-3">
              <div class="team-icon">
                <i class="bi bi-diagram-3-fill"></i>
              </div>
              <div class="flex-grow-1">
                <div class="fw-800" style="font-size:1rem"><?= htmlspecialchars($team['name']) ?></div>
                <div style="font-size:.75rem;color:var(--muted)">
                  <?= $team['member_count'] ?> member<?= $team['member_count'] !== 1 ? 's' : '' ?>
                  &nbsp;·&nbsp;
                  <?= $team['obj_total'] ?> objective<?= $team['obj_total'] !== 1 ? 's' : '' ?>
                </div>
              </div>
              <?php if ($atRiskTotal > 0): ?>
              <span class="status-badge status-at-risk flex-shrink-0">
                <i class="bi bi-exclamation-triangle"></i>
                <?= $atRiskTotal ?> at risk
              </span>
              <?php endif; ?>
              <div class="chevron ms-1">
                <i class="bi bi-chevron-right"></i>
              </div>
            </div>

            <!-- Team avg progress bar -->
            <div class="d-flex justify-content-between mb-1">
              <span style="font-size:.78rem;color:var(--muted);font-weight:600">Team Avg Progress</span>
              <span style="font-size:.82rem;font-weight:700"><?= $tp ?>%</span>
            </div>
            <div class="progress mb-3">
              <div class="progress-bar <?= $pbc ?>" style="width:<?= $tp ?>%"></div>
            </div>

            <!-- Stat counters -->
            <div class="row g-2">
              <div class="col-3">
                <div class="stat-mini" style="background:var(--success-lt);border-radius:8px">
                  <div class="val" style="color:var(--success)"><?= $team['on_track'] ?></div>
                  <div class="lbl">On Track</div>
                </div>
              </div>
              <div class="col-3">
                <div class="stat-mini" style="background:var(--warning-lt);border-radius:8px">
                  <div class="val" style="color:var(--warning)"><?= $team['at_risk'] ?></div>
                  <div class="lbl">At Risk</div>
                </div>
              </div>
              <div class="col-3">
                <div class="stat-mini" style="background:var(--danger-lt);border-radius:8px">
                  <div class="val" style="color:var(--danger)"><?= $team['behind'] ?></div>
                  <div class="lbl">Behind</div>
                </div>
              </div>
              <div class="col-3">
                <div class="stat-mini" style="background:#eff6ff;border-radius:8px">
                  <div class="val" style="color:#3b82f6"><?= $team['completed'] ?></div>
                  <div class="lbl">Done</div>
                </div>
              </div>
            </div>

            <!-- Member avatars preview -->
            <?php if (!empty($team['members'])): ?>
            <div class="d-flex align-items-center gap-1 mt-3 pt-2 border-top">
              <span style="font-size:.7rem;color:var(--muted);font-weight:600;margin-right:.25rem">Members</span>
              <div class="d-flex" style="gap:-4px">
                <?php foreach (array_slice($team['members'], 0, 5) as $m): ?>
                <div class="user-avatar"
                     title="<?= htmlspecialchars($m['full_name']) ?>"
                     style="background:<?= htmlspecialchars($m['avatar_color']) ?>;
                            width:26px;height:26px;font-size:.6rem;border-radius:50%;
                            border:2px solid #fff;margin-left:-4px;flex-shrink:0">
                  <?= initials($m['full_name']) ?>
                </div>
                <?php endforeach; ?>
                <?php if (count($team['members']) > 5): ?>
                <div style="width:26px;height:26px;border-radius:50%;border:2px solid #fff;
                            margin-left:-4px;background:var(--primary-lt);color:var(--primary);
                            font-size:.58rem;font-weight:800;display:flex;align-items:center;justify-content:center">
                  +<?= count($team['members']) - 5 ?>
                </div>
                <?php endif; ?>
              </div>
              <span class="ms-auto" style="font-size:.72rem;color:var(--primary);font-weight:700">
                View members <i class="bi bi-arrow-right"></i>
              </span>
            </div>
            <?php endif; ?>

          </div>
        </div>
      </div>
      <?php endforeach; ?>
      </div>

    </div><!-- /#view-teams -->


    <!-- ══════════════════════════════════════════════════════════════
         VIEW 2 — Member Cards (shown after clicking a team card)
    ══════════════════════════════════════════════════════════════════ -->
    <?php foreach ($teamsData as $team):
      $tp  = $team['avg_progress'];
      $pbc = $tp >= 70 ? 'pb-on-track' : ($tp >= 40 ? 'pb-at-risk' : 'pb-behind');
    ?>
    <div id="members-<?= $team['id'] ?>" class="view-panel" style="display:none">

      <!-- Back navigation + team mini-summary -->
      <div class="card mb-3">
        <div class="card-body py-3">

          <!-- Back button + team name -->
          <div class="d-flex align-items-center gap-3 mb-3">
            <button class="back-btn" onclick="showAllTeams()">
              <i class="bi bi-arrow-left-circle-fill" style="font-size:1.1rem"></i>
              All Teams
            </button>
            <div class="vr mx-1"></div>
            <div class="team-icon" style="width:36px;height:36px;border-radius:10px;
                 background:var(--primary-lt);display:flex;align-items:center;
                 justify-content:center;font-size:1rem;color:var(--primary)">
              <i class="bi bi-diagram-3-fill"></i>
            </div>
            <div>
              <div class="fw-800" style="font-size:.95rem"><?= htmlspecialchars($team['name']) ?></div>
              <div style="font-size:.72rem;color:var(--muted)"><?= $team['member_count'] ?> members · <?= $team['obj_total'] ?> objectives</div>
            </div>
          </div>

          <!-- Team aggregate stats row -->
          <div class="row g-3 text-center align-items-center">
            <div class="col-6 col-md-3 border-end">
              <div class="stat-mini">
                <div class="val" style="color:var(--primary)"><?= $team['avg_progress'] ?>%</div>
                <div class="lbl">Avg Progress</div>
              </div>
            </div>
            <div class="col-6 col-md-3 border-end">
              <div class="stat-mini">
                <div class="val" style="color:var(--success)"><?= $team['on_track'] ?></div>
                <div class="lbl">On Track</div>
              </div>
            </div>
            <div class="col-6 col-md-3 border-end">
              <div class="stat-mini">
                <div class="val" style="color:var(--warning)"><?= $team['at_risk'] ?></div>
                <div class="lbl">At Risk</div>
              </div>
            </div>
            <div class="col-6 col-md-3">
              <div class="stat-mini">
                <div class="val" style="color:#3b82f6"><?= $team['completed'] ?></div>
                <div class="lbl">Completed</div>
              </div>
            </div>
          </div>

          <!-- Team-wide progress bar -->
          <div class="mt-3 px-1">
            <div class="d-flex justify-content-between mb-1">
              <small class="text-muted fw-600">Team Overall Progress</small>
              <small class="fw-700"><?= $tp ?>%</small>
            </div>
            <div class="progress" style="height:8px">
              <div class="progress-bar <?= $pbc ?>" style="width:<?= $tp ?>%"></div>
            </div>
          </div>

        </div>
      </div>

      <!-- Member cards for this team -->
      <div class="row g-3">
      <?php foreach ($team['members'] as $m):
        $mid      = (int)$m['id'];
        $ini      = initials($m['full_name']);
        $prog     = round((float)$m['avg_progress'], 1);
        $objCount = (int)$m['obj_count'];
        $onT      = (int)$m['on_track_count'];
        $atR      = (int)$m['at_risk_count'];
        $beh      = (int)$m['behind_count'];
        $comp     = (int)$m['completed_count'];
        $notS     = (int)$m['not_started_count'];
        $pbc      = $prog >= 70 ? 'pb-on-track' : ($prog >= 40 ? 'pb-at-risk' : 'pb-behind');
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

            <!-- Stat counters -->
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

            <!-- Sub-stats -->
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
                  <?= substr($o['type'], 0, 1) ?>
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

    </div><!-- /#members-{id} -->
    <?php endforeach; ?>

    <?php endif; /* end empty check */ ?>
  </div><!-- /.page-body -->

</main>
</div><!-- /.app-wrapper -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
/**
 * Two-view navigation — no page reload.
 * showTeam()     → hide team cards, show member cards for chosen team
 * showAllTeams() → hide member view, return to team cards
 */
function showTeam(teamId, teamName) {
    // Hide team-summary view
    document.getElementById('view-teams').style.display = 'none';

    // Hide any previously-shown member section
    document.querySelectorAll('[id^="members-"]').forEach(el => el.style.display = 'none');

    // Show the target team's member section
    const target = document.getElementById('members-' + teamId);
    if (target) {
        target.style.display = 'block';
        // Trigger re-animation
        target.classList.remove('view-panel');
        void target.offsetWidth; // reflow
        target.classList.add('view-panel');
    }

    // Update page header to reflect current team
    document.getElementById('header-title').textContent = teamName;
    document.getElementById('header-sub').textContent   = 'Member progress · ' + teamName;
}

function showAllTeams() {
    // Hide all member sections
    document.querySelectorAll('[id^="members-"]').forEach(el => el.style.display = 'none');

    // Restore team-summary view with re-animation
    const teamsView = document.getElementById('view-teams');
    teamsView.classList.remove('view-panel');
    void teamsView.offsetWidth;
    teamsView.classList.add('view-panel');
    teamsView.style.display = 'block';

    // Restore page header
    document.getElementById('header-title').textContent = 'Team Overview';
    document.getElementById('header-sub').textContent   = 'Direct involvement only — owned or assigned objectives';
}
</script>
</body>
</html>
