<?php
// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

/**
 * Миграция существующих олимпиад с диапазонных audience_types (nachalnaya-shkola,
 * srednyaya-starshaya-shkola, 1-4-klassy, 5-8-klassy, 9-11-klassy) на конкретные классы 1-11.
 *
 * Циклично распределяет olympiad_id (ORDER BY id) по классам своей ступени, полностью
 * переписывает title/slug/description/seo_content/questions через AI (сложность вопросов
 * должна отличаться по классу — это не механическая замена текста), обновляет привязки
 * audience_types/specializations на новые (под конкретный класс).
 *
 * UPDATE существующей записи по id (не создаёт новую строку) — сохраняет историю/внешние ссылки.
 *
 * Флаги:
 *   --group=pedagogi-nachalka | pedagogi-srednyaya | shkolnikam
 *   --dry-run
 *
 * Запуск: docker exec pedagogy_web php /var/www/html/database/migrate_olympiads_to_grades.php --group=pedagogi-nachalka
 */
set_time_limit(0);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Olympiad.php';
require_once __DIR__ . '/../classes/YandexGPTJsonService.php';
$db->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

$opts = getopt('', ['group:', 'dry-run']);
$group = $opts['group'] ?? null;
$dryRun = array_key_exists('dry-run', $opts);

if (!in_array($group, ['pedagogi-nachalka', 'pedagogi-srednyaya', 'shkolnikam'], true)) {
    fwrite(STDERR, "Использование: --group=pedagogi-nachalka|pedagogi-srednyaya|shkolnikam [--dry-run]\n");
    exit(1);
}

const QUESTIONS_PER_OLYMPIAD = 10;

$pdo = $db;
$olympiadObj = new Olympiad($pdo);
$ai = new YandexGPTJsonService();

$manifestPath = __DIR__ . '/progress_migrate_olympiads.json';
$manifest = file_exists($manifestPath) ? json_decode(file_get_contents($manifestPath), true) : ['done' => [], 'failed' => []];
if (!isset($manifest['done'])) $manifest['done'] = [];
if (!isset($manifest['failed'])) $manifest['failed'] = [];

function saveManifest($path, $manifest) {
    file_put_contents($path, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// ---- Построение списка миграции ----
$items = [];

if ($group === 'pedagogi-nachalka' || $group === 'pedagogi-srednyaya') {
    $oldTypeId = $group === 'pedagogi-nachalka' ? 2 : 3;
    $grades = $group === 'pedagogi-nachalka' ? [1, 2, 3, 4] : [5, 6, 7, 8, 9, 10, 11];

    $stmt = $pdo->prepare(
        "SELECT o.id, o.title, o.subject, o.target_audience
         FROM olympiads o
         JOIN olympiad_audience_types oat ON oat.olympiad_id = o.id
         WHERE oat.audience_type_id = ?
         ORDER BY o.id"
    );
    $stmt->execute([$oldTypeId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $i => $row) {
        $grade = $grades[$i % count($grades)];
        $newTypeSlug = "pedagogam-{$grade}-klass";
        $newType = $pdo->prepare("SELECT id FROM audience_types WHERE slug = ?");
        $newType->execute([$newTypeSlug]);
        $newTypeId = (int)($newType->fetch(PDO::FETCH_ASSOC)['id'] ?? 0);
        if (!$newTypeId) continue;

        // Найти specialization_id ПОД НОВЫМ типом с тем же slug, что и текущая специализация олимпиады
        $curSpec = $pdo->prepare(
            "SELECT s.slug FROM olympiad_specializations os JOIN audience_specializations s ON s.id = os.specialization_id
             WHERE os.olympiad_id = ? LIMIT 1"
        );
        $curSpec->execute([$row['id']]);
        $specSlug = $curSpec->fetch(PDO::FETCH_ASSOC)['slug'] ?? null;

        $newSpecId = null;
        if ($specSlug) {
            $ns = $pdo->prepare("SELECT id FROM audience_specializations WHERE audience_type_id = ? AND slug = ?");
            $ns->execute([$newTypeId, $specSlug]);
            $newSpecId = $ns->fetch(PDO::FETCH_ASSOC)['id'] ?? null;
        }

        $items[] = [
            'olympiad_id' => (int)$row['id'],
            'grade' => $grade,
            'grade_label' => "{$grade} класс",
            'direction' => $row['subject'],
            'new_audience_type_id' => $newTypeId,
            'new_specialization_id' => $newSpecId ? (int)$newSpecId : null,
            'category_id' => 1,
            'target_audience' => $row['target_audience'],
        ];
    }
} else { // shkolnikam
    $ranges = [11 => [1, 2, 3, 4], 12 => [5, 6, 7, 8], 13 => [9, 10, 11]];
    foreach ($ranges as $oldTypeId => $grades) {
        $stmt = $pdo->prepare(
            "SELECT o.id, o.subject FROM olympiads o
             JOIN olympiad_audience_types oat ON oat.olympiad_id = o.id
             WHERE oat.audience_type_id = ? ORDER BY o.id"
        );
        $stmt->execute([$oldTypeId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $i => $row) {
            $grade = $grades[$i % count($grades)];
            $newType = $pdo->prepare("SELECT id FROM audience_types WHERE slug = ?");
            $newType->execute(["{$grade}-klass"]);
            $newTypeId = (int)($newType->fetch(PDO::FETCH_ASSOC)['id'] ?? 0);
            if (!$newTypeId) continue;

            $items[] = [
                'olympiad_id' => (int)$row['id'],
                'grade' => $grade,
                'grade_label' => "{$grade} класс",
                'direction' => $row['subject'],
                'new_audience_type_id' => $newTypeId,
                'new_specialization_id' => null,
                'category_id' => 3,
                'target_audience' => 'students',
            ];
        }
    }
}

echo "К миграции: " . count($items) . " олимпиад (группа: {$group})\n";

if ($dryRun) {
    foreach ($items as $it) {
        echo "  #{$it['olympiad_id']} -> {$it['grade_label']} | {$it['direction']} (new_type={$it['new_audience_type_id']}, new_spec=" . ($it['new_specialization_id'] ?? 'null') . ")\n";
    }
    exit(0);
}

function buildSeoContent(string $title, string $intro, string $what, array $audience, string $adv1, string $adv2): string {
    $li = implode('', array_map(fn($x) => '<li>' . htmlspecialchars($x, ENT_QUOTES, 'UTF-8') . '</li>', $audience));
    $intro = rtrim(trim($intro), '.');
    $what = rtrim(trim($what), '.');
    return '<p><strong>Всероссийская олимпиада «' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '»</strong> — '
        . htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') . '.</p>'
        . '<h3>Что вас ждёт</h3><p>' . htmlspecialchars($what, ENT_QUOTES, 'UTF-8') . '.</p>'
        . '<h3>Кому подойдёт</h3><ul>' . $li . '</ul>'
        . '<h3>Преимущества участия</h3><ul>'
        . '<li>Участие <strong>полностью бесплатное</strong> — проходите тест в удобное время</li>'
        . '<li>Диплом I, II или III степени для портфолио при аттестации — 229 руб.</li>'
        . '<li>' . htmlspecialchars($adv1, ENT_QUOTES, 'UTF-8') . '</li>'
        . '<li>' . htmlspecialchars($adv2, ENT_QUOTES, 'UTF-8') . '</li>'
        . '</ul>';
}

function validateAiOlympiad(array $o): ?string {
    foreach (['title', 'intro', 'what', 'audience', 'adv1', 'adv2', 'description', 'questions'] as $field) {
        if (!isset($o[$field])) return "missing field: {$field}";
    }
    if (!is_array($o['audience']) || count($o['audience']) < 2) return "audience must have >=2 items";
    if (!is_array($o['questions']) || count($o['questions']) !== QUESTIONS_PER_OLYMPIAD) {
        return "questions count != " . QUESTIONS_PER_OLYMPIAD;
    }
    foreach ($o['questions'] as $i => $q) {
        if (!isset($q['text']) || trim((string)$q['text']) === '') return "question #{$i} empty text";
        if (!isset($q['options']) || !is_array($q['options']) || count($q['options']) !== 4) return "question #{$i} must have 4 options";
        foreach ($q['options'] as $opt) {
            if (trim((string)$opt) === '') return "question #{$i} has empty option";
        }
        $ci = (int)($q['correct'] ?? -1);
        if ($ci < 0 || $ci > 3) return "question #{$i} correct index out of range: {$ci}";
    }
    if (trim((string)$o['title']) === '' || mb_strlen($o['title']) < 8) return "title too short/empty";
    return null;
}

function buildPrompt(array $item): array {
    $gradeLabel = $item['grade_label'];
    $direction = $item['direction'];

    $system = <<<SYS
Ты — методист-эксперт, составляющий задания для всероссийских олимпиад на образовательном портале fgos.pro.
Нужна ОДНА олимпиада по теме «{$direction}» специально для {$gradeLabel}а (для педагогов/учителей, работающих с этим классом).

КРИТИЧЕСКИ ВАЖНО: title и вопросы должны быть СПЕЦИФИЧНЫ ИМЕННО для {$gradeLabel}а — учитывай возрастные особенности
и программный материал именно этого класса (для младших классов — простые темы и базовые понятия, для старших —
более сложный материал). Название темы в title должно отражать конкретику уровня, а не быть общим ярлыком темы.

Нужно:
- title: короткое название олимпиады БЕЗ слов "Олимпиада"/"Всероссийская олимпиада" в начале — просто название темы
- intro: 1 предложение — что это за тестирование
- what: 1 абзац — что проверяют вопросы
- audience: массив из 3-4 строк — кому подойдёт
- adv1, adv2: 2 конкретных преимущества участия
- description: 1 короткое предложение meta-описания
- questions: ровно 10 вопросов теста, у каждого текст вопроса, ровно 4 варианта ответа, "correct" — индекс правильного варианта (0-3)

Вопросы — содержательные, специфичные для {$gradeLabel}а и темы, разного уровня сложности, с правдоподобными
неправильными вариантами. Пиши на русском языке, профессиональным тоном.

Ответ — СТРОГО валидный JSON объект по схеме:
{"title": "...", "intro": "...", "what": "...", "audience": ["...", "..."], "adv1": "...", "adv2": "...", "description": "...", "questions": [{"text": "...", "options": ["...", "...", "...", "..."], "correct": 0}, ...10 штук]}
Никакого текста вне JSON, никаких markdown-блоков.
SYS;

    $user = "Сгенерируй олимпиаду по теме «{$direction}» специально для {$gradeLabel}а.";

    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $user],
    ];
}

$total = count($items);
$done = 0;
$skipped = 0;
$failedNow = 0;
$tokensIn = 0;
$tokensOut = 0;

foreach ($items as $item) {
    $key = 'migrate:' . $item['olympiad_id'];
    if (in_array($key, $manifest['done'], true)) {
        $skipped++;
        continue;
    }

    echo "[" . ($done + $skipped + $failedNow + 1) . "/{$total}] olympiad #{$item['olympiad_id']} -> {$item['grade_label']} ({$item['direction']}) ... ";

    try {
        $messages = buildPrompt($item);
        $result = $ai->generateJson($messages, ['max_tokens' => 3500]);
        $tokensIn += $result['tokens_in'] ?? 0;
        $tokensOut += $result['tokens_out'] ?? 0;

        $o = $result['data'] ?? null;
        if (!is_array($o)) {
            throw new RuntimeException('AI response is not an object');
        }
        $err = validateAiOlympiad($o);
        if ($err !== null) {
            throw new RuntimeException("invalid: {$err}");
        }

        $title = trim($o['title']);
        $slug = $olympiadObj->generateSlug($title);
        if ($slug === '') $slug = $olympiadObj->generateSlug($item['direction'] . ' ' . $item['grade_label']);

        $baseSlug = $slug;
        $suffix = 2;
        while (true) {
            $existing = $olympiadObj->getBySlug($slug);
            if (!$existing || (int)$existing['id'] === $item['olympiad_id']) break;
            $slug = $baseSlug . '-' . $suffix;
            $suffix++;
            if ($suffix > 50) throw new RuntimeException('slug collision unresolved');
        }

        $seoContent = buildSeoContent($title, $o['intro'], $o['what'], $o['audience'], $o['adv1'], $o['adv2']);
        $gradeField = $item['target_audience'] === 'students' ? (string)$item['grade'] : null;

        $pdo->beginTransaction();

        $upd = $pdo->prepare(
            "UPDATE olympiads SET title=?, slug=?, description=?, seo_content=?, grade=? WHERE id=?"
        );
        $upd->execute([$title, $slug, $o['description'], $seoContent, $gradeField, $item['olympiad_id']]);

        $pdo->prepare("DELETE FROM olympiad_questions WHERE olympiad_id = ?")->execute([$item['olympiad_id']]);
        $qStmt = $pdo->prepare(
            "INSERT INTO olympiad_questions (olympiad_id, question_text, options, correct_option_index, display_order) VALUES (?, ?, ?, ?, ?)"
        );
        foreach ($o['questions'] as $i => $q) {
            $qStmt->execute([
                $item['olympiad_id'], trim($q['text']),
                json_encode(array_values($q['options']), JSON_UNESCAPED_UNICODE),
                (int)$q['correct'], $i + 1,
            ]);
        }

        $olympiadObj->setAudienceTypes($item['olympiad_id'], [$item['new_audience_type_id']]);
        $olympiadObj->setAudienceCategories($item['olympiad_id'], [$item['category_id']]);
        if ($item['new_specialization_id']) {
            $olympiadObj->setSpecializations($item['olympiad_id'], [$item['new_specialization_id']]);
        } else {
            $pdo->prepare("DELETE FROM olympiad_specializations WHERE olympiad_id = ?")->execute([$item['olympiad_id']]);
        }

        $pdo->commit();

        $manifest['done'][] = $key;
        saveManifest($manifestPath, $manifest);
        $done++;
        echo "OK (slug: {$slug})\n";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $manifest['failed'][] = ['key' => $key, 'error' => $e->getMessage(), 'at' => date('c')];
        saveManifest($manifestPath, $manifest);
        $failedNow++;
        echo "FAIL: " . $e->getMessage() . "\n";
    }
}

echo "\n=== Итог ===\n";
echo "Успешно: {$done}, пропущено (уже сделано): {$skipped}, ошибок: {$failedNow}\n";
echo "Токены: in={$tokensIn}, out={$tokensOut}\n";
