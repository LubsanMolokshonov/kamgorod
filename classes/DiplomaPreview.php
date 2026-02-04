<?php
/**
 * DiplomaPreview Class
 * Generates dynamic SVG diploma previews with real user data
 * Uses background SVG templates and overlays text dynamically
 *
 * SYNCHRONIZED with Diploma.php for identical output
 * Conversion factor: 1mm = 2.834px (A4: 210x297mm → SVG: 595x842px)
 */

class DiplomaPreview
{
    private $templateId;
    private $data;
    private $recipientType;

    // SVG dimensions (match A4 aspect ratio)
    const WIDTH = 595;
    const HEIGHT = 842;

    // Conversion factor: mm to px
    const MM_TO_PX = 2.834;

    /**
     * Field positions and styles - SYNCHRONIZED with Diploma.php
     * All sizes in pt (same for SVG and PDF)
     * Positions calculated from mm (PDF) to px (SVG) using MM_TO_PX
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
     * 12. supervisor_label - Supervisor name (bottom right, participant only)
     */
    private $fieldPositions = [
        // === HEADER SECTION ===
        'diploma_title' => [
            'x' => 297.5,           // 105mm * 2.834 = center
            'y' => 215,             // 76mm * 2.834
            'size' => 36,           // Larger for impact
            'font_weight' => 'bold',
            'font_style' => 'normal', // No italic
            'color' => '#0077FF',   // Blue #0077FF
            'anchor' => 'middle',
            'max_length' => 20
        ],
        'diploma_subtitle' => [
            'x' => 297.5,
            'y' => 260,             // 90mm * 2.834
            'size' => 18,           // SYNCED with PDF
            'font_weight' => 'bold',
            'color' => '#000000',   // Black
            'anchor' => 'middle',
            'max_length' => 30
        ],

        // === AWARD SECTION ===
        'award_text' => [
            'x' => 297.5,
            'y' => 306,             // 108mm * 2.834
            'size' => 12,           // SYNCED with PDF
            'color' => '#000000',   // Black
            'anchor' => 'middle',
            'max_length' => 30
        ],

        // === MAIN FOCUS: RECIPIENT NAME ===
        'fio' => [
            'x' => 297.5,
            'y' => 349,             // 123mm * 2.834
            'size' => 22,           // Larger for emphasis
            'font_weight' => 'bold',
            'color' => '#000000',   // Black
            'anchor' => 'middle',
            'max_length' => 55
        ],

        // === ACHIEVEMENT DESCRIPTION ===
        'achievement_text' => [
            'x' => 297.5,
            'y' => 400,             // 141mm * 2.834
            'size' => 11,           // SYNCED with PDF
            'font_style' => 'italic',
            'color' => '#000000',   // Black
            'anchor' => 'middle',
            'max_length' => 50
        ],

        // === COMPETITION INFO ===
        'competition_type' => [
            'x' => 297.5,
            'y' => 434,             // 153mm * 2.834
            'size' => 14,           // SYNCED with PDF
            'font_weight' => 'bold',
            'color' => '#0077FF',   // Blue #0077FF
            'anchor' => 'middle',
            'max_length' => 40
        ],
        'competition_name' => [
            'x' => 297.5,           // center
            'y' => 465,             // 164mm * 2.834
            'size' => 12,           // SYNCED with PDF
            'color' => '#000000',   // Black
            'anchor' => 'middle',
            'max_length' => 65
        ],

        // === WORK DETAILS ===
        'work_title_quoted' => [
            'x' => 297.5,
            'y' => 496,             // 175mm * 2.834 (shifted from 164mm)
            'size' => 12,           // SYNCED with PDF
            'color' => '#000000',   // Black
            'anchor' => 'middle',
            'max_length' => 60
        ],
        'nomination_line' => [
            'x' => 297.5,
            'y' => 523,             // 184.5mm * 2.834 (shifted from 173.5mm)
            'size' => 12,           // SYNCED with PDF
            'font_style' => 'italic',
            'color' => '#000000',   // Black
            'anchor' => 'middle',
            'max_length' => 60
        ],

        // === METADATA SECTION (centered, under nomination) ===
        'organization' => [
            'x' => 297.5,           // center
            'y' => 555,             // under nomination
            'size' => 10,           // SYNCED with PDF
            'color' => '#000000',   // Black
            'anchor' => 'middle',
            'max_length' => 70
        ],
        'city' => [
            'x' => 297.5,           // center
            'y' => 580,             // under organization
            'size' => 10,           // SYNCED with PDF
            'color' => '#000000',   // Black
            'anchor' => 'middle',
            'max_length' => 50
        ],
        'supervisor_label' => [
            'x' => 297.5,           // center
            'y' => 605,             // under city
            'size' => 10,           // SYNCED with PDF
            'color' => '#000000',   // Black
            'anchor' => 'middle',
            'max_length' => 55
        ],

        // === FOOTER (date bottom left) ===
        'participation_date' => [
            'x' => 77,              // 27mm * 2.834
            'y' => 706,             // 249mm * 2.834
            'size' => 9,            // SYNCED with PDF
            'color' => '#000000',   // Black
            'anchor' => 'start',
            'max_length' => 20
        ],

        // === CHAIRMAN SIGNATURE BLOCK (bottom right) ===
        'chairman_label' => [
            'x' => 400,             // Right side (moved left)
            'y' => 660,             // Bottom area (moved up)
            'size' => 9,
            'color' => '#000000',   // Black
            'anchor' => 'middle',
            'max_length' => 30
        ],
        'chairman_name' => [
            'x' => 400,
            'y' => 678,
            'size' => 9,
            'font_weight' => 'bold',
            'color' => '#000000',   // Black
            'anchor' => 'middle',
            'max_length' => 25
        ]
    ];

    // Path to chairman stamp image
    const CHAIRMAN_STAMP_PATH = '/assets/images/diplomas/stamp-brehach.png';

    public function __construct($templateId, $data, $recipientType = 'participant')
    {
        $this->templateId = $templateId;
        $this->data = $data;
        $this->recipientType = $recipientType;
    }

    /**
     * Generate SVG with dynamic data
     * @return string SVG content
     */
    public function generate()
    {
        // Load background SVG
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
     * Generate text overlay for SVG
     * Logic synchronized with Diploma.php::buildDiplomaHTML()
     */
    private function generateTextOverlay()
    {
        $svg = "\n<!-- Dynamic text overlay - synchronized with PDF generation -->\n";
        $svg .= '<g id="text-overlay">' . "\n";

        // 1. ДИПЛОМ - Main title
        $svg .= $this->createTextElement('ДИПЛОМ', $this->fieldPositions['diploma_title']);

        // 2. ПОБЕДИТЕЛЯ / УЧАСТНИКА / РУКОВОДИТЕЛЯ - Subtitle
        $subtitle = $this->getDiplomaSubtitle();
        $svg .= $this->createTextElement($subtitle, $this->fieldPositions['diploma_subtitle']);

        // 3. награждается - Award text
        $svg .= $this->createTextElement('награждается', $this->fieldPositions['award_text']);

        // 4. ФИО - Main focus (recipient name)
        $fio = $this->getFIO();
        if (!empty($fio)) {
            $svg .= $this->createTextElement($fio, $this->fieldPositions['fio']);
        }

        // 5. Achievement text (за участие/достижения/подготовку)
        $achievement = $this->getAchievementText();
        $svg .= $this->createTextElement($achievement, $this->fieldPositions['achievement_text']);

        // 6. Competition type (ВСЕРОССИЙСКИЙ КОНКУРС)
        $competitionType = $this->getCompetitionType();
        if (!empty($competitionType)) {
            $svg .= $this->createTextElement($competitionType, $this->fieldPositions['competition_type']);
        }

        // 6.5. Competition name (название конкурса из БД)
        $competitionName = $this->data['competition_name'] ?? '';
        if (!empty($competitionName)) {
            $svg .= $this->createTextElement('«' . $competitionName . '»', $this->fieldPositions['competition_name']);
        }

        // 7. Work title in quotes (if exists) - NEW LOGIC
        $workTitle = $this->data['work_title'] ?? '';
        if (!empty($workTitle)) {
            $svg .= $this->createTextElement('«' . $workTitle . '»', $this->fieldPositions['work_title_quoted']);
        }

        // 8. Nomination line - always show if nomination exists - NEW FIELD
        $nomination = $this->data['nomination'] ?? '';
        if (!empty($nomination)) {
            $nominationText = 'в номинации «' . $nomination . '»';
            $svg .= $this->createTextElement($nominationText, $this->fieldPositions['nomination_line']);
        }

        // 9. Organization
        $organization = $this->getOrganization();
        if (!empty($organization)) {
            $svg .= $this->createTextElement('Учреждение: ' . $organization, $this->fieldPositions['organization']);
        }

        // 10. City
        $city = $this->getCity();
        if (!empty($city)) {
            $svg .= $this->createTextElement('Населенный пункт: ' . $city, $this->fieldPositions['city']);
        }

        // 11. Supervisor label (centered, under city) - only for participant diploma with supervisor
        if ($this->recipientType === 'participant' && !empty($this->data['supervisor_name'])) {
            $svg .= $this->createTextElement('Руководитель: ' . $this->data['supervisor_name'], $this->fieldPositions['supervisor_label']);
        }

        // 12. Participation date (bottom left)
        $date = $this->getParticipationDate();
        if (!empty($date)) {
            $svg .= $this->createTextElement($date, $this->fieldPositions['participation_date']);
        }

        // 13. Chairman signature block with stamp (bottom right)
        $svg .= $this->createChairmanSignatureBlock();

        $svg .= '</g>' . "\n";

        return $svg;
    }

    /**
     * Create chairman signature block with stamp image
     * Adds "Председатель Оргкомитета Брехач Р.А." with stamp
     */
    private function createChairmanSignatureBlock()
    {
        $svg = "\n<!-- Chairman signature block -->\n";
        $svg .= '<g id="chairman-signature">' . "\n";

        // Add stamp image (positioned to the left of text)
        $stampPath = __DIR__ . '/..' . self::CHAIRMAN_STAMP_PATH;
        if (file_exists($stampPath)) {
            $stampData = base64_encode(file_get_contents($stampPath));
            $svg .= sprintf(
                '<image x="%d" y="%d" width="%d" height="%d" href="data:image/png;base64,%s" />' . "\n",
                320,    // x position (moved left)
                620,    // y position (moved up)
                160,    // width
                100,    // height
                $stampData
            );
        }

        // Add chairman label text
        $svg .= $this->createTextElement('Председатель Оргкомитета', $this->fieldPositions['chairman_label']);

        // Add chairman name
        $svg .= $this->createTextElement('Брехач Р.А.', $this->fieldPositions['chairman_name']);

        $svg .= '</g>' . "\n";

        return $svg;
    }

    /**
     * Create SVG text element with styling
     */
    private function createTextElement($text, $position)
    {
        $x = $position['x'] ?? self::WIDTH / 2;
        $y = $position['y'] ?? 100;
        $size = $position['size'] ?? 14;
        $fontWeight = $position['font_weight'] ?? 'normal';
        $fontStyle = $position['font_style'] ?? 'normal';
        $color = $position['color'] ?? '#000000';
        $anchor = $position['anchor'] ?? 'middle';
        $maxLength = $position['max_length'] ?? 50;

        // Truncate long text
        $text = $this->truncateText($text, $maxLength);

        // Escape text for XML
        $escapedText = htmlspecialchars($text, ENT_XML1, 'UTF-8');

        // Use DejaVu Sans for Cyrillic support (matches PDF)
        return sprintf(
            '<text x="%s" y="%s" font-family="DejaVu Sans, Arial, sans-serif" font-size="%dpt" font-weight="%s" font-style="%s" fill="%s" text-anchor="%s">%s</text>' . "\n",
            $x, $y, $size, $fontWeight, $fontStyle, $color, $anchor, $escapedText
        );
    }

    /**
     * Get diploma subtitle based on recipient type and placement
     */
    private function getDiplomaSubtitle()
    {
        if ($this->recipientType === 'supervisor') {
            return 'РУКОВОДИТЕЛЯ';
        }

        $placement = $this->data['placement'] ?? '';

        if (in_array($placement, ['1', '2', '3', 1, 2, 3])) {
            return 'ПОБЕДИТЕЛЯ';
        }

        return 'УЧАСТНИКА';
    }

    /**
     * Get achievement text based on recipient type and placement
     */
    private function getAchievementText()
    {
        if ($this->recipientType === 'supervisor') {
            return 'за подготовку участника конкурса';
        }

        $placement = $this->data['placement'] ?? '';

        if (in_array($placement, ['1', '2', '3', 1, 2, 3])) {
            return 'за высокие достижения в конкурсе';
        }

        return 'за участие в конкурсе';
    }

    /**
     * Get FIO based on recipient type
     */
    private function getFIO()
    {
        if ($this->recipientType === 'supervisor') {
            return $this->data['supervisor_name'] ?? $this->data['fio'] ?? '';
        }
        return $this->data['fio'] ?? '';
    }

    /**
     * Get organization based on recipient type
     */
    private function getOrganization()
    {
        if ($this->recipientType === 'supervisor') {
            return $this->data['supervisor_organization'] ?? $this->data['organization'] ?? '';
        }
        return $this->data['organization'] ?? '';
    }

    /**
     * Get city (same for both recipient types in current implementation)
     */
    private function getCity()
    {
        if ($this->recipientType === 'supervisor') {
            return $this->data['supervisor_city'] ?? $this->data['city'] ?? '';
        }
        return $this->data['city'] ?? '';
    }

    /**
     * Get competition type in uppercase with "КОНКУРС" suffix
     */
    private function getCompetitionType()
    {
        $type = $this->data['competition_type'] ?? '';
        if (empty($type)) {
            return '';
        }
        return mb_strtoupper($type, 'UTF-8') . ' КОНКУРС';
    }

    /**
     * Get formatted participation date
     */
    private function getParticipationDate()
    {
        $date = $this->data['participation_date'] ?? '';
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
     * Truncate text to specified length with ellipsis
     */
    private function truncateText($text, $maxLength)
    {
        if (mb_strlen($text, 'UTF-8') <= $maxLength) {
            return $text;
        }
        return mb_substr($text, 0, $maxLength - 3, 'UTF-8') . '...';
    }

    /**
     * Generate error SVG when template not found
     */
    private function getErrorSVG($message)
    {
        return sprintf(
            '<svg width="%d" height="%d" viewBox="0 0 %d %d" xmlns="http://www.w3.org/2000/svg">
  <rect width="%d" height="%d" fill="#f3f4f6"/>
  <text x="%d" y="%d" font-family="Arial, sans-serif" font-size="18" fill="#ef4444" text-anchor="middle">%s</text>
</svg>',
            self::WIDTH, self::HEIGHT, self::WIDTH, self::HEIGHT,
            self::WIDTH, self::HEIGHT,
            self::WIDTH / 2, self::HEIGHT / 2,
            htmlspecialchars($message, ENT_XML1, 'UTF-8')
        );
    }

    /**
     * Get base64 encoded data URI for embedding in img tag
     */
    public function getDataUri()
    {
        $svg = $this->generate();
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
