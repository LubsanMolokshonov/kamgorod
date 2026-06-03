<?php
/**
 * Раздача файла материала — /material-download.php?id={N}
 *
 * Логика доступа:
 *   - Автор материала (user_id совпадает) — всегда бесплатно (он уже заплатил при генерации).
 *   - status='published', token_cost=0 — всем бесплатно.
 *   - status='published', token_cost>0 — списываем токены (один раз на пользователя
 *     — повторное скачивание автоматически бесплатно, проверяем по token_transactions).
 *   - Прочие статусы (draft/review/rejected/archived) — только автору и админу.
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Material.php';
require_once __DIR__ . '/../classes/UserTokens.php';

$materialId = (int)($_GET['id'] ?? 0);
if ($materialId <= 0) {
    http_response_code(400);
    echo 'Не указан id материала';
    exit;
}

$materialObj = new Material($db);
$material = $materialObj->getById($materialId);

// PDF формируется на лету из content (renderPageStyle), поэтому достаточно content или файла.
if (!$material || (empty($material['content']) && empty($material['file_path']))) {
    http_response_code(404);
    echo 'Материал не найден';
    exit;
}

// Сгенерированное превью без оплаты скачать нельзя — только через разблокировку (ajax/unlock-material.php).
if ((int)$material['is_generated'] === 1 && (int)$material['is_unlocked'] === 0) {
    http_response_code(403);
    echo 'Материал не разблокирован. <a href="/material/' . htmlspecialchars(rawurlencode((string)$material['slug']), ENT_QUOTES, 'UTF-8') . '/">Открыть страницу материала →</a>';
    exit;
}

$userId = $_SESSION['user_id'] ?? null;
$isAuthor = $userId && (int)$material['user_id'] === (int)$userId;
$isPublished = $material['status'] === 'published';

if (!$isAuthor && !$isPublished) {
    http_response_code(403);
    echo 'Материал недоступен';
    exit;
}

$cost = (int)$material['token_cost'];

// Списание токенов, если требуется
if (!$isAuthor && $cost > 0) {
    if (!$userId) {
        header('Location: /login?return=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
    $tokens = new UserTokens($db);

    // Если этот пользователь уже скачивал ЭТОТ материал — не списываем повторно
    $alreadyPaid = (new Database($db))->queryOne(
        "SELECT id FROM token_transactions
          WHERE user_id = ? AND reason = 'download' AND material_id = ?
          LIMIT 1",
        [$userId, $materialId]
    );

    if (!$alreadyPaid) {
        try {
            $tokens->charge((int)$userId, $cost, 'download', ['material_id' => $materialId]);
        } catch (NotEnoughTokensException $e) {
            http_response_code(402);
            echo 'Недостаточно токенов. <a href="/material-balance/">Пополнить →</a>';
            exit;
        }
    }
}

// PDF формируется на лету из content (см. renderPageStyle ниже), отдельный файл на диске
// не требуется — поэтому наличие file_path не проверяем. Для отдачи нужен content.
if (empty($material['content'])) {
    http_response_code(410);
    echo 'Материал пуст или недоступен';
    exit;
}

$safeName = preg_replace('/[^\w\-.]+/u', '_', (string)$material['title']);
$safeName = mb_substr($safeName, 0, 100);

// Формат скачивания определяется типом материала (output_format):
//   - презентация → .pptx, техкарта/конспект/тест/КТП/классный час → .docx, рабочий лист → .pdf.
// DOCX/PPTX рендерим из сырого JSON ответа ИИ (ai_output_json). Если его нет
// (старые материалы) — деградируем до PDF из content.
$format = strtolower((string)($material['type_format'] ?? $material['file_format'] ?? 'pdf'));
$aiOutput = !empty($material['ai_output_json'])
    ? json_decode((string)$material['ai_output_json'], true)
    : null;
if (!empty($material['ai_output_json']) && $aiOutput === null) {
    error_log('material-download: invalid ai_output_json for material=' . $materialId);
}

if (in_array($format, ['docx', 'pptx'], true) && is_array($aiOutput) && !empty($aiOutput)) {
    require_once __DIR__ . '/../classes/renderers/DocxRenderer.php';
    require_once __DIR__ . '/../classes/renderers/PptxRenderer.php';
    // Рендерим во ВРЕМЕННУЮ директорию: файл собирается на лету из ai_output_json,
    // отдаётся и удаляется. Постоянное хранилище uploads/ не засоряем.
    $tmpDir = sys_get_temp_dir() . '/mat-dl';
    try {
        $renderer = $format === 'pptx' ? new PptxRenderer($tmpDir) : new DocxRenderer($tmpDir);
        $result = $renderer->render($aiOutput, (string)$material['title'], (string)$material['slug']);
        $absPath = $result['file_abs'] ?? (dirname(__DIR__) . '/' . ltrim($result['file_path'], '/'));
        if (!is_file($absPath)) {
            throw new RuntimeException('Файл не создан: ' . $absPath);
        }
        $mime = $format === 'pptx'
            ? 'application/vnd.openxmlformats-officedocument.presentationml.presentation'
            : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        $materialObj->incrementDownloads($materialId);
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $safeName . '.' . $format . '"');
        header('Content-Length: ' . (filesize($absPath) ?: 0));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($absPath);
        @unlink($absPath); // временный файл — отдали и удалили
        exit;
    } catch (Throwable $e) {
        error_log('material-download: ' . $format . ' render failed for material=' . $materialId . ', fallback to PDF: ' . $e->getMessage());
        // деградация до PDF ниже
    }
}

// PDF — свёрстанный 1-в-1 со страницей материала (обложка + оформление),
// генерируется на лету из актуального content. Используется для рабочего листа
// и как fallback, если DOCX/PPTX не удалось собрать.

// Метки соответствия программам — те же, что и на детальной странице
$programLabels = [
    'fop_do'    => 'ФОП ДО',
    'fop_noo'   => 'ФОП НОО',
    'fop_ooo'   => 'ФОП ООО',
    'fop_soo'   => 'ФОП СОО',
    'faop_ovz'  => 'ФАОП (ОВЗ)',
    'fgos_2021' => 'ФГОС 2021',
    'fgos_2026' => 'ФГОС 2026',
];
$programs = [];
if (!empty($material['program_compliance'])) {
    foreach (explode(',', $material['program_compliance']) as $code) {
        $code = trim($code);
        if (isset($programLabels[$code])) {
            $programs[] = $programLabels[$code];
        }
    }
}

$previewAbsPath = '';
if (!empty($material['preview_image_url'])) {
    $candidate = dirname(__DIR__) . '/' . ltrim((string)$material['preview_image_url'], '/');
    if (is_file($candidate)) {
        $previewAbsPath = $candidate;
    }
}

require_once __DIR__ . '/../classes/renderers/PdfRenderer.php';
require_once __DIR__ . '/../classes/renderers/MaterialHtmlRenderer.php';

// Скачиваемый файл — версия для учителя: добавляем «Ключи для учителя», даже если
// на странице (бланк ученика) они скрыты. Для этого пересобираем content из ai_output.
if (is_array($aiOutput) && !empty($aiOutput)) {
    if (($material['type_slug'] ?? '') === 'rabochiy-list') {
        // Рабочий лист печатаем бланком: явное деление «для учителя / для ученика»,
        // часть ученика — с новой страницы и с местом для ФИО.
        $material['content'] = (new MaterialHtmlRenderer())->renderWorksheet($aiOutput);
    } elseif (!empty($aiOutput['answer_key']) || !empty($aiOutput['questions'])) {
        $material['content'] = (new MaterialHtmlRenderer())->render($aiOutput, true);
    }
}

try {
    $pdfBytes = (new PdfRenderer())->renderPageStyle($material, $programs, $previewAbsPath);
} catch (Throwable $e) {
    error_log('material-download: PDF render failed for material=' . $materialId . ': ' . $e->getMessage());
    http_response_code(500);
    echo 'Не удалось сформировать PDF. Попробуйте позже.';
    exit;
}

$downloadName = $safeName . '.pdf';

$materialObj->incrementDownloads($materialId);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . strlen($pdfBytes));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

echo $pdfBytes;
exit;
