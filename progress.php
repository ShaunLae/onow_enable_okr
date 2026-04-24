<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';
requireLogin();
$user = getCurrentUser(); $role = $user['role'];
if ($role === 'Admin') { header('Location: dashboard.php'); exit; }
$currentPage = 'progress';
$userId = $user['id'];

$objectiveId = isset($_GET['objective_id']) ? (int)$_GET['objective_id'] : 0;
$krId        = isset($_GET['kr_id'])        ? (int)$_GET['kr_id']        : 0;
$objectives  = getObjectives($userId, $role);

$selectedObj = $objectiveId ? getObjective($objectiveId) : null;
$keyResults  = $selectedObj  ? getKeyResults($objectiveId) : [];
$selectedKR  = null;
$history     = [];

if ($krId && !empty($keyResults)) {
    foreach ($keyResults as $kr) {
        if ($kr['id'] == $krId) { $selectedKR = $kr; break; }
    }
    if ($selectedKR) $history = getProgressHistory($krId);
}

$canUpdate = $selectedKR && (($role === 'Manager') || ($role === 'Member' && $selectedKR['owner_id'] == $userId));
$csrf = getCsrfToken();
function pbClass(string $s): string { return match($s){'On Track'=>'pb-on-track','Completed'=>'pb-completed','At Risk','Behind'=>'pb-at-risk',default=>''}; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Progress Tracker – ONOW Enable OKR</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="css/style.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
</head>
<body>
<div class="app-wrapper">
<?php include 'includes/nav.php'; ?>
<main class="main-content">
  <header class="top-header">
    <button class="btn btn-sm btn-outline-secondary d-md-none" onclick="document.getElementById('sidebar').classList.toggle('open')"><i class="bi bi-list"></i></button>
    <div><div class="page-title">Progress Tracker</div><div class="page-breadcrumb">Key result history and trend analysis</div></div>
  </header>
  <div class="page-body">
    <div class="row g-3">

      <!-- Left: Objective + KR selector -->
      <div class="col-lg-4">
        <div class="card mb-3">
          <div class="card-header"><i class="bi bi-bullseye text-primary"></i><span class="card-title">Select Objective</span></div>
          <div class="card-body p-0" style="max-height:300px;overflow-y:auto">
            <?php if (empty($objectives)): ?>
            <div class="p-3 text-muted text-center small">No objectives found.</div>
            <?php else: foreach ($objectives as $o): $sel=$o['id']==$objectiveId; ?>
            <a href="progress.php?objective_id=<?= $o['id'] ?>"
               class="d-flex align-items-center gap-2 px-3 py-2 text-decoration-none border-bottom"
               style="background:<?= $sel?'var(--primary)':'' ?>;color:<?= $sel?'#fff':'inherit' ?>;transition:.1s">
              <div class="flex-grow-1">
                <div class="d-flex align-items-center gap-1 mb-1">
                  <span class="type-badge type-<?= strtolower($o['type']) ?>" style="<?= $sel?'opacity:.85':'' ?>"><?= substr($o['type'],0,1) ?></span>
                  <span style="font-size:.85rem;font-weight:700"><?= htmlspecialchars(mb_strimwidth($o['title'],0,36,'…')) ?></span>
                </div>
                <div class="d-flex align-items-center gap-2">
                  <div class="progress flex-grow-1" style="height:4px;background:<?= $sel?'rgba(255,255,255,.3)':'' ?>">
                    <div style="height:4px;border-radius:99px;background:<?= $sel?'#fff':'#059669' ?>;width:<?= $o['progress'] ?>%"></div>
                  </div>
                  <span style="font-size:.72rem;font-weight:700;opacity:.8"><?= round($o['progress']) ?>%</span>
                </div>
              </div>
            </a>
            <?php endforeach; endif; ?>
          </div>
        </div>

        <?php if ($selectedObj && !empty($keyResults)): ?>
        <div class="card">
          <div class="card-header"><i class="bi bi-check2-circle text-primary"></i><span class="card-title">Key Results</span></div>
          <div class="card-body p-0">
            <?php foreach ($keyResults as $kr): $sel=$kr['id']==$krId; $canUpd=($role==='Manager')||($role==='Member'&&$kr['owner_id']==$userId); ?>
            <a href="progress.php?objective_id=<?= $objectiveId ?>&kr_id=<?= $kr['id'] ?>"
               class="d-block px-3 py-2 border-bottom text-decoration-none text-dark"
               style="border-left:3px solid <?= $sel?'var(--primary)':'transparent' ?>;background:<?= $sel?'var(--primary-lt)':'' ?>">
              <div class="d-flex align-items-center gap-1 mb-1">
                <span style="font-size:.83rem;font-weight:600;flex:1"><?= htmlspecialchars($kr['title']) ?></span>
                <span title="<?= $canUpd?'You can update this':'View only' ?>" style="font-size:.7rem;color:var(--<?= $canUpd?'primary':'muted' ?>)"><i class="bi bi-<?= $canUpd?'pencil-square':'eye' ?>"></i></span>
              </div>
              <div class="d-flex align-items-center gap-2">
                <div class="progress flex-grow-1" style="height:5px"><div class="progress-bar <?= pbClass($kr['status']) ?>" style="width:<?= $kr['progress'] ?>%"></div></div>
                <span style="font-size:.72rem;font-weight:700"><?= round($kr['progress']) ?>%</span>
              </div>
              <div class="text-muted" style="font-size:.72rem">Owner: <?= htmlspecialchars($kr['owner_name']) ?></div>
            </a>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Right: Detail + History -->
      <div class="col-lg-8">
        <?php if ($selectedKR): ?>
        <div class="card mb-3">
          <div class="card-header">
            <i class="bi bi-graph-up text-primary"></i>
            <div class="flex-grow-1">
              <span class="card-title"><?= htmlspecialchars($selectedKR['title']) ?></span>
              <div class="text-muted" style="font-size:.75rem">Owner: <?= htmlspecialchars($selectedKR['owner_name']) ?></div>
            </div>
            <span class="status-badge <?= statusClass($selectedKR['status']) ?> ms-auto"><?= $selectedKR['status'] ?></span>
          </div>
          <div class="card-body">
            <div class="row g-3 mb-3 text-center">
              <?php foreach ([['Current',$selectedKR['current_value'],'var(--primary)'],['Target',$selectedKR['target_value'],'var(--text2)'],['Progress',round($selectedKR['progress']).'%','var(--success)']] as [$lbl,$val,$col]): ?>
              <div class="col-4">
                <div class="text-muted" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px"><?= $lbl ?></div>
                <div style="font-size:1.8rem;font-weight:800;color:<?= $col ?>"><?= is_numeric($val)?number_format((float)$val,1):$val ?></div>
                <div class="text-muted" style="font-size:.75rem"><?= htmlspecialchars($selectedKR['unit']) ?></div>
              </div>
              <?php endforeach; ?>
            </div>
            <div class="progress mb-3" style="height:12px"><div class="progress-bar <?= pbClass($selectedKR['status']) ?>" style="width:<?= $selectedKR['progress'] ?>%"></div></div>
            <?php if ($canUpdate): ?>
            <button class="btn btn-primary btn-sm" onclick="openProgressModal()"><i class="bi bi-pencil-square me-1"></i>Update Progress</button>
            <?php else: ?>
            <div class="alert alert-secondary py-2 small mb-0"><i class="bi bi-lock me-1"></i>View only — assigned to <?= htmlspecialchars($selectedKR['owner_name']) ?>.</div>
            <?php endif; ?>
            <?php if (!empty($history)): ?>
            <hr class="my-3">
            <canvas id="trendChart" height="150"></canvas>
            <?php endif; ?>
          </div>
        </div>

        <!-- History Table -->
        <div class="card">
          <div class="card-header">
            <i class="bi bi-clock-history text-primary"></i><span class="card-title">Progress History</span>
            <span class="ms-auto text-muted small"><?= count($history) ?> update<?= count($history)!==1?'s':'' ?></span>
          </div>
          <div class="card-body p-0">
            <?php if (empty($history)): ?>
            <div class="empty-state py-4"><i class="bi bi-clock-history" style="font-size:2rem"></i><h5 class="mt-2">No History Yet</h5><p class="small">Progress updates will appear here.</p></div>
            <?php else: ?>
            <div class="table-responsive">
              <table class="table mb-0">
                <thead><tr><th>Date &amp; Time</th><th>Previous</th><th>New Value</th><th>Change</th><th>Updated By</th><th>Note</th></tr></thead>
                <tbody>
                <?php foreach ($history as $h): $diff=$h['new_value']-$h['previous_value']; $dc=$diff>0?'text-success':($diff<0?'text-danger':'text-muted'); $di=$diff>0?'bi-arrow-up':($diff<0?'bi-arrow-down':'bi-dash'); ?>
                <tr>
                  <td class="text-muted" style="font-size:.8rem"><?= date('d M Y, H:i',strtotime($h['created_at'])) ?></td>
                  <td><?= number_format($h['previous_value'],1) ?></td>
                  <td><strong><?= number_format($h['new_value'],1) ?></strong></td>
                  <td class="<?= $dc ?>"><i class="bi <?= $di ?>"></i> <?= $diff>0?'+':'' ?><?= number_format($diff,1) ?></td>
                  <td style="font-size:.82rem"><?= htmlspecialchars($h['updated_by_name']) ?></td>
                  <td class="text-muted" style="font-size:.8rem"><?= htmlspecialchars($h['note']??'—') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <?php elseif ($selectedObj): ?>
        <div class="card"><div class="card-header"><i class="bi bi-bullseye text-primary"></i><span class="card-title"><?= htmlspecialchars($selectedObj['title']) ?></span></div>
        <div class="card-body">
          <?php if (empty($keyResults)): ?>
          <div class="empty-state py-3"><i class="bi bi-check2-circle" style="font-size:2rem"></i><h5 class="mt-2">No Key Results</h5><p class="small">Add key results to track progress.</p><a href="objectives.php" class="btn btn-primary btn-sm mt-2">Manage Objectives</a></div>
          <?php else: ?>
          <p class="text-muted">Select a key result from the left panel to view its history.</p>
          <?php endif; ?>
        </div></div>

        <?php else: ?>
        <div class="card"><div class="card-body">
          <div class="empty-state py-4"><i class="bi bi-graph-up" style="font-size:2.5rem"></i><h5 class="mt-2">Select an Objective</h5><p class="small">Choose an objective from the left panel to begin.</p></div>
        </div></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</main>
</div>

<!-- Progress Modal -->
<?php if ($canUpdate && $selectedKR): ?>
<div class="modal fade" id="progressModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Update Progress</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <p class="fw-700 mb-3" style="font-size:.9rem"><?= htmlspecialchars($selectedKR['title']) ?></p>
      <form id="progressForm">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="update_progress">
        <input type="hidden" name="kr_id" value="<?= $selectedKR['id'] ?>">
        <div class="mb-3">
          <label class="form-label">New Value <span class="text-danger">*</span></label>
          <div class="input-group"><input type="number" name="new_value" id="progValue" class="form-control" value="<?= $selectedKR['current_value'] ?>" step="any" min="0" required><span class="input-group-text">/ <?= $selectedKR['target_value'] ?></span></div>
          <div class="invalid-feedback" id="progValueFeedback"></div>
          <div class="mt-2">
            <div class="d-flex justify-content-between mb-1"><small class="text-muted">Estimated progress</small><small class="fw-700" id="progPct"><?= round($selectedKR['progress']) ?>%</small></div>
            <div class="progress"><div class="progress-bar pb-on-track" id="progBar" style="width:<?= $selectedKR['progress'] ?>%"></div></div>
          </div>
        </div>
        <div><label class="form-label">Note <span class="text-muted small">(optional)</span></label><textarea name="note" class="form-control" rows="2" placeholder="What changed?"></textarea></div>
      </form>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
      <button type="button" class="btn btn-primary" onclick="submitProgress()"><i class="bi bi-graph-up me-1"></i>Save Progress</button>
    </div>
  </div></div>
</div>
<?php endif; ?>

<div id="toastContainer"></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php if (!empty($history)): ?>
<script>
const hData=<?= json_encode(array_reverse($history)) ?>;
const labels=hData.map(h=>{const d=new Date(h.created_at.replace(' ','T'));return d.toLocaleDateString('en-GB',{day:'numeric',month:'short'});});
new Chart(document.getElementById('trendChart'),{type:'line',data:{labels,datasets:[
  {label:'Actual',data:hData.map(h=>parseFloat(h.new_value)),borderColor:'#2563EB',backgroundColor:'rgba(37,99,235,.08)',fill:true,tension:.35,pointRadius:4,pointBackgroundColor:'#2563EB'},
  {label:'Target',data:new Array(labels.length).fill(<?= $selectedKR['target_value'] ?>),borderColor:'#dc2626',borderDash:[6,4],borderWidth:1.5,pointRadius:0,fill:false}
]},options:{responsive:true,plugins:{legend:{position:'bottom',labels:{font:{size:11}}}},scales:{x:{grid:{display:false},ticks:{font:{size:11}}},y:{grid:{color:'#f1f5f9'},ticks:{font:{size:11}}}}}});
</script>
<?php endif; ?>
<script>
const progTarget=<?= $selectedKR?$selectedKR['target_value']:100 ?>;
function openProgressModal(){new bootstrap.Modal(document.getElementById('progressModal')).show();}
document.getElementById('progValue')?.addEventListener('input',updateProgPreview);
function updateProgPreview(){
  const input=document.getElementById('progValue');
  const feedback=document.getElementById('progValueFeedback');
  const bar=document.getElementById('progBar');
  const pctEl=document.getElementById('progPct');
  const v=parseFloat(input?.value)||0;

  if(v>progTarget){
    input?.classList.add('is-invalid');
    if(feedback) feedback.textContent=`Value cannot exceed the target (${progTarget}). Enter ${progTarget} or less.`;
    input?.setCustomValidity(`Cannot exceed target value of ${progTarget}.`);
    if(bar) bar.style.width='100%';
    if(pctEl) pctEl.textContent='100%';
    return;
  }

  input?.classList.remove('is-invalid');
  if(feedback) feedback.textContent='';
  input?.setCustomValidity('');

  const p=progTarget>0?Math.min(100,Math.round((v/progTarget)*100)):0;
  if(pctEl) pctEl.textContent=p+'%';
  if(bar) bar.style.width=p+'%';

  bar?.classList.remove('pb-on-track','pb-at-risk','pb-behind');
  if(p>=70) bar?.classList.add('pb-on-track');
  else if(p>=40) bar?.classList.add('pb-at-risk');
  else bar?.classList.add('pb-behind');
}
function submitProgress(){
  const form=document.getElementById('progressForm');
  const input=document.getElementById('progValue');
  const v=parseFloat(input?.value)||0;
  if(v>progTarget){
    input?.setCustomValidity(`Cannot exceed target value of ${progTarget}.`);
    form.reportValidity();
    return;
  }
  input?.setCustomValidity('');
  if(!form.checkValidity()){form.reportValidity();return;}
  fetch('php/kr_api.php',{method:'POST',body:new FormData(form)}).then(r=>r.json()).then(res=>{
    if(res.success){
      const t=document.createElement('div');t.className='toast-msg success';t.innerHTML='<i class="bi bi-check-circle"></i> Progress updated!';
      document.getElementById('toastContainer').appendChild(t);setTimeout(()=>t.remove(),3000);
      bootstrap.Modal.getInstance(document.getElementById('progressModal')).hide();
      setTimeout(()=>location.reload(),800);
    } else {
      const t=document.createElement('div');t.className='toast-msg error';t.innerHTML=`<i class="bi bi-x-circle"></i> ${res.message||'Error'}`;
      document.getElementById('toastContainer').appendChild(t);setTimeout(()=>t.remove(),3500);
    }
  });
}
</script>
</body></html>
