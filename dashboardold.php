<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';
requireLogin();
$user = getCurrentUser(); $role = $user['role'];
$currentPage = 'dashboard';
$stats = getDashboardStats($user['id'], $role);
$recent = getRecentActivity($user['id'], $role, 8);
$objectives = $role !== 'Admin' ? getObjectives($user['id'], $role) : [];
$typeCounts = ['Organisational'=>0,'Team'=>0,'Personal'=>0];
foreach ($objectives as $o) if (isset($typeCounts[$o['type']])) $typeCounts[$o['type']]++;
$HEAD = '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"><link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link href="css/style.css" rel="stylesheet"><script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>';
$actLabels = ['LOGIN'=>['Signed in','bi-box-arrow-in-right','#3b82f6'],'LOGOUT'=>['Signed out','bi-box-arrow-right','#64748b'],'CREATE_OBJECTIVE'=>['Created objective','bi-plus-circle','#059669'],'UPDATE_OBJECTIVE'=>['Updated objective','bi-pencil','#d97706'],'SOFT_DELETE_OBJECTIVE'=>['Deleted objective','bi-trash','#dc2626'],'CREATE_KEY_RESULT'=>['Added key result','bi-plus-square','#7c3aed'],'UPDATE_PROGRESS'=>['Progress update','bi-graph-up','#0891b2'],'SOFT_DELETE_KEY_RESULT'=>['Deleted key result','bi-trash','#dc2626'],'CREATE_TEAM'=>['Created team','bi-diagram-3','#7c3aed'],'CREATE_USER'=>['Created user','bi-person-plus','#059669']];
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dashboard – ONOW Enable OKR</title><?= $HEAD ?></head><body>
<div class="app-wrapper">
<?php include 'includes/nav.php'; ?>
<main class="main-content">
  <header class="top-header">
    <button class="btn btn-sm btn-outline-secondary d-md-none" onclick="document.getElementById('sidebar').classList.toggle('open')"><i class="bi bi-list"></i></button>
    <div><div class="page-title">Dashboard</div>
    <div class="page-breadcrumb">Welcome, <?= htmlspecialchars($user['full_name']) ?> &nbsp;<span class="role-badge role-<?= strtolower($role) ?>"><?= $role ?></span></div></div>
    <?php if ($role!=='Admin'): ?><div class="header-actions"><a href="objectives.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i> New Objective</a></div><?php endif; ?>
  </header>
  <div class="page-body">

  <?php if ($role==='Admin'): ?>
    <div class="alert alert-info d-flex gap-2 mb-4" style="border-radius:12px"><i class="bi bi-shield-check fs-5"></i><div><strong>Administrator View</strong> — Manage users and teams. OKR management is handled by Managers and Members.</div></div>
    <div class="row g-3 mb-4">
    <?php foreach ([['Total Users',$stats['total_users'],'bi-people','var(--primary)','var(--primary-lt)','users.php'],['Teams',$stats['total_teams'],'bi-diagram-3','#7c3aed','#ede9fe','team_management.php'],['Managers',$stats['total_managers'],'bi-person-gear','var(--info)','var(--info-lt)','users.php'],['Members',$stats['total_members'],'bi-person','var(--success)','var(--success-lt)','users.php']] as [$lbl,$val,$ico,$col,$bg,$href]): ?>
    <div class="col-6 col-lg-3"><a href="<?= $href ?>" class="stat-card d-block text-decoration-none">
      <div class="stat-card-accent" style="background:<?= $col ?>"></div>
      <div class="stat-icon" style="background:<?= $bg ?>;color:<?= $col ?>"><i class="bi <?= $ico ?>"></i></div>
      <div class="stat-label"><?= $lbl ?></div>
      <div class="stat-value" style="color:<?= $col ?>"><?= $val ?></div>
    </a></div>
    <?php endforeach; ?>
    </div>
    <div class="row g-3 mb-4">
      <div class="col-md-6"><div class="stat-card"><div class="stat-card-accent" style="background:var(--warning)"></div><div class="stat-icon" style="background:var(--warning-lt);color:var(--warning)"><i class="bi bi-bullseye"></i></div><div class="stat-label">Total Objectives</div><div class="stat-value" style="color:var(--warning)"><?= $stats['total_objectives'] ?></div></div></div>
      <div class="col-md-6"><div class="stat-card"><div class="stat-card-accent" style="background:var(--secondary)"></div><div class="stat-icon" style="background:#ede9fe;color:var(--secondary)"><i class="bi bi-check2-circle"></i></div><div class="stat-label">Total Key Results</div><div class="stat-value" style="color:var(--secondary)"><?= $stats['total_key_results'] ?></div></div></div>
    </div>
    <div class="row g-3">
      <div class="col-lg-6"><div class="card"><div class="card-header"><i class="bi bi-diagram-3 text-primary"></i><span class="card-title">Teams</span><a href="team_management.php" class="btn btn-sm btn-outline-primary ms-auto">Manage</a></div>
      <div class="card-body p-0"><?php foreach (getAllTeams() as $t): ?><div class="d-flex align-items-center gap-2 px-4 py-2 border-bottom"><div style="width:32px;height:32px;background:#ede9fe;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="bi bi-people" style="color:#7c3aed;font-size:.85rem"></i></div><div class="flex-grow-1"><div class="fw-700" style="font-size:.88rem"><?= htmlspecialchars($t['name']) ?></div><div class="text-muted" style="font-size:.75rem"><?= $t['member_count'] ?> members</div></div></div><?php endforeach; ?></div></div></div>
      <div class="col-lg-6"><div class="card"><div class="card-header"><i class="bi bi-activity text-primary"></i><span class="card-title">Recent Activity</span></div><div class="card-body"><?php foreach ($recent as $a): [$al,$ai,$ac]=$actLabels[$a['action']]??[$a['action'],'bi-circle','#64748b']; ?><div class="timeline-item"><div style="width:28px;height:28px;border-radius:8px;background:<?= $ac ?>20;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:.1rem"><i class="bi <?= $ai ?>" style="color:<?= $ac ?>;font-size:.8rem"></i></div><div class="timeline-content"><div class="timeline-action"><?= $al ?></div><div class="timeline-time"><?= htmlspecialchars($a['full_name']??'System') ?> · <?= date('d M, H:i',strtotime($a['created_at'])) ?></div></div></div><?php endforeach; ?></div></div></div>
    </div>

  <?php else: ?>
    <div class="row g-3 mb-4">
    <?php foreach ([['Objectives',$stats['total_objectives'],'bi-bullseye','var(--primary)','var(--primary-lt)',$stats['total_key_results'].' key results'],['On Track',$stats['on_track'],'bi-check-circle','var(--success)','var(--success-lt)',$stats['completed'].' completed'],['At Risk',$stats['at_risk'],'bi-exclamation-triangle','var(--warning)','var(--warning-lt)',$stats['not_started'].' not started'],['Avg Progress',$stats['avg_progress'].'%','bi-bar-chart','var(--secondary)','#ede9fe','Across all objectives']] as [$lbl,$val,$ico,$col,$bg,$sub]): ?>
    <div class="col-6 col-lg-3"><div class="stat-card"><div class="stat-card-accent" style="background:<?= $col ?>"></div><div class="stat-icon" style="background:<?= $bg ?>;color:<?= $col ?>"><i class="bi <?= $ico ?>"></i></div><div class="stat-label"><?= $lbl ?></div><div class="stat-value" style="color:<?= $col ?>"><?= $val ?></div><div class="stat-sub"><?= $sub ?></div></div></div>
    <?php endforeach; ?>
    </div>
    <div class="row g-3 mb-4">
      <div class="col-lg-8"><div class="card h-100"><div class="card-header"><i class="bi bi-bar-chart-line text-primary"></i><span class="card-title">Objectives Overview</span>
        <select class="form-select form-select-sm ms-auto" id="chartFilter" style="width:auto" onchange="renderChart(this.value)"><option value="all">All Types</option><?php if($role==='Manager'): ?><option value="Organisational">Organisational</option><option value="Team">Team</option><?php endif; ?><option value="Personal">Personal</option></select>
      </div><div class="card-body"><?php if(empty($objectives)): ?><div class="empty-state"><i class="bi bi-bar-chart"></i><h5>No Objectives Yet</h5><a href="objectives.php" class="btn btn-primary btn-sm mt-2">Get Started</a></div><?php else: ?><canvas id="objChart" height="220"></canvas><?php endif; ?></div></div></div>
      <div class="col-lg-4"><div class="card h-100"><div class="card-header"><i class="bi bi-pie-chart text-primary"></i><span class="card-title">Status Split</span></div>
      <div class="card-body d-flex flex-column align-items-center"><canvas id="statusChart" width="160" height="160" style="max-width:160px"></canvas><div class="mt-3 w-100">
      <?php foreach ([['On Track',$stats['on_track'],'#059669'],['At Risk',$stats['at_risk'],'#d97706'],['Completed',$stats['completed'],'#3b82f6'],['Not Started',$stats['not_started'],'#e2e8f0']] as [$lbl,$v,$c]): if($v<=0) continue; ?>
      <div class="d-flex align-items-center justify-content-between mb-2"><div class="d-flex align-items-center gap-2"><span style="width:10px;height:10px;border-radius:50%;background:<?= $c ?>;display:inline-block"></span><span style="font-size:.82rem;font-weight:600"><?= $lbl ?></span></div><span style="font-size:.82rem;font-weight:700"><?= $v ?></span></div>
      <?php endforeach; ?></div></div></div></div>
    </div>
    <div class="row g-3">
      <div class="col-lg-7"><div class="card"><div class="card-header"><i class="bi bi-bullseye text-primary"></i><span class="card-title">Recent Objectives</span><a href="objectives.php" class="btn btn-sm btn-outline-primary ms-auto">View All</a></div>
      <div class="card-body p-0"><?php foreach (array_slice($objectives,0,5) as $o): $pct=(float)$o['progress']; $bc=match($o['status']){'On Track'=>'pb-on-track','Completed'=>'pb-completed','At Risk','Behind'=>'pb-at-risk',default=>''}; ?>
      <div class="px-4 py-3 border-bottom" style="cursor:pointer" onclick="window.location='objectives.php'">
        <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
          <div><span class="type-badge type-<?= strtolower($o['type']) ?> me-2"><?= $o['type'] ?></span><span class="fw-700" style="font-size:.9rem"><?= htmlspecialchars($o['title']) ?></span></div>
          <span class="status-badge <?= statusClass($o['status']) ?> flex-shrink-0"><?= $o['status'] ?></span>
        </div>
        <div class="d-flex align-items-center gap-2"><div class="progress flex-grow-1"><div class="progress-bar <?= $bc ?>" style="width:<?= $pct ?>%"></div></div><span style="font-size:.82rem;font-weight:700"><?= round($pct) ?>%</span></div>
        <div class="text-muted mt-1" style="font-size:.75rem"><i class="bi bi-calendar3 me-1"></i><?= htmlspecialchars($o['time_period']) ?> · <i class="bi bi-person me-1"></i><?= htmlspecialchars($o['owner_name']) ?></div>
      </div>
      <?php endforeach; ?></div></div></div>
      <div class="col-lg-5"><div class="card"><div class="card-header"><i class="bi bi-activity text-primary"></i><span class="card-title">Recent Activity</span></div><div class="card-body">
      <?php foreach ($recent as $a): [$al,$ai,$ac]=$actLabels[$a['action']]??[$a['action'],'bi-circle','#64748b']; ?>
      <div class="timeline-item"><div style="width:28px;height:28px;border-radius:8px;background:<?= $ac ?>20;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:.1rem"><i class="bi <?= $ai ?>" style="color:<?= $ac ?>;font-size:.8rem"></i></div><div class="timeline-content"><div class="timeline-action"><?= $al ?></div><div class="timeline-time"><?= htmlspecialchars($a['full_name']??'') ?> · <?= date('d M, H:i',strtotime($a['created_at'])) ?></div></div></div>
      <?php endforeach; ?>
      </div></div></div>
    </div>
  <?php endif; ?>
  </div>
</main></div>
<div id="toastContainer"></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($role!=='Admin' && !empty($objectives)): ?>
<script>
const objData=<?= json_encode(array_values(array_map(fn($o)=>['title'=>mb_strimwidth($o['title'],0,28,'…'),'progress'=>(float)$o['progress'],'status'=>$o['status'],'type'=>$o['type']],$objectives))) ?>;
function clr(s){return{'On Track':'#059669','At Risk':'#d97706','Behind':'#dc2626','Completed':'#3b82f6','Not Started':'#cbd5e1'}[s]||'#cbd5e1';}
let oc;
function renderChart(f='all'){
  const d=f==='all'?objData:objData.filter(o=>o.type===f);
  const ctx=document.getElementById('objChart');if(!ctx)return;if(oc)oc.destroy();
  oc=new Chart(ctx,{type:'bar',data:{labels:d.map(o=>o.title),datasets:[{label:'Progress %',data:d.map(o=>o.progress),backgroundColor:d.map(o=>clr(o.status)),borderRadius:6,borderSkipped:false}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:{display:false},ticks:{font:{size:11},maxRotation:30}},y:{min:0,max:100,ticks:{callback:v=>v+'%',font:{size:11}},grid:{color:'#f1f5f9'}}}}});
}
new Chart(document.getElementById('statusChart'),{type:'doughnut',data:{labels:['On Track','At Risk','Completed','Not Started'],datasets:[{data:[<?= $stats['on_track'] ?>,<?= $stats['at_risk'] ?>,<?= $stats['completed'] ?>,<?= $stats['not_started'] ?>],backgroundColor:['#059669','#d97706','#3b82f6','#e2e8f0'],borderWidth:2,borderColor:'#fff'}]},options:{cutout:'68%',plugins:{legend:{display:false}}}});
renderChart();
</script>
<?php endif; ?>
</body></html>
