<?php
/**
 * CertificatePreview Class
 * Generates dynamic SVG certificate previews with real user data
 * Uses background SVG templates and overlays text dynamically
 *
 * Pattern: matches DiplomaPreview.php architecture
 * Conversion factor: 1mm = 2.834px (A4: 210x297mm = SVG: 595x842px)
 */

class CertificatePreview
{
    private $templateId;
    private $data;

    // SVG dimensions (A4 aspect ratio)
    const WIDTH = 595;
    const HEIGHT = 842;
    const MM_TO_PX = 2.834;

    /**
     * Per-template color schemes
     * Each template has: header gradient, accent color, text accent, footer gradient
     */
    private $templateColors = [
        1 => [
            'header_from' => '#1E3A5F', 'header_to' => '#2C5282',
            'accent' => '#1E3A5F', 'accent_light' => '#E2E8F0',
            'gold_from' => '#F6E05E', 'gold_mid' => '#ECC94B', 'gold_to' => '#D69E2E',
            'bg' => '#f8fafc', 'border' => '#1E3A5F',
            'info_bg' => '#F1F5F9', 'info_border' => '#E2E8F0',
            'name_bg' => 'white', 'name_border' => '#CBD5E1',
            'qr_accent' => '#1E3A5F'
        ],
        2 => [
            'header_from' => '#059669', 'header_to' => '#047857',
            'accent' => '#059669', 'accent_light' => '#D1FAE5',
            'gold_from' => '#FBBF24', 'gold_mid' => '#F59E0B', 'gold_to' => '#D97706',
            'bg' => '#ECFDF5', 'border' => '#059669',
            'info_bg' => '#F0FDF4', 'info_border' => '#A7F3D0',
            'name_bg' => '#F0FDF4', 'name_border' => '#A7F3D0',
            'qr_accent' => '#059669'
        ],
        3 => [
            'header_from' => '#7C3AED', 'header_to' => '#6D28D9',
            'accent' => '#7C3AED', 'accent_light' => '#EDE9FE',
            'gold_from' => '#F9A8D4', 'gold_mid' => '#F472B6', 'gold_to' => '#EC4899',
            'bg' => '#FAF5FF', 'border' => '#7C3AED',
            'info_bg' => '#F5F3FF', 'info_border' => '#DDD6FE',
            'name_bg' => '#F5F3FF', 'name_border' => '#C4B5FD',
            'qr_accent' => '#7C3AED'
        ],
        4 => [
            'header_from' => '#DC2626', 'header_to' => '#B91C1C',
            'accent' => '#DC2626', 'accent_light' => '#FEE2E2',
            'gold_from' => '#FCD34D', 'gold_mid' => '#FBBF24', 'gold_to' => '#F59E0B',
            'bg' => '#FEF2F2', 'border' => '#DC2626',
            'info_bg' => '#FEF2F2', 'info_border' => '#FECACA',
            'name_bg' => '#FEF2F2', 'name_border' => '#FCA5A5',
            'qr_accent' => '#DC2626'
        ],
        5 => [
            'header_from' => '#EA580C', 'header_to' => '#C2410C',
            'accent' => '#EA580C', 'accent_light' => '#FED7AA',
            'gold_from' => '#FDE68A', 'gold_mid' => '#FCD34D', 'gold_to' => '#FBBF24',
            'bg' => '#FFF7ED', 'border' => '#EA580C',
            'info_bg' => '#FFF7ED', 'info_border' => '#FDBA74',
            'name_bg' => '#FFF7ED', 'name_border' => '#FB923C',
            'qr_accent' => '#EA580C'
        ],
        6 => [
            'header_from' => '#0D9488', 'header_to' => '#0F766E',
            'accent' => '#0D9488', 'accent_light' => '#CCFBF1',
            'gold_from' => '#5EEAD4', 'gold_mid' => '#2DD4BF', 'gold_to' => '#14B8A6',
            'bg' => '#F0FDFA', 'border' => '#0D9488',
            'info_bg' => '#F0FDFA', 'info_border' => '#99F6E4',
            'name_bg' => '#F0FDFA', 'name_border' => '#5EEAD4',
            'qr_accent' => '#0D9488'
        ]
    ];

    public function __construct($templateId, $data)
    {
        $this->templateId = $templateId;
        $this->data = $data;
    }

    /**
     * Generate SVG with dynamic data
     * @return string SVG content
     */
    public function generate()
    {
        $backgroundPath = $this->getBackgroundPath();

        if (!file_exists($backgroundPath)) {
            return $this->getErrorSVG('Шаблон не найден: ' . $this->templateId);
        }

        $svg = file_get_contents($backgroundPath);

        // Generate text overlay
        $textOverlay = $this->generateTextOverlay();

        // Insert text overlay before closing </svg> tag
        $svg = str_replace('</svg>', $textOverlay . '</svg>', $svg);

        return $svg;
    }

    /**
     * Get path to background SVG template
     */
    private function getBackgroundPath()
    {
        return __DIR__ . '/../assets/images/diplomas/templates/backgrounds/template-' . $this->templateId . '.svg';
    }

    /**
     * Get color scheme for current template
     */
    private function getColors()
    {
        return $this->templateColors[$this->templateId] ?? $this->templateColors[1];
    }

    /**
     * Generate text overlay for SVG
     * Layout: content starts below logo area (~y=195), matches DiplomaPreview.php pattern
     * Synchronized with PublicationCertificate.php PDF positions
     */
    private function generateTextOverlay()
    {
        $c = $this->getColors();
        $svg = "\n<!-- Dynamic certificate text overlay -->\n";

        $svg .= '<g id="certificate-overlay">' . "\n";

        // === TITLE (below logo area, +30px gap from logo) ===
        $svg .= $this->createText('СВИДЕТЕЛЬСТВО', 297.5, 230, 34, 'bold', '#0077FF', 'DejaVu Sans, Arial, sans-serif');
        $svg .= $this->createText('О ПУБЛИКАЦИИ', 297.5, 262, 17, 'normal', '#000000', 'DejaVu Sans, Arial, sans-serif');

        // === CONTENT ===

        // Confirmation text
        $svg .= $this->createText(
            'Настоящее свидетельство подтверждает, что',
            297.5, 290, 11, 'normal', '#333333', 'DejaVu Sans, Arial, sans-serif'
        );

        // Author name
        $authorName = $this->data['author_name'] ?? '';
        $svg .= $this->createText(
            !empty($authorName) ? $this->truncateText($authorName, 45) : 'ФИО автора',
            297.5, 320, 18, 'bold', '#000000', 'DejaVu Sans, Arial, sans-serif'
        );

        // Published material text
        $svg .= $this->createText(
            'опубликовал(а) материал в электронном журнале',
            297.5, 348, 11, 'normal', '#333333', 'DejaVu Sans, Arial, sans-serif'
        );

        // Journal name
        $svg .= $this->createText(
            '«ФГОС-Практикум»',
            297.5, 372, 14, 'bold', '#0077FF', 'DejaVu Sans, Arial, sans-serif'
        );

        // Publication title label
        $svg .= $this->createText(
            'Название публикации:',
            297.5, 398, 11, 'normal', '#333333', 'DejaVu Sans, Arial, sans-serif'
        );

        // Publication title (wrap to 2 lines)
        $pubTitle = $this->data['publication_title'] ?? '';
        if (!empty($pubTitle)) {
            $lines = $this->wrapText('«' . $pubTitle . '»', 52);
            $svg .= $this->createText(
                $lines[0] ?? '', 297.5, 420, 12, 'normal', '#000000', 'DejaVu Sans, Arial, sans-serif'
            );
            if (isset($lines[1])) {
                $svg .= $this->createText(
                    $lines[1], 297.5, 437, 12, 'normal', '#000000', 'DejaVu Sans, Arial, sans-serif'
                );
            }
        } else {
            $svg .= $this->createText(
                '«Название работы»', 297.5, 420, 12, 'normal', '#000000', 'DejaVu Sans, Arial, sans-serif'
            );
        }

        // === DETAILS SECTION ===
        $detailsX = 79;
        $detailsY = 468;
        $lineSpacing = 21;

        $organization = $this->data['organization'] ?? '';
        $svg .= $this->createText(
            'Учреждение: ' . $this->truncateText($organization, 55),
            $detailsX, $detailsY, 10, 'normal', '#000000', 'DejaVu Sans, Arial, sans-serif', 'start'
        );

        $detailsY += $lineSpacing;
        $city = $this->data['city'] ?? '';
        $svg .= $this->createText(
            'Населенный пункт: ' . $this->truncateText($city, 50),
            $detailsX, $detailsY, 10, 'normal', '#000000', 'DejaVu Sans, Arial, sans-serif', 'start'
        );

        $detailsY += $lineSpacing;
        $position = $this->data['position'] ?? '';
        $svg .= $this->createText(
            'Должность: ' . $this->truncateText($position, 55),
            $detailsX, $detailsY, 10, 'normal', '#000000', 'DejaVu Sans, Arial, sans-serif', 'start'
        );

        $detailsY += $lineSpacing;
        $direction = $this->data['direction'] ?? ($this->data['publication_type'] ?? '');
        $svg .= $this->createText(
            'Направление: ' . $this->truncateText($direction, 55),
            $detailsX, $detailsY, 10, 'normal', '#000000', 'DejaVu Sans, Arial, sans-serif', 'start'
        );

        // === CERTIFICATE NUMBER ===
        $certNum = $this->data['certificate_number'] ?? ('ПУБ-' . date('Y') . '-XXXXXX');
        $svg .= $this->createText(
            'Свидетельство № ' . $certNum,
            297.5, 575, 9, 'normal', '#94A3B8', 'DejaVu Sans, Arial, sans-serif'
        );

        // === STAMP + CHAIRMAN SIGNATURE (like DiplomaPreview) ===
        $svg .= $this->createChairmanSignatureBlock();

        // === DATE (bottom left) ===
        $pubDate = $this->formatDate($this->data['publication_date'] ?? '');
        $svg .= $this->createText(
            $pubDate,
            77, 706, 9, 'normal', '#000000', 'DejaVu Sans, Arial, sans-serif', 'start'
        );

        // === FOOTER ===
        $svg .= $this->createText(
            'ООО «Едурегионлаб» | ИНН 5904368615',
            297.5, 740, 9, 'normal', '#64748B', 'DejaVu Sans, Arial, sans-serif'
        );
        $svg .= $this->createText(
            'Лицензия № Л035-01212-59/00203856 от 17.12.2021',
            297.5, 755, 8, 'normal', '#94A3B8', 'DejaVu Sans, Arial, sans-serif'
        );
        $svg .= $this->createText(
            'fgos.pro',
            297.5, 770, 8, 'normal', '#94A3B8', 'DejaVu Sans, Arial, sans-serif'
        );

        $svg .= '</g>' . "\n";

        return $svg;
    }

    /**
     * Create chairman signature block with stamp image (matches DiplomaPreview.php)
     */
    private function createChairmanSignatureBlock()
    {
        $svg = '';

        // Stamp image (same as DiplomaPreview)
        $stampPath = __DIR__ . '/../assets/images/diplomas/stamp-brehach.png';
        if (file_exists($stampPath)) {
            $stampData = base64_encode(file_get_contents($stampPath));
            $svg .= sprintf(
                '<image x="%d" y="%d" width="%d" height="%d" href="data:image/png;base64,%s" />' . "\n",
                320, 620, 160, 100, $stampData
            );
        }

        // Chairman label and name
        $svg .= $this->createText(
            'Председатель Оргкомитета',
            400, 660, 9, 'normal', '#000000', 'DejaVu Sans, Arial, sans-serif', 'start'
        );
        $svg .= $this->createText(
            'Брехач Р.А.',
            400, 678, 9, 'bold', '#000000', 'DejaVu Sans, Arial, sans-serif', 'start'
        );

        return $svg;
    }

    /**
     * Create SVG text element
     */
    private function createText($text, $x, $y, $size, $fontWeight, $color, $fontFamily, $anchor = 'middle')
    {
        $escapedText = htmlspecialchars($text, ENT_XML1, 'UTF-8');
        return sprintf(
            '<text x="%s" y="%s" font-family="%s" font-size="%d" font-weight="%s" fill="%s" text-anchor="%s">%s</text>' . "\n",
            $x, $y, $fontFamily, $size, $fontWeight, $color, $anchor, $escapedText
        );
    }

    /**
     * Word-wrap text into lines
     * @param string $text Text to wrap
     * @param int $maxChars Max chars per line
     * @return array Lines
     */
    private function wrapText($text, $maxChars)
    {
        if (mb_strlen($text, 'UTF-8') <= $maxChars) {
            return [$text];
        }

        $words = explode(' ', $text);
        $lines = [];
        $currentLine = '';

        foreach ($words as $word) {
            if (mb_strlen($currentLine . ' ' . $word, 'UTF-8') <= $maxChars || empty($currentLine)) {
                $currentLine = empty($currentLine) ? $word : $currentLine . ' ' . $word;
            } else {
                $lines[] = $currentLine;
                $currentLine = $word;
                if (count($lines) >= 2) {
                    break;
                }
            }
        }

        if (!empty($currentLine) && count($lines) < 2) {
            $lines[] = $currentLine;
        }

        // If second line is too long, truncate with ellipsis
        if (isset($lines[1]) && mb_strlen($lines[1], 'UTF-8') > $maxChars) {
            $lines[1] = $this->truncateText($lines[1], $maxChars);
        }

        return $lines;
    }

    /**
     * Truncate text with ellipsis
     */
    private function truncateText($text, $maxLength)
    {
        if (mb_strlen($text, 'UTF-8') <= $maxLength) {
            return $text;
        }
        return mb_substr($text, 0, $maxLength - 3, 'UTF-8') . '...';
    }

    /**
     * Format date to dd.mm.yyyy
     */
    private function formatDate($date)
    {
        if (empty($date)) {
            return date('d.m.Y');
        }
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return $date;
        }
        return date('d.m.Y', $timestamp);
    }

    /**
     * Generate error SVG
     */
    private function getErrorSVG($message)
    {
        return sprintf(
            '<svg width="%d" height="%d" viewBox="0 0 %d %d" xmlns="http://www.w3.org/2000/svg">'
            . '<rect width="%d" height="%d" fill="#f3f4f6"/>'
            . '<text x="%d" y="%d" font-family="Arial, sans-serif" font-size="16" fill="#ef4444" text-anchor="middle">%s</text>'
            . '</svg>',
            self::WIDTH, self::HEIGHT, self::WIDTH, self::HEIGHT,
            self::WIDTH, self::HEIGHT,
            self::WIDTH / 2, self::HEIGHT / 2,
            htmlspecialchars($message, ENT_XML1, 'UTF-8')
        );
    }

    /**
     * Get base64 data URI for embedding in img tag
     */
    public function getDataUri()
    {
        $svg = $this->generate();
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
