<?php
// fix_passwords.php — Run ONCE after importing database.sql, then DELETE this file.
require_once 'includes/config.php';
$hash = password_hash('Admin@1234', PASSWORD_BCRYPT, ['cost' => 12]);
$db   = getDB();
$db->prepare("UPDATE users SET password_hash = ? WHERE email LIKE '%onow-enable.org'")->execute([$hash]);
echo "<h2 style='font-family:sans-serif;padding:2rem'>";
echo "✅ Done. All seed user passwords set to <strong>Admin@1234</strong><br><br>";
echo "<strong style='color:red'>⚠️ DELETE this file immediately!</strong><br><br>";
echo "Hash used: <code>$hash</code>";
echo "</h2>";
