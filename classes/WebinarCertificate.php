<?php
/**
 * WebinarCertificate Class
 * Generates PDF certificates for webinar participants using mPDF
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Mpdf\Mpdf;

class WebinarCertificate {
    private $db;
    private $pdo;
    private $uploadsDir;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->db = new Database($pdo);
        $this->uploadsDir = __DIR__ . '/../uploads/webinars/certificates/';

        // Create directory if not exists
        if (!is_dir($this->uploadsDir)) {
            mkdir($this->uploadsDir, 0755, true);
        }
    }

    /**
     * Create certificate record
     * @param array $data Certificate data
     * @return int Certificate ID
     */
    public function create($data) {
        $certificateNumber = $this->generateCertificateNumber();

        $insertData = [
            'webinar_id' => $data['webinar_id'],
            'user_id' => $data['user_id'],
            'registration_id' => $data['registration_id'],
            'full_name' => $data['full_name'],
            'author_name' => $data['full_name'],
            'organization' => $data['organization'] ?? '',
            'position' => $data['position'] ?? '',
            'city' => $data['city'] ?? '',
            'template_id' => $data['template_id'] ?? 1,
            'certificate_number' => $certificateNumber,
            'hours' => $data['hours'] ?? 2,
            'price' => $data['price'] ?? 200.00,
            'status' => 'pending'
        ];

        return $this->db->insert('webinar_certificates', $insertData);
    }

    /**
     * Get certificate by ID with webinar and speaker details
     * @param int $id Certificate ID
     * @return array|null Certificate data
     */
    public function getById($id) {
        return $this->db->queryOne(
            "SELECT wc.*, w.title as webinar_title, w.slug as webinar_slug,
                    w.scheduled_at, w.duration_minutes,
                    s.full_name as speaker_name, s.position as speaker_position,
                    s.organization as speaker_organization
             FROM webinar_certificates wc
             JOIN webinars w ON wc.webinar_id = w.id
             LEFT JOIN speakers s ON w.speaker_id = s.id
             WHERE wc.id = ?",
            [$id]
        );
    }

    /**
     * Get certificate by registration ID
     * @param int $registrationId Registration ID
     * @return array|null Certificate data
     */
    public function getByRegistrationId($registrationId) {
        return $this->db->queryOne(
            "SELECT wc.*, w.title as webinar_title, w.slug as webinar_slug,
                    w.scheduled_at, w.duration_minutes,
                    s.full_name as speaker_name, s.position as speaker_position
             FROM webinar_certificates wc
             JOIN webinars w ON wc.webinar_id = w.id
             LEFT JOIN speakers s ON w.speaker_id = s.id
             WHERE wc.registration_id = ?",
            [$registrationId]
        );
    }

    /**
     * Get certificates by user
     * @param int $userId User ID
     * @param string|null $status Filter by status
     * @return array Certificates
     */
    public function getByUser($userId, $status = null) {
        $sql = "SELECT wc.*, w.title as webinar_title, w.slug as webinar_slug,
                       w.scheduled_at
                FROM webinar_certificates wc
                JOIN webinars w ON wc.webinar_id = w.id
                WHERE wc.user_id = ?";
        $params = [$userId];

        if ($status !== null) {
            $sql .= " AND wc.status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY wc.created_at DESC";

        return $this->db->query($sql, $params);
    }

    /**
     * Update certificate status
     * @param int $id Certificate ID
     * @param string $status New status
     * @return bool Success
     */
    public function updateStatus($id, $status) {
        return $this->db->update('webinar_certificates', ['status' => $status], 'id = ?', [$id]) > 0;
    }

    /**
     * Update certificate data
     * @param int $id Certificate ID
     * @param array $data Fields to update
     * @return int Affected rows
     */
    public function update($id, $data) {
        $allowed = ['full_name', 'organization', 'position', 'city', 'template_id'];
        $updateData = array_intersect_key($data, array_flip($allowed));

        if (empty($updateData)) {
            return 0;
        }

        return $this->db->update('webinar_certificates', $updateData, 'id = ?', [$id]);
    }

    /**
     * Generate PDF certificate
     * @param int $certificateId Certificate ID
     * @return array ['success' => bool, 'pdf_path' => string, 'message' => string]
     */
    public function generate($certificateId) {
        try {
            $certificate = $this->getById($certificateId);

            if (!$certificate) {
                return ['success' => false, 'message' => 'Сертификат не найден'];
            }

            if ($certificate['status'] !== 'paid') {
                return ['success' => false, 'message' => 'Сертификат не оплачен'];
            }

            // Check if already generated
            if ($certificate['pdf_path'] && file_exists($this->uploadsDir . basename($certificate['pdf_path']))) {
                return [
                    'success' => true,
                    'pdf_path' => $certificate['pdf_path'],
                    'message' => 'Сертификат уже создан'
                ];
            }

            // Generate PDF
            $pdfFilename = $this->generatePDF($certificate);

            if (!$pdfFilename) {
                return ['success' => false, 'message' => 'Ошибка генерации PDF'];
            }

            // Save path to database
            $pdfPath = '/uploads/webinars/certificates/' . $pdfFilename;
            $this->db->update(
                'webinar_certificates',
                [
                    'pdf_path' => $pdfPath,
                    'status' => 'ready',
                    'issued_at' => date('Y-m-d H:i:s')
                ],
                'id = ?',
                [$certificateId]
            );

            return [
                'success' => true,
                'pdf_path' => $pdfPath,
                'message' => 'Сертификат успешно создан'
            ];

        } catch (Exception $e) {
            error_log("Webinar certificate generation error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()];
        }
    }

    /**
     * Generate PDF using mPDF
     * @param array $certificate Certificate data with webinar/speaker info
     * @return string|null PDF filename
     */
    private function generatePDF($certificate) {
        $positions = $this->getDefaultPositions();

        // Configure mPDF
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
            'tempDir' => '/tmp/mpdf'
        ]);

        // Set background image from diploma templates (same backgrounds as competitions)
        $templateId = $certificate['template_id'] ?? 1;
        $bgPath = __DIR__ . '/../assets/images/diplomas/templates/backgrounds/template-' . $templateId . '.png';

        // Fallback to SVG if PNG doesn't exist
        if (!file_exists($bgPath)) {
            $bgPath = __DIR__ . '/../assets/images/diplomas/templates/backgrounds/template-' . $templateId . '.svg';
        }

        // Final fallback to template-1
        if (!file_exists($bgPath)) {
            $bgPath = __DIR__ . '/../assets/images/diplomas/templates/backgrounds/template-1.png';
        }

        if (file_exists($bgPath)) {
            $mpdf->SetDefaultBodyCSS('background', "url('$bgPath')");
            $mpdf->SetDefaultBodyCSS('background-image-resize', 6);
        }

        // Build HTML
        $html = $this->buildCertificateHTML($certificate, $positions);
        $mpdf->WriteHTML($html);

        // Generate filename
        $filename = sprintf(
            'cert_web_%d_%d.pdf',
            $certificate['id'],
            time()
        );

        // Save PDF
        $outputPath = $this->uploadsDir . $filename;
        $mpdf->Output($outputPath, 'F');

        // Verify file was actually written
        if (!file_exists($outputPath) || filesize($outputPath) === 0) {
            error_log("WebinarCertificate: PDF not written to {$outputPath}");
            return null;
        }

        return $filename;
    }

    /**
     * Build HTML for certificate
     * @param array $certificate Certificate data
     * @param array $positions Field positions
     * @return string HTML
     */
    private function buildCertificateHTML($certificate, $positions) {
        $textFields = '';

        // Title header
        $textFields .= $this->createTextField('СЕРТИФИКАТ', $positions['title_header']);

        // Subtitle
        $textFields .= $this->createTextField('УЧАСТНИКА ВЕБИНАРА', $positions['subtitle']);

        // Confirms text
        $textFields .= $this->createTextField('подтверждает, что', $positions['confirms_text']);

        // Full name
        $textFields .= $this->createTextField(
            $certificate['full_name'],
            $positions['full_name']
        );

        // Participation text
        $textFields .= $this->createTextField(
            'принял(а) участие в вебинаре',
            $positions['participation_text']
        );

        // Webinar title
        $textFields .= $this->createTextField(
            '«' . $certificate['webinar_title'] . '»',
            $positions['webinar_title']
        );

        // Speaker info
        if (!empty($certificate['speaker_name'])) {
            $speakerText = 'Спикер: ' . $certificate['speaker_name'];
            if (!empty($certificate['speaker_position'])) {
                $speakerText .= ', ' . $certificate['speaker_position'];
            }
            $textFields .= $this->createTextField($speakerText, $positions['speaker_info']);
        }

        // Hours
        $hoursText = 'Объём: ' . $certificate['hours'] . ' ч.';
        $textFields .= $this->createTextField($hoursText, $positions['hours_text']);

        // Organization
        if (!empty($certificate['organization'])) {
            $textFields .= $this->createTextField(
                $certificate['organization'],
                $positions['organization']
            );
        }

        // City
        if (!empty($certificate['city'])) {
            $textFields .= $this->createTextField(
                $certificate['city'],
                $positions['city']
            );
        }

        // Certificate number
        $textFields .= $this->createTextField(
            $certificate['certificate_number'],
            $positions['certificate_number']
        );

        // Issue date (bottom left, same as competition diplomas)
        $textFields .= $this->createTextField(
            date('d.m.Y'),
            $positions['issue_date']
        );

        // Chairman signature block (bottom right, same as competition diplomas)
        $textFields .= $this->createTextField('Председатель Оргкомитета', $positions['chairman_label']);
        $textFields .= $this->createTextField('Брехач Р.А.', $positions['chairman_name']);

        // Chairman stamp image
        $stampHtml = '';
        $stampPath = __DIR__ . '/../assets/images/diplomas/stamp-brehach.png';
        if (file_exists($stampPath)) {
            $stampHtml = sprintf(
                '<div style="position: absolute; left: 120mm; top: 225mm; width: 50mm; height: 50mm;"><img src="%s" style="width: 50mm; height: auto;" /></div>',
                $stampPath
            );
        }

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
{$stampHtml}
</body>
</html>
HTML;

        return $html;
    }

    /**
     * Create positioned text field
     * @param string $text Text content
     * @param array $position Position settings
     * @return string HTML
     */
    private function createTextField($text, $position) {
        $x = $position['x'] ?? 0;
        $y = $position['y'] ?? 0;
        $fontSize = $position['size'] ?? $position['font_size'] ?? 16;
        $fontWeight = $position['font_weight'] ?? 'normal';
        $fontStyle = $position['font_style'] ?? 'normal';
        $textAlign = $position['align'] ?? $position['text_align'] ?? 'center';
        $color = $position['color'] ?? '#000000';
        $maxWidth = $position['max_width'] ?? 180;

        $escapedText = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $width = $maxWidth . 'mm';
        $leftPos = $textAlign === 'center' ? ($x - ($maxWidth / 2)) . 'mm' : $x . 'mm';

        return <<<HTML
<div class="text-field" style="left: {$leftPos}; top: {$y}mm; width: {$width}; font-size: {$fontSize}pt; font-weight: {$fontWeight}; font-style: {$fontStyle}; text-align: {$textAlign}; color: {$color};">{$escapedText}</div>
HTML;
    }

    /**
     * Get default field positions (A4: 210x297mm)
     * @return array Default positions
     */
    private function getDefaultPositions() {
        return [
            'title_header' => ['x' => 105, 'y' => 75, 'size' => 28, 'font_weight' => 'bold', 'align' => 'center', 'color' => '#0065B1', 'max_width' => 180],
            'subtitle' => ['x' => 105, 'y' => 90, 'size' => 14, 'font_weight' => 'bold', 'align' => 'center', 'color' => '#333333', 'max_width' => 160],
            'confirms_text' => ['x' => 105, 'y' => 108, 'size' => 12, 'align' => 'center', 'color' => '#333333', 'max_width' => 170],
            'full_name' => ['x' => 105, 'y' => 123, 'size' => 20, 'font_weight' => 'bold', 'align' => 'center', 'color' => '#000000', 'max_width' => 180],
            'participation_text' => ['x' => 105, 'y' => 140, 'size' => 11, 'font_style' => 'italic', 'align' => 'center', 'color' => '#333333', 'max_width' => 170],
            'webinar_title' => ['x' => 105, 'y' => 155, 'size' => 14, 'font_weight' => 'bold', 'align' => 'center', 'color' => '#0065B1', 'max_width' => 170],
            'speaker_info' => ['x' => 105, 'y' => 170, 'size' => 11, 'align' => 'center', 'color' => '#333333', 'max_width' => 170],
            'hours_text' => ['x' => 105, 'y' => 182, 'size' => 12, 'font_weight' => 'bold', 'align' => 'center', 'color' => '#333333', 'max_width' => 170],
            'organization' => ['x' => 105, 'y' => 196, 'size' => 10, 'align' => 'center', 'color' => '#333333', 'max_width' => 170],
            'city' => ['x' => 105, 'y' => 205, 'size' => 10, 'align' => 'center', 'color' => '#333333', 'max_width' => 170],
            'certificate_number' => ['x' => 105, 'y' => 218, 'size' => 10, 'align' => 'center', 'color' => '#666666', 'max_width' => 100],
            'issue_date' => ['x' => 27, 'y' => 249, 'size' => 9, 'align' => 'left', 'color' => '#000000', 'max_width' => 60],
            'chairman_label' => ['x' => 141, 'y' => 233, 'size' => 9, 'align' => 'center', 'color' => '#000000', 'max_width' => 50],
            'chairman_name' => ['x' => 141, 'y' => 239, 'size' => 9, 'font_weight' => 'bold', 'align' => 'center', 'color' => '#000000', 'max_width' => 50]
        ];
    }

    /**
     * Generate unique certificate number
     * @return string Certificate number (ВЕБ-YYYY-NNNNNN)
     */
    private function generateCertificateNumber() {
        $year = date('Y');
        $prefix = 'ВЕБ';

        // Get last number for this year
        $result = $this->db->queryOne(
            "SELECT certificate_number FROM webinar_certificates
             WHERE certificate_number LIKE ?
             ORDER BY id DESC LIMIT 1",
            [$prefix . '-' . $year . '-%']
        );

        if ($result && preg_match('/(\d+)$/', $result['certificate_number'], $matches)) {
            $lastNum = (int)$matches[1];
        } else {
            $lastNum = 0;
        }

        $newNum = str_pad($lastNum + 1, 6, '0', STR_PAD_LEFT);

        return $prefix . '-' . $year . '-' . $newNum;
    }

    /**
     * Verify user owns the certificate
     * @param int $certificateId Certificate ID
     * @param int $userId User ID
     * @return bool Owns
     */
    public function verifyOwnership($certificateId, $userId) {
        $result = $this->db->queryOne(
            "SELECT id FROM webinar_certificates WHERE id = ? AND user_id = ?",
            [$certificateId, $userId]
        );
        return !empty($result);
    }
}
