<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireLogin();
header('Content-Type: application/json');
$userId = (int)$_SESSION['user_id'];
$role   = $_SESSION['user_role'];
if ($role === 'Admin') { echo json_encode(['success'=>false,'message'=>'No access']); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['obj_members'])) {
        echo json_encode(getObjectiveMembers((int)($_GET['objective_id'] ?? 0))); exit;
    }
    if (isset($_GET['objective_id'])) {
        echo json_encode(getKeyResults((int)$_GET['objective_id'])); exit;
    }
    if (isset($_GET['history'])) {
        echo json_encode(getProgressHistory((int)($_GET['kr_id'] ?? 0))); exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success'=>false,'message'=>'Invalid CSRF token']); exit;
    }
    $action = $_POST['action'] ?? '';

    // ── Create KR ─────────────────────────────────────────────────
    // Rules:
    //   - Admin: no access (blocked above)
    //   - Manager: can create KR on any objective type
    //   - Member: CANNOT create KR on Organisational objectives (even public ones)
    //             CAN create KR on Team objectives they're assigned to
    //             CAN create KR on their own Personal objectives (owner forced to self)
    if ($action === 'create_kr') {
        if (empty($_POST['title']) || empty($_POST['objective_id'])) {
            echo json_encode(['success'=>false,'message'=>'Title and objective are required']); exit;
        }
        if ((float)($_POST['target_value'] ?? 0) <= 0) {
            echo json_encode(['success'=>false,'message'=>'Target value must be greater than 0']); exit;
        }
        if ($role === 'Member') {
            // Gate: Members cannot add KRs to Organisational objectives
            $parentObj = getObjective((int)$_POST['objective_id'], 'Member');
            if ($parentObj && $parentObj['type'] === 'Organisational') {
                echo json_encode([
                    'success' => false,
                    'message' => 'Members cannot add key results to Organisational objectives. Contact your manager.'
                ]); exit;
            }
            // Member is always forced to be the KR owner
            $_POST['owner_id'] = $userId;
        }
        echo json_encode(createKeyResult($_POST, $userId)); exit;
    }

    // ── Update KR details ─────────────────────────────────────────
    // Allowed if: Manager OR created_by === $userId
    if ($action === 'update_kr') {
        $id = (int)($_POST['id'] ?? 0);
        $kr = getKeyResult($id);
        if (!$kr) {
            echo json_encode(['success'=>false,'message'=>'Key result not found']); exit;
        }
        if (!canModifyKR($kr, $userId, $role)) {
            echo json_encode(['success'=>false,'message'=>'You can only edit key results you created']); exit;
        }
        echo json_encode(updateKeyResult($id, $_POST, $userId)); exit;
    }

    // ── Delete KR ─────────────────────────────────────────────────
    // Allowed if: Manager OR created_by === $userId
    if ($action === 'delete_kr') {
        $id = (int)($_POST['id'] ?? 0);
        $kr = getKeyResult($id);
        if (!$kr) {
            echo json_encode(['success'=>false,'message'=>'Key result not found']); exit;
        }
        if (!canModifyKR($kr, $userId, $role)) {
            echo json_encode(['success'=>false,'message'=>'You can only delete key results you created']); exit;
        }
        echo json_encode(deleteKeyResult($id, $userId)); exit;
    }

    // ── Update progress ───────────────────────────────────────────
    // Members can only update KRs where they are the owner (assigned to).
    // Managers can update any KR.
    if ($action === 'update_progress') {
        $krId = (int)($_POST['kr_id'] ?? 0);
        $kr   = getKeyResult($krId);
        if (!$kr) {
            echo json_encode(['success'=>false,'message'=>'Key result not found']); exit;
        }
        if ($role === 'Member' && (int)$kr['owner_id'] !== $userId) {
            echo json_encode(['success'=>false,'message'=>'You can only update progress on key results assigned to you']); exit;
        }
        echo json_encode(updateKRProgress($krId, (float)($_POST['new_value'] ?? 0), $_POST['note'] ?? '', $userId)); exit;
    }
}

echo json_encode(['success'=>false,'message'=>'Invalid request']);
