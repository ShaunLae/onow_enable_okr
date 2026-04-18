<?php
// includes/functions.php — OKR business logic v4
require_once __DIR__.'/config.php';
require_once __DIR__.'/auth.php';

/* ══════════════════════════════════════════════════
   STATUS HELPERS
══════════════════════════════════════════════════ */
function getProgressStatus(float $p, ?string $end=null, ?string $start=null): string {
    // Completed
    if ($p >= 100) return 'Completed';
    // No progress at all
    if ($p <= 0)   return 'Not Started';

    // ── No dates set ─────────────────────────────────────────────
    // Without a timeline we cannot assess whether the user is behind
    // schedule — there is no schedule to compare against.
    // Any forward progress is treated as On Track to avoid demotivating
    // users who are simply early in their journey on an undated objective.
    // Behind / At Risk only appear when the system has enough information
    // (dates) to genuinely justify them.
    if (!$start && !$end) {
        return 'On Track';
    }

    // ── Only end_date set (no start_date) ────────────────────────
    // We know the deadline but not when work began.
    // Use days remaining as a pressure signal only.
    if (!$start && $end) {
        $nowTs  = time();
        $endTs  = strtotime($end);
        if ($nowTs > $endTs)  return 'Behind';   // past deadline
        $daysLeft = ($endTs - $nowTs) / 86400;
        if ($p >= 70)         return 'On Track';
        if ($daysLeft <= 14)  return ($p < 50) ? 'Behind' : 'At Risk';
        return 'On Track';                        // enough time, any progress is fine
    }

    // ── Both dates set — full gap analysis ───────────────────────
    $startTs  = strtotime($start);
    $endTs    = strtotime($end);
    $nowTs    = time();
    $totalSec = max(1, $endTs - $startTs);
    $elapsed  = max(0, $nowTs - $startTs);

    // Past deadline and still incomplete
    if ($nowTs > $endTs) return 'Behind';

    // Expected progress given elapsed time (0–100)
    $expected = min(100, ($elapsed / $totalSec) * 100);
    $gap      = $expected - $p;   // positive = behind schedule

    if ($gap <= 0)  return 'On Track';   // ahead of or on schedule
    if ($gap <= 20) return 'At Risk';    // up to 20 points behind schedule
    return 'Behind';                     // more than 20 points behind schedule
}

function statusClass(string $s): string {
    return match($s) {
        'On Track'   => 'status-on-track',
        'At Risk'    => 'status-at-risk',
        'Behind'     => 'status-behind',
        'Completed'  => 'status-completed',
        default      => 'status-not-started',
    };
}

/* ══════════════════════════════════════════════════
   TEAM FUNCTIONS
══════════════════════════════════════════════════ */
function getAllTeams(): array {
    return getDB()->query("SELECT t.*,u.full_name as created_by_name,(SELECT COUNT(*) FROM team_members tm WHERE tm.team_id=t.id) as member_count FROM teams t LEFT JOIN users u ON t.created_by=u.id ORDER BY t.name")->fetchAll();
}

function getTeam(int $id): ?array {
    $s = getDB()->prepare("SELECT * FROM teams WHERE id=?"); $s->execute([$id]); return $s->fetch()?:null;
}

function createTeam(string $name, string $desc, int $uid): array {
    try {
        getDB()->prepare("INSERT INTO teams(name,description,created_by) VALUES(?,?,?)")->execute([sanitize($name),sanitize($desc),$uid]);
        $id = getDB()->lastInsertId();
        logActivity($uid,'CREATE_TEAM','team',$id,"Created team: $name");
        return ['success'=>true,'id'=>$id];
    } catch(PDOException $e){ return ['success'=>false,'message'=>'Failed to create team.']; }
}

function updateTeam(int $id, string $name, string $desc, int $uid): array {
    try {
        getDB()->prepare("UPDATE teams SET name=?,description=?,updated_at=NOW() WHERE id=?")->execute([sanitize($name),sanitize($desc),$id]);
        logActivity($uid,'UPDATE_TEAM','team',$id,"Updated team: $name");
        return ['success'=>true];
    } catch(PDOException $e){ return ['success'=>false,'message'=>'Failed to update team.']; }
}

function deleteTeam(int $id, int $uid): array {
    try {
        $t = getTeam($id);
        getDB()->prepare("DELETE FROM teams WHERE id=?")->execute([$id]);
        logActivity($uid,'DELETE_TEAM','team',$id,"Deleted team: ".($t['name']??$id));
        return ['success'=>true];
    } catch(PDOException $e){ return ['success'=>false,'message'=>'Failed to delete team.']; }
}

function getTeamMembers(int $teamId): array {
    $s = getDB()->prepare("SELECT u.id,u.full_name,u.email,u.role,u.avatar_color,u.job_title,u.department FROM team_members tm JOIN users u ON tm.user_id=u.id WHERE tm.team_id=? ORDER BY u.role,u.full_name");
    $s->execute([$teamId]); return $s->fetchAll();
}

function setTeamMembers(int $teamId, array $userIds, int $uid): void {
    $db = getDB();
    $db->prepare("DELETE FROM team_members WHERE team_id=?")->execute([$teamId]);
    if (!empty($userIds)) {
        $s = $db->prepare("INSERT IGNORE INTO team_members(team_id,user_id) VALUES(?,?)");
        foreach ($userIds as $u) $s->execute([$teamId,(int)$u]);
    }
    logActivity($uid,'UPDATE_TEAM_MEMBERS','team',$teamId,'Updated team members');
}

function getUserTeams(int $userId): array {
    $s = getDB()->prepare("SELECT t.* FROM teams t JOIN team_members tm ON t.id=tm.team_id WHERE tm.user_id=? ORDER BY t.name");
    $s->execute([$userId]); return $s->fetchAll();
}

/** All non-Admin users sharing at least one team with $userId, excluding self */
function getTeammates(int $userId): array {
    $s = getDB()->prepare("SELECT DISTINCT u.id,u.full_name,u.email,u.role,u.avatar_color,u.department,u.job_title FROM users u JOIN team_members tm ON u.id=tm.user_id WHERE tm.team_id IN(SELECT team_id FROM team_members WHERE user_id=?) AND u.id!=? AND u.is_active=1 AND u.role!='Admin' ORDER BY u.role,u.full_name");
    $s->execute([$userId,$userId]); return $s->fetchAll();
}

/** Members of a specific team (excluding self and Admins) */
function getTeamMembersForObjective(int $teamId, int $selfId): array {
    $s = getDB()->prepare("SELECT u.id,u.full_name,u.email,u.role,u.avatar_color,u.department,u.job_title FROM users u JOIN team_members tm ON u.id=tm.user_id WHERE tm.team_id=? AND u.id!=? AND u.is_active=1 AND u.role!='Admin' ORDER BY u.role,u.full_name");
    $s->execute([$teamId,$selfId]); return $s->fetchAll();
}

/* ══════════════════════════════════════════════════
   OBJECTIVES
══════════════════════════════════════════════════ */
function getObjectives(int $userId, string $role, ?string $type=null, ?string $period=null, ?int $teamId=null): array {
    $db = getDB(); $params = [];

    // Suppress parent_title for Members when the parent is a confidential Org objective.
    // Managers see all parent titles unconditionally.
    $parentTitleExpr = $role === 'Manager'
        ? "po.title"
        : "CASE WHEN po.type != 'Organisational' OR po.is_public = 1 THEN po.title ELSE NULL END";

    $base = "SELECT o.*, u.full_name AS owner_name, c.full_name AS created_by_name,
                    $parentTitleExpr AS parent_title, t.name AS team_name
             FROM   objectives o
             LEFT   JOIN users u  ON o.owner_id   = u.id
             LEFT   JOIN users c  ON o.created_by = c.id
             LEFT   JOIN objectives po ON o.parent_objective_id = po.id
             LEFT   JOIN teams t  ON o.team_id    = t.id
             LEFT   JOIN objective_members om ON o.id = om.objective_id";

    if ($role === 'Manager') {
        // Managers see all objectives including confidential Organisational ones
        $sql    = "$base WHERE o.deleted_at IS NULL
                          AND (o.created_by = ? OR om.user_id = ? OR o.type = 'Organisational')";
        $params = [$userId, $userId];
    } else {
        // Members see:  their own objectives + ones they're assigned to
        //             + Organisational objectives that are public (is_public = 1) only
        $sql    = "$base WHERE o.deleted_at IS NULL
                          AND (
                              o.owner_id = ? OR om.user_id = ?
                              OR (o.type = 'Organisational' AND o.is_public = 1)
                          )";
        $params = [$userId, $userId];
    }
    if ($type)   { $sql .= " AND o.type = ?";        $params[] = $type; }
    if ($period) { $sql .= " AND o.time_period = ?";  $params[] = $period; }
    if ($teamId) { $sql .= " AND o.team_id = ?";      $params[] = $teamId; }
    $sql .= " GROUP BY o.id ORDER BY o.created_at DESC";
    $s = $db->prepare($sql); $s->execute($params); return $s->fetchAll();
}

function getObjective(int $id, string $role = 'Manager'): ?array {
    // For Members, suppress the parent_title when the parent is a confidential Org objective.
    $parentTitleExpr = $role === 'Manager'
        ? "po.title"
        : "CASE WHEN po.type != 'Organisational' OR po.is_public = 1 THEN po.title ELSE NULL END";

    $s = getDB()->prepare("
        SELECT o.*, u.full_name AS owner_name, c.full_name AS created_by_name,
               $parentTitleExpr AS parent_title, t.name AS team_name
        FROM   objectives o
        LEFT   JOIN users u  ON o.owner_id   = u.id
        LEFT   JOIN users c  ON o.created_by = c.id
        LEFT   JOIN objectives po ON o.parent_objective_id = po.id
        LEFT   JOIN teams t  ON o.team_id    = t.id
        WHERE  o.id = ? AND o.deleted_at IS NULL
    ");
    $s->execute([$id]); return $s->fetch() ?: null;
}

function getParentOptions(int $userId, string $role, ?int $excludeId=null): array {
    $db = getDB();
    // Confidential Org objectives are never linkable as parents — for anyone.
    // A Manager who created a confidential Org objective cannot accidentally
    // expose it by linking a Team objective to it (the link would be invisible
    // to Members who view that Team objective).
    if ($role === 'Manager') {
        $sql = "SELECT o.id, o.title, o.type, o.time_period, o.is_public
                FROM   objectives o
                LEFT   JOIN objective_members om ON o.id = om.objective_id
                WHERE  o.deleted_at IS NULL
                  AND  (o.created_by = ? OR om.user_id = ?
                        OR (o.type = 'Organisational' AND o.is_public = 1))";
    } else {
        $sql = "SELECT o.id, o.title, o.type, o.time_period, o.is_public
                FROM   objectives o
                LEFT   JOIN objective_members om ON o.id = om.objective_id
                WHERE  o.deleted_at IS NULL
                  AND  (o.owner_id = ? OR om.user_id = ?
                        OR (o.type = 'Organisational' AND o.is_public = 1))";
    }
    $p = [$userId, $userId];
    if ($excludeId) { $sql .= " AND o.id != ?"; $p[] = $excludeId; }
    $sql .= " GROUP BY o.id ORDER BY o.type, o.title";
    $s = $db->prepare($sql); $s->execute($p); return $s->fetchAll();
}

function createObjective(array $d, int $userId): array {
    $db = getDB();
    try {
        // is_public only applies to Organisational type; Team/Personal are always public.
        $isPublic = ($d['type'] === 'Organisational')
            ? (isset($d['is_public']) && $d['is_public'] === '0' ? 0 : 1)
            : 1;
        $s = $db->prepare("INSERT INTO objectives(title,description,type,is_public,status,time_period,start_date,end_date,owner_id,created_by,parent_objective_id,team_id,progress) VALUES(?,?,?,?,'Not Started',?,?,?,?,?,?,?,0.00)");
        $s->execute([
            sanitize($d['title']),
            sanitize($d['description'] ?? ''),
            $d['type'],
            $isPublic,
            sanitize($d['time_period']),
            !empty($d['start_date']) ? $d['start_date'] : null,
            !empty($d['end_date'])   ? $d['end_date']   : null,
            !empty($d['owner_id'])   ? (int)$d['owner_id'] : $userId,
            $userId,
            !empty($d['parent_objective_id']) ? (int)$d['parent_objective_id'] : null,
            !empty($d['team_id'])             ? (int)$d['team_id']             : null,
        ]);
        $id = $db->lastInsertId();
        if (!empty($d['member_ids']) && is_array($d['member_ids'])) {
            $ms = $db->prepare("INSERT IGNORE INTO objective_members(objective_id,user_id) VALUES(?,?)");
            foreach ($d['member_ids'] as $m) $ms->execute([$id,(int)$m]);
        }
        logActivity($userId,'CREATE_OBJECTIVE','objective',$id,"Created: ".$d['title']);
        return ['success'=>true,'id'=>$id];
    } catch(PDOException $e){ return ['success'=>false,'message'=>'Failed to create objective: '.$e->getMessage()]; }
}

function updateObjective(int $id, array $d, int $userId): array {
    $db = getDB();
    try {
        $isPublic = ($d['type'] === 'Organisational')
            ? (isset($d['is_public']) && $d['is_public'] === '0' ? 0 : 1)
            : 1;
        $db->prepare("UPDATE objectives SET title=?,description=?,type=?,is_public=?,time_period=?,start_date=?,end_date=?,owner_id=?,parent_objective_id=?,team_id=?,updated_at=NOW() WHERE id=?")->execute([
            sanitize($d['title']),
            sanitize($d['description'] ?? ''),
            $d['type'],
            $isPublic,
            sanitize($d['time_period']),
            !empty($d['start_date']) ? $d['start_date'] : null,
            !empty($d['end_date'])   ? $d['end_date']   : null,
            !empty($d['owner_id'])   ? (int)$d['owner_id'] : $userId,
            !empty($d['parent_objective_id']) ? (int)$d['parent_objective_id'] : null,
            !empty($d['team_id'])             ? (int)$d['team_id']             : null,
            $id,
        ]);
        if (isset($d['member_ids'])) {
            $db->prepare("DELETE FROM objective_members WHERE objective_id=?")->execute([$id]);
            if (!empty($d['member_ids'])) {
                $ms = $db->prepare("INSERT IGNORE INTO objective_members(objective_id,user_id) VALUES(?,?)");
                foreach ($d['member_ids'] as $m) $ms->execute([$id,(int)$m]);
            }
        }
        logActivity($userId,'UPDATE_OBJECTIVE','objective',$id,"Updated: ".$d['title']);
        return ['success'=>true];
    } catch(PDOException $e){ return ['success'=>false,'message'=>'Failed to update objective.']; }
}

/** Soft-delete objective + all descendants + their key results (atomic, with transaction) */
function deleteObjective(int $id, int $userId): array {
    $db = getDB();
    try {
        $db->beginTransaction();
        $s = $db->prepare("SELECT id,title FROM objectives WHERE id=? AND deleted_at IS NULL");
        $s->execute([$id]); $obj = $s->fetch();
        if (!$obj) { $db->rollBack(); return ['success'=>false,'message'=>'Objective not found or already deleted.']; }

        // Collect all descendant IDs via iterative BFS
        $toDelete = [$id]; $queue = [$id];
        while (!empty($queue)) {
            $pid = array_shift($queue);
            $cs = $db->prepare("SELECT id FROM objectives WHERE parent_objective_id=? AND deleted_at IS NULL");
            $cs->execute([$pid]);
            foreach ($cs->fetchAll(PDO::FETCH_COLUMN) as $cid) { $toDelete[]=$cid; $queue[]=$cid; }
        }

        // Soft-delete all KRs under collected objectives
        $krStmt = $db->prepare("UPDATE key_results SET deleted_at=NOW() WHERE objective_id=? AND deleted_at IS NULL");
        foreach ($toDelete as $oid) $krStmt->execute([$oid]);

        // Soft-delete all objectives
        $ph = implode(',',array_fill(0,count($toDelete),'?'));
        $db->prepare("UPDATE objectives SET deleted_at=NOW() WHERE id IN($ph) AND deleted_at IS NULL")->execute($toDelete);

        $db->commit();
        logActivity($userId,'SOFT_DELETE_OBJECTIVE','objective',$id,"Soft-deleted '{$obj['title']}' and ".(count($toDelete)-1)." child(ren).");
        return ['success'=>true,'deleted_count'=>count($toDelete)];
    } catch(PDOException $e){ $db->rollBack(); return ['success'=>false,'message'=>'Failed to delete: '.$e->getMessage()]; }
}

/* ══════════════════════════════════════════════════
   KEY RESULTS
══════════════════════════════════════════════════ */
function getKeyResults(int $objectiveId): array {
    $s = getDB()->prepare("
        SELECT kr.*,
               u.full_name AS owner_name,
               c.full_name AS created_by_name
        FROM   key_results kr
        LEFT   JOIN users u ON kr.owner_id   = u.id
        LEFT   JOIN users c ON kr.created_by = c.id
        WHERE  kr.objective_id = ? AND kr.deleted_at IS NULL
        ORDER  BY kr.created_at ASC
    ");
    $s->execute([$objectiveId]); return $s->fetchAll();
}

function getKeyResult(int $id): ?array {
    $s = getDB()->prepare("
        SELECT kr.*,
               u.full_name AS owner_name,
               c.full_name AS created_by_name
        FROM   key_results kr
        LEFT   JOIN users u ON kr.owner_id   = u.id
        LEFT   JOIN users c ON kr.created_by = c.id
        WHERE  kr.id = ? AND kr.deleted_at IS NULL
    ");
    $s->execute([$id]); return $s->fetch()?:null;
}

/** Permission check: Manager OR the user who created the KR can edit/delete */
function canModifyKR(array $kr, int $userId, string $role): bool {
    if ($role === 'Manager')   return true;
    if ($role === 'Admin')     return false;  // Admins don't manage OKRs
    return (int)$kr['created_by'] === $userId;
}

function createKeyResult(array $d, int $userId): array {
    $db = getDB();
    try {
        $p = (float)($d['target_value']??0)>0 ? min(100,round(((float)($d['current_value']??0)/(float)$d['target_value'])*100,2)) : 0;
        $db->prepare("INSERT INTO key_results(objective_id,title,description,metric_type,target_value,current_value,unit,progress,status,owner_id,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)")->execute([
            (int)$d['objective_id'],sanitize($d['title']),sanitize($d['description']??''),
            $d['metric_type']??'Percentage',(float)$d['target_value'],(float)($d['current_value']??0),
            sanitize($d['unit']??''),$p,
            (function() use ($d, $p) {
                // Inherit dates from parent objective for KR status calculation
                $parentObj = getObjective((int)$d['objective_id']);
                return getProgressStatus($p, $parentObj['end_date']??null, $parentObj['start_date']??null);
            })(),
            (int)($d['owner_id']??$userId),$userId,
        ]);
        $kid = $db->lastInsertId();
        recalcObjective((int)$d['objective_id']);
        logActivity($userId,'CREATE_KEY_RESULT','key_result',$kid,"Created KR: ".$d['title']);
        return ['success'=>true,'id'=>$kid];
    } catch(PDOException $e){ return ['success'=>false,'message'=>'Failed to create key result.']; }
}

function updateKeyResult(int $id, array $d, int $userId): array {
    $db = getDB();
    try {
        $p = (float)($d['target_value']??0)>0 ? min(100,round(((float)($d['current_value']??0)/(float)$d['target_value'])*100,2)) : 0;
        $db->prepare("UPDATE key_results SET title=?,description=?,metric_type=?,target_value=?,current_value=?,unit=?,progress=?,status=?,owner_id=?,updated_at=NOW() WHERE id=?")->execute([
            sanitize($d['title']),sanitize($d['description']??''),$d['metric_type']??'Percentage',
            (float)$d['target_value'],(float)($d['current_value']??0),sanitize($d['unit']??''),
            $p,
            (function() use ($d, $p, $id) {
                $parentObj = getObjective((int)$d['objective_id']);
                return getProgressStatus($p, $parentObj['end_date']??null, $parentObj['start_date']??null);
            })(),
            (int)($d['owner_id']??$userId),$id,
        ]);
        $row = $db->prepare("SELECT objective_id FROM key_results WHERE id=?"); $row->execute([$id]); $r=$row->fetch();
        if ($r) recalcObjective($r['objective_id']);
        logActivity($userId,'UPDATE_KEY_RESULT','key_result',$id,"Updated KR: ".$d['title']);
        return ['success'=>true];
    } catch(PDOException $e){ return ['success'=>false,'message'=>'Failed to update key result.']; }
}

function updateKRProgress(int $krId, float $newVal, string $note, int $userId): array {
    $db = getDB();
    try {
        $kr = getKeyResult($krId);
        if (!$kr) return ['success'=>false,'message'=>'Key result not found.'];
        $p = $kr['target_value']>0 ? min(100,round(($newVal/$kr['target_value'])*100,2)) : 0;
        // Fetch parent objective dates for accurate status calculation
        $parentObj = getObjective($kr['objective_id']);
        $status = getProgressStatus($p, $parentObj['end_date']??null, $parentObj['start_date']??null);
        $db->prepare("INSERT INTO progress_history(key_result_id,previous_value,new_value,note,updated_by) VALUES(?,?,?,?,?)")->execute([$krId,$kr['current_value'],$newVal,sanitize($note),$userId]);
        $db->prepare("UPDATE key_results SET current_value=?,progress=?,status=?,updated_at=NOW() WHERE id=?")->execute([$newVal,$p,$status,$krId]);
        recalcObjective($kr['objective_id']);
        logActivity($userId,'UPDATE_PROGRESS','key_result',$krId,"Progress → $newVal");
        return ['success'=>true,'progress'=>$p,'status'=>$status];
    } catch(PDOException $e){ return ['success'=>false,'message'=>'Failed to update progress.']; }
}

/** Soft-delete a single KR; then recalculates parent objective progress */
function deleteKeyResult(int $id, int $userId): array {
    $db = getDB();
    try {
        $db->beginTransaction();
        $s = $db->prepare("SELECT id,objective_id,title FROM key_results WHERE id=? AND deleted_at IS NULL");
        $s->execute([$id]); $kr = $s->fetch();
        if (!$kr) { $db->rollBack(); return ['success'=>false,'message'=>'Key result not found or already deleted.']; }
        $db->prepare("UPDATE key_results SET deleted_at=NOW() WHERE id=?")->execute([$id]);
        $db->commit();
        recalcObjective($kr['objective_id']);
        logActivity($userId,'SOFT_DELETE_KEY_RESULT','key_result',$id,"Soft-deleted KR '{$kr['title']}'.");
        return ['success'=>true];
    } catch(PDOException $e){ $db->rollBack(); return ['success'=>false,'message'=>'Failed to delete key result: '.$e->getMessage()]; }
}

function recalcObjective(int $objectiveId): void {
    $db = getDB();
    $r = $db->prepare("SELECT AVG(progress) as avg FROM key_results WHERE objective_id=? AND deleted_at IS NULL");
    $r->execute([$objectiveId]); $res=$r->fetch();
    $p = round((float)($res['avg']??0),2);
    $obj = getObjective($objectiveId);
    $status = getProgressStatus($p, $obj['end_date']??null, $obj['start_date']??null);
    $db->prepare("UPDATE objectives SET progress=?,status=?,updated_at=NOW() WHERE id=?")->execute([$p,$status,$objectiveId]);
}

function getProgressHistory(int $krId): array {
    $s = getDB()->prepare("SELECT ph.*,u.full_name as updated_by_name FROM progress_history ph LEFT JOIN users u ON ph.updated_by=u.id WHERE ph.key_result_id=? ORDER BY ph.created_at DESC LIMIT 30");
    $s->execute([$krId]); return $s->fetchAll();
}

/* ══════════════════════════════════════════════════
   ATTACHMENTS
══════════════════════════════════════════════════ */
function getAttachments(int $objId): array {
    $s = getDB()->prepare("SELECT oa.*,u.full_name as uploader_name FROM objective_attachments oa LEFT JOIN users u ON oa.uploaded_by=u.id WHERE oa.objective_id=? ORDER BY oa.uploaded_at DESC");
    $s->execute([$objId]); return $s->fetchAll();
}

function saveAttachment(int $objId, array $file, int $userId): array {
    $allowed = ['pdf','doc','docx','xls','xlsx','ppt','pptx','png','jpg','jpeg','gif','webp'];
    $ext = strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));
    if (!in_array($ext,$allowed)) return ['success'=>false,'message'=>".$ext not allowed."];
    if ($file['size']>10*1024*1024) return ['success'=>false,'message'=>'File exceeds 10MB.'];
    $dir = __DIR__.'/../uploads/';
    if (!is_dir($dir)) mkdir($dir,0755,true);
    $safe = uniqid('att_',true).'.'.$ext;
    if (!move_uploaded_file($file['tmp_name'],$dir.$safe)) return ['success'=>false,'message'=>'Upload failed.'];
    getDB()->prepare("INSERT INTO objective_attachments(objective_id,file_name,original_name,file_type,file_size,uploaded_by) VALUES(?,?,?,?,?,?)")->execute([$objId,$safe,$file['name'],$file['type'],$file['size'],$userId]);
    return ['success'=>true,'file_name'=>$safe];
}

function deleteAttachment(int $id): array {
    $s = getDB()->prepare("SELECT * FROM objective_attachments WHERE id=?"); $s->execute([$id]); $att=$s->fetch();
    if (!$att) return ['success'=>false,'message'=>'Not found.'];
    $p = __DIR__.'/../uploads/'.$att['file_name'];
    if (file_exists($p)) unlink($p);
    getDB()->prepare("DELETE FROM objective_attachments WHERE id=?")->execute([$id]);
    return ['success'=>true];
}

function fileIcon(string $name): string {
    $e = strtolower(pathinfo($name,PATHINFO_EXTENSION));
    return match(true){
        $e==='pdf'               => 'bi-file-earmark-pdf text-danger',
        in_array($e,['doc','docx']) => 'bi-file-earmark-word text-primary',
        in_array($e,['xls','xlsx']) => 'bi-file-earmark-excel text-success',
        in_array($e,['ppt','pptx']) => 'bi-file-earmark-ppt text-warning',
        in_array($e,['png','jpg','jpeg','gif','webp']) => 'bi-file-earmark-image text-info',
        default => 'bi-file-earmark text-muted',
    };
}

function fmtSize(int $b): string {
    if ($b<1024) return $b.' B';
    if ($b<1048576) return round($b/1024,1).' KB';
    return round($b/1048576,1).' MB';
}

/* ══════════════════════════════════════════════════
   DASHBOARD STATS
══════════════════════════════════════════════════ */
function getDashboardStats(int $userId, string $role): array {
    $db = getDB();
    if ($role==='Admin') return [
        'total_users'    => (int)$db->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn(),
        'total_teams'    => (int)$db->query("SELECT COUNT(*) FROM teams")->fetchColumn(),
        'total_managers' => (int)$db->query("SELECT COUNT(*) FROM users WHERE role='Manager' AND is_active=1")->fetchColumn(),
        'total_members'  => (int)$db->query("SELECT COUNT(*) FROM users WHERE role='Member' AND is_active=1")->fetchColumn(),
        'total_objectives'   => (int)$db->query("SELECT COUNT(*) FROM objectives WHERE deleted_at IS NULL")->fetchColumn(),
        'total_key_results'  => (int)$db->query("SELECT COUNT(*) FROM key_results WHERE deleted_at IS NULL")->fetchColumn(),
    ];
    $objs = getObjectives($userId,$role);
    $stats = ['total_objectives'=>count($objs),'on_track'=>0,'at_risk'=>0,'completed'=>0,'not_started'=>0,'avg_progress'=>0,'total_key_results'=>0];
    $sum = 0;
    foreach ($objs as $o) {
        $s=$o['status'];
        if ($s==='On Track') $stats['on_track']++;
        elseif (in_array($s,['At Risk','Behind'])) $stats['at_risk']++;
        elseif ($s==='Completed') $stats['completed']++;
        else $stats['not_started']++;
        $sum += $o['progress'];
    }
    $stats['avg_progress'] = count($objs)>0 ? round($sum/count($objs),1) : 0;
    $ids = array_column($objs,'id');
    if (!empty($ids)) {
        $in = implode(',',array_map('intval',$ids));
        $stats['total_key_results'] = (int)$db->query("SELECT COUNT(*) FROM key_results WHERE deleted_at IS NULL AND objective_id IN($in)")->fetchColumn();
    }
    return $stats;
}

/* ══════════════════════════════════════════════════
   USER HELPERS
══════════════════════════════════════════════════ */
function getAllUsers(?string $role=null): array {
    $db = getDB();
    if ($role) { $s=$db->prepare("SELECT id,full_name,email,role,department,avatar_color FROM users WHERE is_active=1 AND role=? ORDER BY full_name"); $s->execute([$role]); }
    else { $s=$db->prepare("SELECT id,full_name,email,role,department,avatar_color FROM users WHERE is_active=1 ORDER BY full_name"); $s->execute(); }
    return $s->fetchAll();
}

function getObjectiveMembers(int $objId): array {
    $s = getDB()->prepare("SELECT u.id,u.full_name,u.email,u.role,u.avatar_color FROM objective_members om JOIN users u ON om.user_id=u.id WHERE om.objective_id=?");
    $s->execute([$objId]); return $s->fetchAll();
}

function getRecentActivity(int $userId, string $role, int $limit=10): array {
    $db = getDB();
    if ($role==='Admin') { $s=$db->prepare("SELECT al.*,u.full_name FROM activity_log al LEFT JOIN users u ON al.user_id=u.id ORDER BY al.created_at DESC LIMIT ?"); $s->execute([$limit]); }
    else { $s=$db->prepare("SELECT al.*,u.full_name FROM activity_log al LEFT JOIN users u ON al.user_id=u.id WHERE al.user_id=? ORDER BY al.created_at DESC LIMIT ?"); $s->execute([$userId,$limit]); }
    return $s->fetchAll();
}

function getTimePeriods(): array {
    $y=date('Y');
    return ["Q1 $y","Q2 $y","Q3 $y","Q4 $y","H1 $y","H2 $y","Annual $y","Q1 ".($y+1),"Q2 ".($y+1)];
}

function initials(string $name): string {
    return strtoupper(substr(implode('',array_map(fn($w)=>$w[0],explode(' ',trim($name)))),0,2));
}
