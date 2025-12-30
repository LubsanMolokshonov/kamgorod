<?php
/**
 * DiplomaPreview Class
 * Generates dynamic SVG diploma previews with real user data
 */

class DiplomaPreview
{
    private $templatePath;
    private $data;

    public function __construct($templateId, $data)
    {
        $this->templatePath = __DIR__ . '/../assets/images/diplomas/templates/diploma-template-' . $templateId . '.svg';
        $this->data = $data;
    }

    /**
     * Generate SVG with dynamic data
     * @return string SVG content
     */
    public function generate()
    {
        // Check if template exists
        if (!file_exists($this->templatePath)) {
            return $this->getErrorSVG('Шаблон не найден');
        }

        // Load template
        $svg = file_get_contents($this->templatePath);

        // Load as DOMDocument for manipulation
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;

        // Suppress warnings for invalid SVG structure
        libxml_use_internal_errors(true);
        $dom->loadXML($svg);
        libxml_clear_errors();

        // Update text elements with real data
        $this->updateTextContent($dom);

        return $dom->saveXML();
    }

    /**
     * Update text content in SVG based on form data
     */
    private function updateTextContent($dom)
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('svg', 'http://www.w3.org/2000/svg');

        // Update participant name
        if (!empty($this->data['fio'])) {
            $this->updateTextByContent($xpath, 'Иванов Иван Иванович', $this->data['fio']);
        }

        // Update competition type
        if (!empty($this->data['competition_type'])) {
            $competitionText = mb_convert_case($this->data['competition_type'], MB_CASE_TITLE, 'UTF-8') . ' конкурс';
            $this->updateTextByContent($xpath, 'Всероссийский конкурс', $competitionText);
            // Also try other patterns
            $this->updateTextByContent($xpath, 'Международный конкурс', $competitionText);
            $this->updateTextByContent($xpath, 'Межрегиональный конкурс', $competitionText);
        }

        // Update nomination
        if (!empty($this->data['nomination'])) {
            $this->updateTextByContent($xpath, 'Творческая работа', $this->data['nomination']);
        }

        // Update work title
        if (!empty($this->data['work_title'])) {
            $workTitle = '«' . $this->data['work_title'] . '»';
            $this->updateTextByContent($xpath, '«Название творческой работы»', $workTitle);
        }

        // Update placement
        if (!empty($this->data['placement'])) {
            $placementText = $this->data['placement'];
            if (is_numeric($placementText)) {
                $placementText = $placementText . ' место';
            }
            $this->updateTextByContent($xpath, '1 место', $placementText);
        }

        // Update competition name (from database)
        if (!empty($this->data['competition_name'])) {
            $this->updateTextByContent($xpath, '«Название конкурса»', '«' . $this->data['competition_name'] . '»');
        }

        // Update organization
        if (!empty($this->data['organization'])) {
            $this->updateTextByContent($xpath, 'Учреждение: Название учреждения', 'Учреждение: ' . $this->data['organization']);
        }

        // Update city
        if (!empty($this->data['city'])) {
            $this->updateTextByContent($xpath, 'Населенный пункт: Населенный пункт', 'Населенный пункт: ' . $this->data['city']);
        }

        // Update supervisor name
        if (!empty($this->data['supervisor_name'])) {
            // Try different supervisor text patterns in templates
            $this->updateTextByContent($xpath, 'Руководитель: Иванова Мария Петровна', 'Руководитель: ' . $this->data['supervisor_name']);
            $this->updateTextByContent($xpath, 'Иванова Мария Петровна', $this->data['supervisor_name']);
        }
    }

    /**
     * Update text element by searching for specific content
     */
    private function updateTextByContent($xpath, $searchText, $newText)
    {
        // Escape special characters for XPath
        $searchText = $this->escapeXPath($searchText);

        // Find all text elements
        $textElements = $xpath->query("//svg:text[contains(text(), " . $searchText . ")]");

        if ($textElements->length > 0) {
            foreach ($textElements as $element) {
                $element->nodeValue = $this->truncateText($newText, 55);
            }
        }
    }

    /**
     * Escape text for XPath query
     */
    private function escapeXPath($text)
    {
        // If text contains both single and double quotes
        if (strpos($text, "'") !== false && strpos($text, '"') !== false) {
            return 'concat("' . str_replace('"', '", \'"\', "', $text) . '")';
        }

        // If text contains single quotes
        if (strpos($text, "'") !== false) {
            return '"' . $text . '"';
        }

        // Default: use single quotes
        return "'" . $text . "'";
    }

    /**
     * Truncate text to specified length
     */
    private function truncateText($text, $maxLength)
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }
        return mb_substr($text, 0, $maxLength - 3) . '...';
    }

    /**
     * Generate error SVG
     */
    private function getErrorSVG($message)
    {
        return <<<SVG
<svg width="600" height="848" viewBox="0 0 600 848" xmlns="http://www.w3.org/2000/svg">
  <rect width="600" height="848" fill="#f3f4f6"/>
  <text x="300" y="424" font-family="Arial, sans-serif" font-size="18" fill="#ef4444" text-anchor="middle">{$message}</text>
</svg>
SVG;
    }

    /**
     * Get base64 encoded data URI
     */
    public function getDataUri()
    {
        $svg = $this->generate();
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
