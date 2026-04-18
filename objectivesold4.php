<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';
requireLogin();
$user = getCurrentUser(); $role = $user['role'];
if ($role === 'Admin') { header('Location: dashboard.php'); exit; }
$currentPage = 'objectives';
$userId = $user['id'];

$filterType   = $_GET['type']   ?? '';
$filterPeriod = $_GET['period'] ?? '';
$filterTeamId = isset($_GET['team_id']) ? (int)$_GET['team_id'] : 0;

$objectives    = getObjectives($userId, $role, $filterType ?: null, $filterPeriod ?: null, $filterTeamId ?: null);
$periods       = getTimePeriods();
$parentOptions = getParentOptions($userId, $role);
$userTeams     = getUserTeams($userId);
$teammates     = $role === 'Manager' ? getTeammates($userId) : [];
$csrf = getCsrfToken();

function pbClass(string $status): string {
    return match($status) { 'On Track'=>'pb-on-track','Completed'=>'pb-completed','At Risk','Behind'=>'pb-at-risk', default=>'' };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Objectives – ONOW Enable OKR</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="css/style.css" rel="stylesheet">
<style>
.visibility-opt{transition:border-color .15s,background .15s;user-select:none;cursor:pointer}
.visibility-opt:hover{opacity:.88}
</style>
</head>
<body>
<div class="app-wrapper">
<?php include 'includes/nav.php'; ?>
<main class="main-content">
  <header class="top-header">
    <button class="btn btn-sm btn-outline-secondary d-md-none" onclick="document.getElementById('sidebar').classList.toggle('open')"><i class="bi bi-list"></i></button>
    <div>
      <div class="page-title">Objectives</div>
      <div class="page-breadcrumb"><?= $role==='Manager'?'Strategic alignment — all objective types':'Personal productivity — your objectives' ?></div>
    </div>
    <div class="header-actions">
      <button class="btn btn-primary btn-sm" onclick="openCreateModal()"><i class="bi bi-plus-circle me-1"></i> New Objective</button>
    </div>
  </header>

  <div class="page-body">
    <!-- Filters -->
    <div class="card mb-3"><div class="card-body py-2">
      <form method="GET" class="row g-2 align-items-center">
        <div class="col-auto">
          <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All Types</option>
            <?php if ($role==='Manager'): ?><option value="Organisational" <?= $filterType==='Organisational'?'selected':'' ?>>Organisational</option><option value="Team" <?= $filterType==='Team'?'selected':'' ?>>Team</option><?php endif; ?>
            <option value="Personal" <?= $filterType==='Personal'?'selected':'' ?>>Personal</option>
          </select>
        </div>
        <div class="col-auto">
          <select name="period" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All Periods</option>
            <?php foreach ($periods as $p): ?><option value="<?= $p ?>" <?= $filterPeriod===$p?'selected':'' ?>><?= $p ?></option><?php endforeach; ?>
          </select>
        </div>
        <?php if (!empty($userTeams)): ?>
        <div class="col-auto">
          <select name="team_id" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All Teams</option>
            <?php foreach ($userTeams as $t): ?><option value="<?= $t['id'] ?>" <?= $filterTeamId===$t['id']?'selected':'' ?>><?= htmlspecialchars($t['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <?php if ($filterType||$filterPeriod||$filterTeamId): ?>
        <div class="col-auto"><a href="objectives.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-circle me-1"></i>Clear</a></div>
        <?php endif; ?>
        <div class="col-auto ms-auto text-muted small fw-600"><?= count($objectives) ?> objective<?= count($objectives)!==1?'s':'' ?></div>
      </form>
    </div></div>

    <?php if (empty($objectives)): ?>
    <div class="empty-state card"><div class="card-body">
      <i class="bi bi-bullseye"></i><h5>No Objectives Found</h5><p class="small">Create your first objective to get started.</p>
      <button class="btn btn-primary btn-sm mt-2" onclick="openCreateModal()"><i class="bi bi-plus-circle me-1"></i> Create Objective</button>
    </div></div>
    <?php else: ?>
    <div id="objList">
    <?php foreach ($objectives as $obj):
      $pct        = (float)$obj['progress'];
      $bc         = pbClass($obj['status']);
      $krs        = getKeyResults($obj['id']);
      $mems       = getObjectiveMembers($obj['id']);
      $isCreator  = $obj['created_by'] == $userId;
      $isOwner    = $obj['owner_id']   == $userId;
      $canEdit    = ($role==='Manager'&&$isCreator)||($role==='Member'&&$isOwner);
      $isOrgType  = $obj['type'] === 'Organisational';
      $isPublic   = (int)($obj['is_public'] ?? 1) === 1;
      // Members cannot add KRs to Organisational objectives
      $canAddKR   = ($role==='Manager') || !$isOrgType;
    ?>
    <div class="okr-card" id="obj-<?= $obj['id'] ?>">
      <div class="okr-card-header">
        <div class="flex-grow-1">
          <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
            <span class="type-badge type-<?= strtolower($obj['type']) ?>"><?= $obj['type'] ?></span>
            <span class="status-badge <?= statusClass($obj['status']) ?>"><?= $obj['status'] ?></span>
            <?php if ($isOrgType && !$isPublic): ?>
            <span class="status-badge" style="background:#fef2f2;color:#dc2626;font-size:.66rem">
              <i class="bi bi-lock-fill me-1"></i>Confidential
            </span>
            <?php endif; ?>
            <?php if ($isOrgType && $isPublic && $role==='Manager'): ?>
            <span class="status-badge" style="background:#f0fdf4;color:#16a34a;font-size:.66rem">
              <i class="bi bi-globe me-1"></i>Public
            </span>
            <?php endif; ?>
            <?php if (!empty($obj['team_name'])): ?><span class="team-chip"><i class="bi bi-diagram-3 me-1"></i><?= htmlspecialchars($obj['team_name']) ?></span><?php endif; ?>
            <?php if ($obj['parent_title']): ?><span class="text-muted" style="font-size:.72rem"><i class="bi bi-link-45deg"></i><?= htmlspecialchars(mb_strimwidth($obj['parent_title'],0,38,'…')) ?></span><?php endif; ?>
          </div>
          <div class="okr-title"><?= htmlspecialchars($obj['title']) ?></div>
          <div class="okr-meta">
            <span><i class="bi bi-calendar3"></i> <?= htmlspecialchars($obj['time_period']) ?></span>
            <span><i class="bi bi-person"></i> <?= htmlspecialchars($obj['owner_name']) ?></span>
            <?php if ($obj['created_by']&&$obj['created_by']!=$obj['owner_id']): ?><span><i class="bi bi-pencil-square"></i> by <?= htmlspecialchars($obj['created_by_name']) ?></span><?php endif; ?>
            <?php if (!empty($mems)): ?><span><i class="bi bi-people"></i> <?= count($mems) ?> assigned</span><?php endif; ?>
            <span><i class="bi bi-check2-square"></i> <?= count($krs) ?> KRs</span>
          </div>
        </div>
        <div class="d-flex gap-1 flex-shrink-0">
          <button class="btn btn-sm btn-outline-primary btn-icon" onclick="openDetail(<?= $obj['id'] ?>)" title="View Details"><i class="bi bi-eye"></i></button>
          <?php if ($canEdit): ?><button class="btn btn-sm btn-outline-secondary btn-icon" onclick="editObjective(<?= $obj['id'] ?>)" title="Edit"><i class="bi bi-pencil"></i></button><?php endif; ?>
          <?php if ($canEdit): ?><button class="btn btn-sm btn-outline-danger btn-icon" onclick="confirmDelete(<?= $obj['id'] ?>,'<?= htmlspecialchars(addslashes($obj['title'])) ?>')" title="Delete"><i class="bi bi-trash"></i></button><?php endif; ?>
        </div>
      </div>
      <?php if ($obj['description']): ?><p class="text-muted mb-2" style="font-size:.83rem;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden"><?= htmlspecialchars($obj['description']) ?></p><?php endif; ?>
      <div class="d-flex align-items-center gap-2 mt-1">
        <div class="progress flex-grow-1"><div class="progress-bar <?= $bc ?>" style="width:<?= $pct ?>%"></div></div>
        <span style="font-size:.85rem;font-weight:700;min-width:34px;text-align:right"><?= round($pct) ?>%</span>
      </div>

      <?php if (!empty($krs)): ?>
      <div class="mt-3 ps-1">
        <?php foreach ($krs as $kr):
          $kp          = (float)$kr['progress'];
          $kb          = pbClass($kr['status']);
          $canUpdateKR = ($role==='Manager') || ($role==='Member' && $kr['owner_id']==$userId);
          $canModifyKR = ($role==='Manager') || ((int)($kr['created_by']??0) === $userId);
        ?>
        <div class="kr-item">
          <div class="d-flex align-items-start justify-content-between gap-2">
            <div class="flex-grow-1">
              <div class="kr-title"><?= htmlspecialchars($kr['title']) ?></div>
              <div class="kr-values">
                <?= number_format($kr['current_value'],1) ?> / <?= number_format($kr['target_value'],1) ?>
                <?= htmlspecialchars($kr['unit']) ?> · <?= $kr['metric_type'] ?>
                · <span class="text-muted">Owner: <?= htmlspecialchars($kr['owner_name']) ?></span>
                <?php if (!empty($kr['created_by_name']) && $kr['created_by'] != $kr['owner_id']): ?>
                · <span class="text-muted">Created by: <?= htmlspecialchars($kr['created_by_name']) ?></span>
                <?php endif; ?>
              </div>
              <div class="d-flex align-items-center gap-2">
                <div class="progress flex-grow-1"><div class="progress-bar <?= $kb ?>" style="width:<?= $kp ?>%"></div></div>
                <span style="font-size:.78rem;font-weight:700;min-width:30px;text-align:right"><?= round($kp) ?>%</span>
              </div>
            </div>
            <div class="d-flex gap-1 align-items-start flex-wrap">
              <span class="status-badge <?= statusClass($kr['status']) ?>"><?= $kr['status'] ?></span>
              <?php if ($canUpdateKR): ?>
              <button class="btn btn-sm btn-outline-primary btn-icon" onclick="openProgressModal(<?= $kr['id'] ?>,'<?= htmlspecialchars(addslashes($kr['title'])) ?>',<?= $kr['current_value'] ?>,<?= $kr['target_value'] ?>)" title="Update Progress"><i class="bi bi-pencil-square"></i></button>
              <?php else: ?><button class="btn btn-sm btn-outline-secondary btn-icon" disabled title="Not assigned to you"><i class="bi bi-lock"></i></button><?php endif; ?>
              <?php if ($canModifyKR): ?>
              <button class="btn btn-sm btn-outline-secondary btn-icon" onclick="editKR(<?= $kr['id'] ?>,<?= htmlspecialchars(json_encode($kr)) ?>)" title="Edit"><i class="bi bi-gear"></i></button>
              <button class="btn btn-sm btn-outline-danger btn-icon" onclick="deleteKR(<?= $kr['id'] ?>,'<?= htmlspecialchars(addslashes($kr['title'])) ?>')" title="Delete"><i class="bi bi-trash"></i></button>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div class="mt-2 d-flex gap-2 flex-wrap">
        <?php if ($canAddKR): ?>
        <button class="btn btn-sm btn-outline-secondary" onclick="openAddKRModal(<?= $obj['id'] ?>)"><i class="bi bi-plus me-1"></i> Add Key Result</button>
        <?php else: ?>
        <span class="btn btn-sm btn-outline-secondary disabled" title="Only managers can add key results to Organisational objectives">
          <i class="bi bi-lock me-1"></i>KRs managed by Manager
        </span>
        <?php endif; ?>
        <a href="progress.php?objective_id=<?= $obj['id'] ?>" class="btn btn-sm btn-outline-info"><i class="bi bi-graph-up me-1"></i> History</a>
      </div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</main>
</div>

<!-- Detail Overlay + Panel -->
<div class="detail-overlay" id="detailOverlay" onclick="closeDetail()"></div>
<div class="detail-panel" id="detailPanel">
  <div class="detail-header">
    <button class="btn btn-sm btn-outline-secondary btn-icon" onclick="closeDetail()"><i class="bi bi-x-lg"></i></button>
    <span style="font-size:.95rem;font-weight:700">Objective Detail</span>
    <button class="btn btn-sm btn-primary ms-auto" id="detailEditBtn" style="display:none" onclick="editFromDetail()"><i class="bi bi-pencil me-1"></i> Edit</button>
  </div>
  <div class="detail-body" id="detailBody"><div class="text-center py-5 text-muted"><div class="spinner-border spinner-border-sm"></div></div></div>
</div>

<!-- Create/Edit Objective Modal -->
<!-- STEP 1: Type Picker (Manager only — shown before main form) -->
<?php if ($role === 'Manager'): ?>
<div class="modal fade" id="typePickerModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content" style="border-radius:18px;overflow:hidden">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-800">Select Objective Type</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" onclick="cancelTypePicker()"></button>
      </div>
      <div class="modal-body pt-2 pb-4 px-4">
        <p class="text-muted small mb-3">Choose the type first — the form will adapt accordingly.</p>
        <div class="d-grid gap-2">
          <button class="btn type-picker-btn text-start py-3 px-3" data-type="Organisational"
                  style="border:2px solid #ede9fe;border-radius:12px;background:#fff">
            <div class="d-flex align-items-center gap-2">
              <span style="width:32px;height:32px;background:#ede9fe;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <i class="bi bi-diagram-3" style="color:#7c3aed"></i>
              </span>
              <div>
                <div class="fw-700" style="font-size:.88rem">Organisational</div>
                <div class="text-muted" style="font-size:.73rem">Company-wide goal, no team needed</div>
              </div>
            </div>
          </button>
          <button class="btn type-picker-btn text-start py-3 px-3" data-type="Team"
                  style="border:2px solid var(--primary-lt);border-radius:12px;background:#fff">
            <div class="d-flex align-items-center gap-2">
              <span style="width:32px;height:32px;background:var(--primary-lt);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <i class="bi bi-people" style="color:var(--primary)"></i>
              </span>
              <div>
                <div class="fw-700" style="font-size:.88rem">Team</div>
                <div class="text-muted" style="font-size:.73rem">Shared goal for a specific team</div>
              </div>
            </div>
          </button>
          <button class="btn type-picker-btn text-start py-3 px-3" data-type="Personal"
                  style="border:2px solid var(--success-lt);border-radius:12px;background:#fff">
            <div class="d-flex align-items-center gap-2">
              <span style="width:32px;height:32px;background:var(--success-lt);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <i class="bi bi-person" style="color:var(--success)"></i>
              </span>
              <div>
                <div class="fw-700" style="font-size:.88rem">Personal</div>
                <div class="text-muted" style="font-size:.73rem">Individual goal for one person</div>
              </div>
            </div>
          </button>
        </div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- STEP 2: Main Objective Form Modal -->
<div class="modal fade" id="objModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title" id="objModalTitle">New Objective</h5>
          <!-- Type badge shown next to title when a type is selected -->
          <span id="objTypeBadge" class="type-badge ms-2" style="display:none"></span>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="objForm" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="action"     id="objAction"  value="create">
          <input type="hidden" name="id"         id="objId"      value="">

          <!-- Hidden type field — set by type picker for Manager, hardcoded for Member -->
          <?php if ($role === 'Manager'): ?>
          <input type="hidden" name="type" id="objType" value="">
          <?php else: ?>
          <input type="hidden" name="type" id="objType" value="Personal">
          <?php endif; ?>

          <div class="row g-3">

            <!-- Title + Description — always visible -->
            <div class="col-12">
              <label class="form-label">Title <span class="text-danger">*</span></label>
              <input type="text" name="title" id="objTitle" class="form-control" required
                     maxlength="255" placeholder="e.g. Grow community reach by Q2">
            </div>
            <div class="col-12">
              <label class="form-label">Description</label>
              <textarea name="description" id="objDesc" class="form-control" rows="2"
                        placeholder="Why is this objective important?"></textarea>
            </div>

            <!-- ── Visibility Toggle (Organisational + Manager only) ── -->
            <!-- Shown/hidden by applyTypeRules(). Hidden for Team/Personal types. -->
            <?php if ($role === 'Manager'): ?>
            <div id="grp-visibility" class="col-12" style="display:none">
              <label class="form-label fw-700">
                <i class="bi bi-eye text-primary me-1"></i>Visibility
              </label>
              <div class="d-flex gap-2">
                <label class="visibility-opt" id="vis-public" style="flex:1;border:2px solid var(--success);border-radius:10px;padding:.6rem .9rem;cursor:pointer;transition:all .15s">
                  <input type="radio" name="is_public" value="1" id="visPublic" style="display:none" checked>
                  <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-globe" style="color:var(--success);font-size:1.1rem"></i>
                    <div>
                      <div class="fw-700" style="font-size:.85rem">Public</div>
                      <div class="text-muted" style="font-size:.73rem">Visible to all Managers and Members</div>
                    </div>
                  </div>
                </label>
                <label class="visibility-opt" id="vis-confidential" style="flex:1;border:2px solid var(--border);border-radius:10px;padding:.6rem .9rem;cursor:pointer;transition:all .15s">
                  <input type="radio" name="is_public" value="0" id="visConfidential" style="display:none">
                  <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-lock-fill" style="color:var(--danger);font-size:1.1rem"></i>
                    <div>
                      <div class="fw-700" style="font-size:.85rem">Confidential</div>
                      <div class="text-muted" style="font-size:.73rem">Visible to Managers only</div>
                    </div>
                  </div>
                </label>
              </div>
              <div class="form-text mt-1"><i class="bi bi-info-circle me-1"></i>Confidential objectives are hidden from Members and excluded from member dashboards.</div>
            </div>
            <?php endif; ?>

            <!-- Owner (Manager only — always visible in form) -->
            <?php if ($role === 'Manager'): ?>
            <div class="col-md-6">
              <label class="form-label">Owner</label>
              <select name="owner_id" id="objOwner" class="form-select">
                <option value="<?= $userId ?>"><?= htmlspecialchars($user['full_name']) ?> (You)</option>
                <?php foreach ($teammates as $t): ?>
                <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['full_name']) ?> (<?= $t['role'] ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6"><!-- spacer --></div>
            <?php else: ?>
            <input type="hidden" name="owner_id" value="<?= $userId ?>">
            <?php endif; ?>

            <!-- Time Period + Dates — always visible -->
            <div class="col-md-4">
              <label class="form-label">Time Period <span class="text-danger">*</span></label>
              <select name="time_period" id="objPeriod" class="form-select" required>
                <?php foreach ($periods as $p): ?>
                <option value="<?= $p ?>"><?= $p ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Start Date</label>
              <input type="date" name="start_date" id="objStart" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label">End Date</label>
              <input type="date" name="end_date" id="objEnd" class="form-control">
            </div>

            <!-- ── CONDITIONAL SECTION: Team (Team type only) ── -->
            <div id="grp-team" class="col-12" style="display:none">
              <label class="form-label fw-700">
                <i class="bi bi-diagram-3 text-primary me-1"></i>Assign to Team
                <span class="text-danger">*</span>
              </label>
              <?php if (!empty($userTeams)): ?>
              <select name="team_id" id="objTeam" class="form-select" onchange="onTeamChange(this.value)">
                <option value="">— Select a team —</option>
                <?php foreach ($userTeams as $t): ?>
                <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text" id="teamNote">Selecting a team filters the member list below.</div>
              <?php else: ?>
              <div class="alert alert-warning py-2 small mb-0">
                <i class="bi bi-exclamation-triangle me-1"></i>You are not in any team. Contact your administrator.
              </div>
              <input type="hidden" name="team_id" value="">
              <?php endif; ?>
            </div>

            <!-- ── CONDITIONAL SECTION: Assign Members (Team type only) ── -->
            <?php if ($role === 'Manager'): ?>
            <div id="grp-members" class="col-12" style="display:none">
              <label class="form-label fw-700">
                <i class="bi bi-people text-primary me-1"></i>Assign Members
                <span class="text-danger">*</span>
                <span class="fw-400 text-muted small ms-1" id="memberNote"></span>
              </label>
              <div class="border rounded p-2" style="max-height:140px;overflow-y:auto" id="memberCheckboxes">
                <?php if (!empty($teammates)): ?>
                <?php foreach ($teammates as $t): ?>
                <div class="form-check member-check-row"
                     data-teams='<?= htmlspecialchars(json_encode(array_column(getUserTeams($t['id']),'id'))) ?>'>
                  <input class="form-check-input member-check" type="checkbox"
                         name="member_ids[]" value="<?= $t['id'] ?>" id="mc<?= $t['id'] ?>">
                  <label class="form-check-label" for="mc<?= $t['id'] ?>">
                    <?= htmlspecialchars($t['full_name']) ?>
                    <span class="text-muted small">(<?= $t['role'] ?><?= $t['department'] ? ' · '.htmlspecialchars($t['department']) : '' ?>)</span>
                  </label>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <div class="text-muted small p-1">No teammates found. Ask Admin to assign you to a team.</div>
                <?php endif; ?>
              </div>
            </div>
            <?php endif; ?>

            <!-- ── CONDITIONAL SECTION: Link to Parent ── -->
            <!-- Shown for Team (parent = Organisational) and Personal (parent = Team) -->
            <!-- data-type on each option lets JS filter without a round-trip -->
            <div id="grp-parent" class="col-12" style="display:none">
              <label class="form-label fw-700">
                <i class="bi bi-link-45deg text-primary me-1"></i>Link to Parent Objective
                <span class="fw-400 text-muted small ms-1" id="parentNote"></span>
              </label>
              <select name="parent_objective_id" id="objParent" class="form-select">
                <option value="" data-type="">— None (standalone) —</option>
                <?php foreach ($parentOptions as $po): ?>
                <option value="<?= $po['id'] ?>"
                        data-type="<?= htmlspecialchars($po['type']) ?>">
                  [<?= $po['type'] ?>] <?= htmlspecialchars($po['title']) ?> · <?= $po['time_period'] ?>
                </option>
                <?php endforeach; ?>
              </select>
              <div class="form-text" id="parentHint">Align this objective to a higher-level goal.</div>
            </div>

            <!-- Attachments — always visible -->
            <div class="col-12">
              <label class="form-label">
                <i class="bi bi-paperclip text-primary me-1"></i>Attachments
                <span class="text-muted small">(PDF, Word, Excel, PowerPoint, Images — max 10MB each)</span>
              </label>
              <input type="file" name="attachments[]" id="attInput" class="form-control"
                     multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.png,.jpg,.jpeg,.gif,.webp">
              <div id="attPreview" class="mt-2"></div>
            </div>

          </div><!-- /row -->
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="objSubmitBtn" onclick="submitObjective()">
          <i class="bi bi-check-circle me-1"></i>
          <span id="objSubmitLabel">Create Objective</span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Add/Edit KR Modal -->
<div class="modal fade" id="krModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title" id="krModalTitle">Add Key Result</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <form id="krForm">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" id="krAction" value="create_kr">
        <input type="hidden" name="objective_id" id="krObjId" value="">
        <input type="hidden" name="id" id="krId" value="">
        <div class="row g-3">
          <div class="col-12"><label class="form-label">Title <span class="text-danger">*</span></label><input type="text" name="title" id="krTitle" class="form-control" required placeholder="e.g. Reach 500 beneficiaries"></div>
          <div class="col-12"><label class="form-label">Description</label><textarea name="description" id="krDesc" class="form-control" rows="2"></textarea></div>
          <div class="col-md-6"><label class="form-label">Metric Type</label>
            <select name="metric_type" id="krMetric" class="form-select">
              <option value="Percentage">Percentage (%)</option><option value="Number">Number</option><option value="Currency">Currency (£)</option><option value="Boolean">Boolean (Yes/No)</option>
            </select>
          </div>
          <div class="col-md-6"><label class="form-label">Unit <span class="text-muted small">(optional)</span></label><input type="text" name="unit" id="krUnit" class="form-control" placeholder="e.g. users, £"></div>
          <div class="col-md-6"><label class="form-label">Starting Value</label><input type="number" name="current_value" id="krCurrent" class="form-control" value="0" step="any" min="0"></div>
          <div class="col-md-6"><label class="form-label">Target Value <span class="text-danger">*</span></label><input type="number" name="target_value" id="krTarget" class="form-control" value="100" step="any" min="0.01" required></div>
          <?php if ($role==='Manager'): ?>
          <div class="col-12"><label class="form-label">Owner</label><select name="owner_id" id="krOwner" class="form-select"><option value="<?= $userId ?>"><?= htmlspecialchars($user['full_name']) ?> (You)</option></select><div class="form-text">Only members assigned to this objective.</div></div>
          <?php else: ?><input type="hidden" name="owner_id" value="<?= $userId ?>"><?php endif; ?>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
      <button type="button" class="btn btn-primary" onclick="submitKR()"><i class="bi bi-plus-circle me-1"></i> <span id="krSubmitLabel">Add Key Result</span></button>
    </div>
  </div></div>
</div>

<!-- Progress Modal -->
<div class="modal fade" id="progressModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Update Progress</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <p class="fw-700 mb-3" id="progKrTitle" style="font-size:.9rem"></p>
      <form id="progressForm">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="update_progress">
        <input type="hidden" name="kr_id" id="progKrId">
        <div class="mb-3">
          <label class="form-label">New Value <span class="text-danger">*</span></label>
          <div class="input-group"><input type="number" name="new_value" id="progValue" class="form-control" step="any" min="0" required><span class="input-group-text" id="progTarget">/ 100</span></div>
          <div class="mt-2">
            <div class="d-flex justify-content-between mb-1"><small class="text-muted">Estimated progress</small><small class="fw-700" id="progPct">0%</small></div>
            <div class="progress"><div class="progress-bar pb-on-track" id="progBar" style="width:0%"></div></div>
          </div>
        </div>
        <div><label class="form-label">Note <span class="text-muted small">(optional)</span></label><textarea name="note" id="progNote" class="form-control" rows="2" placeholder="What changed?"></textarea></div>
      </form>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
      <button type="button" class="btn btn-primary" onclick="submitProgress()"><i class="bi bi-graph-up me-1"></i> Save Progress</button>
    </div>
  </div></div>
</div>

<!-- Delete Confirm Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
  <div class="modal-dialog modal-sm"><div class="modal-content">
    <div class="modal-body text-center pt-4">
      <div style="width:56px;height:56px;background:var(--danger-lt);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto .75rem"><i class="bi bi-trash text-danger" style="font-size:1.4rem"></i></div>
      <h6 class="fw-800 mb-1">Delete Objective?</h6>
      <p class="text-muted small mb-0" id="deleteTitle"></p>
      <p class="text-danger small mt-1">All key results and attachments will also be soft-deleted.</p>
    </div>
    <div class="modal-footer justify-content-center border-0 pt-0">
      <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
      <button type="button" class="btn btn-danger btn-sm" id="deleteConfirmBtn">Delete</button>
    </div>
  </div></div>
</div>

<div id="toastContainer"></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF='<?= $csrf ?>',ROLE='<?= $role ?>',ME=<?= $userId ?>;

/* ── Visibility toggle UI sync ────────────────── */
function syncVisibilityUI() {
  const pubRadio  = document.getElementById('visPublic');
  const confRadio = document.getElementById('visConfidential');
  const pubLabel  = document.getElementById('vis-public');
  const confLabel = document.getElementById('vis-confidential');
  if (!pubRadio || !confRadio) return;
  if (pubRadio.checked) {
    pubLabel.style.borderColor  = 'var(--success)';
    confLabel.style.borderColor = 'var(--border)';
    confLabel.style.background  = '#fff';
    pubLabel.style.background   = 'var(--success-lt)';
  } else {
    confLabel.style.borderColor = 'var(--danger)';
    pubLabel.style.borderColor  = 'var(--border)';
    pubLabel.style.background   = '#fff';
    confLabel.style.background  = 'var(--danger-lt)';
  }
}
// Wire up visibility radio buttons
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('input[name="is_public"]').forEach(r => {
    r.addEventListener('change', syncVisibilityUI);
  });
  syncVisibilityUI();
});
let progTarget=100,pendingDeleteId=null,currentDetailId=null;

/* ── Toast ─────────────────────────────────── */
function toast(msg,type='success'){
  const icons={success:'check-circle',error:'x-circle',warning:'exclamation-triangle'};
  const t=document.createElement('div');t.className=`toast-msg ${type}`;
  t.innerHTML=`<i class="bi bi-${icons[type]||'info-circle'}"></i> ${msg}`;
  document.getElementById('toastContainer').appendChild(t);
  setTimeout(()=>t.remove(),3500);
}

/* ── Team dropdown filtering ────────────────── */
function onTeamChange(teamId,cb){
  const ownerSel=document.getElementById('objOwner');
  const rows=document.querySelectorAll('.member-check-row');
  const noteEl=document.getElementById('memberNote');
  if(!teamId){
    if(ownerSel)Array.from(ownerSel.options).forEach(o=>o.style.display='');
    rows.forEach(r=>r.style.display='');
    if(noteEl)noteEl.textContent='';
    if(cb)cb();return;
  }
  fetch(`php/team_api.php?team_id=${teamId}`).then(r=>r.json()).then(d=>{
    if(!d.success){if(cb)cb();return;}
    const ids=d.members.map(m=>parseInt(m.id));
    if(ownerSel)Array.from(ownerSel.options).forEach(o=>{const v=parseInt(o.value);o.style.display=(v===ME||ids.includes(v))?'':' none';});
    let vis=0;
    rows.forEach(r=>{const cb2=r.querySelector('input[type=checkbox]'),uid=parseInt(cb2.value),show=ids.includes(uid);r.style.display=show?'':'none';if(!show)cb2.checked=false;if(show)vis++;});
    if(noteEl)noteEl.textContent=vis>0?`Showing ${vis} member${vis!==1?'s':''} from selected team.`:'No other members in this team.';
    if(cb)cb();
  }).catch(()=>{if(cb)cb();});
}

/* ── Progressive Disclosure — type-driven field visibility ───── */

/**
 * applyTypeRules(type)
 *
 * Controls which field groups are visible and which inputs are required
 * based on the selected objective type. Called every time the type
 * changes — both from the type picker (create) and editObjective (edit).
 *
 * Visibility matrix:
 *   Organisational → hide team, members, parent
 *   Team           → show team (required), show members (required), show parent (Org only)
 *   Personal       → hide team, hide members, show parent (Team only, user's teams)
 */
function applyTypeRules(type) {
  const grpTeam    = document.getElementById('grp-team');
  const grpMembers = document.getElementById('grp-members');
  const grpParent  = document.getElementById('grp-parent');
  const teamSel    = document.getElementById('objTeam');
  const parentSel  = document.getElementById('objParent');
  const parentNote = document.getElementById('parentNote');
  const parentHint = document.getElementById('parentHint');

  // Helper: show/hide a group and toggle required on its first select/input
  function setGroup(el, visible, makeRequired) {
    if (!el) return;
    el.style.display = visible ? '' : 'none';
    // Toggle required on every required-capable field inside the group
    el.querySelectorAll('select, input[type=text], input[type=number]').forEach(f => {
      if (makeRequired) f.setAttribute('required', '');
      else              f.removeAttribute('required');
    });
    // If hiding, also clear value to avoid stale data being submitted
    if (!visible) {
      el.querySelectorAll('select').forEach(s => s.value = '');
      el.querySelectorAll('input[type=checkbox]').forEach(c => c.checked = false);
    }
  }

  // Filter parent dropdown options by allowed parent type
  function filterParent(allowedType) {
    if (!parentSel) return;
    let hasVisible = false;
    Array.from(parentSel.options).forEach(opt => {
      if (opt.value === '') { opt.style.display = ''; return; } // always show "None"
      const match = opt.dataset.type === allowedType;
      opt.style.display = match ? '' : 'none';
      if (match) hasVisible = true;
    });
    // Reset selection if current value is now hidden
    const cur = parentSel.options[parentSel.selectedIndex];
    if (cur && cur.style.display === 'none') parentSel.value = '';
    return hasVisible;
  }

  // Update the type badge in the modal header
  const badge = document.getElementById('objTypeBadge');
  if (badge && type) {
    const typeMap = {
      'Organisational': ['type-organisational', 'Organisational'],
      'Team':           ['type-team',           'Team'],
      'Personal':       ['type-personal',       'Personal'],
    };
    const [cls, lbl] = typeMap[type] || ['', type];
    badge.className = `type-badge ms-2 ${cls}`;
    badge.textContent = lbl;
    badge.style.display = '';
  } else if (badge) {
    badge.style.display = 'none';
  }

  if (type === 'Organisational') {
    setGroup(grpTeam,    false, false);
    setGroup(grpMembers, false, false);
    setGroup(grpParent,  false, false);
    // Show the visibility toggle only for Organisational objectives
    const grpVis = document.getElementById('grp-visibility');
    if (grpVis) grpVis.style.display = '';

  } else if (type === 'Team') {
    // Hide visibility toggle — only relevant for Organisational
    const grpVisHide = document.getElementById('grp-visibility');
    if (grpVisHide) grpVisHide.style.display = 'none';
    setGroup(grpTeam,    true,  true);   // team required
    setGroup(grpMembers, true,  false);  // members shown but not individually required
    setGroup(grpParent,  true,  false);  // parent optional
    const hasOrg = filterParent('Organisational');
    if (parentNote) parentNote.textContent = hasOrg
      ? '(optional — link to an Organisational objective)'
      : '(no Organisational objectives available yet)';
    if (parentHint) parentHint.textContent = 'Align this Team objective to a company-wide goal.';

  } else if (type === 'Personal') {
    const grpVisP = document.getElementById('grp-visibility');
    if (grpVisP) grpVisP.style.display = 'none';
    setGroup(grpTeam,    false, false);
    setGroup(grpMembers, false, false);
    setGroup(grpParent,  true,  false);  // parent optional
    const hasTeam = filterParent('Team');
    if (parentNote) parentNote.textContent = hasTeam
      ? '(optional — link to a Team objective you belong to)'
      : '(no Team objectives available yet)';
    if (parentHint) parentHint.textContent = 'Align your personal goal to a team-level objective.';

  } else {
    // No type selected yet — hide all conditional groups
    const grpVisN = document.getElementById('grp-visibility');
    if (grpVisN) grpVisN.style.display = 'none';
    setGroup(grpTeam,    false, false);
    setGroup(grpMembers, false, false);
    setGroup(grpParent,  false, false);
  }
}

/* ── Objective CRUD ─────────────────────────── */

/**
 * openCreateModal()
 *
 * Manager: shows the type picker first. On selection → opens main form.
 * Member:  skips type picker (always Personal) → opens main form directly.
 */
function openCreateModal() {
  // Reset shared form state
  document.getElementById('objAction').value  = 'create';
  document.getElementById('objModalTitle').textContent = 'New Objective';
  document.getElementById('objSubmitLabel').textContent = 'Create Objective';
  document.getElementById('objForm').reset();
  document.getElementById('objId').value = '';
  document.getElementById('attPreview').innerHTML = '';
  document.querySelectorAll('.member-check').forEach(c => c.checked = false);

  <?php if ($role === 'Manager'): ?>
  // Reset type — will be set by the picker
  document.getElementById('objType').value = '';
  applyTypeRules('');   // hide all conditional groups
  // Reset visibility to Public on each new create
  const pubR = document.getElementById('visPublic');
  if (pubR) { pubR.checked = true; syncVisibilityUI(); }

  // Show type picker first
  const typePicker = new bootstrap.Modal(document.getElementById('typePickerModal'));
  typePicker.show();

  // Wire up the three type picker buttons (idempotent: remove then add)
  document.querySelectorAll('.type-picker-btn').forEach(btn => {
    btn.replaceWith(btn.cloneNode(true)); // strip old listeners
  });
  document.querySelectorAll('.type-picker-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const type = btn.dataset.type;
      document.getElementById('objType').value = type;

      // Close picker, open main form
      bootstrap.Modal.getInstance(document.getElementById('typePickerModal')).hide();
      document.getElementById('typePickerModal').addEventListener('hidden.bs.modal', function onHide() {
        document.getElementById('typePickerModal').removeEventListener('hidden.bs.modal', onHide);
        applyTypeRules(type);
        new bootstrap.Modal(document.getElementById('objModal')).show();
      }, { once: true });
    });
  });

  <?php else: ?>
  // Member — always Personal, skip picker
  document.getElementById('objType').value = 'Personal';
  applyTypeRules('Personal');
  new bootstrap.Modal(document.getElementById('objModal')).show();
  <?php endif; ?>
}

function cancelTypePicker() {
  // Nothing extra needed — btn-close dismisses the modal automatically
}

function editObjective(id) {
  fetch(`php/obj_api.php?get=1&id=${id}`).then(r => r.json()).then(o => {
    document.getElementById('objAction').value  = 'update';
    document.getElementById('objModalTitle').textContent  = 'Edit Objective';
    document.getElementById('objSubmitLabel').textContent = 'Save Changes';
    document.getElementById('objId').value    = o.id;
    document.getElementById('objTitle').value = o.title;
    document.getElementById('objDesc').value  = o.description || '';
    if (document.getElementById('objOwner')) document.getElementById('objOwner').value = o.owner_id;
    document.getElementById('objPeriod').value = o.time_period;
    document.getElementById('objStart').value  = o.start_date  || '';
    document.getElementById('objEnd').value    = o.end_date    || '';
    document.getElementById('attPreview').innerHTML = '';

    // Set type (hidden field) and apply visibility rules first
    const type = o.type || 'Personal';
    document.getElementById('objType').value = type;
    applyTypeRules(type);

    // Restore is_public after applyTypeRules (which may show grp-visibility)
    const isPublic = parseInt(o.is_public ?? 1);
    const pubRadio  = document.getElementById('visPublic');
    const confRadio = document.getElementById('visConfidential');
    if (pubRadio && confRadio) {
      pubRadio.checked  = isPublic === 1;
      confRadio.checked = isPublic === 0;
      syncVisibilityUI();
    }

    // Populate parent after rules applied (options may now be filtered)
    document.getElementById('objParent').value = o.parent_objective_id || '';

    // Populate team and members
    const ts = document.getElementById('objTeam');
    if (ts) {
      ts.value = o.team_id || '';
      onTeamChange(o.team_id || '', () => {
        document.querySelectorAll('.member-check').forEach(c => {
          c.checked = (o.member_ids || []).includes(parseInt(c.value));
        });
      });
    } else {
      document.querySelectorAll('.member-check').forEach(c => {
        c.checked = (o.member_ids || []).includes(parseInt(c.value));
      });
    }

    new bootstrap.Modal(document.getElementById('objModal')).show();
  });
}

function editFromDetail() { if (currentDetailId) editObjective(currentDetailId); }

function submitObjective() {
  const form = document.getElementById('objForm');

  // Extra validation: Team type needs at least one member checked
  const type = document.getElementById('objType').value;
  if (type === 'Team') {
    const checked = document.querySelectorAll('.member-check:checked').length;
    if (checked === 0) {
      toast('Please assign at least one team member.', 'warning');
      return;
    }
    const teamSel = document.getElementById('objTeam');
    if (teamSel && !teamSel.value) {
      toast('Please select a team.', 'warning');
      teamSel.focus();
      return;
    }
  }

  if (!form.checkValidity()) { form.reportValidity(); return; }

  const btn = document.getElementById('objSubmitBtn');
  btn.disabled = true;

  const data  = new FormData(form);
  const files = document.getElementById('attInput').files;

  fetch('php/obj_api.php', { method: 'POST', body: data }).then(r => r.json()).then(res => {
    btn.disabled = false;
    if (res.success) {
      if (files.length > 0 && res.id) {
        const ups = Array.from(files).map(f => {
          const fd = new FormData();
          fd.append('csrf_token', CSRF);
          fd.append('action', 'upload_attachment');
          fd.append('objective_id', res.id);
          fd.append('attachment', f);
          return fetch('php/obj_api.php', { method: 'POST', body: fd }).then(r => r.json());
        });
        Promise.all(ups).then(() => {
          toast('Objective saved with attachments!');
          bootstrap.Modal.getInstance(document.getElementById('objModal')).hide();
          setTimeout(() => location.reload(), 800);
        });
      } else {
        toast('Objective saved!');
        bootstrap.Modal.getInstance(document.getElementById('objModal')).hide();
        setTimeout(() => location.reload(), 800);
      }
    } else {
      toast(res.message || 'Error saving objective.', 'error');
    }
  }).catch(() => { btn.disabled = false; toast('Network error', 'error'); });
}

function confirmDelete(id, title) {
  pendingDeleteId = id;
  document.getElementById('deleteTitle').textContent = `"${title}"`;
  new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
document.getElementById('deleteConfirmBtn').addEventListener('click', () => {
  if (!pendingDeleteId) return;
  const fd = new FormData();
  fd.append('action',     'delete');
  fd.append('id',         pendingDeleteId);
  fd.append('csrf_token', CSRF);
  fetch('php/obj_api.php', { method: 'POST', body: fd }).then(r => r.json()).then(res => {
    bootstrap.Modal.getInstance(document.getElementById('deleteModal')).hide();
    if (res.success) {
      toast('Objective deleted');
      document.getElementById('obj-' + pendingDeleteId)?.remove();
    } else {
      toast(res.message || 'Error', 'error');
    }
    pendingDeleteId = null;
  });
});

document.getElementById('attInput')?.addEventListener('change', function () {
  const p = document.getElementById('attPreview');
  p.innerHTML = '';
  Array.from(this.files).forEach(f => {
    const d = document.createElement('div');
    d.className = 'att-item';
    d.innerHTML = `<i class="bi bi-file-earmark text-muted"></i><span>${f.name}</span><span class="text-muted" style="font-size:.72rem">${(f.size / 1024).toFixed(1)} KB</span>`;
    p.appendChild(d);
  });
});

/* ── Detail Panel ────────────────────────────── */
function openDetail(id){
  currentDetailId=id;
  document.getElementById('detailPanel').classList.add('open');
  document.getElementById('detailOverlay').classList.add('open');
  document.getElementById('detailBody').innerHTML='<div class="text-center py-5 text-muted"><div class="spinner-border spinner-border-sm"></div></div>';
  fetch(`php/detail_api.php?id=${id}`).then(r=>r.json()).then(renderDetail).catch(()=>{document.getElementById('detailBody').innerHTML='<div class="text-muted text-center py-4">Failed to load.</div>';});
}
function closeDetail(){document.getElementById('detailPanel').classList.remove('open');document.getElementById('detailOverlay').classList.remove('open');currentDetailId=null;}
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeDetail();});

function renderDetail(d){
  const obj=d.objective,krs=d.key_results||[],mems=d.members||[],atts=d.attachments||[];
  document.getElementById('detailEditBtn').style.display=obj.can_edit?'':'none';
  const bm={'On Track':'pb-on-track','At Risk':'pb-at-risk','Behind':'pb-at-risk','Completed':'pb-completed'};
  const memsHtml=mems.map(m=>`<span class="member-chip">${m.full_name}</span>`).join('')||'<span class="text-muted small">None assigned</span>';
  const attsHtml=atts.length?atts.map(a=>`<div class="att-item"><i class="bi ${a.icon}"></i><a href="uploads/${a.file_name}" target="_blank">${a.original_name}</a><span class="text-muted" style="font-size:.72rem">${a.size_fmt}</span></div>`).join(''):'<div class="text-muted small">No attachments</div>';
  const canAddKRDetail = d.objective.can_add_kr;
  const krsHtml=krs.map(kr=>{
    const p=parseFloat(kr.progress);
    const canUpd = kr.can_update_progress !== undefined ? kr.can_update_progress : ((ROLE==='Manager')||(kr.owner_id===ME));
    const canMod = kr.can_modify !== undefined ? kr.can_modify : (ROLE==='Manager'||(kr.created_by===ME));
    const updBtn = canUpd ? `<button class="btn btn-sm btn-outline-primary" style="font-size:.72rem;padding:.15rem .5rem" onclick="openProgressModal(${kr.id},'${kr.title.replace(/'/g,"\'")}',${kr.current_value},${kr.target_value})"><i class="bi bi-pencil-square"></i> Update</button>` : '';
    return `<div class="kr-item"><div class="d-flex justify-content-between gap-2 mb-1"><div style="font-size:.87rem;font-weight:700">${kr.title}</div><span class="status-badge ${kr.status_class}">${kr.status}</span></div><div class="text-muted" style="font-size:.77rem;margin-bottom:.4rem">${parseFloat(kr.current_value).toFixed(1)} / ${parseFloat(kr.target_value).toFixed(1)} ${kr.unit||''} · ${kr.metric_type} · Owner: ${kr.owner_name}</div><div class="d-flex align-items-center gap-2"><div class="progress flex-grow-1" style="height:6px"><div class="progress-bar ${bm[kr.status]||''}" style="width:${p}%"></div></div><span style="font-size:.78rem;font-weight:700">${Math.round(p)}%</span>${updBtn}</div></div>`;
  }).join('')||'<div class="text-muted small">No key results yet.</div>';
  const addKRBtn = canAddKRDetail ? `<div class="mt-2"><button class="btn btn-sm btn-outline-secondary" onclick="closeDetail();openAddKRModal(${d.objective.id})"><i class="bi bi-plus me-1"></i>Add Key Result</button></div>` : '';
  document.getElementById('detailBody').innerHTML=`
    <div class="detail-sec"><div class="d-flex gap-2 mb-2 flex-wrap"><span class="type-badge type-${obj.type.toLowerCase()}">${obj.type}</span><span class="status-badge ${obj.status_class}">${obj.status}</span></div>
    <h5 class="fw-800 mb-1">${obj.title}</h5>${obj.description?`<p class="text-muted" style="font-size:.87rem">${obj.description}</p>`:''}</div>
    <div class="detail-sec"><div class="detail-sec-title">Progress</div>
    <div class="d-flex align-items-center gap-3"><div class="progress flex-grow-1"><div class="progress-bar ${bm[obj.status]||''}" style="width:${obj.progress}%"></div></div><span style="font-size:1.1rem;font-weight:800">${Math.round(obj.progress)}%</span></div></div>
    <div class="row g-2 detail-sec">
      <div class="col-6"><div class="detail-sec-title">Period</div><div class="fw-600 small">${obj.time_period}</div></div>
      <div class="col-6"><div class="detail-sec-title">Owner</div><div class="fw-600 small">${obj.owner_name}</div></div>
      ${obj.team_name?`<div class="col-6"><div class="detail-sec-title"><i class="bi bi-diagram-3"></i> Team</div><div class="fw-600 small"><span class="team-chip">${obj.team_name}</span></div></div>`:''}
      ${obj.start_date?`<div class="col-6"><div class="detail-sec-title">Start</div><div class="fw-600 small">${obj.start_date}</div></div>`:''}
      ${obj.end_date?`<div class="col-6"><div class="detail-sec-title">End</div><div class="fw-600 small">${obj.end_date}</div></div>`:''}
      ${obj.parent_title?`<div class="col-12"><div class="detail-sec-title"><i class="bi bi-link-45deg"></i> Parent</div><div class="fw-600 small">${obj.parent_title}</div></div>`:''}
    </div>
    <div class="detail-sec"><div class="detail-sec-title">Members</div><div>${memsHtml}</div></div>
    <div class="detail-sec"><div class="detail-sec-title">Key Results (${krs.length})</div>${krsHtml}${addKRBtn}</div>
    <div class="detail-sec"><div class="detail-sec-title">Attachments (${atts.length})</div>${attsHtml}</div>`;
}

/* ── Key Results ─────────────────────────────── */
function openAddKRModal(objId){
  document.getElementById('krAction').value='create_kr';document.getElementById('krModalTitle').textContent='Add Key Result';document.getElementById('krSubmitLabel').textContent='Add Key Result';
  document.getElementById('krForm').reset();document.getElementById('krObjId').value=objId;document.getElementById('krId').value='';
  if(ROLE==='Manager'&&document.getElementById('krOwner')){
    fetch(`php/kr_api.php?obj_members=1&objective_id=${objId}`).then(r=>r.json()).then(ms=>{
      const sel=document.getElementById('krOwner');sel.innerHTML=`<option value="${ME}"><?= htmlspecialchars($user['full_name']) ?> (You)</option>`;
      ms.forEach(m=>{if(m.id!=ME)sel.innerHTML+=`<option value="${m.id}">${m.full_name}</option>`;});
    });
  }
  new bootstrap.Modal(document.getElementById('krModal')).show();
}
function editKR(id,kr){
  document.getElementById('krAction').value='update_kr';document.getElementById('krModalTitle').textContent='Edit Key Result';document.getElementById('krSubmitLabel').textContent='Save Changes';
  document.getElementById('krId').value=kr.id;document.getElementById('krObjId').value=kr.objective_id;
  document.getElementById('krTitle').value=kr.title;document.getElementById('krDesc').value=kr.description||'';
  document.getElementById('krMetric').value=kr.metric_type;document.getElementById('krUnit').value=kr.unit||'';
  document.getElementById('krCurrent').value=kr.current_value;document.getElementById('krTarget').value=kr.target_value;
  if(document.getElementById('krOwner')){
    fetch(`php/kr_api.php?obj_members=1&objective_id=${kr.objective_id}`).then(r=>r.json()).then(ms=>{
      const sel=document.getElementById('krOwner');sel.innerHTML=`<option value="${ME}"><?= htmlspecialchars($user['full_name']) ?> (You)</option>`;
      ms.forEach(m=>{if(m.id!=ME)sel.innerHTML+=`<option value="${m.id}">${m.full_name}</option>`;});
      sel.value=kr.owner_id;
    });
  }
  new bootstrap.Modal(document.getElementById('krModal')).show();
}
function submitKR(){
  const form=document.getElementById('krForm');if(!form.checkValidity()){form.reportValidity();return;}
  fetch('php/kr_api.php',{method:'POST',body:new FormData(form)}).then(r=>r.json()).then(res=>{
    if(res.success){toast('Key result saved!');bootstrap.Modal.getInstance(document.getElementById('krModal')).hide();setTimeout(()=>location.reload(),800);}
    else toast(res.message||'Error','error');
  });
}
function deleteKR(id,title){
  if(!confirm(`Delete key result "${title}"?`))return;
  const fd=new FormData();fd.append('action','delete_kr');fd.append('id',id);fd.append('csrf_token',CSRF);
  fetch('php/kr_api.php',{method:'POST',body:fd}).then(r=>r.json()).then(res=>{
    if(res.success){toast('Key result deleted');setTimeout(()=>location.reload(),800);}else toast(res.message||'Error','error');
  });
}

/* ── Progress ────────────────────────────────── */
function openProgressModal(krId,title,current,target){
  progTarget=parseFloat(target);
  document.getElementById('progKrId').value=krId;document.getElementById('progKrTitle').textContent=title;
  document.getElementById('progValue').value=current;document.getElementById('progTarget').textContent='/ '+target;
  document.getElementById('progNote').value='';updateProgPreview();
  new bootstrap.Modal(document.getElementById('progressModal')).show();
}
document.getElementById('progValue')?.addEventListener('input',updateProgPreview);
function updateProgPreview(){
  const v=parseFloat(document.getElementById('progValue').value)||0;const p=Math.min(100,Math.round((v/progTarget)*100));
  document.getElementById('progPct').textContent=p+'%';document.getElementById('progBar').style.width=p+'%';
}
function submitProgress(){
  const form=document.getElementById('progressForm');if(!form.checkValidity()){form.reportValidity();return;}
  fetch('php/kr_api.php',{method:'POST',body:new FormData(form)}).then(r=>r.json()).then(res=>{
    if(res.success){toast('Progress updated!');bootstrap.Modal.getInstance(document.getElementById('progressModal')).hide();setTimeout(()=>location.reload(),800);}
    else toast(res.message||'Error','error');
  });
}
</script>
</body></html>
