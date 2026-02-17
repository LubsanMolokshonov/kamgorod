<?php
/**
 * Тестовый скрипт: отправка примера письма об успешной оплате с PDF-вложениями
 *
 * Генерирует тестовый PDF-сертификат и отправляет письмо-пример
 * на указанный email, демонстрируя новый шаблон.
 *
 * Запуск:
 *   php scripts/send_test_purchase_email.php                        # отправка на lubsanmolokshonov@gmail.com
 *   php scripts/send_test_purchase_email.php test@example.com       # отправка на другой email
 *   php scripts/send_test_purchase_email.php --dry-run              # только генерация HTML (без отправки)
 */

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/includes/magic-link-helper.php';
require_once BASE_PATH . '/includes/email-helper.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Mpdf\Mpdf;

// Parse arguments
$dryRun = in_array('--dry-run', $argv ?? []);
$targetEmail = 'lubsanmolokshonov@gmail.com';

foreach (($argv ?? []) as $i => $arg) {
    if ($i === 0) continue;
    if ($arg === '--dry-run') continue;
    if (filter_var($arg, FILTER_VALIDATE_EMAIL)) {
        $targetEmail = $arg;
    }
}

echo "=== Тестовая отправка письма об оплате с PDF ===\n";
echo "Email: {$targetEmail}\n";
echo "Режим: " . ($dryRun ? 'DRY RUN (без отправки)' : 'ОТПРАВКА') . "\n\n";

// --- 1. Generate a test PDF certificate ---
echo "Генерация тестового PDF-сертификата...\n";
$testPdfPath = BASE_PATH . '/uploads/test_certificate_example.pdf';

// Ensure directory exists
$dir = dirname($testPdfPath);
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

$mpdf = new Mpdf([
    'mode' => 'utf-8',
    'format' => 'A4',
    'margin_left' => 20,
    'margin_right' => 20,
    'margin_top' => 30,
    'margin_bottom' => 20,
    'default_font' => 'dejavusans',
    'autoScriptToLang' => true,
    'autoLangToFont' => true,
    'tempDir' => '/tmp/mpdf'
]);

$mpdf->WriteHTML('
<style>
    body { font-family: DejaVuSans; text-align: center; color: #333; }
    .title { font-size: 32pt; color: #0065B1; font-weight: bold; margin-top: 80mm; }
    .subtitle { font-size: 16pt; color: #555; margin-top: 10mm; }
    .name { font-size: 22pt; font-weight: bold; margin-top: 20mm; }
    .description { font-size: 12pt; color: #666; margin-top: 15mm; font-style: italic; }
    .info { font-size: 11pt; color: #333; margin-top: 25mm; }
    .footer { font-size: 9pt; color: #999; margin-top: 40mm; }
</style>
<div class="title">СЕРТИФИКАТ</div>
<div class="subtitle">УЧАСТНИКА ВЕБИНАРА</div>
<div class="name">Иванов Иван Иванович</div>
<div class="description">принял(а) участие в вебинаре</div>
<div class="info">&laquo;Современные подходы к организации учебного процесса&raquo;</div>
<div class="info" style="margin-top: 10mm;">Объём: 2 ак. часа</div>
<div class="info" style="margin-top: 5mm;">Сертификат № ВЕБ-2026-000001</div>
<div class="footer">Это тестовый сертификат для демонстрации отправки документов на почту.</div>
');

$mpdf->Output($testPdfPath, 'F');
echo "PDF создан: {$testPdfPath} (" . round(filesize($testPdfPath) / 1024) . " KB)\n\n";

// --- 2. Build mock order and user data ---
$mockOrder = [
    'order_number' => 'ORD-20260216-TEST01',
    'final_amount' => 447,
    'discount_amount' => 149,
    'paid_at' => date('Y-m-d H:i:s'),
    'created_at' => date('Y-m-d H:i:s'),
    'items' => [
        [
            'registration_id' => 1,
            'certificate_id' => null,
            'webinar_certificate_id' => null,
            'competition_title' => 'Лучший педагог 2026',
            'nomination' => 'Инновационные технологии',
            'publication_title' => null,
            'webinar_title' => null,
        ],
        [
            'registration_id' => null,
            'certificate_id' => 1,
            'webinar_certificate_id' => null,
            'competition_title' => null,
            'nomination' => null,
            'publication_title' => 'Методика проведения занятий в начальной школе',
            'webinar_title' => null,
        ],
        [
            'registration_id' => null,
            'certificate_id' => null,
            'webinar_certificate_id' => 1,
            'competition_title' => null,
            'nomination' => null,
            'publication_title' => null,
            'webinar_title' => 'Современные подходы к организации учебного процесса',
        ],
    ]
];

$mockUser = [
    'id' => 1,
    'email' => $targetEmail,
    'full_name' => 'Иванов Иван Иванович',
];

// Test attachments (the generated test PDF)
$mockAttachments = [
    [
        'path' => $testPdfPath,
        'name' => 'Диплом_Лучший педагог 2026.pdf',
        'type' => 'diploma',
        'title' => 'Лучший педагог 2026',
    ],
    [
        'path' => $testPdfPath,
        'name' => 'Свидетельство_Методика проведения занятий.pdf',
        'type' => 'certificate',
        'title' => 'Методика проведения занятий в начальной школе',
    ],
    [
        'path' => $testPdfPath,
        'name' => 'Сертификат_Современные подходы.pdf',
        'type' => 'webinar_certificate',
        'title' => 'Современные подходы к организации учебного процесса',
    ],
];

// --- 3. Build email body ---
echo "Сборка HTML-письма...\n";
$htmlBody = buildSuccessEmailBody($mockOrder, $mockUser, $mockAttachments);
$textBody = buildSuccessEmailBodyText($mockOrder, $mockUser, $mockAttachments);

if ($dryRun) {
    echo "\n=== HTML письма ===\n";
    echo $htmlBody;
    echo "\n\n=== Plain text ===\n";
    echo $textBody;
    echo "\n\nDry run завершен. Письмо не отправлено.\n";

    // Clean up test PDF
    if (file_exists($testPdfPath)) {
        unlink($testPdfPath);
    }
    exit(0);
}

// --- 4. Send email ---
echo "Отправка письма на {$targetEmail}...\n";

try {
    $mail = new PHPMailer(true);
    configureMailer($mail);

    // Use hostname instead of IP for SSL certificate matching
    $mail->Host = 'mail.fgos.pro';

    // Allow self-signed or mismatched SSL certificates
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ];

    $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
    $mail->addAddress($targetEmail, $mockUser['full_name']);

    $mail->isHTML(true);
    $mail->Subject = 'Ваши документы по заказу ' . $mockOrder['order_number'] . ' (ТЕСТ)';
    $mail->Body = $htmlBody;
    $mail->AltBody = $textBody;

    // Attach test PDFs
    foreach ($mockAttachments as $att) {
        $mail->addAttachment($att['path'], $att['name']);
    }

    $mail->send();

    echo "\n[OK] Письмо успешно отправлено на {$targetEmail}\n";
    echo "Вложений: " . count($mockAttachments) . "\n";

} catch (Exception $e) {
    echo "\n[ОШИБКА] Не удалось отправить: " . $e->getMessage() . "\n";

    // Show SMTP debug info
    echo "\nSMTP Config:\n";
    echo "  Host: " . SMTP_HOST . "\n";
    echo "  Port: " . SMTP_PORT . "\n";
    echo "  User: " . SMTP_USERNAME . "\n";
    echo "  From: " . SMTP_FROM_EMAIL . "\n";
}

// Clean up test PDF
if (file_exists($testPdfPath)) {
    unlink($testPdfPath);
    echo "\nТестовый PDF удалён.\n";
}

echo "\nГотово!\n";
