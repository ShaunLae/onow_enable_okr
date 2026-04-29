<?php
// Auto-create and permission the uploads directory on first run
$uploadsDir = __DIR__ . '/../uploads/';
if (!is_dir($uploadsDir)) {
    mkdir($uploadsDir, 0755, true);
}
if (!is_writable($uploadsDir)) {
    chmod($uploadsDir, 0755);
}

// includes/config.php
define('DB_HOST',        'localhost');
define('DB_USER',        'root');
define('DB_PASS',        '');
define('DB_NAME',        'onow_enable_okr');
define('DB_CHARSET',     'utf8mb4');
define('SESSION_TIMEOUT', 1800);   // 30 minutes 
define('APP_NAME',       'ONOW Enable OKR');

date_default_timezone_set('Europe/London');
error_reporting(E_ALL);
ini_set('display_errors', 1);

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=".DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['success'=>false,'message'=>'Database connection failed.']));
        }
    }
    return $pdo;
}
