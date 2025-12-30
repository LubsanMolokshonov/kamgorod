<?php
/**
 * Diploma Class
 * Generates PDF diplomas using mPDF with Cyrillic support
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Mpdf\Mpdf;

class Diploma {
    private $db;
    private $uploadsDir;

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
     * Generate PDF using mPDF
     */
    private function generatePDF($registration, $template, $recipientType) {
        // Decode field positions
        $positions = json_decode($template['field_positions'], true);

        // Prepare data based on recipient type
        $recipientName = $recipientType === 'supervisor'
            ? $registration['supervisor_name']
            : $registration['user_full_name'];

        $recipientOrganization = $recipientType === 'supervisor'
            ? $registration['supervisor_organization']
            : $registration['organization'];

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

        // Set background image
        $templateImagePath = __DIR__ . '/../assets/images/diplomas/' . $template['template_image'];
        $mpdf->SetDefaultBodyCSS('background', "url('$templateImagePath')");
        $mpdf->SetDefaultBodyCSS('background-image-resize', 6);

        // Build HTML for PDF
        $html = $this->buildDiplomaHTML($registration, $template, $positions, $recipientName, $recipientOrganization, $recipientType);

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
     */
    private function buildDiplomaHTML($registration, $template, $positions, $recipientName, $recipientOrganization, $recipientType) {
        // Format date
        $participationDate = $registration['participation_date']
            ? date('d.m.Y', strtotime($registration['participation_date']))
            : date('d.m.Y');

        // Build positioned text fields
        $textFields = '';

        // Add recipient name (FIO)
        if (isset($positions['fio'])) {
            $textFields .= $this->createTextField(
                $recipientName,
                $positions['fio']
            );
        }

        // Add organization (check both 'organization' and 'org' for backward compatibility)
        $orgPosition = $positions['organization'] ?? $positions['org'] ?? null;
        if ($orgPosition && $recipientOrganization) {
            $textFields .= $this->createTextField(
                $recipientOrganization,
                $orgPosition
            );
        }

        // Add nomination
        if (isset($positions['nomination'])) {
            $textFields .= $this->createTextField(
                $registration['nomination'],
                $positions['nomination']
            );
        }

        // Add work title
        if (isset($positions['work_title']) && $registration['work_title']) {
            $textFields .= $this->createTextField(
                '«' . $registration['work_title'] . '»',
                $positions['work_title']
            );
        }

        // Add competition name
        if (isset($positions['competition_name'])) {
            $textFields .= $this->createTextField(
                $registration['competition_name'],
                $positions['competition_name']
            );
        }

        // Add placement
        if (isset($positions['placement']) && $registration['placement']) {
            $placementText = is_numeric($registration['placement'])
                ? $registration['placement'] . ' место'
                : $registration['placement'];
            $textFields .= $this->createTextField(
                $placementText,
                $positions['placement']
            );
        }

        // Add participation date
        if (isset($positions['participation_date'])) {
            $textFields .= $this->createTextField(
                $participationDate,
                $positions['participation_date']
            );
        }

        // Add city
        if (isset($positions['city']) && $registration['city']) {
            $textFields .= $this->createTextField(
                $registration['city'],
                $positions['city']
            );
        }

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
    </style>
</head>
<body>
{$textFields}
</body>
</html>
HTML;

        return $html;
    }

    /**
     * Create positioned text field HTML
     */
    private function createTextField($text, $position) {
        $x = $position['x'] ?? 0;
        $y = $position['y'] ?? 0;
        // Support both 'size' and 'font_size' for backward compatibility
        $fontSize = $position['size'] ?? $position['font_size'] ?? 16;
        $fontWeight = $position['font_weight'] ?? 'normal';
        // Support both 'align' and 'text_align' for backward compatibility
        $textAlign = $position['align'] ?? $position['text_align'] ?? 'center';
        $color = $position['color'] ?? '#000000';
        $maxWidth = $position['max_width'] ?? '200';

        // Properly escape HTML but preserve UTF-8
        $escapedText = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // Calculate positioning
        $width = $maxWidth . 'mm';
        $leftPos = $textAlign === 'center' ? ($x - ($maxWidth / 2)) . 'mm' : $x . 'mm';

        return <<<HTML
<div class="text-field" style="left: {$leftPos}; top: {$y}mm; width: {$width}; font-size: {$fontSize}pt; font-weight: {$fontWeight}; text-align: {$textAlign}; color: {$color};">{$escapedText}</div>
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
