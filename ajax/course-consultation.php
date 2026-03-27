<?php
/**
 * AJAX: Course Consultation Request
 * Заявка на бесплатную консультацию по курсу
 */

header('Content-Type: application/json; charset=UTF-8');

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Validator.php';
require_once __DIR__ . '/../classes/Bitrix24Integration.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Метод не поддерживается');
    }

    $validator = new Validator($_POST);
    $validator->required(['phone'])
              ->phone('phone');

    if ($validator->fails()) {
        throw new Exception($validator->getFirstError());
    }

    $data = $validator->getData();
    $phone = $data['phone'];
    $courseId = !empty($_POST['course_id']) ? intval($_POST['course_id']) : null;
    $courseTitle = !empty($_POST['course_title']) ? mb_substr(trim($_POST['course_title']), 0, 500) : null;

    // UTM-метки, Яндекс.Метрика, страница-источник
    $utmSource = trim($_POST['utm_source'] ?? '');
    $utmMedium = trim($_POST['utm_medium'] ?? '');
    $utmCampaign = trim($_POST['utm_campaign'] ?? '');
    $utmContent = trim($_POST['utm_content'] ?? '');
    $utmTerm = trim($_POST['utm_term'] ?? '');
    $ymUid = trim($_POST['ym_uid'] ?? '');
    $sourcePage = trim($_POST['source_page'] ?? '');

    $dbObj = new Database($db);

    // Сохраняем заявку
    $consultationData = [
        'course_id' => $courseId,
        'course_title' => $courseTitle,
        'phone' => $phone,
        'status' => 'new'
    ];
    if ($utmSource) $consultationData['utm_source'] = $utmSource;
    if ($utmMedium) $consultationData['utm_medium'] = $utmMedium;
    if ($utmCampaign) $consultationData['utm_campaign'] = $utmCampaign;
    if ($utmContent) $consultationData['utm_content'] = $utmContent;
    if ($utmTerm) $consultationData['utm_term'] = $utmTerm;
    if ($ymUid) $consultationData['ym_uid'] = $ymUid;
    if ($sourcePage) $consultationData['source_page'] = $sourcePage;

    $consultationId = $dbObj->insert('course_consultations', $consultationData);

    // Bitrix24 + email — в фоновом процессе, не блокирует ответ
    $scriptPath = __DIR__ . '/../scripts/process-course-consultation.php';
    exec('php ' . escapeshellarg($scriptPath) . ' ' . intval($consultationId) . ' > /dev/null 2>&1 &');

    echo json_encode([
        'success' => true,
        'message' => 'Заявка отправлена! Мы перезвоним вам в ближайшее время.'
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
