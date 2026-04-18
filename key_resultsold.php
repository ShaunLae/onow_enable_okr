<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';
requireLogin();
$user = getCurrentUser(); $role = $user['role'];
if ($role === 'Admin') { header('Location: dashboard.php'); exit; }
$currentPage = 'key_results';
$userId = $user['id'];

$objectives = getObjectives($userId, $role);
$allKRs = [];
foreach ($objectives as $obj) {
    foreach (getKeyResults($obj['id']) as $kr) {
        $kr['objective_title'] = $obj['title'];
        $kr['objective_type']  = $obj['type'];
        $kr['objective_id_ref']= $obj['id'];
        $kr['can_update'] = ($role === 'Manager') || ($role === 'Member' && $kr['owner_id'] == $userId);
        $allKRs[] = $kr;
    }
}
$total     = count($allKRs);
$onTrack   = count(array_filter($allKRs, fn($k) => $k['status'] === 'On Track'));
$atRisk    = count(array_filter($allKRs, fn($k) => in_array($k['status'], ['At Risk','Behind'])));
$completed = count(array_filter($allKRs, fn($k) => $k['status'] === 'Completed'));
$myKRs     = count(array_filter($allKRs, fn($k) => $k['owner_id'] == $userId));
$csrf = getCsrfToken();
function pbClass(string $s): string { return match($s){'On Track'=>'pb-on-track','Completed'=>'pb-completed','At Risk','Behind'=>'pb-at-risk',default=>''}; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Key Results – ONOW Enable OKR</title>
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
    <div>
      <div class="page-title">Key Results</div>
      <div class="page-breadcrumb"><?= $role==='Member'?'Update progress on key results assigned to you':'All measurable key results across your objectives' ?></div>
    </div>
    <div class="header-actions">
      <input type="text" id="srch" class="form-control form-control-sm" placeholder="Search…" style="max-width:180px" oninput="filterKRs()">
      <select id="stFilter" class="form-select form-select-sm" style="max-width:140px" onchange="filterKRs()">
        <option value="">All Statuses</option>
        <option value="On Track">On Track</option>
        <option value="At Risk">At Risk</option>
        <option value="Behind">Behind</option>
        <option value="Completed">Completed</option>
        <option value="Not Started">Not Started</option>
      </select>
    </div>
  </header>
  <div class="page-body">
    <?php if ($role==='Member'): ?>
    <div class="alert alert-info d-flex gap-2 mb-3" style="border-radius:12px;font-size:.88rem">
      <i class="bi bi-info-circle fs-5"></i>
      <div>You can <strong>update progress</strong> on key results assigned to you (<i class="bi bi-pencil-square"></i>). Others are view-only (<i class="bi bi-lock"></i>).</div>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="row g-3 mb-4">
      <?php foreach ([
        ['Total KRs',                $total,    'var(--primary)',   'var(--primary-lt)'],
        ['On Track',                 $onTrack,  'var(--success)',   'var(--success-lt)'],
        ['At Risk / Behind',         $atRisk,   'var(--warning)',   'var(--warning-lt)'],
        [$role==='Member'?'Assigned to Me':'Completed', $role==='Member'?$myKRs:$completed, 'var(--secondary)', '#ede9fe'],
      ] as [$lbl,$val,$col,$bg]): ?>
      <div class="col-6 col-lg-3"><div class="stat-card">
        <div class="stat-card-accent" style="background:<?= $col ?>"></div>
        <div class="stat-label"><?= $lbl ?></div>
        <div class="stat-value" style="color:<?= $col ?>"><?= $val ?></div>
      </div></div>
      <?php endforeach; ?>
    </div>

    <?php if (empty($allKRs)): ?>
    <div class="empty-state card"><div class="card-body">
      <i class="bi bi-check2-circle"></i><h5>No Key Results Yet</h5>
      <p class="small">Go to Objectives to add key results.</p>
      <a href="objectives.php" class="btn btn-primary btn-sm mt-2"><i class="bi bi-bullseye me-1"></i>Go to Objectives</a>
    </div></div>
    <?php else: ?>
    <div class="card"><div class="card-body p-0">
      <div class="table-responsive">
        <table class="table mb-0" id="krTable">
          <thead><tr>
            <th>Key Result</th><th>Objective</th><th>Progress</th>
            <th>Current / Target</th><th>Owner</th><th>Status</th><th>Actions</th>
          </tr></thead>
          <tbody>
          <?php foreach ($allKRs as $kr): $pct=(float)$kr['progress']; ?>
          <tr data-status="<?= htmlspecialchars($kr['status']) ?>">
            <td>
              <div class="fw-700" style="font-size:.87rem"><?= htmlspecialchars($kr['title']) ?></div>
              <div class="text-muted" style="font-size:.75rem"><?= $kr['metric_type'] ?><?= $kr['unit']?' · '.htmlspecialchars($kr['unit']):'' ?></div>
            </td>
            <td>
              <span class="type-badge type-<?= strtolower($kr['objective_type']) ?> me-1"><?= substr($kr['objective_type'],0,1) ?></span>
              <span style="font-size:.82rem"><?= htmlspecialchars(mb_strimwidth($kr['objective_title'],0,35,'…')) ?></span>
            </td>
            <td style="min-width:110px">
              <div class="d-flex align-items-center gap-2">
                <div class="progress flex-grow-1"><div class="progress-bar <?= pbClass($kr['status']) ?>" style="width:<?= $pct ?>%"></div></div>
                <span style="font-size:.78rem;font-weight:700;min-width:30px"><?= round($pct) ?>%</span>
              </div>
            </td>
            <td style="font-size:.85rem"><strong><?= number_format($kr['current_value'],1) ?></strong> / <?= number_format($kr['target_value'],1) ?><?= $kr['unit']?' <span class="text-muted">'.htmlspecialchars($kr['unit']).'</span>':'' ?></td>
            <td>
              <div class="d-flex align-items-center gap-1">
                <?php $ini=strtoupper(substr(implode('',array_map(fn($w)=>$w[0],explode(' ',$kr['owner_name']))),0,2)); $isMe=$kr['owner_id']==$userId; ?>
                <div class="user-avatar" style="background:var(--primary);width:22px;height:22px;font-size:.55rem;border-radius:6px"><?= $ini ?></div>
                <span style="font-size:.82rem"><?= htmlspecialchars($kr['owner_name']) ?><?= $isMe?' <span class="text-primary" style="font-size:.72rem">(You)</span>':'' ?></span>
              </div>
            </td>
            <td><span class="status-badge <?= statusClass($kr['status']) ?>"><?= $kr['status'] ?></span></td>
            <td>
              <div class="d-flex gap-1">
                <a href="progress.php?objective_id=<?= $kr['objective_id_ref'] ?>&kr_id=<?= $kr['id'] ?>" class="btn btn-sm btn-outline-info btn-icon" title="History"><i class="bi bi-graph-up"></i></a>
                <?php if ($kr['can_update']): ?>
                <button class="btn btn-sm btn-outline-primary btn-icon" onclick="openProgressModal(<?= $kr['id'] ?>,'<?= htmlspecialchars(addslashes($kr['title'])) ?>',<?= $kr['current_value'] ?>,<?= $kr['target_value'] ?>)" title="Update Progress"><i class="bi bi-pencil-square"></i></button>
                <?php else: ?>
                <button class="btn btn-sm btn-outline-secondary btn-icon" disabled title="Not assigned to you"><i class="bi bi-lock"></i></button>
                <?php endif; ?>
                <?php if ($role==='Manager'): ?>
                <button class="btn btn-sm btn-outline-secondary btn-icon" onclick="editKR(<?= $kr['id'] ?>,<?= htmlspecialchars(json_encode($kr)) ?>)" title="Edit"><i class="bi bi-gear"></i></button>
                <button class="btn btn-sm btn-outline-danger btn-icon" onclick="deleteKR(<?= $kr['id'] ?>,'<?= htmlspecialchars(addslashes($kr['title'])) ?>')" title="Delete"><i class="bi bi-trash"></i></button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div></div>
    <?php endif; ?>
  </div>
</main>
</div>

<!-- Progress Modal -->
<div class="modal fade" id="progressModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Update Progress</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <p class="fw-700 mb-3" id="progTitle" style="font-size:.9rem"></p>
      <form id="progressForm">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="update_progress">
        <input type="hidden" name="kr_id" id="progKrId">
        <div class="mb-3">
          <label class="form-label">New Value <span class="text-danger">*</span></label>
          <div class="input-group"><input type="number" name="new_value" id="progValue" class="form-control" step="any" min="0" required><span class="input-group-text" id="progTargetLabel">/ 100</span></div>
          <div class="mt-2">
            <div class="d-flex justify-content-between mb-1"><small class="text-muted">Estimated progress</small><small class="fw-700" id="progPct">0%</small></div>
            <div class="progress"><div class="progress-bar pb-on-track" id="progBar" style="width:0%"></div></div>
          </div>
        </div>
        <div><label class="form-label">Note <span class="text-muted small">(optional)</span></label><textarea name="note" id="progNote" class="form-control" rows="2" placeholder="What changed? Any blockers?"></textarea></div>
      </form>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
      <button type="button" class="btn btn-primary" onclick="submitProgress()"><i class="bi bi-graph-up me-1"></i>Save Progress</button>
    </div>
  </div></div>
</div>

<!-- Edit KR Modal (Manager only) -->
<?php if ($role==='Manager'): ?>
<div class="modal fade" id="editKrModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Edit Key Result</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <form id="editKrForm">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="update_kr">
        <input type="hidden" name="id" id="ekId">
        <input type="hidden" name="objective_id" id="ekObjId">
        <div class="row g-3">
          <div class="col-12"><label class="form-label">Title <span class="text-danger">*</span></label><input type="text" name="title" id="ekTitle" class="form-control" required></div>
          <div class="col-12"><label class="form-label">Description</label><textarea name="description" id="ekDesc" class="form-control" rows="2"></textarea></div>
          <div class="col-md-6"><label class="form-label">Metric Type</label>
            <select name="metric_type" id="ekMetric" class="form-select">
              <option value="Percentage">Percentage</option><option value="Number">Number</option><option value="Currency">Currency</option><option value="Boolean">Boolean</option>
            </select>
          </div>
          <div class="col-md-6"><label class="form-label">Unit</label><input type="text" name="unit" id="ekUnit" class="form-control"></div>
          <div class="col-md-6"><label class="form-label">Current Value</label><input type="number" name="current_value" id="ekCurrent" class="form-control" step="any"></div>
          <div class="col-md-6"><label class="form-label">Target Value <span class="text-danger">*</span></label><input type="number" name="target_value" id="ekTarget" class="form-control" step="any" required></div>
          <div class="col-12"><label class="form-label">Owner</label><select name="owner_id" id="ekOwner" class="form-select"></select></div>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
      <button type="button" class="btn btn-primary" onclick="submitEditKR()">Save Changes</button>
    </div>
  </div></div>
</div>
<?php endif; ?>

<div id="toastContainer"></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF='<?= $csrf ?>',ME=<?= $userId ?>,ROLE='<?= $role ?>';
let progTarget=100;

function filterKRs(){
  const q=document.getElementById('srch').value.toLowerCase();
  const st=document.getElementById('stFilter').value;
  document.querySelectorAll('#krTable tbody tr').forEach(r=>{
    const tm=r.textContent.toLowerCase().includes(q);
    const sm=!st||r.dataset.status===st;
    r.style.display=(tm&&sm)?'':'none';
  });
}

function toast(msg,type='success'){
  const icons={success:'check-circle',error:'x-circle',warning:'exclamation-triangle'};
  const t=document.createElement('div');t.className=`toast-msg ${type}`;
  t.innerHTML=`<i class="bi bi-${icons[type]||'info-circle'}"></i> ${msg}`;
  document.getElementById('toastContainer').appendChild(t);
  setTimeout(()=>t.remove(),3500);
}

function openProgressModal(krId,title,current,target){
  progTarget=parseFloat(target);
  document.getElementById('progKrId').value=krId;
  document.getElementById('progTitle').textContent=title;
  document.getElementById('progValue').value=current;
  document.getElementById('progTargetLabel').textContent='/ '+target;
  document.getElementById('progNote').value='';
  updatePreview();
  new bootstrap.Modal(document.getElementById('progressModal')).show();
}
document.getElementById('progValue')?.addEventListener('input',updatePreview);
function updatePreview(){
  const v=parseFloat(document.getElementById('progValue').value)||0;
  const p=Math.min(100,Math.round((v/progTarget)*100));
  document.getElementById('progPct').textContent=p+'%';
  document.getElementById('progBar').style.width=p+'%';
}
function submitProgress(){
  const form=document.getElementById('progressForm');
  if(!form.checkValidity()){form.reportValidity();return;}
  fetch('php/kr_api.php',{method:'POST',body:new FormData(form)}).then(r=>r.json()).then(res=>{
    if(res.success){toast('Progress updated!');bootstrap.Modal.getInstance(document.getElementById('progressModal')).hide();setTimeout(()=>location.reload(),800);}
    else toast(res.message||'Error','error');
  });
}

function editKR(id,kr){
  document.getElementById('ekId').value=kr.id;
  document.getElementById('ekObjId').value=kr.objective_id;
  document.getElementById('ekTitle').value=kr.title;
  document.getElementById('ekDesc').value=kr.description||'';
  document.getElementById('ekMetric').value=kr.metric_type;
  document.getElementById('ekUnit').value=kr.unit||'';
  document.getElementById('ekCurrent').value=kr.current_value;
  document.getElementById('ekTarget').value=kr.target_value;
  fetch(`php/kr_api.php?obj_members=1&objective_id=${kr.objective_id}`).then(r=>r.json()).then(ms=>{
    const sel=document.getElementById('ekOwner');
    sel.innerHTML=`<option value="${ME}"><?= htmlspecialchars($user['full_name']) ?> (You)</option>`;
    ms.forEach(m=>{if(m.id!=ME)sel.innerHTML+=`<option value="${m.id}">${m.full_name} (${m.role})</option>`;});
    sel.value=kr.owner_id;
  });
  new bootstrap.Modal(document.getElementById('editKrModal')).show();
}
function submitEditKR(){
  const form=document.getElementById('editKrForm');
  if(!form.checkValidity()){form.reportValidity();return;}
  fetch('php/kr_api.php',{method:'POST',body:new FormData(form)}).then(r=>r.json()).then(res=>{
    if(res.success){toast('Key result updated!');bootstrap.Modal.getInstance(document.getElementById('editKrModal')).hide();setTimeout(()=>location.reload(),800);}
    else toast(res.message||'Error','error');
  });
}
function deleteKR(id,title){
  if(!confirm(`Delete key result "${title}"?`))return;
  const fd=new FormData();fd.append('action','delete_kr');fd.append('id',id);fd.append('csrf_token',CSRF);
  fetch('php/kr_api.php',{method:'POST',body:fd}).then(r=>r.json()).then(res=>{
    if(res.success){toast('Key result deleted');setTimeout(()=>location.reload(),800);}
    else toast(res.message||'Error','error');
  });
}
</script>
</body></html>
