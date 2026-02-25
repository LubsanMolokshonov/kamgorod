<?php
/**
 * PublicationCertificate Class
 * Generates PDF certificates for publications using mPDF
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Mpdf\Mpdf;

class PublicationCertificate {
    private $db;
    private $pdo;
    private $uploadsDir;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->db = new Database($pdo);
        $this->uploadsDir = __DIR__ . '/../uploads/publications/certificates/';

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
            'publication_id' => $data['publication_id'],
            'user_id' => $data['user_id'],
            'template_id' => $data['template_id'] ?? 1,
            'author_name' => $data['author_name'],
            'organization' => $data['organization'] ?? '',
            'position' => $data['position'] ?? '',
            'certificate_number' => $certificateNumber,
            'price' => $data['price'] ?? 149.00,
            'status' => 'pending'
        ];

        // Add optional fields if provided (requires migration 018)
        if (!empty($data['city'])) {
            $insertData['city'] = $data['city'];
        }
        if (!empty($data['publication_date'])) {
            $insertData['publication_date'] = $data['publication_date'];
        }

        return $this->db->insert('publication_certificates', $insertData);
    }

    /**
     * Get certificate by ID
     * @param int $id Certificate ID
     * @return array|null Certificate data
     */
    public function getById($id) {
        return $this->db->queryOne(
            "SELECT pc.*, p.title as publication_title, p.slug as publication_slug,
                    ct.name as template_name, ct.template_image
             FROM publication_certificates pc
             JOIN publications p ON pc.publication_id = p.id
             LEFT JOIN certificate_templates ct ON pc.template_id = ct.id
             WHERE pc.id = ?",
            [$id]
        );
    }

    /**
     * Get certificate by publication ID
     * @param int $publicationId Publication ID
     * @return array|null Certificate data
     */
    public function getByPublicationId($publicationId) {
        return $this->db->queryOne(
            "SELECT * FROM publication_certificates WHERE publication_id = ?",
            [$publicationId]
        );
    }

    /**
     * Get certificates by user
     * @param int $userId User ID
     * @param string|null $status Filter by status
     * @return array Certificates
     */
    public function getByUser($userId, $status = null) {
        $sql = "SELECT pc.*, p.title as publication_title, p.slug as publication_slug
                FROM publication_certificates pc
                JOIN publications p ON pc.publication_id = p.id
                WHERE pc.user_id = ?";
        $params = [$userId];

        if ($status !== null) {
            $sql .= " AND pc.status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY pc.created_at DESC";

        return $this->db->query($sql, $params);
    }

    /**
     * Update certificate status
     * @param int $id Certificate ID
     * @param string $status New status
     * @return bool Success
     */
    public function updateStatus($id, $status) {
        $updateData = ['status' => $status];

        if ($status === 'paid') {
            // Update publication certificate status
            $cert = $this->getById($id);
            if ($cert) {
                $this->db->update(
                    'publications',
                    ['certificate_status' => 'paid'],
                    'id = ?',
                    [$cert['publication_id']]
                );
            }
        }

        return $this->db->update('publication_certificates', $updateData, 'id = ?', [$id]) > 0;
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
                return ['success' => false, 'message' => 'Свидетельство не найдено'];
            }

            if ($certificate['status'] !== 'paid') {
                return ['success' => false, 'message' => 'Свидетельство не оплачено'];
            }

            // Check if already generated
            if ($certificate['pdf_path'] && file_exists($this->uploadsDir . basename($certificate['pdf_path']))) {
                return [
                    'success' => true,
                    'pdf_path' => $certificate['pdf_path'],
                    'message' => 'Свидетельство уже создано'
                ];
            }

            // Get template
            $template = $this->getTemplate($certificate['template_id']);
            if (!$template) {
                return ['success' => false, 'message' => 'Шаблон не найден'];
            }

            // Fetch publication data for direction tag
            require_once __DIR__ . '/Publication.php';
            $pubObj = new Publication($this->pdo);
            $publication = $pubObj->getById($certificate['publication_id']);
            $direction = '';
            if ($publication) {
                $tags = $pubObj->getTags($certificate['publication_id']);
                foreach ($tags as $tag) {
                    if ($tag['tag_type'] === 'direction') {
                        $direction = $tag['name'];
                        break;
                    }
                }
                if (empty($direction) && !empty($publication['type_name'])) {
                    $direction = $publication['type_name'];
                }
            }
            $certificate['direction'] = $direction;

            // Generate PDF
            $pdfFilename = $this->generatePDF($certificate, $template);

            if (!$pdfFilename) {
                return ['success' => false, 'message' => 'Ошибка генерации PDF'];
            }

            // Save path to database
            $pdfPath = '/uploads/publications/certificates/' . $pdfFilename;
            $this->db->update(
                'publication_certificates',
                [
                    'pdf_path' => $pdfPath,
                    'status' => 'ready',
                    'issued_at' => date('Y-m-d H:i:s')
                ],
                'id = ?',
                [$certificateId]
            );

            // Update publication certificate status
            $this->db->update(
                'publications',
                ['certificate_status' => 'ready'],
                'id = ?',
                [$certificate['publication_id']]
            );

            return [
                'success' => true,
                'pdf_path' => $pdfPath,
                'message' => 'Свидетельство успешно создано'
            ];

        } catch (Exception $e) {
            error_log("Certificate generation error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()];
        }
    }

    /**
     * Get template data
     * @param int $templateId Template ID
     * @return array|null Template data
     */
    private function getTemplate($templateId) {
        return $this->db->queryOne(
            "SELECT * FROM certificate_templates WHERE id = ?",
            [$templateId]
        );
    }

    /**
     * Get all active templates
     * @return array Templates
     */
    public function getTemplates() {
        return $this->db->query(
            "SELECT * FROM certificate_templates WHERE is_active = 1 ORDER BY display_order ASC"
        );
    }

    /**
     * Generate PDF using mPDF
     * @param array $certificate Certificate data
     * @param array $template Template data
     * @return string|null PDF filename
     */
    private function generatePDF($certificate, $template) {
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
            'autoLangToFont' => true
        ]);

        // Set background - use new diploma background templates (PNG for mPDF)
        $templateId = $certificate['template_id'] ?? 1;
        $bgPath = __DIR__ . '/../assets/images/diplomas/templates/backgrounds/template-' . $templateId . '.png';
        if (!file_exists($bgPath)) {
            // Fallback to old certificate templates
            $bgPath = __DIR__ . '/../assets/images/certificates/' . ($template['template_image'] ?? '');
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
            'cert_pub_%d_%d.pdf',
            $certificate['id'],
            time()
        );

        // Save PDF
        $mpdf->Output($this->uploadsDir . $filename, 'F');

        return $filename;
    }

    /**
     * Build HTML for certificate with complete layout
     * Positions in mm (A4: 210x297mm), synchronized with CertificatePreview.php SVG positions
     * SVG px / 2.834 = mm
     * @param array $certificate Certificate data
     * @param array $positions Field positions
     * @return string HTML
     */
    private function buildCertificateHTML($certificate, $positions) {
        $authorName = htmlspecialchars($certificate['author_name'] ?? '', ENT_QUOTES, 'UTF-8');
        $organization = htmlspecialchars($certificate['organization'] ?? '', ENT_QUOTES, 'UTF-8');
        $city = htmlspecialchars($certificate['city'] ?? '', ENT_QUOTES, 'UTF-8');
        $position = htmlspecialchars($certificate['position'] ?? '', ENT_QUOTES, 'UTF-8');
        $pubTitle = htmlspecialchars('«' . ($certificate['publication_title'] ?? '') . '»', ENT_QUOTES, 'UTF-8');
        $direction = htmlspecialchars($certificate['direction'] ?? '', ENT_QUOTES, 'UTF-8');
        $certNumber = htmlspecialchars($certificate['certificate_number'] ?? '', ENT_QUOTES, 'UTF-8');
        $pubDate = !empty($certificate['publication_date'])
            ? date('d.m.Y', strtotime($certificate['publication_date']))
            : date('d.m.Y');

        $textFields = '';

        // Title: СВИДЕТЕЛЬСТВО (below logo area)
        $textFields .= $this->createTextField('СВИДЕТЕЛЬСТВО', $positions['title']);
        $textFields .= $this->createTextField('О ПУБЛИКАЦИИ', $positions['subtitle']);

        // Confirmation text
        $textFields .= $this->createTextField(
            'Настоящее свидетельство подтверждает, что',
            $positions['confirmation_text']
        );

        // Author name
        $textFields .= $this->createTextField($authorName, $positions['author_name']);

        // Published material text
        $textFields .= $this->createTextField(
            'опубликовал(а) материал в электронном журнале',
            $positions['published_text']
        );
        $textFields .= $this->createTextField('«ФГОС-Практикум»', $positions['journal_name']);

        // Publication title
        $textFields .= $this->createTextField('Название публикации:', $positions['pub_title_label']);
        $textFields .= $this->createTextField($pubTitle, $positions['publication_title']);

        // Details
        $textFields .= $this->createTextField('Учреждение: ' . $organization, $positions['org_line']);
        $textFields .= $this->createTextField('Населенный пункт: ' . $city, $positions['city_line']);
        $textFields .= $this->createTextField('Должность: ' . $position, $positions['position_line']);
        $textFields .= $this->createTextField('Направление: ' . $direction, $positions['direction_line']);

        // Certificate number
        $textFields .= $this->createTextField(
            'Свидетельство № ' . $certNumber,
            $positions['certificate_number']
        );

        // Stamp image
        $stampPath = __DIR__ . '/../assets/images/diplomas/stamp-brehach.png';
        if (file_exists($stampPath)) {
            $textFields .= '<div style="position: absolute; left: 113mm; top: 219mm;">'
                . '<img src="' . $stampPath . '" width="56mm" height="35mm" />'
                . '</div>';
        }

        // Chairman signature
        $textFields .= $this->createTextField('Председатель Оргкомитета', $positions['chairman_label']);
        $textFields .= $this->createTextField('Брехач Р.А.', $positions['chairman_name']);

        // Date (bottom left)
        $textFields .= $this->createTextField($pubDate, $positions['date_line']);

        // Footer
        $textFields .= $this->createTextField(
            'ООО «Едурегионлаб» | ИНН 5904368615',
            $positions['footer_company']
        );
        $textFields .= $this->createTextField(
            'Лицензия № Л035-01212-59/00203856 от 17.12.2021',
            $positions['footer_license']
        );
        $textFields .= $this->createTextField('fgos.pro', $positions['footer_site']);

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
            line-height: 1.3;
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
        $textAlign = $position['align'] ?? $position['text_align'] ?? 'center';
        $color = $position['color'] ?? '#000000';
        $maxWidth = $position['max_width'] ?? 180;

        $escapedText = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $width = $maxWidth . 'mm';
        $leftPos = $textAlign === 'center' ? ($x - ($maxWidth / 2)) . 'mm' : $x . 'mm';

        return <<<HTML
<div class="text-field" style="left: {$leftPos}; top: {$y}mm; width: {$width}; font-size: {$fontSize}pt; font-weight: {$fontWeight}; text-align: {$textAlign}; color: {$color};">{$escapedText}</div>
HTML;
    }

    /**
     * Get default field positions in mm (A4: 210x297mm)
     * Synchronized with CertificatePreview.php SVG positions (px / 2.834 = mm)
     * Content starts below logo area (~70mm)
     * @return array Default positions
     */
    private function getDefaultPositions() {
        return [
            // Title (below logo area, dark text on white background)
            'title'              => ['x' => 105, 'y' => 81,  'size' => 24, 'font_weight' => 'bold', 'align' => 'center', 'color' => '#0077FF', 'max_width' => 180],
            'subtitle'           => ['x' => 105, 'y' => 92,  'size' => 13, 'align' => 'center', 'color' => '#000000', 'max_width' => 180],
            // Confirmation
            'confirmation_text'  => ['x' => 105, 'y' => 102, 'size' => 9,  'align' => 'center', 'color' => '#333333', 'max_width' => 180],
            // Author name (main focus)
            'author_name'        => ['x' => 105, 'y' => 113, 'size' => 14, 'font_weight' => 'bold', 'align' => 'center', 'color' => '#000000', 'max_width' => 160],
            // Published material
            'published_text'     => ['x' => 105, 'y' => 123, 'size' => 9,  'align' => 'center', 'color' => '#333333', 'max_width' => 180],
            'journal_name'       => ['x' => 105, 'y' => 131, 'size' => 11, 'font_weight' => 'bold', 'align' => 'center', 'color' => '#0077FF', 'max_width' => 180],
            // Publication title
            'pub_title_label'    => ['x' => 105, 'y' => 140, 'size' => 9,  'align' => 'center', 'color' => '#333333', 'max_width' => 180],
            'publication_title'  => ['x' => 105, 'y' => 148, 'size' => 10, 'align' => 'center', 'color' => '#000000', 'max_width' => 170],
            // Details section (left-aligned)
            'org_line'           => ['x' => 28,  'y' => 165, 'size' => 8,  'align' => 'left', 'color' => '#000000', 'max_width' => 160],
            'city_line'          => ['x' => 28,  'y' => 172, 'size' => 8,  'align' => 'left', 'color' => '#000000', 'max_width' => 160],
            'position_line'      => ['x' => 28,  'y' => 179, 'size' => 8,  'align' => 'left', 'color' => '#000000', 'max_width' => 160],
            'direction_line'     => ['x' => 28,  'y' => 186, 'size' => 8,  'align' => 'left', 'color' => '#000000', 'max_width' => 160],
            // Certificate number
            'certificate_number' => ['x' => 105, 'y' => 203, 'size' => 7,  'align' => 'center', 'color' => '#94A3B8', 'max_width' => 100],
            // Chairman signature (right side)
            'chairman_label'     => ['x' => 141, 'y' => 233, 'size' => 7,  'align' => 'left', 'color' => '#000000', 'max_width' => 60],
            'chairman_name'      => ['x' => 141, 'y' => 239, 'size' => 7,  'font_weight' => 'bold', 'align' => 'left', 'color' => '#000000', 'max_width' => 60],
            // Date (bottom left)
            'date_line'          => ['x' => 27,  'y' => 249, 'size' => 7,  'align' => 'left', 'color' => '#000000', 'max_width' => 40],
            // Footer
            'footer_company'     => ['x' => 105, 'y' => 261, 'size' => 7,  'align' => 'center', 'color' => '#64748B', 'max_width' => 180],
            'footer_license'     => ['x' => 105, 'y' => 266, 'size' => 6,  'align' => 'center', 'color' => '#94A3B8', 'max_width' => 180],
            'footer_site'        => ['x' => 105, 'y' => 272, 'size' => 6,  'align' => 'center', 'color' => '#94A3B8', 'max_width' => 180]
        ];
    }

    /**
     * Generate unique certificate number
     * @return string Certificate number
     */
    private function generateCertificateNumber() {
        $year = date('Y');
        $prefix = 'ПУБ';

        // Get last number for this year
        $result = $this->db->queryOne(
            "SELECT certificate_number FROM publication_certificates
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
            "SELECT id FROM publication_certificates WHERE id = ? AND user_id = ?",
            [$certificateId, $userId]
        );
        return !empty($result);
    }
}
