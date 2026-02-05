<?php
/**
 * Diploma Class
 * Generates PDF diplomas using mPDF with Cyrillic support
 *
 * SYNCHRONIZED with DiplomaPreview.php for identical output
 * All positions in mm (A4: 210x297mm)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Mpdf\Mpdf;

class Diploma {
    private $db;
    private $uploadsDir;

    /**
     * Field positions and styles - SYNCHRONIZED with DiplomaPreview.php
     * All sizes in pt (same for SVG and PDF)
     * Positions in mm for A4 format (210x297mm)
     *
     * Visual hierarchy:
     * 1. diploma_title - Main header "ДИПЛОМ"
     * 2. diploma_subtitle - "ПОБЕДИТЕЛЯ/УЧАСТНИКА/РУКОВОДИТЕЛЯ"
     * 3. award_text - "награждается"
     * 4. fio - Recipient name (MAIN FOCUS)
     * 5. achievement_text - "за участие/достижения/подготовку"
     * 6. competition_type - "ВСЕРОССИЙСКИЙ КОНКУРС"
     * 7. work_title_quoted - Work title in quotes (if exists)
     * 8. nomination_line - "в номинации «...»"
     * 9. organization - Institution name
     * 10. city - Location
     * 11. participation_date - Date (bottom left)
     * 12. supervisor_name - Supervisor name (bottom right, participant only)
     */
    private $fieldPositions = [
        // === HEADER SECTION ===
        'diploma_title' => [
            'x' => 105,             // Center of A4 (210/2)
            'y' => 76,              // 215px / 2.834
            'size' => 36,           // Larger for impact
            'font_weight' => 'bold',
            'font_style' => 'normal', // No italic
            'align' => 'center',
            'color' => '#0077FF',   // Blue #0077FF
            'max_width' => 180
        ],
        'diploma_subtitle' => [
            'x' => 105,
            'y' => 92,              // Adjusted for larger title
            'size' => 18,           // SYNCED with SVG
            'font_weight' => 'bold',
            'align' => 'center',
            'color' => '#000000',   // Black
            'max_width' => 160
        ],

        // === AWARD SECTION ===
        'award_text' => [
            'x' => 105,
            'y' => 108,             // 306px / 2.834
            'size' => 12,           // SYNCED with SVG
            'align' => 'center',
            'color' => '#000000',   // Black
            'max_width' => 170
        ],

        // === MAIN FOCUS: RECIPIENT NAME ===
        'fio' => [
            'x' => 105,
            'y' => 123,             // 349px / 2.834
            'size' => 22,           // Larger for emphasis
            'font_weight' => 'bold',
            'align' => 'center',
            'color' => '#000000',   // Black (matching example)
            'max_width' => 180
        ],

        // === ACHIEVEMENT DESCRIPTION ===
        'achievement_text' => [
            'x' => 105,
            'y' => 141,             // 400px / 2.834
            'size' => 11,           // SYNCED with SVG
            'font_style' => 'italic',
            'align' => 'center',
            'color' => '#000000',   // Black
            'max_width' => 170
        ],

        // === COMPETITION INFO ===
        'competition_type' => [
            'x' => 105,
            'y' => 153,             // 434px / 2.834
            'size' => 14,           // SYNCED with SVG
            'font_weight' => 'bold',
            'align' => 'center',
            'color' => '#0077FF',   // Blue #0077FF
            'max_width' => 170
        ],
        'competition_name' => [
            'x' => 105,
            'y' => 164,             // 465px / 2.834
            'size' => 12,           // SYNCED with SVG
            'align' => 'center',
            'color' => '#000000',   // Black
            'max_width' => 170
        ],

        // === WORK DETAILS ===
        'work_title_quoted' => [
            'x' => 105,
            'y' => 175,             // 496px / 2.834 (shifted from 164mm)
            'size' => 12,           // SYNCED with SVG
            'align' => 'center',
            'color' => '#000000',   // Black
            'max_width' => 170
        ],
        'nomination_line' => [
            'x' => 105,
            'y' => 184.5,           // 523px / 2.834 (shifted from 173.5mm)
            'size' => 12,           // SYNCED with SVG
            'font_style' => 'italic',
            'align' => 'center',
            'color' => '#000000',   // Black
            'max_width' => 170
        ],

        // === METADATA SECTION (centered, under nomination) ===
        'organization' => [
            'x' => 105,             // center (A4 width / 2)
            'y' => 196,             // 555px / 2.834
            'size' => 10,           // SYNCED with SVG
            'align' => 'center',
            'color' => '#000000',   // Black
            'max_width' => 170
        ],
        'city' => [
            'x' => 105,             // center
            'y' => 205,             // 580px / 2.834
            'size' => 10,           // SYNCED with SVG
            'align' => 'center',
            'color' => '#000000',   // Black
            'max_width' => 170
        ],
        'supervisor_name' => [
            'x' => 105,             // center
            'y' => 213,             // 605px / 2.834
            'size' => 10,           // SYNCED with SVG
            'align' => 'center',
            'color' => '#000000',   // Black
            'max_width' => 170
        ],

        // === FOOTER (date bottom left) ===
        'participation_date' => [
            'x' => 27,              // 77px / 2.834
            'y' => 249,             // 706px / 2.834
            'size' => 9,            // SYNCED with SVG
            'align' => 'left',
            'color' => '#000000',   // Black
            'max_width' => 60
        ],

        // === CHAIRMAN SIGNATURE BLOCK (bottom right) ===
        'chairman_label' => [
            'x' => 141,             // 400px / 2.834 (moved left)
            'y' => 233,             // 660px / 2.834 (moved up)
            'size' => 9,
            'align' => 'center',
            'color' => '#000000',   // Black
            'max_width' => 50
        ],
        'chairman_name' => [
            'x' => 141,
            'y' => 239,             // 678px / 2.834
            'size' => 9,
            'font_weight' => 'bold',
            'align' => 'center',
            'color' => '#000000',   // Black
            'max_width' => 50
        ]
    ];

    // Path to chairman stamp image
    const CHAIRMAN_STAMP_PATH = '/assets/images/diplomas/stamp-brehach.png';

    public function __construct($pdo) {
        $this->db = $pdo;
        $this->uploadsDir = __DIR__ . '/../uploads/diplomas/';

        // Create uploads directory if it doesn't exist
        if (!is_dir($this->uploadsDir)) {
            mkdir($this->uploadsDir, 0755, true);
        }
    }

    /**
     * Generate diploma PDF for a registration
     * @param int $registrationId Registration ID
     * @param string $recipientType 'participant' or 'supervisor'
     * @return array ['success' => bool, 'pdf_path' => string, 'message' => string]
     */
    public function generate($registrationId, $recipientType = 'participant') {
        try {
            // Get registration data with all related info
            $registration = $this->getRegistrationData($registrationId);

            if (!$registration) {
                return ['success' => false, 'message' => 'Регистрация не найдена'];
            }

            // Check if registration is paid
            if ($registration['status'] !== 'paid' && $registration['status'] !== 'diploma_ready') {
                return ['success' => false, 'message' => 'Регистрация не оплачена'];
            }

            // Check if supervisor diploma is requested but no supervisor exists
            if ($recipientType === 'supervisor' && !$registration['has_supervisor']) {
                return ['success' => false, 'message' => 'Руководитель не указан'];
            }

            // Check if diploma already exists
            $existingDiploma = $this->findExistingDiploma($registrationId, $recipientType);
            if ($existingDiploma && file_exists($this->uploadsDir . $existingDiploma['pdf_filename'])) {
                return [
                    'success' => true,
                    'pdf_path' => '/uploads/diplomas/' . $existingDiploma['pdf_filename'],
                    'message' => 'Диплом уже существует'
                ];
            }

            // Get template data
            $template = $this->getTemplate($registration['diploma_template_id']);
            if (!$template) {
                return ['success' => false, 'message' => 'Шаблон не найден'];
            }

            // Generate PDF
            $pdfFilename = $this->generatePDF($registration, $template, $recipientType);

            if (!$pdfFilename) {
                return ['success' => false, 'message' => 'Ошибка генерации PDF'];
            }

            // Save diploma record to database
            $this->saveDiplomaRecord($registrationId, $template['id'], $pdfFilename, $recipientType);

            // Update registration status
            $this->updateRegistrationStatus($registrationId, 'diploma_ready');

            return [
                'success' => true,
                'pdf_path' => '/uploads/diplomas/' . $pdfFilename,
                'message' => 'Диплом успешно создан'
            ];

        } catch (Exception $e) {
            error_log("Diploma generation error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()];
        }
    }

    /**
     * Get registration data with all related information
     */
    private function getRegistrationData($registrationId) {
        // Ensure UTF-8 is used for this query
        $this->db->exec("SET NAMES utf8mb4");

        $stmt = $this->db->prepare("
            SELECT
                r.*,
                u.full_name as user_full_name,
                u.email as user_email,
                u.phone,
                u.city,
                u.organization,
                c.title as competition_name,
                c.academic_year,
                c.category
            FROM registrations r
            JOIN users u ON r.user_id = u.id
            JOIN competitions c ON r.competition_id = c.id
            WHERE r.id = ?
        ");
        $stmt->execute([$registrationId]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        // Ensure all text fields are properly UTF-8 encoded
        if ($data) {
            foreach ($data as $key => $value) {
                if (is_string($value)) {
                    $data[$key] = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
                }
            }
        }

        return $data;
    }

    /**
     * Find existing diploma in database
     */
    private function findExistingDiploma($registrationId, $recipientType) {
        $stmt = $this->db->prepare("
            SELECT * FROM diplomas
            WHERE registration_id = ? AND recipient_type = ?
        ");
        $stmt->execute([$registrationId, $recipientType]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        // For backward compatibility, map pdf_path to pdf_filename if needed
        if ($result && isset($result['pdf_path']) && !isset($result['pdf_filename'])) {
            $result['pdf_filename'] = $result['pdf_path'];
        }
        return $result;
    }

    /**
     * Get template data from database
     */
    private function getTemplate($templateId) {
        $stmt = $this->db->prepare("SELECT * FROM diploma_templates WHERE id = ?");
        $stmt->execute([$templateId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get diploma subtitle based on recipient type and placement
     */
    private function getDiplomaSubtitle($recipientType, $placement) {
        if ($recipientType === 'supervisor') {
            return 'РУКОВОДИТЕЛЯ';
        }

        if (in_array($placement, ['1', '2', '3', 1, 2, 3])) {
            return 'ПОБЕДИТЕЛЯ';
        }

        return 'УЧАСТНИКА';
    }

    /**
     * Get achievement text based on recipient type and placement
     */
    private function getAchievementText($recipientType, $placement) {
        if ($recipientType === 'supervisor') {
            return 'за подготовку участника конкурса';
        }

        if (in_array($placement, ['1', '2', '3', 1, 2, 3])) {
            return 'за высокие достижения в конкурсе';
        }

        return 'за участие в конкурсе';
    }

    /**
     * Generate PDF using mPDF
     * Logic synchronized with DiplomaPreview.php::generateTextOverlay()
     */
    private function generatePDF($registration, $template, $recipientType) {
        // Prepare data based on recipient type
        $recipientName = $recipientType === 'supervisor'
            ? $registration['supervisor_name']
            : $registration['user_full_name'];

        $recipientOrganization = $recipientType === 'supervisor'
            ? $registration['supervisor_organization']
            : $registration['organization'];

        // Get city based on recipient type
        $recipientCity = $recipientType === 'supervisor'
            ? ($registration['supervisor_city'] ?? $registration['city'])
            : $registration['city'];

        // Configure mPDF with explicit UTF-8 support
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4', // A4 Portrait (210mm x 297mm)
            'margin_left' => 0,
            'margin_right' => 0,
            'margin_top' => 0,
            'margin_bottom' => 0,
            'default_font' => 'dejavusans', // Supports Cyrillic
            'autoScriptToLang' => true,
            'autoLangToFont' => true
        ]);

        // Set background image from backgrounds folder (PNG for better mPDF compatibility)
        $templateId = $registration['diploma_template_id'] ?? 1;
        $templateImagePath = __DIR__ . '/../assets/images/diplomas/templates/backgrounds/template-' . $templateId . '.png';

        // Fallback to SVG if PNG doesn't exist
        if (!file_exists($templateImagePath)) {
            $templateImagePath = __DIR__ . '/../assets/images/diplomas/templates/backgrounds/template-' . $templateId . '.svg';
        }

        // Fallback to old path if new doesn't exist
        if (!file_exists($templateImagePath)) {
            $templateImagePath = __DIR__ . '/../assets/images/diplomas/' . $template['template_image'];
        }

        $mpdf->SetDefaultBodyCSS('background', "url('$templateImagePath')");
        $mpdf->SetDefaultBodyCSS('background-image-resize', 6);

        // Build HTML for PDF (synchronized with DiplomaPreview.php)
        $html = $this->buildDiplomaHTML($registration, $recipientName, $recipientOrganization, $recipientCity, $recipientType);

        // Write HTML to PDF
        $mpdf->WriteHTML($html);

        // Generate filename
        $timestamp = time();
        $filename = sprintf(
            'diploma_%d_%s_%d.pdf',
            $registration['id'],
            $recipientType,
            $timestamp
        );

        // Save PDF
        $mpdf->Output($this->uploadsDir . $filename, 'F');

        return $filename;
    }

    /**
     * Build HTML for diploma PDF
     * Logic synchronized with DiplomaPreview.php::generateTextOverlay()
     */
    private function buildDiplomaHTML($registration, $recipientName, $recipientOrganization, $recipientCity, $recipientType) {
        // Format date
        $participationDate = $registration['participation_date']
            ? date('d.m.Y', strtotime($registration['participation_date']))
            : date('d.m.Y');

        $placement = $registration['placement'] ?? '';

        // Build positioned text fields
        $textFields = '';

        // 1. ДИПЛОМ - Main title
        $textFields .= $this->createTextField('ДИПЛОМ', $this->fieldPositions['diploma_title']);

        // 2. ПОБЕДИТЕЛЯ / УЧАСТНИКА / РУКОВОДИТЕЛЯ - Subtitle
        $subtitle = $this->getDiplomaSubtitle($recipientType, $placement);
        $textFields .= $this->createTextField($subtitle, $this->fieldPositions['diploma_subtitle']);

        // 3. награждается - Award text
        $textFields .= $this->createTextField('награждается', $this->fieldPositions['award_text']);

        // 4. ФИО - Main focus (recipient name)
        if (!empty($recipientName)) {
            $textFields .= $this->createTextField($recipientName, $this->fieldPositions['fio']);
        }

        // 5. Achievement text (за участие/достижения/подготовку)
        $achievementText = $this->getAchievementText($recipientType, $placement);
        $textFields .= $this->createTextField($achievementText, $this->fieldPositions['achievement_text']);

        // 6. Competition type (ВСЕРОССИЙСКИЙ КОНКУРС)
        if (!empty($registration['competition_type'])) {
            $competitionType = mb_strtoupper($registration['competition_type'], 'UTF-8') . ' КОНКУРС';
            $textFields .= $this->createTextField($competitionType, $this->fieldPositions['competition_type']);
        }

        // 6.5. Competition name (название конкурса из БД)
        if (!empty($registration['competition_name'])) {
            $competitionNameQuoted = '«' . $registration['competition_name'] . '»';
            $textFields .= $this->createTextField($competitionNameQuoted, $this->fieldPositions['competition_name']);
        }

        // 7. Work title in quotes (if exists) - NEW LOGIC
        if (!empty($registration['work_title'])) {
            $workTitleQuoted = '«' . $registration['work_title'] . '»';
            $textFields .= $this->createTextField($workTitleQuoted, $this->fieldPositions['work_title_quoted']);
        }

        // 8. Nomination line - always show if nomination exists - NEW FIELD
        if (!empty($registration['nomination'])) {
            $nominationLine = 'в номинации «' . $registration['nomination'] . '»';
            $textFields .= $this->createTextField($nominationLine, $this->fieldPositions['nomination_line']);
        }

        // 9. Organization
        if (!empty($recipientOrganization)) {
            $textFields .= $this->createTextField(
                'Учреждение: ' . $recipientOrganization,
                $this->fieldPositions['organization']
            );
        }

        // 10. City
        if (!empty($recipientCity)) {
            $textFields .= $this->createTextField(
                'Населенный пункт: ' . $recipientCity,
                $this->fieldPositions['city']
            );
        }

        // 11. Supervisor name (centered, under city) - only for participant diploma with supervisor
        if ($recipientType === 'participant' && !empty($registration['supervisor_name'])) {
            $textFields .= $this->createTextField(
                'Руководитель: ' . $registration['supervisor_name'],
                $this->fieldPositions['supervisor_name']
            );
        }

        // 12. Participation date (bottom left)
        $textFields .= $this->createTextField($participationDate, $this->fieldPositions['participation_date']);

        // 13. Chairman signature block with stamp (bottom right)
        $chairmanBlock = $this->createChairmanSignatureBlock();

        // Build complete HTML (background image set via mPDF->SetDefaultBodyCSS)
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: DejaVuSans;
        }
        .text-field {
            position: absolute;
            line-height: 1.2;
        }
        .chairman-block {
            position: absolute;
            text-align: center;
        }
        .chairman-stamp {
            position: absolute;
        }
    </style>
</head>
<body>
{$textFields}
{$chairmanBlock}
</body>
</html>
HTML;

        return $html;
    }

    /**
     * Create chairman signature block with stamp for PDF
     * Adds "Председатель Оргкомитета Брехач Р.А." with stamp image
     */
    private function createChairmanSignatureBlock() {
        $stampPath = __DIR__ . '/..' . self::CHAIRMAN_STAMP_PATH;
        $stampHtml = '';

        // Add stamp image if exists (positioned near chairman signature block)
        if (file_exists($stampPath)) {
            $stampHtml = sprintf(
                '<div style="position: absolute; left: 120mm; top: 225mm; width: 50mm; height: 50mm;"><img src="%s" style="width: 50mm; height: auto;" /></div>',
                $stampPath
            );
        }

        // Chairman label and name
        $labelPos = $this->fieldPositions['chairman_label'];
        $namePos = $this->fieldPositions['chairman_name'];

        $labelHtml = $this->createTextField('Председатель Оргкомитета', $labelPos);
        $nameHtml = $this->createTextField('Брехач Р.А.', $namePos);

        return $stampHtml . $labelHtml . $nameHtml;
    }

    /**
     * Create positioned text field HTML
     */
    private function createTextField($text, $position) {
        $x = $position['x'] ?? 0;
        $y = $position['y'] ?? 0;
        $fontSize = $position['size'] ?? $position['font_size'] ?? 16;
        $fontWeight = $position['font_weight'] ?? 'normal';
        $fontStyle = $position['font_style'] ?? 'normal';
        $textAlign = $position['align'] ?? $position['text_align'] ?? 'center';
        $color = $position['color'] ?? '#000000';
        $maxWidth = $position['max_width'] ?? '200';

        // Properly escape HTML but preserve UTF-8
        $escapedText = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // Calculate positioning
        $width = $maxWidth . 'mm';
        $leftPos = $textAlign === 'center' ? ($x - ($maxWidth / 2)) . 'mm' : $x . 'mm';

        return <<<HTML
<div class="text-field" style="left: {$leftPos}; top: {$y}mm; width: {$width}; font-size: {$fontSize}pt; font-weight: {$fontWeight}; font-style: {$fontStyle}; text-align: {$textAlign}; color: {$color};">{$escapedText}</div>
HTML;
    }

    /**
     * Save diploma record to database
     */
    private function saveDiplomaRecord($registrationId, $templateId, $pdfFilename, $recipientType) {
        $stmt = $this->db->prepare("
            INSERT INTO diplomas (registration_id, template_id, pdf_path, recipient_type, generated_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                pdf_path = VALUES(pdf_path),
                generated_at = NOW()
        ");
        $stmt->execute([$registrationId, $templateId, $pdfFilename, $recipientType]);
    }

    /**
     * Update registration status
     */
    private function updateRegistrationStatus($registrationId, $status) {
        $stmt = $this->db->prepare("UPDATE registrations SET status = ? WHERE id = ?");
        $stmt->execute([$status, $registrationId]);
    }

    /**
     * Get diploma download count
     */
    public function incrementDownloadCount($diplomaId) {
        $stmt = $this->db->prepare("
            UPDATE diplomas
            SET download_count = download_count + 1,
                last_downloaded_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$diplomaId]);
    }

    /**
     * Check if user owns the registration
     */
    public function verifyOwnership($registrationId, $userId) {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count
            FROM registrations
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$registrationId, $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] > 0;
    }
}
