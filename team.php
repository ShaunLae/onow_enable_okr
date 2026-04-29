<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';
requireLogin();
requireRole(['Admin','Manager']);
$user = getCurrentUser();
if ($user['role'] === 'Admin') { header('Location: team_management.php'); exit; }
$currentPage = 'team';
$userId      = $user['id'];
$db          = getDB();

// ── All teams the manager belongs to ────────────────────────────
$myTeams = getUserTeams($userId);

// Active team filter — default to first team, or URL param
$activeTeamId = isset($_GET['team_id']) ? (int)$_GET['team_id'] : 0;
if ($activeTeamId === 0 && !empty($myTeams)) {
    $activeTeamId = (int)$myTeams[0]['id'];
}
// Validate the selected team actually belongs to this manager
$validTeamIds = array_column($myTeams, 'id');
if (!in_array($activeTeamId, $validTeamIds)) {
    $activeTeamId = !empty($validTeamIds) ? (int)$validTeamIds[0] : 0;
}

// ── Members of the active team (excluding self and Admins) ───────
$activeTeamMembers = $activeTeamId
    ? getTeamMembers($activeTeamId)
    : [];
// Remove Admins and self
$activeTeamMembers = array_values(array_filter(
    $activeTeamMembers,
    fn($m) => $m['role'] !== 'Admin' && (int)$m['id'] !== $userId
));

// ══════════════════════════════════════════════════════════════════
// Team-scoped health query — direct involvement only, scoped to
// the ACTIVE TEAM so each member shows only objectives tied to
// this specific team (team_id = activeTeamId OR assigned via
// objective_members on a team objective).
//
// Matches dashboard MG-Q1 logic exactly:
//   owner_id = u.id  OR  EXISTS in objective_members
// PLUS: objective must be tied to this team
//   (o.team_id = :tid  OR  o.type = 'Personal' and owner = u.id)
//
// Rule:
//  - Team objectives for this team        → always counted
//  - Personal objectives owned by member  → counted (personal work)
//  - Organisational objectives            → excluded (not team-scoped)
//  - Team objectives for OTHER teams      → excluded (different scope)
// ══════════════════════════════════════════════════════════════════
$teamHealth = [];
if ($activeTeamId && !empty($activeTeamMembers)) {
    $memberIds = array_column($activeTeamMembers, 'id');
    $placeholders = implode(',', array_fill(0, count($memberIds), '?'));

    $stmt = $db->prepare("
        SELECT u.id, u.full_name, u.avatar_color, u.role,
               u.job_title, u.department, u.email,
               COUNT(DISTINCT o.id)                                                 AS obj_count,
               COALESCE(AVG(o.progress), 0)                                        AS avg_progress,
               SUM(CASE WHEN o.status = 'On Track'               THEN 1 ELSE 0 END) AS on_track_count,
               SUM(CASE WHEN o.status = 'At Risk'                THEN 1 ELSE 0 END) AS at_risk_count,
               SUM(CASE WHEN o.status = 'Behind'                 THEN 1 ELSE 0 END) AS behind_count,
               SUM(CASE WHEN o.status = 'Completed'              THEN 1 ELSE 0 END) AS completed_count,
               SUM(CASE WHEN o.status = 'Not Started'            THEN 1 ELSE 0 END) AS not_started_count
        FROM   users u
        -- Only members of the active team
        JOIN   team_members tm ON u.id = tm.user_id AND tm.team_id = ?
        LEFT   JOIN objectives o ON (
                   -- Direct involvement: they own it or are assigned
                   (
                       o.owner_id = u.id
                    OR EXISTS (
                           SELECT 1 FROM objective_members om2
                           WHERE  om2.objective_id = o.id AND om2.user_id = u.id
                       )
                   )
                   -- Team-scoped: must belong to this team OR be a personal objective
                   AND (
                       o.team_id = ?
                    OR (o.type = 'Personal' AND o.owner_id = u.id)
                   )
                   AND o.deleted_at IS NULL
               )
        WHERE  u.id    IN ($placeholders)
          AND  u.role  != 'Admin'
          AND  u.is_active = 1
        GROUP  BY u.id, u.full_name, u.avatar_color, u.role, u.job_title, u.department, u.email
        ORDER  BY avg_progress ASC
    ");
    $params = array_merge([$activeTeamId, $activeTeamId], $memberIds);
    $stmt->execute($params);
    $teamHealth = $stmt->fetchAll();
}

// ── Team summary aggregates ──────────────────────────────────────
$teamStats = [
    'members'      => count($teamHealth),
    'obj_total'    => 0,
    'avg_progress' => 0,
    'on_track'     => 0,
    'at_risk'      => 0,
    'behind'       => 0,
    'completed'    => 0,
    'not_started'  => 0,
];
if (!empty($teamHealth)) {
    $teamStats['obj_total']    = array_sum(array_column($teamHealth, 'obj_count'));
    $teamStats['on_track']     = array_sum(array_column($teamHealth, 'on_track_count'));
    $teamStats['at_risk']      = array_sum(array_column($teamHealth, 'at_risk_count'));
    $teamStats['behind']       = array_sum(array_column($teamHealth, 'behind_count'));
    $teamStats['completed']    = array_sum(array_column($teamHealth, 'completed_count'));
    $teamStats['not_started']  = array_sum(array_column($teamHealth, 'not_started_count'));
    $progSum = array_sum(array_column($teamHealth, 'avg_progress'));
    $teamStats['avg_progress'] = round($progSum / count($teamHealth), 1);
}

// ── Per-member team-scoped objectives (for detail view) ──────────
// Fetches full objective rows for the active team + member,
// scoped identically to the health query above.
$memberObjsMap = [];
if ($activeTeamId && !empty($teamHealth)) {
    foreach ($teamHealth as $m) {
        $mid = (int)$m['id'];
        $stmt2 = $db->prepare("
            SELECT o.id, o.title, o.type, o.status, o.progress,
                   o.time_period, o.start_date, o.end_date,
                   o.owner_id, o.team_id,
                   u.full_name AS owner_name,
                   t.name      AS team_name
            FROM   objectives o
            LEFT   JOIN users u ON o.owner_id = u.id
            LEFT   JOIN teams t ON o.team_id  = t.id
            LEFT   JOIN objective_members om ON o.id = om.objective_id AND om.user_id = ?
            WHERE  o.deleted_at IS NULL
              AND  (o.owner_id = ? OR om.user_id = ?)
              AND  (o.team_id = ? OR (o.type = 'Personal' AND o.owner_id = ?))
            GROUP  BY o.id
            ORDER  BY o.status, o.progress ASC
        ");
        $stmt2->execute([$mid, $mid, $mid, $activeTeamId, $mid]);
        $memberObjsMap[$mid] = $stmt2->fetchAll();
    }
}

// ── Helpers ──────────────────────────────────────────────────────
function pbClass(string $s): string {
    return match($s) {
        'On Track'          => 'pb-on-track',
        'Completed'         => 'pb-completed',
        'At Risk', 'Behind' => 'pb-at-risk',
        default             => ''
    };
}

$activeTeam = getTeam($activeTeamId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Team Overview – ONOW Enable OKR</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="css/style.css" rel="stylesheet">
<style>
/* ── Team tabs ── */
.team-tab-bar{display:flex;gap:.35rem;flex-wrap:wrap;padding:.75rem 1rem;background:var(--surface);border-bottom:1px solid var(--border)}
.team-tab{display:flex;align-items:center;gap:.5rem;padding:.4rem .85rem;border-radius:20px;font-size:.82rem;font-weight:700;border:1.5px solid var(--border);background:var(--surface);color:var(--muted);cursor:pointer;transition:all .15s;text-decoration:none}
.team-tab:hover{border-color:var(--primary);color:var(--primary);background:var(--primary-lt)}
.team-tab.active{border-color:var(--primary);background:var(--primary);color:#fff}
.team-tab .badge-count{background:rgba(255,255,255,.25);border-radius:10px;padding:.05rem .4rem;font-size:.72rem}
.team-tab:not(.active) .badge-count{background:var(--border);color:var(--muted)}

/* ── Summary bar ── */
.summary-bar{background:var(--surface);border-bottom:1px solid var(--border);padding:.75rem 1.5rem}
.s-stat{text-align:center;padding:.25rem .5rem}
.s-stat .val{font-size:1.5rem;font-weight:800;line-height:1.1}
.s-stat .lbl{font-size:.68rem;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-top:.1rem}

/* ── Member cards ── */
.member-card{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius);padding:1.25rem;cursor:pointer;transition:all .18s;box-shadow:var(--shadow)}
.member-card:hover{border-color:var(--primary);box-shadow:0 0 0 3px rgba(37,99,235,.1);transform:translateY(-2px)}
.stat-quad{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:.4rem;margin:.75rem 0}
.stat-q{text-align:center;padding:.4rem .2rem;border-radius:8px}
.stat-q .v{font-size:1.2rem;font-weight:800;line-height:1}
.stat-q .l{font-size:.65rem;font-weight:600;text-transform:uppercase;letter-spacing:.3px;margin-top:.15rem;color:var(--muted)}

/* ── Detail panel (View 2) ── */
.detail-view{position:fixed;top:0;right:0;bottom:0;width:600px;background:var(--surface);border-left:1px solid var(--border);z-index:600;display:flex;flex-direction:column;transform:translateX(100%);transition:transform .28s cubic-bezier(.4,0,.2,1);box-shadow:-12px 0 40px rgba(0,0,0,.1)}
.detail-view.open{transform:translateX(0)}
.detail-overlay{position:fixed;inset:0;background:rgba(0,0,0,.25);z-index:599;display:none}
.detail-overlay.open{display:block}
.detail-view-header{padding:1rem 1.25rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.75rem;flex-shrink:0}
.detail-view-tabs{display:flex;gap:0;border-bottom:1px solid var(--border);flex-shrink:0;background:var(--surface2)}
.dv-tab{flex:1;text-align:center;padding:.6rem 1rem;font-size:.83rem;font-weight:700;color:var(--muted);border:none;background:none;cursor:pointer;border-bottom:2px solid transparent;transition:.15s}
.dv-tab.active{color:var(--primary);border-bottom-color:var(--primary);background:var(--surface)}
.detail-view-body{flex:1;overflow-y:auto;padding:1.25rem}
.dv-section{margin-bottom:1.5rem}
.dv-section-title{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);margin-bottom:.6rem;padding-bottom:.35rem;border-bottom:1px solid var(--border)}
.obj-row{border:1px solid var(--border);border-radius:10px;padding:.85rem 1rem;margin-bottom:.5rem;background:var(--surface2);transition:border-color .15s}
.obj-row:hover{border-color:var(--primary)}
.kr-row{display:flex;align-items:center;gap:.6rem;padding:.45rem 0;border-bottom:1px solid var(--border)}
.kr-row:last-child{border-bottom:none}
.ph-row{display:flex;gap:.6rem;padding:.4rem 0;font-size:.8rem;border-bottom:1px solid var(--border)}
.ph-row:last-child{border-bottom:none}

/* ── Responsive ── */
@media(max-width:640px){.detail-view{width:100%}}
</style>
</head>
<body>
<div class="app-wrapper">
<?php include 'includes/nav.php'; ?>
<main class="main-content">

  <!-- ── Header ── -->
  <header class="top-header">
    <button class="btn btn-sm btn-outline-secondary d-md-none"
            onclick="document.getElementById('sidebar').classList.toggle('open')">
      <i class="bi bi-list"></i>
    </button>
    <div>
      <div class="page-title">Team Overview</div>
      <div class="page-breadcrumb">
        <?= $activeTeam ? htmlspecialchars($activeTeam['name']) : 'No team selected' ?>
        — team-scoped objectives only
      </div>
    </div>
  </header>

  <?php if (empty($myTeams)): ?>
  <!-- No teams -->
  <div class="page-body">
    <div class="empty-state card"><div class="card-body">
      <i class="bi bi-diagram-3"></i>
      <h5>Not Assigned to Any Team</h5>
      <p class="small">Ask your administrator to assign you to a team.</p>
    </div></div>
  </div>

  <?php else: ?>

  <!-- ── View 1: Team Summary ── -->
  <div id="view-summary">

    <!-- Team tab bar -->
    <div class="team-tab-bar no-print">
      <?php foreach ($myTeams as $t):
        $tCount = count(getTeamMembers($t['id']));
        $isActive = (int)$t['id'] === $activeTeamId;
      ?>
      <a href="team.php?team_id=<?= $t['id'] ?>"
         class="team-tab <?= $isActive ? 'active' : '' ?>">
        <i class="bi bi-diagram-3"></i>
        <?= htmlspecialchars($t['name']) ?>
        <span class="badge-count"><?= $tCount ?></span>
      </a>
      <?php endforeach; ?>
    </div>

    <?php if (empty($teamHealth)): ?>
    <div class="page-body">
      <div class="empty-state card"><div class="card-body">
        <i class="bi bi-people"></i>
        <h5>No Members in This Team</h5>
        <p class="small">Ask your administrator to assign members to
          <?= htmlspecialchars($activeTeam['name'] ?? 'this team') ?>.</p>
      </div></div>
    </div>

    <?php else: ?>

    <!-- ── Team summary stats bar ── -->
    <div class="summary-bar">
      <div class="row g-2 align-items-center">
        <div class="col-auto">
          <div class="s-stat">
            <div class="val" style="color:var(--primary)"><?= $teamStats['members'] ?></div>
            <div class="lbl">Members</div>
          </div>
        </div>
        <div class="col-auto">
          <div style="width:1px;height:36px;background:var(--border)"></div>
        </div>
        <div class="col-auto">
          <div class="s-stat">
            <div class="val" style="color:var(--text2)"><?= $teamStats['obj_total'] ?></div>
            <div class="lbl">Objectives</div>
          </div>
        </div>
        <div class="col-auto">
          <div style="width:1px;height:36px;background:var(--border)"></div>
        </div>
        <div class="col-auto">
          <div class="s-stat">
            <div class="val" style="color:var(--success)"><?= $teamStats['on_track'] ?></div>
            <div class="lbl">On Track</div>
          </div>
        </div>
        <div class="col-auto">
          <div class="s-stat">
            <div class="val" style="color:var(--warning)"><?= $teamStats['at_risk'] ?></div>
            <div class="lbl">At Risk</div>
          </div>
        </div>
        <div class="col-auto">
          <div class="s-stat">
            <div class="val" style="color:var(--danger)"><?= $teamStats['behind'] ?></div>
            <div class="lbl">Behind</div>
          </div>
        </div>
        <div class="col-auto">
          <div class="s-stat">
            <div class="val" style="color:#3b82f6"><?= $teamStats['completed'] ?></div>
            <div class="lbl">Completed</div>
          </div>
        </div>
        <!-- Team overall progress bar -->
        <div class="col flex-grow-1">
          <div class="d-flex justify-content-between mb-1">
            <small class="text-muted fw-600">Team Progress</small>
            <small class="fw-700"><?= $teamStats['avg_progress'] ?>%</small>
          </div>
          <div class="progress" style="height:8px">
            <div class="progress-bar <?= $teamStats['avg_progress']>=70?'pb-on-track':($teamStats['avg_progress']>=40?'pb-at-risk':'pb-behind') ?>"
                 style="width:<?= $teamStats['avg_progress'] ?>%"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Member cards grid ── -->
    <div class="page-body">
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
        $recentObjs = array_slice($memberObjsMap[$mid] ?? [], 0, 3);
        // Encode member data for JS detail view
        $mData = json_encode([
            'id'         => $mid,
            'name'       => $m['full_name'],
            'avatar'     => $m['avatar_color'],
            'role'       => $m['role'],
            'job_title'  => $m['job_title'] ?? '',
            'department' => $m['department'] ?? '',
            'email'      => $m['email'] ?? '',
            'prog'       => $prog,
            'obj_count'  => $objCount,
            'on_track'   => $onT,
            'at_risk'    => $atR,
            'behind'     => $beh,
            'completed'  => $comp,
            'not_started'=> $notS,
        ]);
      ?>
      <div class="col-md-6 col-xl-4">
        <div class="member-card" onclick='openMemberDetail(<?= htmlspecialchars($mData, ENT_QUOTES) ?>)'>

          <!-- Identity row -->
          <div class="d-flex align-items-center gap-3 mb-3">
            <div class="user-avatar"
                 style="background:<?= htmlspecialchars($m['avatar_color']) ?>;width:46px;height:46px;font-size:.95rem;border-radius:14px;flex-shrink:0">
              <?= $ini ?>
            </div>
            <div class="flex-grow-1">
              <div class="fw-800" style="font-size:.93rem"><?= htmlspecialchars($m['full_name']) ?></div>
              <div style="font-size:.74rem;color:var(--muted)">
                <?= htmlspecialchars($m['job_title'] ?? ($m['department'] ?? '—')) ?>
              </div>
              <span class="role-badge role-<?= strtolower($m['role']) ?>"><?= $m['role'] ?></span>
            </div>
            <?php if ($atR + $beh > 0): ?>
            <span class="status-badge status-at-risk flex-shrink-0" style="font-size:.68rem">
              <i class="bi bi-exclamation-triangle"></i> <?= $atR + $beh ?>
            </span>
            <?php endif; ?>
            <i class="bi bi-chevron-right text-muted ms-1" style="font-size:.8rem"></i>
          </div>

          <!-- Progress bar -->
          <div class="d-flex justify-content-between mb-1">
            <span style="font-size:.75rem;color:var(--muted);font-weight:600">Overall Progress</span>
            <span style="font-size:.8rem;font-weight:700"><?= $prog ?>%</span>
          </div>
          <div class="progress mb-2" style="height:6px">
            <div class="progress-bar <?= $pbc ?>" style="width:<?= $prog ?>%"></div>
          </div>

          <!-- Stat quad — matches dashboard exactly -->
          <div class="stat-quad">
            <div class="stat-q" style="background:var(--primary-lt)">
              <div class="v" style="color:var(--primary)"><?= $objCount ?></div>
              <div class="l">Obj</div>
            </div>
            <div class="stat-q" style="background:var(--success-lt)">
              <div class="v" style="color:var(--success)"><?= $onT ?></div>
              <div class="l">Track</div>
            </div>
            <div class="stat-q" style="background:var(--warning-lt)">
              <div class="v" style="color:var(--warning)"><?= $atR ?></div>
              <div class="l">Risk</div>
            </div>
            <div class="stat-q" style="background:var(--danger-lt)">
              <div class="v" style="color:var(--danger)"><?= $beh ?></div>
              <div class="l">Behind</div>
            </div>
          </div>

          <!-- Sub-stats -->
          <div class="d-flex gap-3" style="font-size:.73rem;color:var(--muted)">
            <span><i class="bi bi-check-circle me-1" style="color:#3b82f6"></i><?= $comp ?> completed</span>
            <span><i class="bi bi-dash-circle me-1"></i><?= $notS ?> not started</span>
          </div>

          <!-- Recent objectives preview -->
          <?php if (!empty($recentObjs)): ?>
          <div class="mt-2 pt-2 border-top">
            <?php foreach ($recentObjs as $o): ?>
            <div class="d-flex align-items-center gap-2 mb-1">
              <span class="type-badge type-<?= strtolower($o['type']) ?>" style="font-size:.6rem;padding:.1rem .35rem;flex-shrink:0">
                <?= substr($o['type'],0,1) ?>
              </span>
              <span style="font-size:.76rem;font-weight:600;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                <?= htmlspecialchars($o['title']) ?>
              </span>
              <span class="status-badge <?= statusClass($o['status']) ?>" style="font-size:.65rem;padding:.1rem .4rem;flex-shrink:0">
                <?= round($o['progress']) ?>%
              </span>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

        </div>
      </div>
      <?php endforeach; ?>
      </div>
    </div><!-- /page-body -->

    <?php endif; /* empty teamHealth */ ?>
  </div><!-- /#view-summary -->

  <?php endif; /* empty myTeams */ ?>

</main>
</div>

<!-- ══════════════════════════════════════════════
     VIEW 2: Member Detail Panel (slide-in)
══════════════════════════════════════════════ -->
<div class="detail-overlay" id="dvOverlay" onclick="closeMemberDetail()"></div>
<div class="detail-view" id="detailView">

  <!-- Header -->
  <div class="detail-view-header">
    <button class="btn btn-sm btn-outline-secondary btn-icon" onclick="closeMemberDetail()">
      <i class="bi bi-x-lg"></i>
    </button>
    <div class="user-avatar" id="dvAvatar"
         style="width:38px;height:38px;font-size:.85rem;border-radius:12px;flex-shrink:0"></div>
    <div class="flex-grow-1">
      <div id="dvName" style="font-size:.95rem;font-weight:800"></div>
      <div id="dvMeta" style="font-size:.75rem;color:var(--muted)"></div>
    </div>
    <div id="dvStatBadge"></div>
  </div>

  <!-- Sub-tabs -->
  <div class="detail-view-tabs">
    <button class="dv-tab active" onclick="switchDVTab('objectives',this)">
      <i class="bi bi-bullseye me-1"></i>Objectives
    </button>
    <button class="dv-tab" onclick="switchDVTab('krs',this)">
      <i class="bi bi-check2-square me-1"></i>Key Results
    </button>
    <button class="dv-tab" onclick="switchDVTab('activity',this)">
      <i class="bi bi-graph-up me-1"></i>Activity
    </button>
  </div>

  <!-- Body -->
  <div class="detail-view-body" id="dvBody">
    <div class="text-center py-5 text-muted">
      <div class="spinner-border spinner-border-sm"></div>
    </div>
  </div>
</div>

<!-- ── PHP data: pass team-scoped objectives to JS ── -->
<script>
const TEAM_ID   = <?= $activeTeamId ?>;
const TEAM_NAME = <?= json_encode($activeTeam['name'] ?? '') ?>;
const MEMBER_OBJECTIVES = <?= json_encode(
    array_map(
        fn($mid) => array_map(fn($o) => [
            'id'          => (int)$o['id'],
            'title'       => $o['title'],
            'type'        => $o['type'],
            'status'      => $o['status'],
            'progress'    => round((float)$o['progress'], 1),
            'time_period' => $o['time_period'],
            'start_date'  => $o['start_date'],
            'end_date'    => $o['end_date'],
            'owner_name'  => $o['owner_name'],
            'team_name'   => $o['team_name'],
        ], $memberObjsMap[(int)$mid] ?? []),
        array_column($teamHealth, 'id')
    ),
    JSON_THROW_ON_ERROR
) ?>;

// Map member id → objectives array
const memberObjMap = {};
<?php foreach ($teamHealth as $m): ?>
memberObjMap[<?= $m['id'] ?>] = MEMBER_OBJECTIVES[<?= array_search($m['id'], array_column($teamHealth,'id')) ?>] || [];
<?php endforeach; ?>

// Current member shown in detail view
let currentMember = null;
let currentDVTab  = 'objectives';

/* ── Status helpers ──────────────────────────────── */
const STATUS_CLASS = {
    'On Track':  'status-on-track',
    'At Risk':   'status-at-risk',
    'Behind':    'status-behind',
    'Completed': 'status-completed',
    'Not Started':'status-not-started',
};
const PB_CLASS = {
    'On Track':  'pb-on-track',
    'Completed': 'pb-completed',
    'At Risk':   'pb-at-risk',
    'Behind':    'pb-at-risk',
};
function sClass(s) { return STATUS_CLASS[s] || 'status-not-started'; }
function pbCls(s)  { return PB_CLASS[s]     || ''; }

/* ── Open/close detail panel ─────────────────────── */
function openMemberDetail(member) {
    currentMember = member;
    currentDVTab  = 'objectives';

    // Header
    const ini = member.name.trim().split(' ').map(w=>w[0]).join('').slice(0,2).toUpperCase();
    document.getElementById('dvAvatar').textContent  = ini;
    document.getElementById('dvAvatar').style.background = member.avatar;
    document.getElementById('dvName').textContent    = member.name;
    document.getElementById('dvMeta').textContent    =
        [member.job_title, member.department].filter(Boolean).join(' · ')
        || member.email || member.role;

    // At-risk badge
    const badgeEl = document.getElementById('dvStatBadge');
    const needsAttn = (member.at_risk || 0) + (member.behind || 0);
    badgeEl.innerHTML = needsAttn > 0
        ? `<span class="status-badge status-at-risk"><i class="bi bi-exclamation-triangle"></i> ${needsAttn} at risk</span>`
        : `<span class="status-badge status-completed"><i class="bi bi-check-circle"></i> All good</span>`;

    // Reset to objectives tab
    document.querySelectorAll('.dv-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.dv-tab')[0].classList.add('active');

    renderDVObjectives();
    document.getElementById('detailView').classList.add('open');
    document.getElementById('dvOverlay').classList.add('open');
}

function closeMemberDetail() {
    document.getElementById('detailView').classList.remove('open');
    document.getElementById('dvOverlay').classList.remove('open');
    currentMember = null;
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeMemberDetail(); });

/* ── Tab switching ───────────────────────────────── */
function switchDVTab(tab, btn) {
    currentDVTab = tab;
    document.querySelectorAll('.dv-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    if (tab === 'objectives') renderDVObjectives();
    if (tab === 'krs')        renderDVKeyResults();
    if (tab === 'activity')   renderDVActivity();
}

/* ── VIEW 2 TAB 1: Objectives ────────────────────── */
function renderDVObjectives() {
    const objs = memberObjMap[currentMember.id] || [];
    const body = document.getElementById('dvBody');

    if (!objs.length) {
        body.innerHTML = `<div class="empty-state py-4">
            <i class="bi bi-bullseye" style="font-size:2rem;opacity:.3"></i>
            <h5 class="mt-2">No Objectives</h5>
            <p class="small text-muted">No team-scoped objectives found for ${TEAM_NAME}.</p>
        </div>`;
        return;
    }

    // Summary mini-stats at top of detail
    const m = currentMember;
    const html = [`
        <div class="dv-section">
          <div class="d-flex gap-2 mb-3 flex-wrap">
            <div style="flex:1;min-width:80px;background:var(--primary-lt);border-radius:10px;padding:.6rem;text-align:center">
              <div style="font-size:1.4rem;font-weight:800;color:var(--primary)">${m.obj_count}</div>
              <div style="font-size:.68rem;color:var(--muted);font-weight:600;text-transform:uppercase">Objectives</div>
            </div>
            <div style="flex:1;min-width:80px;background:var(--success-lt);border-radius:10px;padding:.6rem;text-align:center">
              <div style="font-size:1.4rem;font-weight:800;color:var(--success)">${m.on_track}</div>
              <div style="font-size:.68rem;color:var(--muted);font-weight:600;text-transform:uppercase">On Track</div>
            </div>
            <div style="flex:1;min-width:80px;background:var(--warning-lt);border-radius:10px;padding:.6rem;text-align:center">
              <div style="font-size:1.4rem;font-weight:800;color:var(--warning)">${m.at_risk}</div>
              <div style="font-size:.68rem;color:var(--muted);font-weight:600;text-transform:uppercase">At Risk</div>
            </div>
            <div style="flex:1;min-width:80px;background:var(--danger-lt);border-radius:10px;padding:.6rem;text-align:center">
              <div style="font-size:1.4rem;font-weight:800;color:var(--danger)">${m.behind}</div>
              <div style="font-size:.68rem;color:var(--muted);font-weight:600;text-transform:uppercase">Behind</div>
            </div>
          </div>
          <!-- Overall progress -->
          <div class="d-flex justify-content-between mb-1">
            <small class="text-muted fw-600">Overall Progress</small>
            <small class="fw-700">${m.prog}%</small>
          </div>
          <div class="progress mb-3" style="height:8px">
            <div class="progress-bar ${m.prog>=70?'pb-on-track':m.prog>=40?'pb-at-risk':'pb-behind'}"
                 style="width:${m.prog}%"></div>
          </div>
        </div>
        <div class="dv-section">
          <div class="dv-section-title">Objectives — ${TEAM_NAME}</div>
    `];

    objs.forEach(o => {
        const pct = parseFloat(o.progress);
        const dates = [o.start_date, o.end_date].filter(Boolean)
            .map(d => new Date(d).toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'}))
            .join(' → ');
        html.push(`
            <div class="obj-row" onclick="loadObjKRs(${o.id},this)" style="cursor:pointer">
              <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                <div class="flex-grow-1">
                  <div class="d-flex gap-1 mb-1 flex-wrap">
                    <span class="type-badge type-${o.type.toLowerCase()}">${o.type}</span>
                    ${o.team_name?`<span class="team-chip"><i class="bi bi-diagram-3 me-1"></i>${o.team_name}</span>`:''}
                  </div>
                  <div style="font-size:.88rem;font-weight:700">${escHtml(o.title)}</div>
                  <div style="font-size:.74rem;color:var(--muted);margin-top:.15rem">
                    ${o.time_period}${dates?' · '+dates:''}
                  </div>
                </div>
                <span class="status-badge ${sClass(o.status)} flex-shrink-0">${o.status}</span>
              </div>
              <div class="d-flex align-items-center gap-2">
                <div class="progress flex-grow-1" style="height:5px">
                  <div class="progress-bar ${pbCls(o.status)}" style="width:${pct}%"></div>
                </div>
                <span style="font-size:.78rem;font-weight:700">${Math.round(pct)}%</span>
              </div>
              <!-- KR sub-list loaded on click -->
              <div class="kr-sublist mt-2" id="krsub-${o.id}" style="display:none"></div>
            </div>
        `);
    });

    html.push('</div>');
    body.innerHTML = html.join('');
}

/* ── Load KRs for an objective (inline expand) ───── */
function loadObjKRs(objId, rowEl) {
    const sub = rowEl.querySelector('.kr-sublist');
    if (!sub) return;
    if (sub.style.display !== 'none') { sub.style.display = 'none'; return; }
    sub.style.display = 'block';
    sub.innerHTML = '<div class="text-muted text-center py-2 small"><div class="spinner-border spinner-border-sm"></div></div>';
    fetch(`php/kr_api.php?objective_id=${objId}`)
        .then(r => r.json())
        .then(krs => {
            if (!krs.length) {
                sub.innerHTML = '<div class="text-muted small py-1">No key results.</div>';
                return;
            }
            sub.innerHTML = krs.map(kr => {
                const p = parseFloat(kr.progress);
                return `<div class="kr-row">
                  <div class="flex-grow-1">
                    <div style="font-size:.82rem;font-weight:700">${escHtml(kr.title)}</div>
                    <div style="font-size:.73rem;color:var(--muted)">
                      ${parseFloat(kr.current_value).toFixed(1)} / ${parseFloat(kr.target_value).toFixed(1)}
                      ${kr.unit||''} · ${kr.metric_type}
                      · <span>Owner: ${escHtml(kr.owner_name)}</span>
                    </div>
                    <div class="d-flex align-items-center gap-2 mt-1">
                      <div class="progress flex-grow-1" style="height:4px">
                        <div class="progress-bar ${pbCls(kr.status)}" style="width:${p}%"></div>
                      </div>
                      <span style="font-size:.72rem;font-weight:700">${Math.round(p)}%</span>
                    </div>
                  </div>
                  <span class="status-badge ${sClass(kr.status)} flex-shrink-0" style="font-size:.65rem">${kr.status}</span>
                </div>`;
            }).join('');
        })
        .catch(() => { sub.innerHTML = '<div class="text-muted small py-1">Failed to load.</div>'; });
}

/* ── VIEW 2 TAB 2: All Key Results ──────────────── */
function renderDVKeyResults() {
    const objs = memberObjMap[currentMember.id] || [];
    const body = document.getElementById('dvBody');
    if (!objs.length) {
        body.innerHTML = '<div class="empty-state py-4"><i class="bi bi-check2-square" style="font-size:2rem;opacity:.3"></i><h5 class="mt-2">No Key Results</h5></div>';
        return;
    }
    body.innerHTML = '<div class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm"></div><p class="mt-2 small">Loading key results…</p></div>';

    // Fetch KRs for all objectives in parallel
    const fetches = objs.map(o =>
        fetch(`php/kr_api.php?objective_id=${o.id}`)
            .then(r => r.json())
            .then(krs => ({ obj: o, krs }))
    );
    Promise.all(fetches).then(results => {
        const sections = results
            .filter(r => r.krs.length > 0)
            .map(({ obj, krs }) => {
                const krRows = krs.map(kr => {
                    const p = parseFloat(kr.progress);
                    return `<div class="kr-row">
                      <div class="flex-grow-1">
                        <div style="font-size:.83rem;font-weight:700">${escHtml(kr.title)}</div>
                        <div style="font-size:.73rem;color:var(--muted)">
                          ${parseFloat(kr.current_value).toFixed(1)} / ${parseFloat(kr.target_value).toFixed(1)}
                          ${kr.unit||''} · Owner: ${escHtml(kr.owner_name)}
                        </div>
                        <div class="d-flex align-items-center gap-2 mt-1">
                          <div class="progress flex-grow-1" style="height:4px">
                            <div class="progress-bar ${pbCls(kr.status)}" style="width:${p}%"></div>
                          </div>
                          <span style="font-size:.72rem;font-weight:700">${Math.round(p)}%</span>
                        </div>
                      </div>
                      <span class="status-badge ${sClass(kr.status)} flex-shrink-0" style="font-size:.65rem">${kr.status}</span>
                    </div>`;
                }).join('');
                return `<div class="dv-section">
                  <div class="dv-section-title">
                    <span class="type-badge type-${obj.type.toLowerCase()} me-1">${obj.type[0]}</span>
                    ${escHtml(obj.title)}
                  </div>
                  ${krRows}
                </div>`;
            });
        if (!sections.length) {
            body.innerHTML = '<div class="empty-state py-4"><i class="bi bi-check2-square" style="font-size:2rem;opacity:.3"></i><h5 class="mt-2">No Key Results Yet</h5></div>';
        } else {
            body.innerHTML = sections.join('');
        }
    });
}

/* ── VIEW 2 TAB 3: Activity (progress history) ───── */
function renderDVActivity() {
    const objs = memberObjMap[currentMember.id] || [];
    const body = document.getElementById('dvBody');
    if (!objs.length) {
        body.innerHTML = '<div class="empty-state py-4"><i class="bi bi-graph-up" style="font-size:2rem;opacity:.3"></i><h5 class="mt-2">No Activity</h5></div>';
        return;
    }
    body.innerHTML = '<div class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm"></div><p class="mt-2 small">Loading activity…</p></div>';

    // Fetch KRs first, then history for each KR
    const krFetches = objs.map(o =>
        fetch(`php/kr_api.php?objective_id=${o.id}`)
            .then(r => r.json())
            .then(krs => krs.map(kr => ({ ...kr, objTitle: o.title })))
    );
    Promise.all(krFetches).then(nestedKRs => {
        const allKRs = nestedKRs.flat();
        if (!allKRs.length) {
            body.innerHTML = '<div class="empty-state py-4"><i class="bi bi-graph-up" style="font-size:2rem;opacity:.3"></i><h5 class="mt-2">No Activity Yet</h5></div>';
            return;
        }
        const histFetches = allKRs.map(kr =>
            fetch(`php/kr_api.php?history=1&kr_id=${kr.id}`)
                .then(r => r.json())
                .then(hist => hist.map(h => ({ ...h, krTitle: kr.title, objTitle: kr.objTitle })))
        );
        Promise.all(histFetches).then(nestedHist => {
            const allHist = nestedHist.flat()
                .sort((a, b) => new Date(b.created_at) - new Date(a.created_at))
                .slice(0, 30);
            if (!allHist.length) {
                body.innerHTML = '<div class="empty-state py-4"><i class="bi bi-graph-up" style="font-size:2rem;opacity:.3"></i><h5 class="mt-2">No Progress Updates Yet</h5></div>';
                return;
            }
            const rows = allHist.map(h => {
                const diff = parseFloat(h.new_value) - parseFloat(h.previous_value);
                const col  = diff >= 0 ? 'var(--success)' : 'var(--danger)';
                const dt   = new Date(h.created_at).toLocaleDateString('en-GB',
                    {day:'numeric',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});
                return `<div class="ph-row">
                  <div style="width:8px;height:8px;border-radius:50%;background:var(--primary);flex-shrink:0;margin-top:.35rem"></div>
                  <div class="flex-grow-1">
                    <div style="font-size:.83rem;font-weight:700">${escHtml(h.krTitle)}</div>
                    <div style="font-size:.73rem;color:var(--muted)">${escHtml(h.objTitle)}</div>
                    <div class="d-flex gap-3 mt-1">
                      <span style="font-size:.75rem;font-weight:700;color:${col}">${diff>=0?'+':''}${diff.toFixed(1)}</span>
                      <span style="font-size:.73rem;color:var(--muted)">${parseFloat(h.previous_value).toFixed(1)} → ${parseFloat(h.new_value).toFixed(1)}</span>
                      ${h.note?`<span style="font-size:.73rem;color:var(--muted);font-style:italic">"${escHtml(h.note)}"</span>`:''}
                    </div>
                    <div style="font-size:.71rem;color:var(--muted)">${dt} · by ${escHtml(h.updated_by_name||'—')}</div>
                  </div>
                </div>`;
            }).join('');
            body.innerHTML = `<div class="dv-section">
                <div class="dv-section-title">Recent Progress Updates (last 30)</div>
                ${rows}
            </div>`;
        });
    });
}

/* ── Utility ─────────────────────────────────────── */
function escHtml(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
