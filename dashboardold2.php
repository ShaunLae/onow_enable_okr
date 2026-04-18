<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';
requireLogin();

$user    = getCurrentUser();
$role    = $user['role'];
$userId  = $user['id'];
$db      = getDB();
$currentPage = 'dashboard';

// ─── Shared base data ────────────────────────────────────────────
$stats      = getDashboardStats($userId, $role);
$recent     = getRecentActivity($userId, $role, 8);
$objectives = $role !== 'Admin' ? getObjectives($userId, $role) : [];

// ════════════════════════════════════════════════════════════════
// MEMBER — 6 standalone queries
// ════════════════════════════════════════════════════════════════
if ($role === 'Member') {

    // M-Q1: My Key Results assigned to me (for quick-update panel)
    $stmt = $db->prepare("
        SELECT kr.id, kr.title, kr.progress, kr.status,
               kr.current_value, kr.target_value, kr.unit, kr.metric_type,
               o.title AS objective_title, o.id AS objective_id
        FROM   key_results kr
        JOIN   objectives o ON kr.objective_id = o.id
        WHERE  kr.owner_id    = :uid
          AND  kr.deleted_at  IS NULL
          AND  o.deleted_at   IS NULL
        ORDER  BY kr.status DESC, kr.progress ASC
    ");
    $stmt->execute([':uid' => $userId]);
    $myKRs = $stmt->fetchAll();

    // M-Q2: My progress updates this week
    $stmt = $db->prepare("
        SELECT COUNT(*)                          AS updates_this_week,
               COALESCE(SUM(ph.new_value - ph.previous_value), 0) AS total_increase
        FROM   progress_history ph
        WHERE  ph.updated_by = :uid
          AND  ph.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $stmt->execute([':uid' => $userId]);
    $weekActivity = $stmt->fetch();

    // M-Q3: Objectives I am contributing to (assigned by a Manager, not personally owned)
    $stmt = $db->prepare("
        SELECT o.id, o.title, o.type, o.progress, o.status,
               o.time_period, u.full_name AS owner_name
        FROM   objective_members om
        JOIN   objectives o ON om.objective_id = o.id
        JOIN   users u      ON o.owner_id      = u.id
        WHERE  om.user_id    = :uid
          AND  o.owner_id   != :uid2
          AND  o.deleted_at  IS NULL
        ORDER  BY o.progress ASC
    ");
    $stmt->execute([':uid' => $userId, ':uid2' => $userId]);
    $contributingTo = $stmt->fetchAll();

    // M-Q4: Upcoming deadlines within 14 days
    $stmt = $db->prepare("
        SELECT o.id, o.title, o.progress, o.status, o.end_date,
               DATEDIFF(o.end_date, NOW()) AS days_left
        FROM   objectives o
        LEFT   JOIN objective_members om ON o.id = om.objective_id
        WHERE  (o.owner_id = :uid OR om.user_id = :uid2)
          AND  o.deleted_at IS NULL
          AND  o.end_date IS NOT NULL
          AND  o.end_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 14 DAY)
        GROUP  BY o.id
        ORDER  BY o.end_date ASC
    ");
    $stmt->execute([':uid' => $userId, ':uid2' => $userId]);
    $upcomingDeadlines = $stmt->fetchAll();

    // M-Q5: My completion rate (owned objectives)
    $stmt = $db->prepare("
        SELECT COUNT(*)                                                   AS total,
               SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END)    AS completed
        FROM   objectives
        WHERE  owner_id   = :uid
          AND  deleted_at IS NULL
    ");
    $stmt->execute([':uid' => $userId]);
    $completionRate = $stmt->fetch();
    $completionPct  = $completionRate['total'] > 0
        ? round(($completionRate['completed'] / $completionRate['total']) * 100)
        : 0;

    // M-Q6: Recent progress I personally submitted
    $stmt = $db->prepare("
        SELECT ph.created_at, ph.new_value, ph.previous_value, ph.note,
               kr.title  AS kr_title,
               o.title   AS objective_title
        FROM   progress_history ph
        JOIN   key_results kr ON ph.key_result_id = kr.id
        JOIN   objectives  o  ON kr.objective_id  = o.id
        WHERE  ph.updated_by = :uid
        ORDER  BY ph.created_at DESC
        LIMIT  5
    ");
    $stmt->execute([':uid' => $userId]);
    $myRecentProgress = $stmt->fetchAll();
}

// ════════════════════════════════════════════════════════════════
// MANAGER — 6 standalone queries
// ════════════════════════════════════════════════════════════════
if ($role === 'Manager') {

    // MG-Q1: Team health — each teammate's objective count, avg progress, at-risk count
    $stmt = $db->prepare("
        SELECT u.id, u.full_name, u.avatar_color, u.role,
               u.job_title, u.department,
               COUNT(DISTINCT o.id)                                           AS obj_count,
               COALESCE(AVG(o.progress), 0)                                  AS avg_progress,
               SUM(CASE WHEN o.status IN('At Risk','Behind') THEN 1 ELSE 0 END) AS at_risk_count,
               SUM(CASE WHEN o.status = 'Completed'          THEN 1 ELSE 0 END) AS completed_count
        FROM   users u
        JOIN   team_members tm ON u.id = tm.user_id
        LEFT   JOIN objectives o ON (
                   o.owner_id  = u.id
                OR EXISTS (
                       SELECT 1 FROM objective_members om2
                       WHERE  om2.objective_id = o.id AND om2.user_id = u.id
                   )
               ) AND o.deleted_at IS NULL
        WHERE  tm.team_id IN (
                   SELECT team_id FROM team_members WHERE user_id = :uid
               )
          AND  u.id    != :uid2
          AND  u.role  != 'Admin'
          AND  u.is_active = 1
        GROUP  BY u.id, u.full_name, u.avatar_color, u.role, u.job_title, u.department
        ORDER  BY avg_progress ASC
    ");
    $stmt->execute([':uid' => $userId, ':uid2' => $userId]);
    $teamHealth = $stmt->fetchAll();

    // MG-Q2: Objectives by type (created by this manager)
    $stmt = $db->prepare("
        SELECT type,
               COUNT(*)       AS obj_count,
               AVG(progress)  AS avg_progress
        FROM   objectives
        WHERE  created_by  = :uid
          AND  deleted_at  IS NULL
        GROUP  BY type
    ");
    $stmt->execute([':uid' => $userId]);
    $byType = [];
    foreach ($stmt->fetchAll() as $row) $byType[$row['type']] = $row;

    // MG-Q3: Key results needing attention (at risk / behind / not started on manager's objectives)
    $stmt = $db->prepare("
        SELECT kr.id, kr.title, kr.progress, kr.status,
               kr.current_value, kr.target_value, kr.unit,
               u.full_name  AS owner_name,
               o.title      AS objective_title,
               o.end_date
        FROM   key_results kr
        JOIN   objectives  o ON kr.objective_id = o.id
        JOIN   users       u ON kr.owner_id     = u.id
        WHERE  o.created_by  = :uid
          AND  kr.deleted_at IS NULL
          AND  o.deleted_at  IS NULL
          AND  kr.status IN ('At Risk', 'Behind', 'Not Started')
        ORDER  BY o.end_date ASC, kr.progress ASC
        LIMIT  8
    ");
    $stmt->execute([':uid' => $userId]);
    $needsAttention = $stmt->fetchAll();

    // MG-Q4: Progress updates submitted by team members on manager's objectives
    $stmt = $db->prepare("
        SELECT ph.created_at, ph.new_value, ph.previous_value,
               u.full_name  AS updated_by,
               u.avatar_color,
               kr.title     AS kr_title,
               o.title      AS objective_title
        FROM   progress_history ph
        JOIN   key_results kr ON ph.key_result_id = kr.id
        JOIN   objectives  o  ON kr.objective_id  = o.id
        JOIN   users       u  ON ph.updated_by    = u.id
        WHERE  o.created_by = :uid
          AND  ph.updated_by != :uid2
        ORDER  BY ph.created_at DESC
        LIMIT  8
    ");
    $stmt->execute([':uid' => $userId, ':uid2' => $userId]);
    $teamProgressFeed = $stmt->fetchAll();

    // MG-Q5: Objectives near deadline with low progress (end within 30 days, progress < 50%)
    $stmt = $db->prepare("
        SELECT id, title, progress, status, end_date,
               DATEDIFF(end_date, NOW()) AS days_left
        FROM   objectives
        WHERE  created_by  = :uid
          AND  deleted_at  IS NULL
          AND  end_date    IS NOT NULL
          AND  end_date    >= NOW()
          AND  end_date    <= DATE_ADD(NOW(), INTERVAL 30 DAY)
          AND  progress    < 50
        ORDER  BY end_date ASC
        LIMIT  6
    ");
    $stmt->execute([':uid' => $userId]);
    $atRiskDeadlines = $stmt->fetchAll();

    // MG-Q6: Parent vs child objective alignment
    $stmt = $db->prepare("
        SELECT o.id, o.title, o.progress AS child_progress, o.status AS child_status,
               po.id    AS parent_id,
               po.title AS parent_title,
               po.progress AS parent_progress,
               po.status   AS parent_status
        FROM   objectives o
        JOIN   objectives po ON o.parent_objective_id = po.id
        LEFT   JOIN objective_members om ON o.id = om.objective_id
        WHERE  (o.created_by = :uid OR om.user_id = :uid2)
          AND  o.deleted_at  IS NULL
          AND  po.deleted_at IS NULL
        GROUP  BY o.id, o.title, o.progress, o.status,
                  po.id, po.title, po.progress, po.status
        ORDER  BY ABS(o.progress - po.progress) DESC
        LIMIT  6
    ");
    $stmt->execute([':uid' => $userId, ':uid2' => $userId]);
    $alignmentData = $stmt->fetchAll();
}

// ─── Shared helpers ───────────────────────────────────────────────
$actLabels = [
    'LOGIN'                 => ['Signed in',         'bi-box-arrow-in-right', '#3b82f6'],
    'LOGOUT'                => ['Signed out',        'bi-box-arrow-right',    '#64748b'],
    'CREATE_OBJECTIVE'      => ['Created objective', 'bi-plus-circle',        '#059669'],
    'UPDATE_OBJECTIVE'      => ['Updated objective', 'bi-pencil',             '#d97706'],
    'SOFT_DELETE_OBJECTIVE' => ['Deleted objective', 'bi-trash',              '#dc2626'],
    'CREATE_KEY_RESULT'     => ['Added key result',  'bi-plus-square',        '#7c3aed'],
    'UPDATE_PROGRESS'       => ['Progress update',   'bi-graph-up',           '#0891b2'],
    'SOFT_DELETE_KEY_RESULT'=> ['Deleted KR',        'bi-trash',              '#dc2626'],
    'CREATE_TEAM'           => ['Created team',      'bi-diagram-3',          '#7c3aed'],
    'CREATE_USER'           => ['Created user',      'bi-person-plus',        '#059669'],
];

function pbClass(string $s): string {
    return match($s) {
        'On Track'  => 'pb-on-track',
        'Completed' => 'pb-completed',
        'At Risk', 'Behind' => 'pb-at-risk',
        default => ''
    };
}

function deadlineUrgency(int $days): string {
    if ($days <= 3)  return 'var(--danger)';
    if ($days <= 7)  return 'var(--warning)';
    return 'var(--info)';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dashboard – ONOW Enable OKR</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="css/style.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<style>
.dash-section-title{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:.75rem;padding-bottom:.4rem;border-bottom:1px solid var(--border)}
.kr-quick-row{display:flex;align-items:center;gap:.75rem;padding:.65rem 0;border-bottom:1px solid var(--border)}
.kr-quick-row:last-child{border-bottom:none}
.deadline-badge{font-size:.72rem;font-weight:700;padding:.2rem .55rem;border-radius:6px;color:#fff}
.align-arrow{font-size:.9rem;color:var(--muted);flex-shrink:0}
.feed-dot{width:8px;height:8px;border-radius:50%;background:var(--primary);flex-shrink:0;margin-top:.45rem}
</style>
</head>
<body>
<div class="app-wrapper">
<?php include 'includes/nav.php'; ?>
<main class="main-content">
  <header class="top-header">
    <button class="btn btn-sm btn-outline-secondary d-md-none" onclick="document.getElementById('sidebar').classList.toggle('open')"><i class="bi bi-list"></i></button>
    <div>
      <div class="page-title">Dashboard</div>
      <div class="page-breadcrumb">Welcome, <?= htmlspecialchars($user['full_name']) ?>&nbsp;<span class="role-badge role-<?= strtolower($role) ?>"><?= $role ?></span></div>
    </div>
    <?php if ($role !== 'Admin'): ?>
    <div class="header-actions">
      <a href="objectives.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>New Objective</a>
    </div>
    <?php endif; ?>
  </header>

  <div class="page-body">

  <?php /* ════════════════════════════════════ ADMIN ════════════════════════════════════ */ ?>
  <?php if ($role === 'Admin'): ?>

    <div class="alert alert-info d-flex gap-2 mb-4" style="border-radius:12px">
      <i class="bi bi-shield-check fs-5"></i>
      <div><strong>Administrator View</strong> — Manage users and teams. OKR management is handled by Managers and Members.</div>
    </div>

    <!-- System stat cards -->
    <div class="row g-3 mb-4">
    <?php foreach ([
      ['Total Users',    $stats['total_users'],    'bi-people',      'var(--primary)', 'var(--primary-lt)', 'users.php'],
      ['Teams',          $stats['total_teams'],    'bi-diagram-3',   '#7c3aed',        '#ede9fe',           'team_management.php'],
      ['Managers',       $stats['total_managers'], 'bi-person-gear', 'var(--info)',    'var(--info-lt)',    'users.php'],
      ['Members',        $stats['total_members'],  'bi-person',      'var(--success)', 'var(--success-lt)', 'users.php'],
    ] as [$lbl,$val,$ico,$col,$bg,$href]): ?>
    <div class="col-6 col-lg-3">
      <a href="<?= $href ?>" class="stat-card d-block text-decoration-none">
        <div class="stat-card-accent" style="background:<?= $col ?>"></div>
        <div class="stat-icon" style="background:<?= $bg ?>;color:<?= $col ?>"><i class="bi <?= $ico ?>"></i></div>
        <div class="stat-label"><?= $lbl ?></div>
        <div class="stat-value" style="color:<?= $col ?>"><?= $val ?></div>
      </a>
    </div>
    <?php endforeach; ?>
    </div>

    <div class="row g-3 mb-4">
      <div class="col-md-6">
        <div class="stat-card">
          <div class="stat-card-accent" style="background:var(--warning)"></div>
          <div class="stat-icon" style="background:var(--warning-lt);color:var(--warning)"><i class="bi bi-bullseye"></i></div>
          <div class="stat-label">Total Objectives (system-wide)</div>
          <div class="stat-value" style="color:var(--warning)"><?= $stats['total_objectives'] ?></div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="stat-card">
          <div class="stat-card-accent" style="background:var(--secondary)"></div>
          <div class="stat-icon" style="background:#ede9fe;color:var(--secondary)"><i class="bi bi-check2-circle"></i></div>
          <div class="stat-label">Total Key Results (system-wide)</div>
          <div class="stat-value" style="color:var(--secondary)"><?= $stats['total_key_results'] ?></div>
        </div>
      </div>
    </div>

    <div class="row g-3">
      <div class="col-lg-6">
        <div class="card">
          <div class="card-header"><i class="bi bi-diagram-3 text-primary"></i><span class="card-title">Teams</span><a href="team_management.php" class="btn btn-sm btn-outline-primary ms-auto">Manage</a></div>
          <div class="card-body p-0">
          <?php foreach (getAllTeams() as $t): ?>
          <div class="d-flex align-items-center gap-2 px-4 py-2 border-bottom">
            <div style="width:32px;height:32px;background:#ede9fe;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="bi bi-people" style="color:#7c3aed;font-size:.85rem"></i></div>
            <div class="flex-grow-1">
              <div class="fw-700" style="font-size:.88rem"><?= htmlspecialchars($t['name']) ?></div>
              <div class="text-muted" style="font-size:.75rem"><?= $t['member_count'] ?> members · created <?= date('d M Y', strtotime($t['created_at'])) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card">
          <div class="card-header"><i class="bi bi-activity text-primary"></i><span class="card-title">Recent Activity</span></div>
          <div class="card-body">
          <?php foreach ($recent as $a): [$al,$ai,$ac] = $actLabels[$a['action']] ?? [$a['action'],'bi-circle','#64748b']; ?>
          <div class="timeline-item">
            <div style="width:28px;height:28px;border-radius:8px;background:<?= $ac ?>20;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:.1rem"><i class="bi <?= $ai ?>" style="color:<?= $ac ?>;font-size:.8rem"></i></div>
            <div class="timeline-content">
              <div class="timeline-action"><?= $al ?></div>
              <div class="timeline-time"><?= htmlspecialchars($a['full_name'] ?? 'System') ?> · <?= date('d M, H:i', strtotime($a['created_at'])) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

  <?php /* ════════════════════════════════════ MEMBER ════════════════════════════════════ */ ?>
  <?php elseif ($role === 'Member'): ?>

    <!-- Existing stat row -->
    <div class="row g-3 mb-4">
    <?php foreach ([
      ['Objectives',   $stats['total_objectives'], 'bi-bullseye',           'var(--primary)',   'var(--primary-lt)',  $stats['total_key_results'].' key results'],
      ['On Track',     $stats['on_track'],          'bi-check-circle',       'var(--success)',   'var(--success-lt)', $stats['completed'].' completed'],
      ['At Risk',      $stats['at_risk'],           'bi-exclamation-triangle','var(--warning)',  'var(--warning-lt)', $stats['not_started'].' not started'],
      ['Completion',   $completionPct.'%',          'bi-trophy',             'var(--secondary)', '#ede9fe',           $completionRate['completed'].' of '.$completionRate['total'].' owned'],
    ] as [$lbl,$val,$ico,$col,$bg,$sub]): ?>
    <div class="col-6 col-lg-3">
      <div class="stat-card">
        <div class="stat-card-accent" style="background:<?= $col ?>"></div>
        <div class="stat-icon" style="background:<?= $bg ?>;color:<?= $col ?>"><i class="bi <?= $ico ?>"></i></div>
        <div class="stat-label"><?= $lbl ?></div>
        <div class="stat-value" style="color:<?= $col ?>"><?= $val ?></div>
        <div class="stat-sub"><?= $sub ?></div>
      </div>
    </div>
    <?php endforeach; ?>
    </div>

    <div class="row g-3 mb-3">
      <!-- M-Q1: My Key Results quick-update panel -->
      <div class="col-lg-7">
        <div class="card h-100">
          <div class="card-header">
            <i class="bi bi-check2-circle text-primary"></i>
            <span class="card-title">My Key Results</span>
            <span class="ms-auto text-muted small"><?= count($myKRs) ?> active</span>
          </div>
          <div class="card-body">
          <?php if (empty($myKRs)): ?>
            <div class="empty-state py-3"><i class="bi bi-check2-circle" style="font-size:2rem"></i><h5 class="mt-2">No Key Results Assigned</h5><p class="small">A manager will assign key results to you.</p></div>
          <?php else: ?>
          <?php foreach ($myKRs as $kr):
            $pct = (float)$kr['progress'];
            $bc  = pbClass($kr['status']);
          ?>
          <div class="kr-quick-row">
            <div class="flex-grow-1">
              <div class="d-flex justify-content-between align-items-start mb-1 gap-2">
                <div>
                  <div class="fw-700" style="font-size:.86rem"><?= htmlspecialchars($kr['title']) ?></div>
                  <div class="text-muted" style="font-size:.74rem"><?= htmlspecialchars(mb_strimwidth($kr['objective_title'],0,45,'…')) ?></div>
                </div>
                <span class="status-badge <?= statusClass($kr['status']) ?> flex-shrink-0"><?= $kr['status'] ?></span>
              </div>
              <div class="d-flex align-items-center gap-2">
                <div class="progress flex-grow-1"><div class="progress-bar <?= $bc ?>" style="width:<?= $pct ?>%"></div></div>
                <span style="font-size:.78rem;font-weight:700;min-width:30px"><?= round($pct) ?>%</span>
                <span class="text-muted" style="font-size:.74rem"><?= number_format($kr['current_value'],1) ?>/<?= number_format($kr['target_value'],1) ?><?= $kr['unit']?' '.htmlspecialchars($kr['unit']):'' ?></span>
              </div>
            </div>
            <a href="progress.php?objective_id=<?= $kr['objective_id'] ?>&kr_id=<?= $kr['id'] ?>" class="btn btn-sm btn-outline-primary btn-icon ms-2" title="Update Progress"><i class="bi bi-pencil-square"></i></a>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- M-Q5 + M-Q2: Completion rate ring + weekly stats -->
      <div class="col-lg-5">
        <div class="card mb-3">
          <div class="card-header"><i class="bi bi-graph-up text-primary"></i><span class="card-title">My Progress This Week</span></div>
          <div class="card-body">
            <div class="row g-2 text-center">
              <div class="col-6">
                <div class="p-3 rounded" style="background:var(--primary-lt)">
                  <div style="font-size:1.8rem;font-weight:800;color:var(--primary)"><?= $weekActivity['updates_this_week'] ?></div>
                  <div style="font-size:.75rem;color:var(--muted);font-weight:600">Updates made</div>
                </div>
              </div>
              <div class="col-6">
                <div class="p-3 rounded" style="background:var(--success-lt)">
                  <div style="font-size:1.8rem;font-weight:800;color:var(--success)">+<?= number_format((float)$weekActivity['total_increase'],1) ?></div>
                  <div style="font-size:.75rem;color:var(--muted);font-weight:600">Total increase</div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="card">
          <div class="card-header"><i class="bi bi-clock-history text-primary"></i><span class="card-title">My Recent Updates</span></div>
          <div class="card-body p-0" style="max-height:200px;overflow-y:auto">
          <?php if (empty($myRecentProgress)): ?>
            <div class="p-3 text-muted text-center small">No progress updates yet.</div>
          <?php else: ?>
          <?php foreach ($myRecentProgress as $p): $diff = $p['new_value'] - $p['previous_value']; ?>
          <div class="d-flex align-items-start gap-2 px-3 py-2 border-bottom">
            <div class="feed-dot mt-1"></div>
            <div class="flex-grow-1">
              <div style="font-size:.82rem;font-weight:600"><?= htmlspecialchars(mb_strimwidth($p['kr_title'],0,38,'…')) ?></div>
              <div style="font-size:.74rem;color:var(--muted)"><?= htmlspecialchars(mb_strimwidth($p['objective_title'],0,38,'…')) ?></div>
              <div class="d-flex gap-2 mt-1">
                <span style="font-size:.74rem;color:<?= $diff>=0?'var(--success)':'var(--danger)' ?>;font-weight:700"><?= $diff>=0?'+':'' ?><?= number_format($diff,1) ?></span>
                <span style="font-size:.73rem;color:var(--muted)"><?= date('d M, H:i',strtotime($p['created_at'])) ?></span>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-3 mb-3">
      <!-- M-Q4: Upcoming deadlines -->
      <div class="col-lg-6">
        <div class="card h-100">
          <div class="card-header">
            <i class="bi bi-calendar-event text-primary"></i>
            <span class="card-title">Upcoming Deadlines</span>
            <span class="ms-auto text-muted small">Next 14 days</span>
          </div>
          <div class="card-body p-0">
          <?php if (empty($upcomingDeadlines)): ?>
            <div class="p-4 text-muted text-center small"><i class="bi bi-calendar-check" style="font-size:1.5rem;opacity:.3"></i><p class="mt-2 mb-0">No deadlines in the next 14 days.</p></div>
          <?php else: ?>
          <?php foreach ($upcomingDeadlines as $d): $urgency = deadlineUrgency((int)$d['days_left']); ?>
          <div class="d-flex align-items-center gap-3 px-4 py-2 border-bottom">
            <div style="width:36px;height:36px;border-radius:10px;background:<?= $urgency ?>20;display:flex;flex-direction:column;align-items:center;justify-content:center;flex-shrink:0">
              <span style="font-size:.9rem;font-weight:800;color:<?= $urgency ?>;line-height:1"><?= $d['days_left'] ?></span>
              <span style="font-size:.55rem;color:<?= $urgency ?>;font-weight:600">days</span>
            </div>
            <div class="flex-grow-1">
              <div class="fw-700" style="font-size:.85rem"><?= htmlspecialchars(mb_strimwidth($d['title'],0,40,'…')) ?></div>
              <div class="d-flex align-items-center gap-2 mt-1">
                <div class="progress flex-grow-1" style="height:5px"><div class="progress-bar <?= pbClass($d['status']) ?>" style="width:<?= $d['progress'] ?>%"></div></div>
                <span style="font-size:.74rem;font-weight:700"><?= round($d['progress']) ?>%</span>
              </div>
            </div>
            <span class="status-badge <?= statusClass($d['status']) ?>"><?= $d['status'] ?></span>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- M-Q3: Objectives I'm contributing to -->
      <div class="col-lg-6">
        <div class="card h-100">
          <div class="card-header">
            <i class="bi bi-people text-primary"></i>
            <span class="card-title">Contributing To</span>
            <span class="ms-auto text-muted small"><?= count($contributingTo) ?> objective<?= count($contributingTo) !== 1 ? 's' : '' ?></span>
          </div>
          <div class="card-body p-0">
          <?php if (empty($contributingTo)): ?>
            <div class="p-4 text-muted text-center small"><i class="bi bi-people" style="font-size:1.5rem;opacity:.3"></i><p class="mt-2 mb-0">No shared objectives yet.</p></div>
          <?php else: ?>
          <?php foreach ($contributingTo as $o): $pct=(float)$o['progress']; ?>
          <div class="px-4 py-2 border-bottom">
            <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
              <div>
                <span class="type-badge type-<?= strtolower($o['type']) ?> me-1"><?= substr($o['type'],0,1) ?></span>
                <span class="fw-700" style="font-size:.85rem"><?= htmlspecialchars(mb_strimwidth($o['title'],0,40,'…')) ?></span>
              </div>
              <span class="status-badge <?= statusClass($o['status']) ?> flex-shrink-0"><?= $o['status'] ?></span>
            </div>
            <div class="d-flex align-items-center gap-2">
              <div class="progress flex-grow-1" style="height:5px"><div class="progress-bar <?= pbClass($o['status']) ?>" style="width:<?= $pct ?>%"></div></div>
              <span style="font-size:.74rem;font-weight:700"><?= round($pct) ?>%</span>
            </div>
            <div class="text-muted mt-1" style="font-size:.73rem"><i class="bi bi-person me-1"></i>Owner: <?= htmlspecialchars($o['owner_name']) ?></div>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Existing: objective overview chart + activity -->
    <div class="row g-3">
      <div class="col-lg-8">
        <div class="card h-100">
          <div class="card-header">
            <i class="bi bi-bar-chart-line text-primary"></i><span class="card-title">Objectives Overview</span>
            <select class="form-select form-select-sm ms-auto" id="chartFilter" style="width:auto" onchange="renderChart(this.value)">
              <option value="all">All Types</option><option value="Personal">Personal</option>
            </select>
          </div>
          <div class="card-body">
          <?php if (empty($objectives)): ?>
            <div class="empty-state"><i class="bi bi-bar-chart"></i><h5>No Objectives Yet</h5><a href="objectives.php" class="btn btn-primary btn-sm mt-2">Get Started</a></div>
          <?php else: ?>
            <canvas id="objChart" height="200"></canvas>
          <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="card h-100">
          <div class="card-header"><i class="bi bi-activity text-primary"></i><span class="card-title">My Activity</span></div>
          <div class="card-body">
          <?php foreach ($recent as $a): [$al,$ai,$ac] = $actLabels[$a['action']] ?? [$a['action'],'bi-circle','#64748b']; ?>
          <div class="timeline-item">
            <div style="width:26px;height:26px;border-radius:8px;background:<?= $ac ?>20;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:.1rem"><i class="bi <?= $ai ?>" style="color:<?= $ac ?>;font-size:.75rem"></i></div>
            <div class="timeline-content"><div class="timeline-action"><?= $al ?></div><div class="timeline-time"><?= date('d M, H:i', strtotime($a['created_at'])) ?></div></div>
          </div>
          <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

  <?php /* ════════════════════════════════════ MANAGER ════════════════════════════════════ */ ?>
  <?php else: /* Manager */ ?>

    <!-- Existing stat row -->
    <div class="row g-3 mb-4">
    <?php foreach ([
      ['Objectives',   $stats['total_objectives'], 'bi-bullseye',            'var(--primary)',   'var(--primary-lt)', $stats['total_key_results'].' key results'],
      ['On Track',     $stats['on_track'],          'bi-check-circle',        'var(--success)',   'var(--success-lt)', $stats['completed'].' completed'],
      ['At Risk',      $stats['at_risk'],           'bi-exclamation-triangle','var(--warning)',   'var(--warning-lt)', $stats['not_started'].' not started'],
      ['Avg Progress', $stats['avg_progress'].'%',  'bi-bar-chart',           'var(--secondary)', '#ede9fe',           'Across all objectives'],
    ] as [$lbl,$val,$ico,$col,$bg,$sub]): ?>
    <div class="col-6 col-lg-3">
      <div class="stat-card">
        <div class="stat-card-accent" style="background:<?= $col ?>"></div>
        <div class="stat-icon" style="background:<?= $bg ?>;color:<?= $col ?>"><i class="bi <?= $ico ?>"></i></div>
        <div class="stat-label"><?= $lbl ?></div>
        <div class="stat-value" style="color:<?= $col ?>"><?= $val ?></div>
        <div class="stat-sub"><?= $sub ?></div>
      </div>
    </div>
    <?php endforeach; ?>
    </div>

    <!-- MG-Q2: Objectives by type breakdown -->
    <div class="row g-3 mb-3">
    <?php foreach (['Organisational'=>['#7c3aed','#ede9fe','bi-diagram-3'], 'Team'=>['var(--primary)','var(--primary-lt)','bi-people'], 'Personal'=>['var(--success)','var(--success-lt)','bi-person']] as $type => [$col,$bg,$ico]): ?>
    <div class="col-md-4">
      <div class="stat-card">
        <div class="stat-card-accent" style="background:<?= $col ?>"></div>
        <div class="stat-icon" style="background:<?= $bg ?>;color:<?= $col ?>"><i class="bi <?= $ico ?>"></i></div>
        <div class="stat-label"><?= $type ?> Objectives</div>
        <div class="stat-value" style="color:<?= $col ?>"><?= $byType[$type]['obj_count'] ?? 0 ?></div>
        <div class="stat-sub">Avg <?= isset($byType[$type]) ? round($byType[$type]['avg_progress'],1) : 0 ?>% progress</div>
      </div>
    </div>
    <?php endforeach; ?>
    </div>

    <div class="row g-3 mb-3">
      <!-- MG-Q1: Team health summary -->
      <div class="col-lg-7">
        <div class="card h-100">
          <div class="card-header">
            <i class="bi bi-person-workspace text-primary"></i>
            <span class="card-title">Team Health</span>
            <a href="team.php" class="btn btn-sm btn-outline-primary ms-auto">Full Overview</a>
          </div>
          <div class="card-body p-0">
          <?php if (empty($teamHealth)): ?>
            <div class="p-4 text-center text-muted small"><i class="bi bi-people" style="font-size:1.5rem;opacity:.3"></i><p class="mt-2 mb-0">No teammates found. Ask Admin to assign you to a team.</p></div>
          <?php else: ?>
          <?php foreach ($teamHealth as $m): $prog = round((float)$m['avg_progress'],1); $ini = initials($m['full_name']); ?>
          <div class="d-flex align-items-center gap-3 px-4 py-2 border-bottom">
            <div class="user-avatar" style="background:<?= htmlspecialchars($m['avatar_color']) ?>;width:34px;height:34px;font-size:.75rem;border-radius:10px;flex-shrink:0"><?= $ini ?></div>
            <div class="flex-grow-1">
              <div class="d-flex justify-content-between align-items-center gap-2 mb-1">
                <div>
                  <span class="fw-700" style="font-size:.86rem"><?= htmlspecialchars($m['full_name']) ?></span>
                  <span class="role-badge role-<?= strtolower($m['role']) ?> ms-1"><?= $m['role'] ?></span>
                </div>
                <div class="d-flex gap-1 flex-shrink-0">
                  <?php if ($m['at_risk_count'] > 0): ?>
                  <span class="status-badge status-at-risk"><i class="bi bi-exclamation-triangle"></i> <?= $m['at_risk_count'] ?> at risk</span>
                  <?php endif; ?>
                </div>
              </div>
              <div class="d-flex align-items-center gap-2">
                <div class="progress flex-grow-1" style="height:5px"><div class="progress-bar <?= $prog>=70?'pb-on-track':($prog>=40?'pb-at-risk':'pb-behind') ?>" style="width:<?= $prog ?>%"></div></div>
                <span style="font-size:.75rem;font-weight:700"><?= $prog ?>%</span>
                <span class="text-muted" style="font-size:.73rem"><?= $m['obj_count'] ?> obj</span>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- MG-Q5: Objectives near deadline with low progress -->
      <div class="col-lg-5">
        <div class="card h-100">
          <div class="card-header">
            <i class="bi bi-exclamation-triangle text-danger"></i>
            <span class="card-title">At-Risk Deadlines</span>
            <span class="ms-auto text-muted small">Progress &lt; 50%, next 30 days</span>
          </div>
          <div class="card-body p-0">
          <?php if (empty($atRiskDeadlines)): ?>
            <div class="p-4 text-center" style="color:var(--success)"><i class="bi bi-check-circle" style="font-size:1.5rem"></i><p class="mt-2 mb-0 small fw-600">No at-risk deadlines!</p></div>
          <?php else: ?>
          <?php foreach ($atRiskDeadlines as $d): $urgency = deadlineUrgency((int)$d['days_left']); ?>
          <div class="d-flex align-items-center gap-3 px-4 py-2 border-bottom">
            <div style="width:38px;text-align:center;flex-shrink:0">
              <div style="font-size:.95rem;font-weight:800;color:<?= $urgency ?>;line-height:1"><?= $d['days_left'] ?></div>
              <div style="font-size:.6rem;color:<?= $urgency ?>;font-weight:600">days</div>
            </div>
            <div class="flex-grow-1">
              <div class="fw-700" style="font-size:.84rem"><?= htmlspecialchars(mb_strimwidth($d['title'],0,38,'…')) ?></div>
              <div class="d-flex align-items-center gap-2 mt-1">
                <div class="progress flex-grow-1" style="height:5px"><div class="progress-bar pb-at-risk" style="width:<?= $d['progress'] ?>%"></div></div>
                <span style="font-size:.74rem;font-weight:700;color:var(--danger)"><?= round($d['progress']) ?>%</span>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-3 mb-3">
      <!-- MG-Q3: Key results needing attention -->
      <div class="col-lg-7">
        <div class="card h-100">
          <div class="card-header">
            <i class="bi bi-flag text-danger"></i>
            <span class="card-title">Key Results Needing Attention</span>
            <span class="ms-auto text-muted small"><?= count($needsAttention) ?> items</span>
          </div>
          <div class="card-body p-0">
          <?php if (empty($needsAttention)): ?>
            <div class="p-4 text-center" style="color:var(--success)"><i class="bi bi-check-circle" style="font-size:1.5rem"></i><p class="mt-2 mb-0 small fw-600">All key results on track!</p></div>
          <?php else: ?>
          <?php foreach ($needsAttention as $kr): ?>
          <div class="d-flex align-items-start gap-3 px-4 py-2 border-bottom">
            <span class="status-badge <?= statusClass($kr['status']) ?> flex-shrink-0 mt-1"><?= $kr['status'] ?></span>
            <div class="flex-grow-1">
              <div class="fw-700" style="font-size:.84rem"><?= htmlspecialchars(mb_strimwidth($kr['title'],0,38,'…')) ?></div>
              <div class="text-muted" style="font-size:.74rem"><?= htmlspecialchars(mb_strimwidth($kr['objective_title'],0,38,'…')) ?></div>
              <div class="d-flex align-items-center gap-2 mt-1">
                <div class="progress flex-grow-1" style="height:5px"><div class="progress-bar <?= pbClass($kr['status']) ?>" style="width:<?= $kr['progress'] ?>%"></div></div>
                <span style="font-size:.74rem;font-weight:700"><?= round($kr['progress']) ?>%</span>
              </div>
              <div class="text-muted mt-1" style="font-size:.73rem"><i class="bi bi-person me-1"></i><?= htmlspecialchars($kr['owner_name']) ?><?= $kr['end_date']?' · Due '.date('d M',strtotime($kr['end_date'])):'' ?></div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- MG-Q4: Team progress feed -->
      <div class="col-lg-5">
        <div class="card h-100">
          <div class="card-header"><i class="bi bi-graph-up text-primary"></i><span class="card-title">Team Progress Updates</span></div>
          <div class="card-body p-0" style="max-height:340px;overflow-y:auto">
          <?php if (empty($teamProgressFeed)): ?>
            <div class="p-4 text-muted text-center small"><i class="bi bi-graph-up" style="font-size:1.5rem;opacity:.3"></i><p class="mt-2 mb-0">No team updates yet.</p></div>
          <?php else: ?>
          <?php foreach ($teamProgressFeed as $f): $diff = $f['new_value'] - $f['previous_value']; $ini = initials($f['updated_by']); ?>
          <div class="d-flex align-items-start gap-2 px-3 py-2 border-bottom">
            <div class="user-avatar" style="background:<?= htmlspecialchars($f['avatar_color']) ?>;width:28px;height:28px;font-size:.6rem;border-radius:8px;flex-shrink:0;margin-top:.1rem"><?= $ini ?></div>
            <div class="flex-grow-1">
              <div style="font-size:.83rem;font-weight:600"><?= htmlspecialchars(mb_strimwidth($f['kr_title'],0,38,'…')) ?></div>
              <div style="font-size:.74rem;color:var(--muted)"><?= htmlspecialchars(mb_strimwidth($f['objective_title'],0,38,'…')) ?></div>
              <div class="d-flex gap-2 mt-1">
                <span style="font-size:.74rem;color:<?= $diff>=0?'var(--success)':'var(--danger)' ?>;font-weight:700"><?= $diff>=0?'+':'' ?><?= number_format($diff,1) ?></span>
                <span style="font-size:.73rem;color:var(--muted)"><?= htmlspecialchars($f['updated_by']) ?> · <?= date('d M, H:i',strtotime($f['created_at'])) ?></span>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-3 mb-3">
      <!-- MG-Q6: Parent vs child alignment -->
      <div class="col-lg-6">
        <div class="card h-100">
          <div class="card-header">
            <i class="bi bi-link-45deg text-primary"></i>
            <span class="card-title">Objective Alignment</span>
            <span class="ms-auto text-muted small">Child vs Parent progress</span>
          </div>
          <div class="card-body p-0">
          <?php if (empty($alignmentData)): ?>
            <div class="p-4 text-muted text-center small"><i class="bi bi-link-45deg" style="font-size:1.5rem;opacity:.3"></i><p class="mt-2 mb-0">No linked objectives found.</p></div>
          <?php else: ?>
          <?php foreach ($alignmentData as $a):
            $gap  = abs((float)$a['child_progress'] - (float)$a['parent_progress']);
            $gapColor = $gap > 30 ? 'var(--danger)' : ($gap > 15 ? 'var(--warning)' : 'var(--success)');
          ?>
          <div class="px-4 py-2 border-bottom">
            <div class="d-flex align-items-center gap-2 mb-1">
              <span class="text-muted" style="font-size:.72rem;font-weight:600;flex-shrink:0">PARENT</span>
              <span style="font-size:.82rem;font-weight:700;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars(mb_strimwidth($a['parent_title'],0,35,'…')) ?></span>
              <span style="font-size:.8rem;font-weight:800;color:var(--text2)"><?= round($a['parent_progress']) ?>%</span>
            </div>
            <div class="d-flex align-items-center gap-2 mb-1 ps-2">
              <i class="bi bi-arrow-return-right text-muted" style="font-size:.8rem;flex-shrink:0"></i>
              <span style="font-size:.8rem;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars(mb_strimwidth($a['title'],0,35,'…')) ?></span>
              <span style="font-size:.8rem;font-weight:800;color:var(--primary)"><?= round($a['child_progress']) ?>%</span>
            </div>
            <?php if ($gap > 15): ?>
            <div style="font-size:.72rem;color:<?= $gapColor ?>;font-weight:600"><i class="bi bi-exclamation-circle me-1"></i><?= round($gap) ?>% gap between child and parent</div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Existing: overview chart -->
      <div class="col-lg-6">
        <div class="card h-100">
          <div class="card-header">
            <i class="bi bi-bar-chart-line text-primary"></i><span class="card-title">Objectives Overview</span>
            <select class="form-select form-select-sm ms-auto" id="chartFilter" style="width:auto" onchange="renderChart(this.value)">
              <option value="all">All Types</option>
              <option value="Organisational">Organisational</option>
              <option value="Team">Team</option>
              <option value="Personal">Personal</option>
            </select>
          </div>
          <div class="card-body">
          <?php if (empty($objectives)): ?>
            <div class="empty-state"><i class="bi bi-bar-chart"></i><h5>No Objectives Yet</h5><a href="objectives.php" class="btn btn-primary btn-sm mt-2">Get Started</a></div>
          <?php else: ?>
            <canvas id="objChart" height="200"></canvas>
          <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Status donut + activity -->
    <div class="row g-3">
      <div class="col-lg-4">
        <div class="card h-100">
          <div class="card-header"><i class="bi bi-pie-chart text-primary"></i><span class="card-title">Status Split</span></div>
          <div class="card-body d-flex flex-column align-items-center">
            <canvas id="statusChart" width="160" height="160" style="max-width:160px"></canvas>
            <div class="mt-3 w-100">
            <?php foreach ([['On Track',$stats['on_track'],'#059669'],['At Risk',$stats['at_risk'],'#d97706'],['Completed',$stats['completed'],'#3b82f6'],['Not Started',$stats['not_started'],'#e2e8f0']] as [$lbl,$v,$c]): if($v<=0) continue; ?>
            <div class="d-flex align-items-center justify-content-between mb-2">
              <div class="d-flex align-items-center gap-2"><span style="width:10px;height:10px;border-radius:50%;background:<?= $c ?>;display:inline-block"></span><span style="font-size:.82rem;font-weight:600"><?= $lbl ?></span></div>
              <span style="font-size:.82rem;font-weight:700"><?= $v ?></span>
            </div>
            <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
      <div class="col-lg-8">
        <div class="card h-100">
          <div class="card-header"><i class="bi bi-activity text-primary"></i><span class="card-title">My Activity</span></div>
          <div class="card-body">
          <?php foreach ($recent as $a): [$al,$ai,$ac] = $actLabels[$a['action']] ?? [$a['action'],'bi-circle','#64748b']; ?>
          <div class="timeline-item">
            <div style="width:28px;height:28px;border-radius:8px;background:<?= $ac ?>20;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:.1rem"><i class="bi <?= $ai ?>" style="color:<?= $ac ?>;font-size:.8rem"></i></div>
            <div class="timeline-content"><div class="timeline-action"><?= $al ?></div><div class="timeline-time"><?= date('d M, H:i',strtotime($a['created_at'])) ?></div></div>
          </div>
          <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

  <?php endif; /* end role switch */ ?>
  </div><!-- /page-body -->
</main>
</div>

<div id="toastContainer"></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($role !== 'Admin' && !empty($objectives)): ?>
<script>
const objData = <?= json_encode(array_values(array_map(fn($o) => [
    'title'    => mb_strimwidth($o['title'], 0, 26, '…'),
    'progress' => (float)$o['progress'],
    'status'   => $o['status'],
    'type'     => $o['type'],
], $objectives))) ?>;

function clr(s) {
    return {'On Track':'#059669','At Risk':'#d97706','Behind':'#dc2626','Completed':'#3b82f6','Not Started':'#cbd5e1'}[s] || '#cbd5e1';
}
let oc;
function renderChart(f = 'all') {
    const d = f === 'all' ? objData : objData.filter(o => o.type === f);
    const ctx = document.getElementById('objChart');
    if (!ctx) return;
    if (oc) oc.destroy();
    if (!d.length) return;
    oc = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: d.map(o => o.title),
            datasets: [{ label: 'Progress %', data: d.map(o => o.progress), backgroundColor: d.map(o => clr(o.status)), borderRadius: 6, borderSkipped: false }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 11 }, maxRotation: 30 } },
                y: { min: 0, max: 100, ticks: { callback: v => v + '%', font: { size: 11 } }, grid: { color: '#f1f5f9' } }
            }
        }
    });
}

<?php if ($role !== 'Member'): ?>
new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: ['On Track', 'At Risk', 'Completed', 'Not Started'],
        datasets: [{ data: [<?= $stats['on_track'] ?>, <?= $stats['at_risk'] ?>, <?= $stats['completed'] ?>, <?= $stats['not_started'] ?>], backgroundColor: ['#059669','#d97706','#3b82f6','#e2e8f0'], borderWidth: 2, borderColor: '#fff' }]
    },
    options: { cutout: '68%', plugins: { legend: { display: false } } }
});
<?php endif; ?>

renderChart();
</script>
<?php endif; ?>
</body>
</html>
