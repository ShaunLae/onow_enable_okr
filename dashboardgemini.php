<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';
requireLogin();

$user = getCurrentUser();
$role = $user['role'];
$userId = $user['id'];
$currentPage = 'dashboard';

// ─── Quarter filter ──────────────────────────────────────────────
$availableQuarters = getTimePeriods();
$selectedQ = isset($_GET['q']) ? trim($_GET['q']) : '';
if ($selectedQ && !in_array($selectedQ, $availableQuarters)) $selectedQ = '';

// We define specific clauses and their corresponding parameters separately
$qObjClause  = ''; $qObjParams  = []; // For queries using o.time_period
$qBaseClause = ''; $qBaseParams = []; // For queries using time_period without alias
$qPhClause   = ''; $qPhParams   = []; // For queries using progress_history dates

if ($selectedQ !== '') {
    $parts = explode(' ', $selectedQ);
    $qPart = $parts[0] ?? '';
    $qYear = isset($parts[1]) ? (int)$parts[1] : (int)date('Y');

    switch ($qPart) {
        case 'Q1': $qs = "$qYear-01-01 00:00:00"; $qe = "$qYear-03-31 23:59:59"; break;
        case 'Q2': $qs = "$qYear-04-01 00:00:00"; $qe = "$qYear-06-30 23:59:59"; break;
        case 'Q3': $qs = "$qYear-07-01 00:00:00"; $qe = "$qYear-09-30 23:59:59"; break;
        case 'Q4': $qs = "$qYear-10-01 00:00:00"; $qe = "$qYear-12-31 23:59:59"; break;
        case 'H1': $qs = "$qYear-01-01 00:00:00"; $qe = "$qYear-06-30 23:59:59"; break;
        case 'H2': $qs = "$qYear-07-01 00:00:00"; $qe = "$qYear-12-31 23:59:59"; break;
        case 'Annual': $qs = "$qYear-01-01 00:00:00"; $qe = "$qYear-12-31 23:59:59"; break;
        default:   $qs = "$qYear-01-01 00:00:00"; $qe = "$qYear-12-31 23:59:59"; break;
    }

    $qObjClause  = " AND o.time_period = :qp ";
    $qObjParams  = [':qp' => $selectedQ];

    $qBaseClause = " AND time_period = :qp ";
    $qBaseParams = [':qp' => $selectedQ];

    $qPhClause   = " AND ph.created_at BETWEEN :qs AND :qe ";
    $qPhParams   = [':qs' => $qs, ':qe' => $qe];
}

$db = getDB();
$data = [];

// ==========================================
// MEMBER DASHBOARD DATA
// ==========================================
if ($role === 'Member') {
    // M-Q1: My assigned objectives & quick KRs
    $sql1 = "SELECT o.id, o.title, o.status, o.progress, o.time_period, 
                    (SELECT COUNT(*) FROM key_results WHERE objective_id = o.id AND deleted_at IS NULL) as kr_count
             FROM objectives o
             WHERE o.owner_id = :uid AND o.deleted_at IS NULL $qObjClause
             ORDER BY o.progress ASC LIMIT 5";
    $stmt = $db->prepare($sql1);
    $stmt->execute(array_merge([':uid' => $userId], $qObjParams));
    $data['my_objectives'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // M-Q2: My Progress Updates this Week vs Last Week
    $sql2 = "SELECT 
             SUM(CASE WHEN ph.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as this_week,
             SUM(CASE WHEN ph.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND ph.created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as last_week
             FROM progress_history ph
             JOIN key_results kr ON ph.key_result_id = kr.id
             JOIN objectives o ON kr.objective_id = o.id
             WHERE ph.updated_by = :uid AND o.deleted_at IS NULL $qPhClause";
    $stmt = $db->prepare($sql2);
    $stmt->execute(array_merge([':uid' => $userId], $qPhParams));
    $data['activity_stats'] = $stmt->fetch(PDO::FETCH_ASSOC);

    // M-Q3: Overall Progress Avg
    $sql3 = "SELECT AVG(progress) as avg_progress, COUNT(id) as total_obj 
             FROM objectives 
             WHERE owner_id = :uid AND deleted_at IS NULL $qBaseClause";
    $stmt = $db->prepare($sql3);
    $stmt->execute(array_merge([':uid' => $userId], $qBaseParams));
    $data['overall_progress'] = $stmt->fetch(PDO::FETCH_ASSOC);

    // M-Q4: Upcoming Deadlines (Next 14 days)
    $sql4 = "SELECT id, title, end_date, DATEDIFF(end_date, NOW()) as days_left
             FROM objectives
             WHERE owner_id = :uid AND deleted_at IS NULL 
             AND status != 'Completed' AND end_date IS NOT NULL 
             AND end_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 14 DAY)
             $qBaseClause
             ORDER BY end_date ASC LIMIT 4";
    $stmt = $db->prepare($sql4);
    $stmt->execute(array_merge([':uid' => $userId], $qBaseParams));
    $data['deadlines'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // M-Q5: Status Distribution
    $sql5 = "SELECT status, COUNT(id) as count 
             FROM objectives 
             WHERE owner_id = :uid AND deleted_at IS NULL $qBaseClause
             GROUP BY status";
    $stmt = $db->prepare($sql5);
    $stmt->execute(array_merge([':uid' => $userId], $qBaseParams));
    $data['status_split'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // M-Q6: Recent Activity Feed
    $sql6 = "SELECT ph.new_value, ph.previous_value, ph.created_at, kr.title as kr_title, o.title as obj_title
             FROM progress_history ph
             JOIN key_results kr ON ph.key_result_id = kr.id
             JOIN objectives o ON kr.objective_id = o.id
             WHERE ph.updated_by = :uid AND o.deleted_at IS NULL $qPhClause
             ORDER BY ph.created_at DESC LIMIT 5";
    $stmt = $db->prepare($sql6);
    $stmt->execute(array_merge([':uid' => $userId], $qPhParams));
    $data['recent_activity'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ==========================================
// MANAGER DASHBOARD DATA
// ==========================================
if ($role === 'Manager') {
    $myTeams = getUserTeams($userId);
    $teamIds = array_column($myTeams, 'id');
    $teamIdsList = empty($teamIds) ? '0' : implode(',', array_map('intval', $teamIds));

    // MG-Q1: Team Health (Average progress per team)
    $sql1 = "SELECT t.name, t.id,
                    COUNT(o.id) as total_obj,
                    AVG(o.progress) as avg_progress,
                    SUM(CASE WHEN o.status IN ('At Risk', 'Behind') THEN 1 ELSE 0 END) as risk_count
             FROM teams t
             LEFT JOIN objectives o ON t.id = o.team_id AND o.deleted_at IS NULL $qObjClause
             WHERE t.id IN ($teamIdsList)
             GROUP BY t.id, t.name";
    $stmt = $db->prepare($sql1);
    $stmt->execute($qObjParams);
    $data['team_health'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // MG-Q2: Department Overall Progress
    $sql2 = "SELECT AVG(progress) as avg_progress, COUNT(id) as total_obj,
                    SUM(CASE WHEN status='Completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status IN ('At Risk', 'Behind') THEN 1 ELSE 0 END) as at_risk
             FROM objectives o
             WHERE team_id IN ($teamIdsList) AND deleted_at IS NULL $qObjClause";
    $stmt = $db->prepare($sql2);
    $stmt->execute($qObjParams);
    $data['dept_stats'] = $stmt->fetch(PDO::FETCH_ASSOC);

    // MG-Q3: Stagnant Key Results (No updates in 14 days)
    $sql3 = "SELECT kr.id, kr.title, kr.progress, o.title as obj_title, u.full_name as owner_name,
                    DATEDIFF(NOW(), COALESCE(MAX(ph.created_at), kr.created_at)) as days_stagnant
             FROM key_results kr
             JOIN objectives o ON kr.objective_id = o.id
             JOIN users u ON kr.owner_id = u.id
             LEFT JOIN progress_history ph ON kr.id = ph.key_result_id
             WHERE o.team_id IN ($teamIdsList) AND kr.deleted_at IS NULL 
             AND kr.status != 'Completed' AND o.deleted_at IS NULL $qObjClause
             GROUP BY kr.id
             HAVING days_stagnant > 14
             ORDER BY days_stagnant DESC LIMIT 5";
    $stmt = $db->prepare($sql3);
    $stmt->execute($qObjParams);
    $data['stagnant_krs'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // MG-Q4: Status Distribution for Chart
    $sql4 = "SELECT status, COUNT(id) as count 
             FROM objectives o
             WHERE team_id IN ($teamIdsList) AND deleted_at IS NULL $qObjClause
             GROUP BY status";
    $stmt = $db->prepare($sql4);
    $stmt->execute($qObjParams);
    $data['status_split'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // MG-Q5: Workload Distribution (Active KRs per member)
    $sql5 = "SELECT u.full_name, COUNT(kr.id) as active_krs
             FROM users u
             JOIN team_members tm ON u.id = tm.user_id
             LEFT JOIN key_results kr ON u.id = kr.owner_id AND kr.status != 'Completed' AND kr.deleted_at IS NULL
             WHERE tm.team_id IN ($teamIdsList)
             GROUP BY u.id
             ORDER BY active_krs DESC LIMIT 5";
    $stmt = $db->query($sql5); // No qParams needed here
    $data['workload'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // MG-Q6: Strategic Alignment Gap (Org objectives with no Team objectives)
    $sql6 = "SELECT o.id, o.title, o.progress
             FROM objectives o
             WHERE o.type = 'Organisational' AND o.deleted_at IS NULL $qObjClause
             AND o.id NOT IN (SELECT parent_objective_id FROM objectives WHERE type = 'Team' AND parent_objective_id IS NOT NULL AND deleted_at IS NULL)
             ORDER BY o.created_at DESC LIMIT 3";
    $stmt = $db->prepare($sql6);
    $stmt->execute($qObjParams);
    $data['alignment_gaps'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Chart Prep
$labels = array_keys($data['status_split'] ?? []);
$counts = array_values($data['status_split'] ?? []);
$chartColors = [
    'On Track' => '#22c55e',
    'Completed' => '#3b82f6',
    'At Risk' => '#f59e0b',
    'Behind' => '#ef4444',
    'Not Started' => '#e2e8f0'
];
$bgColors = array_map(fn($l) => $chartColors[$l] ?? '#ccc', $labels);

function deadlineUrgency($days) {
    if ($days < 0) return 'text-danger fw-bold';
    if ($days <= 3) return 'text-danger';
    if ($days <= 7) return 'text-warning text-dark';
    return 'text-muted';
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
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
/* ── Print/Export PDF CSS ────────────────────────────── */
@media print {
    body { background-color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    #sidebar, .top-header button, .d-print-none, .toast-container { display: none !important; }
    .main-content { margin-left: 0 !important; width: 100% !important; padding: 0 !important; }
    .app-wrapper { display: block !important; }
    .card { border: 1px solid #ddd !important; box-shadow: none !important; page-break-inside: avoid; }
    .page-title { font-size: 24pt !important; margin-bottom: 20px !important; }
}
.widget-card { height: 100%; border: none; border-radius: 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.03); }
.widget-title { font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; color: #64748b; margin-bottom: 1rem; }
.stat-value { font-size: 2.2rem; font-weight: 800; line-height: 1.1; }
.progress-ring { width: 120px; height: 120px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: conic-gradient(var(--bs-primary) var(--p), #e2e8f0 0); position: relative; margin: 0 auto; }
.progress-ring::after { content: ''; position: absolute; width: 90px; height: 90px; background: #fff; border-radius: 50%; }
.progress-ring span { position: relative; z-index: 1; font-size: 1.5rem; font-weight: 800; color: #1e293b; }
.feed-item { padding: 0.75rem 0; border-bottom: 1px solid #f1f5f9; }
.feed-item:last-child { border-bottom: none; }
</style>
</head>
<body>
<div class="app-wrapper">
<?php include 'includes/nav.php'; ?>
<main class="main-content">
  <header class="top-header d-flex justify-content-between align-items-center flex-wrap">
    <div>
        <h1 class="page-title mb-0 d-flex align-items-center gap-2">
            Dashboard 
            <small class="text-muted fs-6 fw-normal ms-2">
                — <?= htmlspecialchars($selectedQ ?: 'All Periods') ?>
            </small>
        </h1>
        <div class="page-breadcrumb">Welcome back, <?= htmlspecialchars($user['full_name']) ?></div>
    </div>
    
    <div class="d-flex gap-2 mt-3 mt-md-0 d-print-none">
      <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-printer me-1"></i> Export PDF
      </button>

      <form method="GET" class="d-flex align-items-center">
        <select name="q" class="form-select form-select-sm" onchange="this.form.submit()" style="min-width: 140px;">
          <option value="">All Periods</option>
          <?php foreach ($availableQuarters as $q): ?>
            <option value="<?= htmlspecialchars($q) ?>" <?= $selectedQ === $q ? 'selected' : '' ?>>
              <?= htmlspecialchars($q) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
  </header>

  <div class="page-body">
    
    <?php if ($role === 'Member'): ?>
    <div class="row g-3 mb-4">
      <div class="col-md-3">
        <div class="card widget-card"><div class="card-body text-center">
          <div class="widget-title">Overall Progress</div>
          <div class="progress-ring my-3" style="--p: <?= round((float)($data['overall_progress']['avg_progress'] ?? 0)) ?>%">
            <span><?= round((float)($data['overall_progress']['avg_progress'] ?? 0)) ?>%</span>
          </div>
          <div class="text-muted small">Across <?= $data['overall_progress']['total_obj'] ?? 0 ?> objectives</div>
        </div></div>
      </div>
      <div class="col-md-5">
        <div class="card widget-card"><div class="card-body">
          <div class="widget-title d-flex justify-content-between"><span>My Priority Objectives</span> <a href="objectives.php" class="text-decoration-none small">View All</a></div>
          <?php if (empty($data['my_objectives'])): ?>
            <div class="text-muted text-center py-4 small">No active objectives found.</div>
          <?php else: foreach($data['my_objectives'] as $obj): ?>
            <div class="d-flex align-items-center gap-3 mb-3">
              <div class="flex-grow-1">
                <div class="fw-600" style="font-size:0.9rem"><?= htmlspecialchars($obj['title']) ?></div>
                <div class="d-flex align-items-center gap-2 mt-1">
                  <div class="progress flex-grow-1" style="height:6px"><div class="progress-bar <?= statusClass($obj['status']) ?>" style="width:<?= (float)$obj['progress'] ?>%"></div></div>
                  <span class="small fw-bold" style="min-width:35px"><?= round((float)$obj['progress']) ?>%</span>
                </div>
              </div>
              <a href="objectives.php?type=Personal" class="btn btn-sm btn-outline-primary btn-icon"><i class="bi bi-arrow-right"></i></a>
            </div>
          <?php endforeach; endif; ?>
        </div></div>
      </div>
      <div class="col-md-4">
        <div class="card widget-card border-warning border-opacity-50"><div class="card-body">
          <div class="widget-title text-warning"><i class="bi bi-bell-fill me-1"></i> Upcoming Deadlines</div>
          <?php if (empty($data['deadlines'])): ?>
            <div class="text-muted text-center py-4 small">No approaching deadlines.</div>
          <?php else: foreach($data['deadlines'] as $dl): ?>
            <div class="feed-item d-flex justify-content-between align-items-start">
              <div>
                <div class="fw-600" style="font-size:0.85rem"><?= htmlspecialchars($dl['title']) ?></div>
                <div class="small <?= deadlineUrgency($dl['days_left']) ?>">
                  <?= $dl['days_left'] < 0 ? 'Overdue by '.abs($dl['days_left']).' days' : ($dl['days_left'] == 0 ? 'Due Today!' : 'Due in '.$dl['days_left'].' days') ?>
                </div>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div></div>
      </div>
    </div>
    <div class="row g-3">
      <div class="col-md-4">
        <div class="card widget-card"><div class="card-body">
          <div class="widget-title">Status Distribution</div>
          <div style="height:200px;position:relative"><canvas id="statusChart"></canvas></div>
        </div></div>
      </div>
      <div class="col-md-8">
        <div class="card widget-card"><div class="card-body">
          <div class="widget-title d-flex justify-content-between">
            <span>Recent Activity</span>
            <span class="badge bg-primary rounded-pill"><?= $data['activity_stats']['this_week'] ?? 0 ?> updates this week</span>
          </div>
          <?php if (empty($data['recent_activity'])): ?>
            <div class="text-muted text-center py-4 small">No recent updates. Keep pushing!</div>
          <?php else: foreach($data['recent_activity'] as $act): 
            $diff = (float)$act['new_value'] - (float)$act['previous_value'];
            $icon = $diff >= 0 ? '<i class="bi bi-arrow-up-right text-success"></i>' : '<i class="bi bi-arrow-down-right text-danger"></i>';
          ?>
            <div class="feed-item">
              <div class="d-flex gap-2">
                <div class="mt-1"><?= $icon ?></div>
                <div>
                  <div style="font-size:0.9rem">Updated <strong><?= htmlspecialchars($act['kr_title']) ?></strong> from <?= (float)$act['previous_value'] ?> to <?= (float)$act['new_value'] ?></div>
                  <div class="text-muted" style="font-size:0.75rem"><?= htmlspecialchars($act['obj_title']) ?> · <?= date('M j, g:i a', strtotime($act['created_at'])) ?></div>
                </div>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div></div>
      </div>
    </div>

    <?php elseif ($role === 'Manager'): ?>
    <div class="row g-3 mb-4">
      <div class="col-md-3">
        <div class="card widget-card"><div class="card-body">
          <div class="widget-title">Department Progress</div>
          <div class="stat-value text-primary mb-2"><?= round((float)($data['dept_stats']['avg_progress'] ?? 0)) ?>%</div>
          <div class="text-muted small"><?= $data['dept_stats']['completed'] ?? 0 ?> Completed · <span class="text-danger"><?= $data['dept_stats']['at_risk'] ?? 0 ?> At Risk</span></div>
        </div></div>
      </div>
      <div class="col-md-5">
        <div class="card widget-card"><div class="card-body">
          <div class="widget-title">Team Health</div>
          <?php if (empty($data['team_health'])): ?>
            <div class="text-muted small">No teams assigned.</div>
          <?php else: ?>
            <div class="table-responsive"><table class="table table-sm table-borderless align-middle mb-0">
              <tbody>
                <?php foreach($data['team_health'] as $th): ?>
                <tr>
                  <td class="fw-600" style="font-size:0.9rem"><?= htmlspecialchars($th['name']) ?></td>
                  <td style="width:40%">
                    <div class="progress" style="height:6px"><div class="progress-bar bg-primary" style="width:<?= (float)$th['avg_progress'] ?>%"></div></div>
                  </td>
                  <td class="text-end small fw-bold"><?= round((float)$th['avg_progress']) ?>%</td>
                  <td class="text-end"><?php if($th['risk_count']>0): ?><span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25" title="Objectives At Risk"><i class="bi bi-exclamation-triangle"></i> <?= $th['risk_count'] ?></span><?php endif; ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table></div>
          <?php endif; ?>
        </div></div>
      </div>
      <div class="col-md-4">
        <div class="card widget-card"><div class="card-body">
          <div class="widget-title"><i class="bi bi-link-45deg"></i> Strategic Alignment Gaps</div>
          <?php if (empty($data['alignment_gaps'])): ?>
            <div class="alert alert-success py-2 small mb-0"><i class="bi bi-check-circle me-1"></i> All Organisational objectives have linked Team objectives.</div>
          <?php else: ?>
            <div class="alert alert-warning py-2 small mb-3"><i class="bi bi-exclamation-triangle me-1"></i> These top-level goals have no teams assigned to them.</div>
            <?php foreach($data['alignment_gaps'] as $gap): ?>
              <div class="feed-item py-1">
                <div class="fw-600 text-truncate" style="font-size:0.85rem" title="<?= htmlspecialchars($gap['title']) ?>"><?= htmlspecialchars($gap['title']) ?></div>
              </div>
            <?php endforeach; endif; ?>
        </div></div>
      </div>
    </div>
    
    <div class="row g-3">
      <div class="col-md-7">
        <div class="card widget-card border-danger border-opacity-25"><div class="card-body">
          <div class="widget-title text-danger"><i class="bi bi-activity me-1"></i> Stagnant Key Results (>14 days no update)</div>
          <?php if (empty($data['stagnant_krs'])): ?>
            <div class="text-muted text-center py-4 small">Great job! All team key results have been updated recently.</div>
          <?php else: ?>
            <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
              <thead class="text-muted small"><tr><th>Key Result</th><th>Owner</th><th class="text-end">Stagnant</th></tr></thead>
              <tbody>
                <?php foreach($data['stagnant_krs'] as $kr): ?>
                <tr>
                  <td><div class="fw-600" style="font-size:0.85rem"><?= htmlspecialchars($kr['title']) ?></div><div class="text-muted" style="font-size:0.75rem"><?= htmlspecialchars($kr['obj_title']) ?></div></td>
                  <td style="font-size:0.85rem"><i class="bi bi-person text-muted"></i> <?= htmlspecialchars($kr['owner_name']) ?></td>
                  <td class="text-end text-danger small fw-bold"><?= $kr['days_stagnant'] ?> days</td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table></div>
          <?php endif; ?>
        </div></div>
      </div>
      <div class="col-md-5">
        <div class="row g-3 h-100">
          <div class="col-12 h-50">
            <div class="card widget-card"><div class="card-body d-flex flex-column">
              <div class="widget-title">Status Split</div>
              <div class="flex-grow-1" style="position:relative; min-height:150px"><canvas id="statusChart"></canvas></div>
            </div></div>
          </div>
          <div class="col-12 h-50">
            <div class="card widget-card"><div class="card-body">
              <div class="widget-title">Workload Distribution</div>
              <?php foreach($data['workload'] as $wl): ?>
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <span style="font-size:0.85rem"><?= htmlspecialchars($wl['full_name']) ?></span>
                  <span class="badge bg-secondary rounded-pill"><?= $wl['active_krs'] ?> KRs</span>
                </div>
              <?php endforeach; ?>
            </div></div>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

  </div>
</main>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    <?php if (!empty($labels)): ?>
    const ctx = document.getElementById('statusChart').getContext('2d');
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($labels) ?>,
            datasets: [{
                data: <?= json_encode($counts) ?>,
                backgroundColor: <?= json_encode($bgColors) ?>,
                borderWidth: 0,
                hoverOffset: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '75%',
            plugins: {
                legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11, family: "'Plus Jakarta Sans', sans-serif" } } }
            }
        }
    });
    <?php endif; ?>
});
</script>
</body>
</html>