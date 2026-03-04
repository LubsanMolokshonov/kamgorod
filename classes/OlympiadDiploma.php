<?php
/**
 * OlympiadDiploma Class
 * Generates PDF diplomas for olympiad winners
 * Uses same templates as competition diplomas
 * Based on Diploma.php with olympiad-specific content
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Mpdf\Mpdf;

class OlympiadDiploma {
    private $db;
    private $uploadsDir;

    /**
     * Field positions - identical to Diploma.php
     */
    private $fieldPositions = [
        'diploma_title' => [
            'x' => 105, 'y' => 76, 'size' => 36,
            'font_weight' => 'bold', 'font_style' => 'normal',
            'align' => 'center', 'color' => '#0077FF', 'max_width' => 180
        ],
        'diploma_subtitle' => [
            'x' => 105, 'y' => 92, 'size' => 18,
            'font_weight' => 'bold', 'align' => 'center',
            'color' => '#000000', 'max_width' => 160
        ],
        'award_text' => [
            'x' => 105, 'y' => 108, 'size' => 12,
            'align' => 'center', 'color' => '#000000', 'max_width' => 170
        ],
        'fio' => [
            'x' => 105, 'y' => 123, 'size' => 22,
            'font_weight' => 'bold', 'align' => 'center',
            'color' => '#000000', 'max_width' => 180
        ],
        'achievement_text' => [
            'x' => 105, 'y' => 141, 'size' => 11,
            'font_style' => 'italic', 'align' => 'center',
            'color' => '#000000', 'max_width' => 170
        ],
        'competition_type' => [
            'x' => 105, 'y' => 153, 'size' => 14,
            'font_weight' => 'bold', 'align' => 'center',
            'color' => '#0077FF', 'max_width' => 170
        ],
        'competition_name' => [
            'x' => 105, 'y' => 164, 'size' => 12,
            'align' => 'center', 'color' => '#000000', 'max_width' => 170
        ],
        'placement_line' => [
            'x' => 105, 'y' => 175, 'size' => 12,
            'font_weight' => 'bold', 'align' => 'center',
            'color' => '#0077FF', 'max_width' => 170
        ],
        'organization' => [
            'x' => 105, 'y' => 190, 'size' => 10,
            'align' => 'center', 'color' => '#000000', 'max_width' => 170
        ],
        'city' => [
            'x' => 105, 'y' => 199, 'size' => 10,
            'align' => 'center', 'color' => '#000000', 'max_width' => 170
        ],
        'supervisor_name' => [
            'x' => 105, 'y' => 207, 'size' => 10,
            'align' => 'center', 'color' => '#000000', 'max_width' => 170
        ],
        'participation_date' => [
            'x' => 27, 'y' => 249, 'size' => 9,
            'align' => 'left', 'color' => '#000000', 'max_width' => 60
        ],
        'chairman_label' => [
            'x' => 141, 'y' => 233, 'size' => 9,
            'align' => 'center', 'color' => '#000000', 'max_width' => 50
        ],
        'chairman_name' => [
            'x' => 141, 'y' => 239, 'size' => 9,
            'font_weight' => 'bold', 'align' => 'center',
            'color' => '#000000', 'max_width' => 50
        ]
    ];

    const CHAIRMAN_STAMP_PATH = '/assets/images/diplomas/stamp-brehach.png';

    public function __construct($pdo) {
        $this->db = $pdo;
        $this->uploadsDir = __DIR__ . '/../uploads/diplomas/';

        if (!is_dir($this->uploadsDir)) {
            mkdir($this->uploadsDir, 0755, true);
        }
    }

    /**
     * Generate olympiad diploma PDF
     */
    public function generate($olympiadRegistrationId, $recipientType = 'participant') {
        try {
            $registration = $this->getRegistrationData($olympiadRegistrationId);

            if (!$registration) {
                return ['success' => false, 'message' => 'Регистрация не найдена'];
            }

            if ($registration['status'] !== 'paid' && $registration['status'] !== 'diploma_ready') {
                return ['success' => false, 'message' => 'Регистрация не оплачена'];
            }

            if ($recipientType === 'supervisor' && !$registration['has_supervisor']) {
                return ['success' => false, 'message' => 'Руководитель не указан'];
            }

            // Check existing diploma
            $existingDiploma = $this->findExistingDiploma($olympiadRegistrationId, $recipientType);
            if ($existingDiploma && file_exists($this->uploadsDir . $existingDiploma['pdf_path'])) {
                return [
                    'success' => true,
                    'pdf_path' => '/uploads/diplomas/' . $existingDiploma['pdf_path'],
                    'message' => 'Диплом уже существует'
                ];
            }

            $template = $this->getTemplate($registration['diploma_template_id']);
            if (!$template) {
                return ['success' => false, 'message' => 'Шаблон не найден'];
            }

            $pdfFilename = $this->generatePDF($registration, $template, $recipientType);

            if (!$pdfFilename) {
                return ['success' => false, 'message' => 'Ошибка генерации PDF'];
            }

            $this->saveDiplomaRecord($olympiadRegistrationId, $template['id'], $pdfFilename, $recipientType);
            $this->updateRegistrationStatus($olympiadRegistrationId, 'diploma_ready');

            return [
                'success' => true,
                'pdf_path' => '/uploads/diplomas/' . $pdfFilename,
                'message' => 'Диплом успешно создан'
            ];

        } catch (Exception $e) {
            error_log("Olympiad diploma generation error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()];
        }
    }

    /**
     * Get registration data with olympiad info
     */
    private function getRegistrationData($registrationId) {
        $this->db->exec("SET NAMES utf8mb4");

        $stmt = $this->db->prepare("
            SELECT
                r.*,
                o.title as olympiad_title,
                o.target_audience,
                o.subject,
                u.full_name as user_full_name,
                u.email as user_email,
                u.phone,
                u.city as user_city,
                u.organization as user_organization
            FROM olympiad_registrations r
            JOIN olympiads o ON r.olympiad_id = o.id
            JOIN users u ON r.user_id = u.id
            WHERE r.id = ?
        ");
        $stmt->execute([$registrationId]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($data) {
            foreach ($data as $key => $value) {
                if (is_string($value)) {
                    $data[$key] = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
                }
            }
        }

        return $data;
    }

    private function findExistingDiploma($registrationId, $recipientType) {
        $stmt = $this->db->prepare(
            "SELECT * FROM olympiad_diplomas WHERE olympiad_registration_id = ? AND recipient_type = ?"
        );
        $stmt->execute([$registrationId, $recipientType]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function getTemplate($templateId) {
        $stmt = $this->db->prepare("SELECT * FROM diploma_templates WHERE id = ?");
        $stmt->execute([$templateId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get diploma subtitle for olympiad
     */
    private function getDiplomaSubtitle($recipientType, $placement) {
        if ($recipientType === 'supervisor') {
            return 'РУКОВОДИТЕЛЯ';
        }
        if ($placement === '1') {
            return 'ПОБЕДИТЕЛЯ';
        }
        return 'ПРИЗЁРА';
    }

    /**
     * Get achievement text for olympiad
     */
    private function getAchievementText($recipientType, $placement) {
        if ($recipientType === 'supervisor') {
            return 'за подготовку участника олимпиады';
        }
        if ($placement === '1') {
            return 'за высокие достижения в олимпиаде';
        }
        return 'за достижения в олимпиаде';
    }

    /**
     * Get placement text
     */
    private function getPlacementText($placement) {
        $labels = [
            '1' => 'I место (Победитель)',
            '2' => 'II место (Призёр)',
            '3' => 'III место (Призёр)'
        ];
        return $labels[$placement] ?? '';
    }

    /**
     * Generate PDF
     */
    private function generatePDF($registration, $template, $recipientType) {
        $recipientName = $recipientType === 'supervisor'
            ? $registration['supervisor_name']
            : $registration['user_full_name'];

        $recipientOrganization = $recipientType === 'supervisor'
            ? ($registration['supervisor_organization'] ?? $registration['organization'])
            : $registration['organization'];

        $recipientCity = $registration['city'] ?: $registration['user_city'];

        $tempDir = '/tmp/mpdf';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 0,
            'margin_right' => 0,
            'margin_top' => 0,
            'margin_bottom' => 0,
            'default_font' => 'dejavusans',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'tempDir' => $tempDir
        ]);

        $templateId = $registration['diploma_template_id'] ?? 1;
        $templateImagePath = __DIR__ . '/../assets/images/diplomas/templates/backgrounds/template-' . $templateId . '.png';

        if (!file_exists($templateImagePath)) {
            $templateImagePath = __DIR__ . '/../assets/images/diplomas/templates/backgrounds/template-' . $templateId . '.svg';
        }

        if (!file_exists($templateImagePath)) {
            $templateImagePath = __DIR__ . '/../assets/images/diplomas/' . $template['template_image'];
        }

        $mpdf->SetDefaultBodyCSS('background', "url('$templateImagePath')");
        $mpdf->SetDefaultBodyCSS('background-image-resize', 6);

        $html = $this->buildDiplomaHTML($registration, $recipientName, $recipientOrganization, $recipientCity, $recipientType);
        $mpdf->WriteHTML($html);

        $filename = sprintf('olympiad_diploma_%d_%s_%d.pdf', $registration['id'], $recipientType, time());
        $mpdf->Output($this->uploadsDir . $filename, 'F');

        return $filename;
    }

    /**
     * Build HTML for diploma
     */
    private function buildDiplomaHTML($registration, $recipientName, $recipientOrganization, $recipientCity, $recipientType) {
        $participationDate = $registration['participation_date']
            ? date('d.m.Y', strtotime($registration['participation_date']))
            : date('d.m.Y');

        $placement = $registration['placement'] ?? '';
        $textFields = '';

        // 1. ДИПЛОМ
        $textFields .= $this->createTextField('ДИПЛОМ', $this->fieldPositions['diploma_title']);

        // 2. ПОБЕДИТЕЛЯ / ПРИЗЁРА / РУКОВОДИТЕЛЯ
        $subtitle = $this->getDiplomaSubtitle($recipientType, $placement);
        $textFields .= $this->createTextField($subtitle, $this->fieldPositions['diploma_subtitle']);

        // 3. награждается
        $textFields .= $this->createTextField('награждается', $this->fieldPositions['award_text']);

        // 4. ФИО
        if (!empty($recipientName)) {
            $textFields .= $this->createTextField($recipientName, $this->fieldPositions['fio']);
        }

        // 5. Achievement text
        $achievementText = $this->getAchievementText($recipientType, $placement);
        $textFields .= $this->createTextField($achievementText, $this->fieldPositions['achievement_text']);

        // 6. Тип олимпиады (ВСЕРОССИЙСКАЯ ОЛИМПИАДА)
        if (!empty($registration['competition_type'])) {
            $competitionType = mb_strtoupper($registration['competition_type'], 'UTF-8') . ' ОЛИМПИАДА';
            $textFields .= $this->createTextField($competitionType, $this->fieldPositions['competition_type']);
        }

        // 7. Название олимпиады
        if (!empty($registration['olympiad_title'])) {
            $olympiadNameQuoted = '«' . $registration['olympiad_title'] . '»';
            $textFields .= $this->createTextField($olympiadNameQuoted, $this->fieldPositions['competition_name']);
        }

        // 8. Место
        if (!empty($placement)) {
            $placementText = $this->getPlacementText($placement);
            $textFields .= $this->createTextField($placementText, $this->fieldPositions['placement_line']);
        }

        // 9. Организация
        if (!empty($recipientOrganization)) {
            $textFields .= $this->createTextField(
                'Учреждение: ' . $recipientOrganization,
                $this->fieldPositions['organization']
            );
        }

        // 10. Город
        if (!empty($recipientCity)) {
            $textFields .= $this->createTextField(
                'Населенный пункт: ' . $recipientCity,
                $this->fieldPositions['city']
            );
        }

        // 11. Руководитель
        if ($recipientType === 'participant' && !empty($registration['supervisor_name'])) {
            $textFields .= $this->createTextField(
                'Руководитель: ' . $registration['supervisor_name'],
                $this->fieldPositions['supervisor_name']
            );
        }

        // 12. Дата
        $textFields .= $this->createTextField($participationDate, $this->fieldPositions['participation_date']);

        // 13. Подпись председателя
        $chairmanBlock = $this->createChairmanSignatureBlock();

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <style>
        body { margin: 0; padding: 0; font-family: DejaVuSans; }
        .text-field { position: absolute; line-height: 1.2; }
    </style>
</head>
<body>
{$textFields}
{$chairmanBlock}
</body>
</html>
HTML;
    }

    private function createChairmanSignatureBlock() {
        $stampPath = __DIR__ . '/..' . self::CHAIRMAN_STAMP_PATH;
        $stampHtml = '';

        if (file_exists($stampPath)) {
            $stampHtml = sprintf(
                '<div style="position: absolute; left: 120mm; top: 225mm; width: 50mm; height: 50mm;"><img src="%s" style="width: 50mm; height: auto;" /></div>',
                $stampPath
            );
        }

        $labelHtml = $this->createTextField('Председатель Оргкомитета', $this->fieldPositions['chairman_label']);
        $nameHtml = $this->createTextField('Брехач Р.А.', $this->fieldPositions['chairman_name']);

        return $stampHtml . $labelHtml . $nameHtml;
    }

    private function createTextField($text, $position) {
        $x = $position['x'] ?? 0;
        $y = $position['y'] ?? 0;
        $fontSize = $position['size'] ?? 16;
        $fontWeight = $position['font_weight'] ?? 'normal';
        $fontStyle = $position['font_style'] ?? 'normal';
        $textAlign = $position['align'] ?? 'center';
        $color = $position['color'] ?? '#000000';
        $maxWidth = $position['max_width'] ?? 200;

        $escapedText = htmlspecialchars(html_entity_decode($text, ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8');
        $width = $maxWidth . 'mm';
        $leftPos = $textAlign === 'center' ? ($x - ($maxWidth / 2)) . 'mm' : $x . 'mm';

        return <<<HTML
<div class="text-field" style="left: {$leftPos}; top: {$y}mm; width: {$width}; font-size: {$fontSize}pt; font-weight: {$fontWeight}; font-style: {$fontStyle}; text-align: {$textAlign}; color: {$color};">{$escapedText}</div>
HTML;
    }

    private function saveDiplomaRecord($registrationId, $templateId, $pdfFilename, $recipientType) {
        $stmt = $this->db->prepare("
            INSERT INTO olympiad_diplomas (olympiad_registration_id, template_id, pdf_path, recipient_type, generated_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                pdf_path = VALUES(pdf_path),
                generated_at = NOW()
        ");
        $stmt->execute([$registrationId, $templateId, $pdfFilename, $recipientType]);
    }

    private function updateRegistrationStatus($registrationId, $status) {
        $stmt = $this->db->prepare("UPDATE olympiad_registrations SET status = ? WHERE id = ?");
        $stmt->execute([$status, $registrationId]);
    }

    public function incrementDownloadCount($diplomaId) {
        $stmt = $this->db->prepare("
            UPDATE olympiad_diplomas
            SET download_count = download_count + 1, last_downloaded_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$diplomaId]);
    }
}
