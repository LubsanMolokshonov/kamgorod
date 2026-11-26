<?php
/**
 * Database Connection
 * Establishes PDO connection to MySQL database
 */

require_once __DIR__ . '/config.php';

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ];

    $db = new PDO($dsn, DB_USER, DB_PASS, $options);

    // Explicitly set charset and collation for the connection
    $db->exec("SET CHARACTER SET utf8mb4");
    $db->exec("SET character_set_connection=utf8mb4");
    $db->exec("SET character_set_client=utf8mb4");
    $db->exec("SET character_set_results=utf8mb4");

} catch (PDOException $e) {
    // Log the error
    error_log('Database Connection Error: ' . $e->getMessage());

    // Show user-friendly error (customize based on environment)
    if (YOOKASSA_MODE === 'sandbox') {
        die('Database connection failed: ' . $e->getMessage());
    } else {
        die('Извините, возникла техническая проблема. Попробуйте позже.');
    }
}

// Старый код читает user_id напрямую. До чтения защищённых данных проверяем
// происхождение сессии, включая скрипты, которые откроют её только после require.
require_once __DIR__ . '/../includes/account-auth.php';
if (PHP_SAPI !== 'cli' && (session_status() === PHP_SESSION_ACTIVE || isset($_COOKIE[session_name()]) || isset($_COOKIE['fgos_account']))) {
    $wasOpen = session_status() === PHP_SESSION_ACTIVE;
    verifiedAccountId($db);
    if (!$wasOpen) session_write_close();
}
return $db;
