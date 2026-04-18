<?php
// includes/auth.php
require_once __DIR__.'/config.php';

function startSecureSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params(['lifetime'=>0,'path'=>'/','httponly'=>true,'samesite'=>'Strict']);
        session_start();
    }
}

/* FR 1.2 — session timeout */
function isLoggedIn(): bool {
    startSecureSession();
    if (empty($_SESSION['user_id']) || empty($_SESSION['last_activity'])) return false;
    if (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) { logoutUser(); return false; }
    $_SESSION['last_activity'] = time();
    return true;
}

function requireLogin(): void {
    if (!isLoggedIn()) { header('Location: login.php?timeout=1'); exit; }
}

function requireRole(array $roles): void {
    requireLogin();
    if (!in_array($_SESSION['user_role'], $roles)) { header('Location: dashboard.php?error=unauthorized'); exit; }
}

function getCurrentUser(): ?array {
    if (!isLoggedIn()) return null;
    $s = getDB()->prepare("SELECT id,full_name,email,role,phone,department,job_title,avatar_color,last_login FROM users WHERE id=? AND is_active=1");
    $s->execute([$_SESSION['user_id']]);
    return $s->fetch() ?: null;
}

/* FR 1.1 */
function loginUser(string $email, string $password): array {
    $s = getDB()->prepare("SELECT * FROM users WHERE email=? AND is_active=1");
    $s->execute([trim($email)]);
    $u = $s->fetch();
    if (!$u || !password_verify($password, $u['password_hash']))
        return ['success'=>false,'message'=>'Invalid email or password.'];
    startSecureSession();
    session_regenerate_id(true);
    $_SESSION['user_id']       = $u['id'];
    $_SESSION['user_role']     = $u['role'];
    $_SESSION['user_name']     = $u['full_name'];
    $_SESSION['last_activity'] = time();
    getDB()->prepare("UPDATE users SET last_login=NOW() WHERE id=?")->execute([$u['id']]);
    logActivity($u['id'],'LOGIN','user',$u['id'],'User logged in');
    return ['success'=>true,'role'=>$u['role']];
}

function logoutUser(): void {
    startSecureSession();
    if (!empty($_SESSION['user_id'])) logActivity($_SESSION['user_id'],'LOGOUT','user',$_SESSION['user_id'],'User logged out');
    $_SESSION = []; session_destroy();
}

function hasRole(string $role): bool { return isset($_SESSION['user_role']) && $_SESSION['user_role']===$role; }

function sanitize(string $input): string { return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8'); }

function getCsrfToken(): string {
    startSecureSession();
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function validateCsrf(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function logActivity(?int $userId, string $action, string $entity, ?int $entityId, string $desc): void {
    try { getDB()->prepare("INSERT INTO activity_log(user_id,action,entity_type,entity_id,description) VALUES(?,?,?,?,?)")->execute([$userId,$action,$entity,$entityId,$desc]); } catch(Exception $e){}
}
