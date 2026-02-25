<?php
/**
 * DocumentExtractor Class
 * Extracts text and HTML content from PDF, DOCX, and DOC files
 */

class DocumentExtractor {

    /**
     * Extract plain text from a file
     * @param string $filePath Absolute path to the file
     * @return string Extracted text
     */
    public function extractText(string $filePath): string {
        $ext = $this->getFileExtension($filePath);

        switch ($ext) {
            case 'docx':
                return $this->extractTextFromDocx($filePath);
            case 'pdf':
                return $this->extractTextFromPdf($filePath);
            case 'doc':
                return $this->extractTextFromDoc($filePath);
            default:
                throw new Exception("Unsupported file type: {$ext}");
        }
    }

    /**
     * Extract HTML content from a file
     * @param string $filePath Absolute path to the file
     * @return string Sanitized HTML content
     */
    public function extractHtml(string $filePath): string {
        $ext = $this->getFileExtension($filePath);

        switch ($ext) {
            case 'docx':
                return $this->extractHtmlFromDocx($filePath);
            case 'pdf':
                return $this->extractHtmlFromPdf($filePath);
            case 'doc':
                return $this->extractHtmlFromDoc($filePath);
            default:
                throw new Exception("Unsupported file type: {$ext}");
        }
    }

    /**
     * Get file extension (lowercase)
     */
    private function getFileExtension(string $filePath): string {
        // Try MIME type first for tmp files without extension
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $filePath);
        finfo_close($finfo);

        $mimeMap = [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
        ];

        if (isset($mimeMap[$mime])) {
            return $mimeMap[$mime];
        }

        // DOCX files are ZIP archives — finfo may detect them as application/zip or application/octet-stream
        if (in_array($mime, ['application/zip', 'application/octet-stream', 'application/x-zip-compressed'])) {
            // Check if it's a DOCX by looking for word/document.xml inside
            $zip = new ZipArchive();
            if ($zip->open($filePath) === true) {
                $hasDocXml = ($zip->locateName('word/document.xml') !== false);
                $zip->close();
                if ($hasDocXml) {
                    return 'docx';
                }
            }
        }

        // Fallback to extension
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (!empty($ext)) {
            return $ext;
        }

        // Last resort: try to detect by file content signatures
        $header = file_get_contents($filePath, false, null, 0, 8);
        if ($header !== false) {
            // PDF starts with %PDF
            if (strpos($header, '%PDF') === 0) {
                return 'pdf';
            }
            // ZIP/DOCX starts with PK
            if (substr($header, 0, 2) === "PK") {
                $zip = new ZipArchive();
                if ($zip->open($filePath) === true) {
                    $hasDocXml = ($zip->locateName('word/document.xml') !== false);
                    $zip->close();
                    return $hasDocXml ? 'docx' : 'zip';
                }
            }
            // DOC starts with D0 CF 11 E0 (OLE compound document)
            if (substr($header, 0, 4) === "\xD0\xCF\x11\xE0") {
                return 'doc';
            }
        }

        return '';
    }

    // ==================== DOCX ====================

    /**
     * Extract plain text from DOCX
     */
    private function extractTextFromDocx(string $filePath): string {
        $xml = $this->getDocxXml($filePath);
        if (!$xml) {
            throw new Exception('Failed to read DOCX content');
        }

        $dom = new DOMDocument();
        $dom->loadXML($xml, LIBXML_NOERROR | LIBXML_NOWARNING);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        // Method 1: structured extraction via XPath — preserves paragraph breaks
        $text = '';
        $paragraphs = $xpath->query('//w:body//w:p');

        foreach ($paragraphs as $para) {
            $paraText = $this->extractDocxParagraphText($xpath, $para);
            if (trim($paraText) !== '') {
                $text .= $paraText . "\n";
            }
        }

        // Fallback: if XPath returned very little text, use textContent of body
        // This handles non-standard DOCX structures, custom XML elements, etc.
        if (mb_strlen(trim($text)) < 100) {
            $body = $xpath->query('//w:body');
            if ($body->length > 0) {
                $bodyText = $body->item(0)->textContent;
                // Clean up whitespace: collapse multiple spaces, preserve newlines
                $bodyText = preg_replace('/[^\S\n]+/', ' ', $bodyText);
                $bodyText = preg_replace('/\n{3,}/', "\n\n", $bodyText);
                if (mb_strlen(trim($bodyText)) > mb_strlen(trim($text))) {
                    $text = $bodyText;
                }
            }
        }

        // Final fallback: regex extraction from raw XML (handles namespace issues)
        if (mb_strlen(trim($text)) < 100) {
            if (preg_match_all('/<w:t[^>]*>([^<]+)<\/w:t>/u', $xml, $matches)) {
                $regexText = implode(' ', $matches[1]);
                if (mb_strlen(trim($regexText)) > mb_strlen(trim($text))) {
                    $text = $regexText;
                }
            }
        }

        return trim($text);
    }

    /**
     * Extract HTML from DOCX with formatting
     */
    private function extractHtmlFromDocx(string $filePath): string {
        $xml = $this->getDocxXml($filePath);
        if (!$xml) {
            throw new Exception('Failed to read DOCX content');
        }

        $dom = new DOMDocument();
        $dom->loadXML($xml, LIBXML_NOERROR | LIBXML_NOWARNING);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $html = '';
        $bodyChildren = $xpath->query('//w:body/*');

        foreach ($bodyChildren as $child) {
            $html .= $this->processDocxNodeToHtml($xpath, $child);
        }

        return $this->sanitizeHtml($html);
    }

    /**
     * Recursively process a DOCX XML node into HTML
     * Handles w:p, w:tbl, and w:sdt (structured document tags) wrappers
     */
    private function processDocxNodeToHtml(DOMXPath $xpath, DOMNode $node): string {
        switch ($node->localName) {
            case 'p':
                return $this->convertDocxParagraphToHtml($xpath, $node);
            case 'tbl':
                return $this->convertDocxTableToHtml($xpath, $node);
            case 'sdt':
                // Structured Document Tag — dive into sdtContent and process children
                $html = '';
                $sdtContent = $xpath->query('./w:sdtContent/*', $node);
                foreach ($sdtContent as $child) {
                    $html .= $this->processDocxNodeToHtml($xpath, $child);
                }
                return $html;
            default:
                return '';
        }
    }

    /**
     * Read document.xml from DOCX archive
     */
    private function getDocxXml(string $filePath): ?string {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            return null;
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        return $xml ?: null;
    }

    /**
     * Extract plain text from a DOCX paragraph node
     */
    private function extractDocxParagraphText(DOMXPath $xpath, DOMNode $para): string {
        $runs = $xpath->query('.//w:r', $para);
        $text = '';

        foreach ($runs as $run) {
            // Regular text
            $textNodes = $xpath->query('.//w:t', $run);
            foreach ($textNodes as $tn) {
                $text .= $tn->nodeValue;
            }

            // Tab characters
            $tabs = $xpath->query('.//w:tab', $run);
            if ($tabs->length > 0) {
                $text .= "\t";
            }
        }

        return $text;
    }

    /**
     * Convert a DOCX paragraph to HTML
     */
    private function convertDocxParagraphToHtml(DOMXPath $xpath, DOMNode $para): string {
        // Determine tag based on paragraph style
        $tag = 'p';
        $styleNodes = $xpath->query('.//w:pPr/w:pStyle/@w:val', $para);
        if ($styleNodes->length > 0) {
            $style = $styleNodes->item(0)->nodeValue;

            // English heading styles
            if (preg_match('/^Heading(\d)$/i', $style, $m)) {
                $level = min(intval($m[1]), 6);
                $tag = 'h' . $level;
            }
            // Russian heading styles
            elseif (preg_match('/^[Зз]аголовок\s*(\d)/u', $style, $m)) {
                $level = min(intval($m[1]), 6);
                $tag = 'h' . $level;
            }
            // List items
            elseif (stripos($style, 'ListParagraph') !== false || stripos($style, 'List') !== false) {
                $tag = 'p'; // Will handle as paragraph; full list reconstruction is complex
            }
        }

        // Check for numbering (list items)
        $numPr = $xpath->query('.//w:pPr/w:numPr', $para);
        $isListItem = $numPr->length > 0;

        // Build content from runs
        $content = '';
        $runs = $xpath->query('.//w:r', $para);

        foreach ($runs as $run) {
            $runText = '';
            $textNodes = $xpath->query('.//w:t', $run);
            foreach ($textNodes as $tn) {
                $runText .= htmlspecialchars($tn->nodeValue, ENT_QUOTES, 'UTF-8');
            }

            // Break tags
            $breaks = $xpath->query('.//w:br', $run);
            if ($breaks->length > 0) {
                $runText .= '<br>';
            }

            if ($runText === '') {
                continue;
            }

            // Apply formatting
            $rPr = $xpath->query('.//w:rPr', $run);
            if ($rPr->length > 0) {
                $props = $rPr->item(0);

                $bold = $xpath->query('.//w:b[not(@w:val="false") and not(@w:val="0")]', $props);
                $boldCs = $xpath->query('.//w:bCs[not(@w:val="false") and not(@w:val="0")]', $props);
                $italic = $xpath->query('.//w:i[not(@w:val="false") and not(@w:val="0")]', $props);
                $underline = $xpath->query('.//w:u[@w:val and not(@w:val="none")]', $props);

                if ($bold->length > 0 || $boldCs->length > 0) {
                    $runText = '<strong>' . $runText . '</strong>';
                }
                if ($italic->length > 0) {
                    $runText = '<em>' . $runText . '</em>';
                }
                if ($underline->length > 0) {
                    $runText = '<u>' . $runText . '</u>';
                }
            }

            $content .= $runText;
        }

        // Skip empty paragraphs
        if (trim(strip_tags($content)) === '') {
            return '';
        }

        if ($isListItem) {
            return "<li>{$content}</li>\n";
        }

        return "<{$tag}>{$content}</{$tag}>\n";
    }

    /**
     * Convert a DOCX table to HTML
     */
    private function convertDocxTableToHtml(DOMXPath $xpath, DOMNode $table): string {
        $html = "<table>\n";

        $rows = $xpath->query('.//w:tr', $table);
        $isFirstRow = true;

        foreach ($rows as $row) {
            $html .= "<tr>\n";
            $cells = $xpath->query('.//w:tc', $row);

            foreach ($cells as $cell) {
                $cellTag = $isFirstRow ? 'th' : 'td';

                // Check colspan
                $gridSpan = $xpath->query('.//w:tcPr/w:gridSpan/@w:val', $cell);
                $colspan = $gridSpan->length > 0 ? intval($gridSpan->item(0)->nodeValue) : 1;

                // Check rowspan (vMerge)
                $vMerge = $xpath->query('.//w:tcPr/w:vMerge', $cell);
                if ($vMerge->length > 0) {
                    $restartVal = $xpath->query('.//w:tcPr/w:vMerge/@w:val', $cell);
                    if ($restartVal->length === 0 || $restartVal->item(0)->nodeValue !== 'restart') {
                        continue; // Skip merged continuation cells
                    }
                }

                $attrs = '';
                if ($colspan > 1) {
                    $attrs .= ' colspan="' . $colspan . '"';
                }

                // Cell content
                $cellContent = '';
                $cellParagraphs = $xpath->query('.//w:p', $cell);
                foreach ($cellParagraphs as $cp) {
                    $pText = $this->extractDocxParagraphText($xpath, $cp);
                    if (trim($pText) !== '') {
                        $cellContent .= htmlspecialchars($pText, ENT_QUOTES, 'UTF-8') . '<br>';
                    }
                }
                $cellContent = rtrim($cellContent, '<br>');

                $html .= "<{$cellTag}{$attrs}>{$cellContent}</{$cellTag}>\n";
            }

            $html .= "</tr>\n";
            $isFirstRow = false;
        }

        $html .= "</table>\n";
        return $html;
    }

    // ==================== PDF ====================

    /**
     * Extract plain text from PDF
     */
    private function extractTextFromPdf(string $filePath): string {
        // Try pdftotext first
        $pdftotext = $this->findPdftotext();
        if ($pdftotext) {
            $escapedPath = escapeshellarg($filePath);
            $output = [];
            $returnCode = 0;
            exec("{$pdftotext} -layout {$escapedPath} - 2>/dev/null", $output, $returnCode);

            if ($returnCode === 0 && !empty($output)) {
                return implode("\n", $output);
            }
        }

        // Fallback: basic binary text extraction
        return $this->extractTextFromPdfBinary($filePath);
    }

    /**
     * Extract HTML from PDF (text wrapped in paragraphs)
     */
    private function extractHtmlFromPdf(string $filePath): string {
        $text = $this->extractTextFromPdf($filePath);
        if (empty($text)) {
            return '';
        }

        return $this->textToHtml($text);
    }

    /**
     * Find pdftotext binary path
     */
    private function findPdftotext(): ?string {
        $paths = [
            '/opt/homebrew/bin/pdftotext',
            '/usr/local/bin/pdftotext',
            '/usr/bin/pdftotext',
        ];

        foreach ($paths as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        // Try which
        $result = trim(shell_exec('which pdftotext 2>/dev/null') ?? '');
        if ($result && file_exists($result)) {
            return $result;
        }

        return null;
    }

    /**
     * Fallback: extract text from PDF binary using regex
     * (Works for simple text PDFs, not reliable for complex ones)
     */
    private function extractTextFromPdfBinary(string $filePath): string {
        $content = file_get_contents($filePath);
        if (!$content) {
            return '';
        }

        $text = '';

        // Extract text between BT and ET markers
        if (preg_match_all('/BT\s*(.*?)\s*ET/s', $content, $matches)) {
            foreach ($matches[1] as $block) {
                // Extract text from Tj and TJ operators
                if (preg_match_all('/\(([^)]*)\)\s*Tj/s', $block, $tjMatches)) {
                    foreach ($tjMatches[1] as $str) {
                        $text .= $this->decodePdfString($str) . ' ';
                    }
                }
                if (preg_match_all('/\[(.*?)\]\s*TJ/s', $block, $tjArrayMatches)) {
                    foreach ($tjArrayMatches[1] as $arr) {
                        if (preg_match_all('/\(([^)]*)\)/', $arr, $arrStrings)) {
                            foreach ($arrStrings[1] as $str) {
                                $text .= $this->decodePdfString($str);
                            }
                        }
                    }
                    $text .= ' ';
                }
            }
        }

        // Try to extract from streams (deflated content)
        if (empty(trim($text))) {
            if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $content, $streams)) {
                foreach ($streams[1] as $stream) {
                    $decoded = @gzuncompress($stream);
                    if ($decoded === false) {
                        $decoded = @gzinflate($stream);
                    }
                    if ($decoded) {
                        if (preg_match_all('/\(([^)]+)\)\s*Tj/s', $decoded, $tjMatches)) {
                            foreach ($tjMatches[1] as $str) {
                                $text .= $this->decodePdfString($str) . ' ';
                            }
                        }
                    }
                }
            }
        }

        return trim($text);
    }

    /**
     * Decode PDF string escape sequences
     */
    private function decodePdfString(string $str): string {
        $str = str_replace(['\\n', '\\r', '\\t', '\\(', '\\)', '\\\\'], ["\n", "\r", "\t", '(', ')', '\\'], $str);
        return $str;
    }

    // ==================== DOC ====================

    /**
     * Extract plain text from DOC (legacy binary format)
     */
    private function extractTextFromDoc(string $filePath): string {
        $escapedPath = escapeshellarg($filePath);

        // Try macOS textutil (built-in, most reliable on macOS)
        // -format doc tells textutil the input format (needed for tmp files without extension)
        $result = @shell_exec("/usr/bin/textutil -convert txt -stdout -format doc {$escapedPath} 2>/dev/null");
        if ($result && mb_strlen(trim($result)) > 50) {
            return trim($result);
        }

        // Try antiword with UTF-8 output
        $result = @shell_exec("antiword -m UTF-8.txt {$escapedPath} 2>/dev/null");
        if ($result && mb_strlen(trim($result)) > 50) {
            return trim($result);
        }

        // Try catdoc
        $result = @shell_exec("catdoc {$escapedPath} 2>/dev/null");
        if ($result && mb_strlen(trim($result)) > 50) {
            return trim($result);
        }

        // Try LibreOffice conversion to text
        $tmpDir = sys_get_temp_dir() . '/doc_extract_' . uniqid();
        mkdir($tmpDir, 0755, true);
        $result = @shell_exec("libreoffice --headless --convert-to txt:Text {$escapedPath} --outdir {$tmpDir} 2>/dev/null");
        $txtFiles = glob($tmpDir . '/*.txt');
        if (!empty($txtFiles)) {
            $text = file_get_contents($txtFiles[0]);
            // Cleanup
            array_map('unlink', glob($tmpDir . '/*'));
            rmdir($tmpDir);
            if ($text && mb_strlen(trim($text)) > 50) {
                return trim($text);
            }
        }
        // Cleanup in case of no output
        @array_map('unlink', glob($tmpDir . '/*'));
        @rmdir($tmpDir);

        // Last resort: extract readable strings from binary
        return $this->extractTextFromBinary($filePath);
    }

    /**
     * Extract HTML from DOC
     */
    private function extractHtmlFromDoc(string $filePath): string {
        $escapedPath = escapeshellarg($filePath);

        // Try wvHtml (best HTML from DOC — preserves bold, italic, structure)
        $result = @shell_exec("wvHtml {$escapedPath} /dev/stdout 2>/dev/null");
        if ($result && mb_strlen(trim($result)) > 100) {
            if (preg_match('/<body[^>]*>(.*)<\/body>/si', $result, $m)) {
                return $this->cleanConvertedHtml($m[1]);
            }
        }

        // Try macOS textutil — can output HTML directly with formatting
        $result = @shell_exec("/usr/bin/textutil -convert html -stdout -format doc {$escapedPath} 2>/dev/null");
        if ($result && mb_strlen(trim($result)) > 100) {
            if (preg_match('/<body[^>]*>(.*)<\/body>/si', $result, $m)) {
                return $this->cleanConvertedHtml($m[1]);
            }
        }

        // Fallback: extract text and wrap in paragraphs
        $text = $this->extractTextFromDoc($filePath);
        if (empty($text)) {
            return '';
        }

        return $this->textToHtml($text);
    }

    /**
     * Extract readable text from binary file (last resort)
     */
    private function extractTextFromBinary(string $filePath): string {
        $content = file_get_contents($filePath);
        if (!$content) {
            return '';
        }

        // Try to find the WordDocument stream and extract text
        $text = '';

        // Look for Unicode text sequences (UTF-16LE which DOC uses)
        if (preg_match_all('/(?:[\x20-\x7E\xC0-\xFF]\x00){4,}/', $content, $matches)) {
            foreach ($matches[0] as $match) {
                $decoded = @mb_convert_encoding($match, 'UTF-8', 'UTF-16LE');
                if ($decoded) {
                    $text .= $decoded . "\n";
                }
            }
        }

        // Also try ASCII sequences
        if (empty(trim($text))) {
            if (preg_match_all('/[\x20-\x7E\xC0-\xFF]{10,}/', $content, $matches)) {
                $text = implode("\n", $matches[0]);
            }
        }

        return trim($text);
    }

    // ==================== Utilities ====================

    /**
     * Convert plain text to HTML paragraphs
     */
    private function textToHtml(string $text): string {
        $html = '';
        // Split by double newlines for paragraphs
        $blocks = preg_split('/\n{2,}/', $text);

        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }

            // Escape HTML entities
            $escaped = htmlspecialchars($block, ENT_QUOTES, 'UTF-8');
            // Preserve single line breaks within paragraphs
            $escaped = nl2br($escaped);

            $html .= "<p>{$escaped}</p>\n";
        }

        return $this->sanitizeHtml($html);
    }

    /**
     * Clean HTML produced by wvHtml, textutil, or LibreOffice converters
     * Converts legacy tags to semantic HTML, strips inline styles, then sanitizes
     */
    private function cleanConvertedHtml(string $html): string {
        // Remove HTML comments
        $html = preg_replace('/<!--.*?-->/s', '', $html);

        // Convert <b> → <strong>, <i> → <em>
        $html = preg_replace('/<b\b[^>]*>/i', '<strong>', $html);
        $html = str_ireplace('</b>', '</strong>', $html);
        $html = preg_replace('/<i\b[^>]*>/i', '<em>', $html);
        $html = str_ireplace('</i>', '</em>', $html);

        // Strip <font>, <div>, <span> tags but keep content
        $html = preg_replace('/<\/?font[^>]*>/i', '', $html);
        $html = preg_replace('/<\/?div[^>]*>/i', '', $html);
        $html = preg_replace('/<\/?span[^>]*>/i', '', $html);

        // Remove empty <strong>/<em> tags
        $html = preg_replace('/<strong>\s*<\/strong>/i', '', $html);
        $html = preg_replace('/<em>\s*<\/em>/i', '', $html);

        // Remove empty paragraphs and paragraphs with only whitespace
        $html = preg_replace('/<p[^>]*>\s*<\/p>/i', '', $html);

        // Collapse multiple <br> into paragraph breaks
        $html = preg_replace('/(<br\s*\/?>\s*){3,}/i', '</p><p>', $html);

        // Run through sanitizer first (strips styles, unknown tags, etc.)
        $html = $this->sanitizeHtml($html);

        // Post-sanitize: fix nested/broken <p> tags (repeat to catch multiple levels)
        for ($i = 0; $i < 3; $i++) {
            $html = preg_replace('/<p>\s*<p>/i', '<p>', $html);
            $html = preg_replace('/<\/p>\s*<\/p>/i', '</p>', $html);
        }

        // Remove paragraphs that contain only whitespace or &nbsp;
        $html = preg_replace('/<p>(\s|&nbsp;)*<\/p>/i', '', $html);

        // Trim leading whitespace inside paragraphs
        $html = preg_replace('/<p>\s+/i', '<p>', $html);

        // Collapse multiple blank lines
        $html = preg_replace('/\n{3,}/', "\n\n", $html);

        return trim($html);
    }

    /**
     * Sanitize HTML — allowlist of safe tags and attributes
     */
    private function sanitizeHtml(string $html): string {
        $allowedTags = '<p><h1><h2><h3><h4><h5><h6><strong><em><u><ul><ol><li><table><thead><tbody><tr><th><td><br><blockquote>';

        // Strip everything except allowed tags
        $html = strip_tags($html, $allowedTags);

        // Remove all attributes except colspan and rowspan on td/th
        $html = preg_replace_callback(
            '/<(td|th)\s+([^>]*)>/i',
            function ($matches) {
                $tag = $matches[1];
                $attrs = $matches[2];
                $safe = '';

                if (preg_match('/colspan\s*=\s*"(\d+)"/', $attrs, $m)) {
                    $safe .= ' colspan="' . $m[1] . '"';
                }
                if (preg_match('/rowspan\s*=\s*"(\d+)"/', $attrs, $m)) {
                    $safe .= ' rowspan="' . $m[1] . '"';
                }

                return "<{$tag}{$safe}>";
            },
            $html
        );

        // Remove attributes from all other tags
        $html = preg_replace('/<(p|h[1-6]|strong|em|u|ul|ol|li|table|thead|tbody|tr|br|blockquote)\s+[^>]*>/i', '<$1>', $html);

        // Remove any on* event handlers that might have survived
        $html = preg_replace('/\s+on\w+\s*=\s*"[^"]*"/i', '', $html);
        $html = preg_replace('/\s+on\w+\s*=\s*\'[^\']*\'/i', '', $html);

        // Remove any style attributes
        $html = preg_replace('/\s+style\s*=\s*"[^"]*"/i', '', $html);
        $html = preg_replace('/\s+style\s*=\s*\'[^\']*\'/i', '', $html);

        // Wrap consecutive <li> items in <ul> if not already wrapped
        $html = $this->wrapListItems($html);

        return trim($html);
    }

    /**
     * Wrap consecutive <li> elements in <ul> tags
     */
    private function wrapListItems(string $html): string {
        // Find consecutive <li> tags not inside <ul> or <ol>
        $html = preg_replace_callback(
            '/(?:(?:<li>.*?<\/li>\s*)+)/s',
            function ($matches) {
                $block = $matches[0];
                // Check if already wrapped in ul/ol
                // We check the content before and after in the full html
                return $block;
            },
            $html
        );

        // Simple approach: if there are <li> tags without a parent <ul> or <ol>,
        // wrap all standalone <li> groups in <ul>
        $lines = explode("\n", $html);
        $result = [];
        $inList = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            $isLi = (strpos($trimmed, '<li>') === 0);

            if ($isLi && !$inList) {
                // Check if previous line is <ul> or <ol>
                $prevResult = end($result);
                if ($prevResult && !preg_match('/<[uo]l>/i', trim($prevResult))) {
                    $result[] = '<ul>';
                    $inList = true;
                }
            } elseif (!$isLi && $inList) {
                $result[] = '</ul>';
                $inList = false;
            }

            $result[] = $line;
        }

        if ($inList) {
            $result[] = '</ul>';
        }

        return implode("\n", $result);
    }
}
