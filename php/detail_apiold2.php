<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireLogin();
header('Content-Type: application/json');
$userId = (int)$_SESSION['user_id'];
$role   = $_SESSION['user_role'];

// Admins have no access to OKR detail
if ($role === 'Admin') { echo json_encode(['error'=>'No access']); exit; }

$id  = (int)($_GET['id'] ?? 0);
$obj = getObjective($id, $role);   // pass role so parent_title suppression is applied

if (!$obj) { echo json_encode(['error'=>'Not found']); exit; }

// ── Visibility gate for confidential Organisational objectives ────
// Members must not be able to access confidential Org objectives even
// via a direct API call with a known id.
if ($role === 'Member' && $obj['type'] === 'Organisational' && (int)$obj['is_public'] === 0) {
    echo json_encode(['error'=>'This objective is not available to you.']); exit;
}

$krs         = getKeyResults($id);
$members     = getObjectiveMembers($id);
$attachments = getAttachments($id);

// can_edit: Manager must be creator; Member must be owner
$canEdit = ($role === 'Manager' && $obj['created_by'] == $userId)
        || ($role === 'Member'  && $obj['owner_id']   == $userId);

// can_add_kr: Members cannot add KRs to Organisational objectives
$canAddKR = ($role === 'Manager')
         || ($role === 'Member' && $obj['type'] !== 'Organisational');

$obj['status_class'] = statusClass($obj['status']);
$obj['can_edit']     = $canEdit;
$obj['can_add_kr']   = $canAddKR;
$obj['progress']     = (float)$obj['progress'];
$obj['is_public']    = (int)($obj['is_public'] ?? 1);

foreach ($krs as &$kr) {
    $kr['status_class']   = statusClass($kr['status']);
    $kr['progress']       = (float)$kr['progress'];
    $kr['current_value']  = (float)$kr['current_value'];
    $kr['target_value']   = (float)$kr['target_value'];
    $kr['owner_id']       = (int)$kr['owner_id'];
    $kr['created_by']     = (int)($kr['created_by'] ?? 0);
    $kr['can_modify']     = ($role === 'Manager') || ((int)($kr['created_by'] ?? 0) === $userId);
    $kr['can_update_progress'] = ($role === 'Manager') || ($kr['owner_id'] === $userId);
}
unset($kr);

foreach ($attachments as &$a) {
    $a['icon']     = fileIcon($a['original_name']);
    $a['size_fmt'] = fmtSize((int)$a['file_size']);
}
unset($a);

echo json_encode([
    'objective'   => $obj,
    'key_results' => $krs,
    'members'     => $members,
    'attachments' => $attachments,
]);
