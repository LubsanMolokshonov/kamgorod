<?php
// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

/**
 * Генератор олимпиад для категорийных страниц «класс × направление» в блоке «Педагогам»
 * и «класс × предмет» в блоке «Школьникам». По 3 олимпиады на комбинацию, контент — через AI
 * (OpenRouterAIService, модель OPENROUTER_MODEL_STRUCTURED).
 *
 * Флаги:
 *   --category=pedagogi|shkolnikam   (обязателен)
 *   --stage=nachalka|srednyaya       (только pedagogi: ограничить ступенью)
 *   --role-only                      (только pedagogi: только роли)
 *   --subject-only                   (только pedagogi: только предметы)
 *   --spec=<slug>                    (только эта специализация — для пилота)
 *   --dry-run                        (посчитать матрицу, без AI-вызовов и записи в БД)
 *
 * Запуск: docker exec pedagogy_web php /var/www/html/database/generate_grade_olympiads.php --spec=logopediya
 */
set_time_limit(0);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Olympiad.php';
require_once __DIR__ . '/../classes/YandexGPTJsonService.php';
$db->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

// ---- CLI args ----
$opts = getopt('', ['category:', 'stage::', 'role-only', 'subject-only', 'spec::', 'dry-run']);
$optCategory = $opts['category'] ?? null;
$optStage    = $opts['stage'] ?? null;
$optRoleOnly = array_key_exists('role-only', $opts);
$optSubjOnly = array_key_exists('subject-only', $opts);
$optSpec     = $opts['spec'] ?? null;
$dryRun      = array_key_exists('dry-run', $opts);

if (!in_array($optCategory, ['pedagogi', 'shkolnikam'], true)) {
    fwrite(STDERR, "Использование: --category=pedagogi|shkolnikam [--stage=nachalka|srednyaya] [--role-only|--subject-only] [--spec=slug] [--dry-run]\n");
    exit(1);
}

const CATEGORY_ID_PEDAGOGI  = 1;
const CATEGORY_ID_SHKOLNIKAM = 3;
const DIPLOMA_PRICE = 229.00;
const ACADEMIC_YEAR = '2025-2026';
const OLYMPIADS_PER_COMBO = 3;
const QUESTIONS_PER_OLYMPIAD = 10;

$manifestPath = __DIR__ . '/progress_grade_olympiads.json';
$manifest = file_exists($manifestPath) ? json_decode(file_get_contents($manifestPath), true) : ['done' => [], 'failed' => []];
if (!isset($manifest['done'])) $manifest['done'] = [];
if (!isset($manifest['failed'])) $manifest['failed'] = [];

function saveManifest($path, $manifest) {
    file_put_contents($path, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

$pdo = $db;
$dbw = new Database($pdo);
$olympiadObj = new Olympiad($pdo);

// ---- Построение матрицы генерации ----
$matrix = [];

if ($optCategory === 'pedagogi') {
    $stmt = $pdo->prepare("SELECT id, slug, name FROM audience_types WHERE category_id = ? AND slug LIKE 'pedagogam-%-klass' ORDER BY display_order");
    $stmt->execute([CATEGORY_ID_PEDAGOGI]);
    $gradeTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($gradeTypes as $gt) {
        if (!preg_match('/pedagogam-(\d+)-klass/', $gt['slug'], $m)) continue;
        $grade = (int)$m[1];
        $stage = $grade <= 4 ? 'nachalka' : 'srednyaya';
        if ($optStage && $optStage !== $stage) continue;

        $specStmt = $pdo->prepare("SELECT id, slug, name, specialization_type, name_dative FROM audience_specializations WHERE audience_type_id = ? ORDER BY display_order");
        $specStmt->execute([$gt['id']]);
        $specs = $specStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($specs as $spec) {
            if ($optSpec && $spec['slug'] !== $optSpec) continue;
            if ($optRoleOnly && $spec['specialization_type'] !== 'role') continue;
            if ($optSubjOnly && $spec['specialization_type'] !== 'subject') continue;

            $matrix[] = [
                'category'         => 'pedagogi',
                'grade'            => $grade,
                'grade_label'      => $gt['name'],
                'audience_type_id' => (int)$gt['id'],
                'audience_type_slug' => $gt['slug'],
                'specialization_id'  => (int)$spec['id'],
                'specialization_slug'=> $spec['slug'],
                'specialization_name'=> $spec['name'],
                'specialization_type'=> $spec['specialization_type'],
                'target_audience'  => 'pedagogues_school',
                'category_id'      => CATEGORY_ID_PEDAGOGI,
            ];
        }
    }
} else { // shkolnikam
    $stmt = $pdo->prepare("SELECT id, slug, name FROM audience_types WHERE category_id = ? AND slug REGEXP '^[0-9]+-klass$' ORDER BY display_order");
    $stmt->execute([CATEGORY_ID_SHKOLNIKAM]);
    $gradeTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 6 предметов из существующих students-олимпиад: matematika, russkiy-yazyk, okruzhayushchiy-mir,
    // istoriya, estestvennye-nauki, obshchestvoznanie — задаются явным списком (для "Школьникам"
    // audience_specializations по этим типам исторически не заведены, фильтр там работает через
    // legacy subject text, не specialization_id — поэтому specialization_id здесь не используется).
    $studentSubjects = [
        'Русский язык', 'Математика', 'Окружающий мир',
        'История', 'Обществознание', 'Естественные науки',
    ];

    foreach ($gradeTypes as $gt) {
        if (!preg_match('/^(\d+)-klass$/', $gt['slug'], $m)) continue;
        $grade = (int)$m[1];

        foreach ($studentSubjects as $subject) {
            if ($optSpec && $optSpec !== $subject) continue;
            $matrix[] = [
                'category'         => 'shkolnikam',
                'grade'            => $grade,
                'grade_label'      => $gt['name'],
                'audience_type_id' => (int)$gt['id'],
                'audience_type_slug' => $gt['slug'],
                'specialization_id'  => null,
                'specialization_slug'=> $olympiadObj->generateSlug($subject),
                'specialization_name'=> $subject,
                'specialization_type'=> 'subject',
                'target_audience'  => 'students',
                'category_id'      => CATEGORY_ID_SHKOLNIKAM,
            ];
        }
    }
}

echo "Матрица: " . count($matrix) . " комбинаций (" . (count($matrix) * OLYMPIADS_PER_COMBO) . " олимпиад)\n";

if ($dryRun) {
    foreach ($matrix as $m) {
        echo "  {$m['category']} | {$m['grade_label']} | {$m['specialization_name']} ({$m['specialization_type']})\n";
    }
    exit(0);
}

$ai = new YandexGPTJsonService();

/**
 * Собирает HTML seo_content по шаблону старых сидеров, из текстовых кусков AI-ответа.
 */
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
        return "questions count != " . QUESTIONS_PER_OLYMPIAD . " (got " . (is_array($o['questions']) ? count($o['questions']) : 'non-array') . ")";
    }
    foreach ($o['questions'] as $i => $q) {
        if (!isset($q['text']) || trim((string)$q['text']) === '') return "question #{$i} empty text";
        if (!isset($q['options']) || !is_array($q['options']) || count($q['options']) !== 4) return "question #{$i} must have 4 options";
        foreach ($q['options'] as $opt) {
            if (trim((string)$opt) === '') return "question #{$i} has empty option";
        }
        if (!isset($q['correct']) || !is_int($q['correct']) && !ctype_digit((string)$q['correct'])) return "question #{$i} missing correct index";
        $ci = (int)$q['correct'];
        if ($ci < 0 || $ci > 3) return "question #{$i} correct index out of range: {$ci}";
    }
    if (trim((string)$o['title']) === '' || mb_strlen($o['title']) < 8) return "title too short/empty";
    return null;
}

function buildPrompt(array $combo): array {
    $gradeLabel = $combo['grade_label'];
    $direction = $combo['specialization_name'];
    $isRole = $combo['specialization_type'] === 'role';
    $audienceHint = $isRole
        ? "педагогов в роли «{$direction}», работающих с учениками {$gradeLabel}а"
        : "учителей предмета «{$direction}» для {$gradeLabel}а";

    $system = <<<SYS
Ты — методист-эксперт, составляющий задания для всероссийских олимпиад на образовательном портале fgos.pro.
Для педагогов ({$audienceHint}) нужно подготовить ровно 3 РАЗНЫЕ олимпиады по теме «{$direction}» применительно к {$gradeLabel}у.
Каждая олимпиада должна раскрывать свой отдельный аспект темы (например: диагностика/методика/практика, или разные разделы предмета) —
олимпиады не должны дублировать друг друга по содержанию и вопросам.

КРИТИЧЕСКИ ВАЖНО: title и вопросы должны быть СПЕЦИФИЧНЫ ИМЕННО для {$gradeLabel}а — не используй общие формулировки,
которые одинаково подошли бы любому классу. Явно учитывай возрастные особенности и программный материал именно
{$gradeLabel}а (например для начальных классов — простые темы и базовые понятия, для старших классов — более сложный,
специализированный материал). Название темы в title должно отражать конкретику уровня, а не быть общим ярлыком темы.

Для каждой олимпиады нужно:
- title: короткое название олимпиады БЕЗ слов "Олимпиада"/"Всероссийская олимпиада" в начале (эти слова добавляются
  отдельно в вёрстке) — просто название темы, например "Диагностика дислалии у первоклассников" (не "Олимпиада: ...")
- intro: 1 предложение — что это за тестирование (для вставки после названия)
- what: 1 абзац — что проверяют вопросы (перечисление тем через запятую)
- audience: массив из 3-4 строк — кому подойдёт (конкретные должности/роли)
- adv1, adv2: 2 конкретных преимущества участия (не общие фразы про диплом — это уже есть отдельно)
- description: 1 короткое предложение meta-описания
- questions: ровно 10 вопросов теста, у каждого текст вопроса, ровно 4 варианта ответа, "correct" — индекс правильного варианта (0-3)

Вопросы должны быть содержательными, специфичными для {$gradeLabel}а и темы «{$direction}» (не общими/шаблонными),
разного уровня сложности внутри теста, с правдоподобными неправильными вариантами (не абсурдными).
Пиши на русском языке, профессиональным тоном для педагогической аудитории.

Ответ — СТРОГО валидный JSON объект по схеме:
{"olympiads": [{"title": "...", "intro": "...", "what": "...", "audience": ["...", "..."], "adv1": "...", "adv2": "...", "description": "...", "questions": [{"text": "...", "options": ["...", "...", "...", "..."], "correct": 0}, ...10 штук]}, ...3 штуки]}
Никакого текста вне JSON, никаких markdown-блоков.
SYS;

    $user = "Сгенерируй 3 РАЗНЫЕ олимпиады по теме «{$direction}» специально для {$gradeLabel}а ({$audienceHint}). "
        . "Title и вопросы должны явно отражать специфику именно {$gradeLabel}а, не быть общей формулировкой темы, "
        . "которая подошла бы любому классу.";

    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $user],
    ];
}

function insertOlympiad($pdo, $dbw, Olympiad $olympiadObj, array $combo, array $aiOlympiad, int &$displayOrder): ?int {
    $title = trim($aiOlympiad['title']);
    $slug = $olympiadObj->generateSlug($title);
    if ($slug === '') $slug = $olympiadObj->generateSlug($combo['specialization_name'] . ' ' . $combo['grade_label']);

    // Уникализация slug при коллизии
    $baseSlug = $slug;
    $suffix = 2;
    while (true) {
        $exists = $olympiadObj->getBySlug($slug);
        if (!$exists) break;
        $slug = $baseSlug . '-' . $suffix;
        $suffix++;
        if ($suffix > 50) return null; // защита от бесконечного цикла
    }

    $seoContent = buildSeoContent(
        $title, $aiOlympiad['intro'], $aiOlympiad['what'],
        $aiOlympiad['audience'], $aiOlympiad['adv1'], $aiOlympiad['adv2']
    );

    $stmt = $pdo->prepare(
        "INSERT INTO olympiads (title, slug, description, seo_content, target_audience, subject, grade, diploma_price, academic_year, is_active, display_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)"
    );
    $gradeField = $combo['category'] === 'shkolnikam' ? (string)$combo['grade'] : null;
    $stmt->execute([
        $title, $slug, $aiOlympiad['description'], $seoContent,
        $combo['target_audience'], $combo['specialization_name'], $gradeField,
        DIPLOMA_PRICE, ACADEMIC_YEAR, $displayOrder,
    ]);
    $olympiadId = (int)$pdo->lastInsertId();
    $displayOrder++;

    $qStmt = $pdo->prepare(
        "INSERT INTO olympiad_questions (olympiad_id, question_text, options, correct_option_index, display_order) VALUES (?, ?, ?, ?, ?)"
    );
    foreach ($aiOlympiad['questions'] as $i => $q) {
        $qStmt->execute([
            $olympiadId, trim($q['text']),
            json_encode(array_values($q['options']), JSON_UNESCAPED_UNICODE),
            (int)$q['correct'], $i + 1,
        ]);
    }

    $olympiadObj->setAudienceTypes($olympiadId, [$combo['audience_type_id']]);
    $olympiadObj->setAudienceCategories($olympiadId, [$combo['category_id']]);
    if (!empty($combo['specialization_id'])) {
        $olympiadObj->setSpecializations($olympiadId, [$combo['specialization_id']]);
    }

    return $olympiadId;
}

// ---- Основной цикл ----
$totalCombos = count($matrix);
$done = 0;
$skipped = 0;
$failedNow = 0;
$tokensIn = 0;
$tokensOut = 0;

foreach ($matrix as $combo) {
    $key = $combo['category'] . ':' . $combo['audience_type_slug'] . ':' . $combo['specialization_slug'];
    if (in_array($key, $manifest['done'], true)) {
        $skipped++;
        continue;
    }

    echo "[" . ($done + $skipped + $failedNow + 1) . "/{$totalCombos}] {$key} ... ";

    try {
        $messages = buildPrompt($combo);
        $result = $ai->generateJson($messages, ['max_tokens' => 7000]);
        $tokensIn += $result['tokens_in'] ?? 0;
        $tokensOut += $result['tokens_out'] ?? 0;

        $olympiadsData = $result['data']['olympiads'] ?? null;
        if (!is_array($olympiadsData) || count($olympiadsData) !== OLYMPIADS_PER_COMBO) {
            throw new RuntimeException('expected ' . OLYMPIADS_PER_COMBO . ' olympiads, got ' . (is_array($olympiadsData) ? count($olympiadsData) : 'non-array'));
        }

        foreach ($olympiadsData as $idx => $o) {
            $err = validateAiOlympiad($o);
            if ($err !== null) {
                throw new RuntimeException("olympiad #{$idx} invalid: {$err}");
            }
        }

        $pdo->beginTransaction();
        $displayOrder = (int)($pdo->query("SELECT MAX(display_order) m FROM olympiads")->fetch()['m'] ?? 0) + 1;
        $insertedIds = [];
        foreach ($olympiadsData as $o) {
            $id = insertOlympiad($pdo, $dbw, $olympiadObj, $combo, $o, $displayOrder);
            if ($id === null) {
                throw new RuntimeException('slug collision unresolved');
            }
            $insertedIds[] = $id;
        }
        $pdo->commit();

        $manifest['done'][] = $key;
        saveManifest($manifestPath, $manifest);
        $done++;
        echo "OK (ids: " . implode(',', $insertedIds) . ")\n";
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
