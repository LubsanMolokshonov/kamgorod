<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

/** Настоящий текст PDF. Требует временную catalog_qa, vendor и pdftotext (Poppler). */
$socket = getopt('', ['qa-socket:'])['qa-socket'] ?? '';
if (!is_string($socket) || !preg_match('~^/private/tmp/fgos-checkout-[a-zA-Z0-9]+/mysql\.sock$~D', $socket)
    || realpath($socket) !== $socket || filetype($socket) !== 'socket') {
    throw new RuntimeException('Укажите --qa-socket временного MySQL');
}
$db = new PDO("mysql:unix_socket=$socket;dbname=catalog_qa;charset=utf8mb4", 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
if ($db->query('SELECT @@socket')->fetchColumn() !== $socket) throw new RuntimeException('Ожидается QA MySQL');

foreach (['Database', 'Registration', 'OlympiadRegistration', 'Diploma', 'OlympiadDiploma'] as $class) {
    require_once __DIR__ . '/../classes/' . $class . '.php';
}
$output = sys_get_temp_dir() . '/fgos-diploma-qa-' . bin2hex(random_bytes(6));
mkdir($output, 0700);
$db->beginTransaction();
try {
    $db->prepare('INSERT INTO users(email,full_name,organization,city) VALUES(?,?,?,?)')->execute([
        'qa-' . bin2hex(random_bytes(5)) . '@example.test', 'Старое Имя', 'Старая школа', 'Старый город',
    ]);
    $userId = (int)$db->lastInsertId();
    $template = $db->query('SELECT id FROM diploma_templates WHERE is_active=1 ORDER BY id LIMIT 1')->fetchColumn();
    $competition = $db->query('SELECT id FROM competitions WHERE is_active=1 ORDER BY id LIMIT 1')->fetchColumn();
    if (!$template || !$competition) throw new RuntimeException('Нужны тестовые шаблон и конкурс');
    $db->prepare('INSERT INTO olympiads(title,slug,target_audience,diploma_price,is_active) VALUES(?,?,?,?,1)')
        ->execute(['Тестовая олимпиада', 'qa-diploma-' . bin2hex(random_bytes(6)), 'QA', 149]);
    $base = [
        'user_id' => $userId, 'participant_name' => 'Новый Участник', 'diploma_template_id' => $template,
        'competition_id' => $competition, 'olympiad_id' => (int)$db->lastInsertId(), 'nomination' => 'Проверка',
        'placement' => '1', 'score' => 100, 'participation_date' => date('Y-m-d'),
        'has_supervisor' => 1, 'supervisor_name' => 'Новый Руководитель',
    ];
    $reg = new Registration($db);
    $olymp = new OlympiadRegistration($db);
    $generators = ['registrations' => new Diploma($db), 'olympiad_registrations' => new OlympiadDiploma($db)];
    foreach ($generators as $generator) {
        (new ReflectionProperty($generator, 'uploadsDir'))->setValue($generator, $output . '/');
    }

    $check = static function (string $table, int $id, string $recipient, array $required, array $forbidden)
        use ($db, $generators, $output): void {
        $generator = $generators[$table];
        $db->prepare("UPDATE $table SET status='paid' WHERE id=?")->execute([$id]);
        $result = $generator->generate($id, $recipient);
        if (!$result['success']) throw new RuntimeException($result['message']);
        $path = $output . '/' . basename($result['pdf_path']);
        if (!isIssuedDocumentPdf($path)) throw new RuntimeException('Невалидный PDF');
        $process = proc_open(['pdftotext', '-layout', $path, '-'], [1 => ['pipe', 'w']], $pipes);
        if (!$process) throw new RuntimeException('Требуется pdftotext');
        $text = preg_replace('/\s+/u', ' ', stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        if (proc_close($process) !== 0) throw new RuntimeException('Не удалось прочитать PDF');
        foreach ($required as $word) {
            if (!str_contains($text, $word)) throw new RuntimeException("В PDF отсутствует «$word»");
        }
        foreach ($forbidden as $word) {
            if (str_contains($text, $word)) throw new RuntimeException("В PDF попало «$word»");
        }
    };

    $oldProfile = ['Старая школа', 'Старый город'];
    $new = ['organization' => 'Новая школа', 'city' => 'Новый город',
            'supervisor_organization' => 'Школа педагога', 'supervisor_city' => 'Город педагога'];
    $id = (int)$reg->create($new + $base);
    $check('registrations', $id, 'participant', ['Новый Участник', 'Новая школа', 'Новый город'], $oldProfile);
    $check('registrations', $id, 'supervisor', ['Новый Руководитель', 'Школа педагога', 'Город педагога'], $oldProfile);
    $id = (int)$reg->create(['organization' => 'Групповая школа', 'city' => '', 'supervisor_organization' => 'Групповая школа'] + $base);
    $check('registrations', $id, 'participant', ['Новый Участник', 'Групповая школа'], $oldProfile);
    $check('registrations', $id, 'supervisor', ['Новый Руководитель', 'Групповая школа'], $oldProfile);
    $id = (int)$reg->create($base);
    $check('registrations', $id, 'participant', ['Новый Участник', ...$oldProfile], []);
    $id = (int)$olymp->create(['city' => ''] + $new + $base);
    $check('olympiad_registrations', $id, 'participant', ['Новый Участник', 'Новая школа'], $oldProfile);
    $check('olympiad_registrations', $id, 'supervisor', ['Школа педагога', 'Город педагога'], $oldProfile);
    $id = (int)$olymp->create(['organization' => 'Школа олимпиады'] + $base);
    $check('olympiad_registrations', $id, 'participant', ['Школа олимпиады', 'Старый город'], []);
    echo "Проверено дипломов: 8\n";
} finally {
    $db->rollBack();
    foreach (glob($output . '/*.pdf') as $pdf) unlink($pdf);
    rmdir($output);
}
