<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(300);

require_once 'config/config.php';

/**
 * Разбивает SQL-дамп на отдельные стейтменты по ';'.
 * Учитывает строковые литералы ('...', "...", `...`), экранирование (\' и '')
 * и комментарии MySQL (строчные "-- " и "#", а также блочные).
 * Это нужно, чтобы ';' и '--' внутри строк и комментариев не ломали разбор.
 */
function splitSqlStatements(string $sql): array
{
    $statements = [];
    $current = '';
    $len = strlen($sql);
    $inSingle = $inDouble = $inBacktick = false;
    $i = 0;

    while ($i < $len) {
        $ch = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        if ($inSingle) {
            $current .= $ch;
            if ($ch === '\\' && $next !== '') { $current .= $next; $i += 2; continue; }
            if ($ch === "'") {
                if ($next === "'") { $current .= $next; $i += 2; continue; } // экранирование ''
                $inSingle = false;
            }
            $i++; continue;
        }
        if ($inDouble) {
            $current .= $ch;
            if ($ch === '\\' && $next !== '') { $current .= $next; $i += 2; continue; }
            if ($ch === '"') {
                if ($next === '"') { $current .= $next; $i += 2; continue; }
                $inDouble = false;
            }
            $i++; continue;
        }
        if ($inBacktick) {
            $current .= $ch;
            if ($ch === '`') { $inBacktick = false; }
            $i++; continue;
        }

        // Комментарий "-- " (двойное тире + пробел/таб/перевод строки) — по правилам MySQL
        if ($ch === '-' && $next === '-') {
            $after = $i + 2 < $len ? $sql[$i + 2] : "\n";
            if ($after === ' ' || $after === "\t" || $after === "\n" || $after === "\r") {
                while ($i < $len && $sql[$i] !== "\n") { $i++; }
                continue;
            }
        }
        // Комментарий '#' до конца строки
        if ($ch === '#') {
            while ($i < $len && $sql[$i] !== "\n") { $i++; }
            continue;
        }
        // Блочный комментарий /* ... */
        if ($ch === '/' && $next === '*') {
            $i += 2;
            while ($i < $len && !($sql[$i] === '*' && ($i + 1 < $len) && $sql[$i + 1] === '/')) { $i++; }
            $i += 2;
            continue;
        }

        if ($ch === "'") { $inSingle = true; $current .= $ch; $i++; continue; }
        if ($ch === '"') { $inDouble = true; $current .= $ch; $i++; continue; }
        if ($ch === '`') { $inBacktick = true; $current .= $ch; $i++; continue; }

        if ($ch === ';') {
            $trimmed = trim($current);
            if ($trimmed !== '') { $statements[] = $trimmed; }
            $current = '';
            $i++; continue;
        }

        $current .= $ch;
        $i++;
    }

    $trimmed = trim($current);
    if ($trimmed !== '') { $statements[] = $trimmed; }

    return $statements;
}

// Create fresh DB connection with buffered queries
$dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true
];

$db = new PDO($dsn, DB_USER, DB_PASS, $options);

$basePath = __DIR__ . '/database/migrations/';
$files = glob($basePath . '[0-9][0-9][0-9]_*.sql');
sort($files);
$migrations = array_map('basename', $files);

$applied = 0;
$skipped = 0;

foreach ($migrations as $migration) {
    $stmt = $db->prepare('SELECT id FROM migrations WHERE migration_name = ?');
    $stmt->execute([$migration]);
    $exists = $stmt->fetch();
    
    if ($exists) {
        echo "⏭️  $migration\n";
        $skipped++;
        continue;
    }
    
    $filePath = $basePath . $migration;
    
    if (!file_exists($filePath)) {
        echo "❌ $migration (не найден)\n";
        continue;
    }
    
    echo "⚙️  $migration...\n";
    
    try {
        $sql = file_get_contents($filePath);
        $statements = splitSqlStatements($sql);
        $executed = 0;
        
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (empty($statement)) {
                continue;
            }
            
            try {
                $result = $db->query($statement);
                if ($result) $result->closeCursor();
                $executed++;
            } catch (PDOException $e) {
                $msg = $e->getMessage();
                if (strpos($msg, 'Duplicate entry') === false && 
                    strpos($msg, 'already exists') === false &&
                    strpos($msg, "Can't DROP") === false) {
                    echo "   ⚠️  " . substr($msg, 0, 80) . "\n";
                }
            }
        }
        
        $stmt = $db->prepare('INSERT INTO migrations (migration_name) VALUES (?)');
        $stmt->execute([$migration]);
        
        echo "✅ $migration ($executed)\n\n";
        $applied++;
        
    } catch (Exception $e) {
        echo "❌ $migration: " . $e->getMessage() . "\n\n";
    }
}

echo "\n════════════════════════════\n";
echo "Применено: $applied\n";
echo "Пропущено: $skipped\n";
echo "════════════════════════════\n";
