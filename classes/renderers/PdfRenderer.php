<?php
/**
 * PdfRenderer — рендер материала в PDF через mPDF.
 *
 * Принимает структурированные данные от ИИ, превращает их в HTML через
 * MaterialHtmlRenderer и сохраняет PDF в /uploads/materials/{Y}/{m}/.
 *
 * Возвращает массив:
 *   ['file_path' => относительный путь, 'file_size' => bytes, 'file_format' => 'pdf']
 */

require_once __DIR__ . '/MaterialHtmlRenderer.php';
require_once __DIR__ . '/MaterialTheme.php';

class PdfRenderer
{
    private MaterialHtmlRenderer $html;
    private string $uploadsBase;

    public function __construct(?string $uploadsBase = null)
    {
        $this->html = new MaterialHtmlRenderer();
        $this->uploadsBase = $uploadsBase
            ?? (dirname(__DIR__, 2) . '/uploads/materials');
    }

    public function render(array $data, string $title, string $slug, string $typeSlug = ''): array
    {
        if (!class_exists('\\Mpdf\\Mpdf')) {
            require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
        }

        // Рабочий лист — печатаем бланком с явным делением «для учителя / для ученика».
        $body = $typeSlug === 'rabochiy-list'
            ? $this->html->renderWorksheet($data)
            : $this->html->render($data);
        $fullHtml = $this->sanitizeGlyphsForPdf($this->buildDocument($title, $body));

        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'default_font' => 'freesans',
            'margin_left' => 18,
            'margin_right' => 18,
            'margin_top' => 30,
            'margin_bottom' => 22,
            'margin_header' => 9,
            'margin_footer' => 9,
        ]);
        $mpdf->SetTitle($title);
        $mpdf->SetCreator('fgos.pro');
        $this->applyBranding($mpdf);
        $mpdf->WriteHTML($fullHtml);

        [$relativePath, $absolutePath] = $this->ensureOutputPath($slug, 'pdf');
        $mpdf->Output($absolutePath, \Mpdf\Output\Destination::FILE);

        return [
            'file_path' => $relativePath,
            'file_size' => filesize($absolutePath) ?: 0,
            'file_format' => 'pdf',
        ];
    }

    /**
     * Рендер PDF, повторяющего вёрстку страницы /material/{slug}/.
     * Возвращает байты PDF (для отдачи на лету в material-download.php).
     *
     * @param array  $material      Запись materials (нужны title, type_name, file_format, description, content)
     * @param array  $programs      Человекочитаемые метки соответствия (ФГОС 2021 и т.п.)
     * @param string $previewAbsPath Абсолютный путь к обложке на диске (или '')
     */
    public function renderPageStyle(array $material, array $programs = [], string $previewAbsPath = ''): string
    {
        if (!class_exists('\\Mpdf\\Mpdf')) {
            require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
        }

        $title = (string)($material['title'] ?? 'Материал');
        $fullHtml = $this->sanitizeGlyphsForPdf($this->buildPageDocument($material, $programs, $previewAbsPath));

        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'default_font' => 'freesans',
            'margin_left' => 18,
            'margin_right' => 18,
            'margin_top' => 30,
            'margin_bottom' => 22,
            'margin_header' => 9,
            'margin_footer' => 9,
        ]);
        $mpdf->SetTitle($title);
        $mpdf->SetCreator('fgos.pro');
        $this->applyBranding($mpdf);
        $mpdf->WriteHTML($fullHtml);

        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    /**
     * Подменяет символы, которых нет в шрифте FreeSans: mPDF рисует их пустым
     * «тофу»-квадратом со знаком вопроса (жалоба про квадратики у № вопросов).
     * Рабочие глифы (★ повышенного уровня, ☐/☑ чек-боксы) заменяем на
     * отображаемые аналоги, decorative-эмодзи из текста ИИ убираем.
     * Делается только на этапе PDF — в вебе эти символы рендерятся штатно.
     */
    private function sanitizeGlyphsForPdf(string $html): string
    {
        $map = [
            "\u{2605}" => '*',          // ★ бейдж повышенного уровня → *
            "\u{2606}" => '*',          // ☆
            "\u{2B50}" => '*',          // ⭐
            "\u{2610}" => '[ ]',        // ☐ → [ ] (множественный выбор; □ нет в freesans → тофу)
            "\u{2611}" => '[x]',        // ☑ → [x]
            "\u{2612}" => '[x]',        // ☒ → [x]
            "\u{25A1}" => '[ ]',        // □ пустой квадрат (нет в freesans) → [ ]
            "\u{25A0}" => '[x]',        // ■ → [x]
            "\u{25CB}" => '( )',        // ○ (нет в freesans) → ( )
            "\u{25CF}" => '( )',        // ● → ( )
            // Маркеры-буллеты, которые ИИ вставляет внутрь narrative-текста и которых нет
            // в freesans (иначе пустые квадраты у пунктов «Время в твоих руках»):
            "\u{2022}" => '-',          // • bullet
            "\u{25E6}" => '-',          // ◦ white bullet
            "\u{2023}" => '-',          // ‣ triangular bullet
            "\u{2043}" => '-',          // ⁃ hyphen bullet
            "\u{25AA}" => '-',          // ▪ black small square
            "\u{25AB}" => '-',          // ▫ white small square
            "\u{00B7}" => '-',          // · middle dot
        ];
        $html = strtr($html, $map);

        // Остальные неподдерживаемые пиктограммы/эмодзи (включая ✓, ✏, ❓ и весь
        // блок SMP-эмодзи) убираем вместе с висящим за ними пробелом. Геометрические
        // фигуры (○ ■ □), стрелки (→), тире (—) и пунктуация остаются — они вне диапазонов.
        $html = preg_replace(
            '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE00}-\x{FE0F}\x{200D}\x{2049}\x{203C}]\x{0020}?/u',
            '',
            $html
        );

        return $html ?? '';
    }

    /**
     * Брендирование mPDF: фирменный колонтитул с логотипом и подвал с
     * названием портала и номером страницы — повторяются на каждой странице.
     */
    private function applyBranding(\Mpdf\Mpdf $mpdf): void
    {
        $indigo700 = MaterialTheme::css(MaterialTheme::INDIGO_700);
        $ink200    = MaterialTheme::css(MaterialTheme::INK_200);
        $brand     = htmlspecialchars(MaterialTheme::BRAND_LABEL, ENT_QUOTES, 'UTF-8');
        $site      = htmlspecialchars(MaterialTheme::BRAND_SITE, ENT_QUOTES, 'UTF-8');

        $logoPath = MaterialTheme::logoColorPath();
        $logoCell = $logoPath !== ''
            ? '<img src="' . htmlspecialchars($logoPath, ENT_QUOTES, 'UTF-8') . '" style="width:42mm;">'
            : '<span style="font-weight:bold;color:' . $indigo700 . ';font-size:12pt;">' . $brand . '</span>';

        $header = '<table width="100%"><tr>'
            . '<td style="padding-bottom:3pt;border:none;">' . $logoCell . '</td>'
            . '<td style="padding-bottom:3pt;border:none;text-align:right;font-size:8pt;color:#8b90a8;vertical-align:bottom;">'
            . $brand . '</td>'
            . '</tr></table>'
            . '<div style="border-bottom:0.6pt solid ' . $ink200 . ';"></div>';

        $footer = '<div style="border-top:0.6pt solid ' . $ink200 . ';"></div>'
            . '<table width="100%" style="font-size:8pt;color:#8b90a8;"><tr>'
            . '<td style="padding-top:3pt;border:none;">Сгенерировано на ' . $site . '</td>'
            . '<td style="padding-top:3pt;border:none;text-align:right;">стр. {PAGENO} из {nbpg}</td>'
            . '</tr></table>';

        $mpdf->SetHTMLHeader($header);
        $mpdf->SetHTMLFooter($footer);
    }

    private function buildPageDocument(array $material, array $programs, string $previewAbsPath): string
    {
        $title = (string)($material['title'] ?? 'Материал');
        $titleEsc = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

        $typeName = trim((string)($material['type_name'] ?? ''));
        $format   = strtoupper((string)($material['file_format'] ?? ''));
        $typeLine = $typeName;
        if ($format !== '') {
            $typeLine = $typeName !== '' ? ($typeName . ' · ' . $format) : $format;
        }
        $typeLineEsc = htmlspecialchars($typeLine, ENT_QUOTES, 'UTF-8');

        // Контент уже содержит свой <h1> с названием — убираем, чтобы не дублировать
        // заголовок страницы (так же, как шапка детальной страницы рендерит h1 отдельно).
        $content = (string)($material['content'] ?? '');
        $content = preg_replace('~^\s*<h1\b[^>]*>.*?</h1>~isu', '', $content, 1);

        $description = trim((string)($material['description'] ?? ''));
        $descHtml = $description !== ''
            ? '<p class="desc">' . nl2br(htmlspecialchars($description, ENT_QUOTES, 'UTF-8')) . '</p>'
            : '';

        $tagsHtml = '';
        if (!empty($programs)) {
            $tagsHtml = '<div class="tags">';
            foreach ($programs as $label) {
                $tagsHtml .= '<span class="tag">'
                    . htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') . '</span> ';
            }
            $tagsHtml .= '</div>';
        }

        $imgHtml = '';
        if ($previewAbsPath !== '' && is_file($previewAbsPath)) {
            $src = htmlspecialchars($previewAbsPath, ENT_QUOTES, 'UTF-8');
            $imgHtml = '<div class="cover"><img src="' . $src . '"></div>';
        }

        $indigo50  = MaterialTheme::css(MaterialTheme::INDIGO_50);
        $indigo700 = MaterialTheme::css(MaterialTheme::INDIGO_700);
        $indigo800 = MaterialTheme::css(MaterialTheme::INDIGO_800);
        $indigo100 = MaterialTheme::css(MaterialTheme::INDIGO_100);
        $ink900    = MaterialTheme::css(MaterialTheme::INK_900);
        $ink700    = MaterialTheme::css(MaterialTheme::INK_700);
        $ink200    = MaterialTheme::css(MaterialTheme::INK_200);
        $ink50     = MaterialTheme::css(MaterialTheme::INK_50);

        return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>{$titleEsc}</title>
    <style>
        body { font-family: freesans, sans-serif; font-size: 12pt; line-height: 1.65; color: {$ink700}; }
        .type { font-size: 9pt; letter-spacing: 0.6pt; text-transform: uppercase; color: #8b90a8; margin: 0 0 4pt; }
        h1 { font-size: 22pt; font-weight: bold; line-height: 1.2; margin: 0 0 12pt; color: {$ink900}; }
        .tags { margin: 0 0 12pt; }
        .tag {
            display: inline-block; padding: 3pt 9pt; margin: 0 4pt 4pt 0;
            background: {$indigo50}; color: {$indigo700};
            border-radius: 20pt; font-size: 9pt; font-weight: bold;
        }
        .cover { margin: 0 0 14pt; }
        .cover img { width: 100%; max-width: 420pt; border-radius: 10pt; }
        .desc { font-size: 12pt; color: {$ink700}; margin: 0 0 14pt; }
        .content {
            background: {$ink50}; padding: 16pt; border-radius: 10pt;
        }
        .content h2 {
            font-size: 14pt; margin: 14pt 0 8pt; color: {$indigo800};
            border-bottom: 2px solid {$indigo100}; padding-bottom: 4pt;
        }
        .content h2:first-child { margin-top: 0; }
        .content h2.md-part {
            font-size: 15pt; margin: 18pt 0 12pt; padding: 8pt 12pt;
            background: {$indigo50}; color: {$indigo800};
            border-left: 5pt solid {$indigo700}; border-bottom: none; border-radius: 4pt;
        }
        .content h2.md-part-student { page-break-before: always; }
        .md-signbar { width: 100%; border-collapse: collapse; margin: 4pt 0 14pt; }
        .md-signbar td { border: none; padding: 12pt 4pt 2pt; vertical-align: bottom; font-size: 11pt; }
        .md-signlabel { color: {$ink700}; padding-right: 6pt; }
        .md-signline { border-bottom: 0.7pt solid {$ink900}; }
        .content h3 { font-size: 12pt; margin: 12pt 0 6pt; color: {$ink900}; }
        .content p { margin: 0 0 8pt; }
        .content ul, .content ol { margin: 0 0 8pt; }
        .content strong { color: {$ink900}; }
        .content table { font-size: 10pt; width: 100%; border-collapse: collapse; margin: 0 0 12pt; }
        .content th, .content td { border: 0.5pt solid {$ink200}; padding: 5pt 7pt; text-align: left; vertical-align: top; }
        .content th { background: {$indigo50}; color: {$indigo800}; font-weight: bold; }
        .md-writelines { margin: 8pt 0 4pt; }
        .md-writeline { border-bottom: 0.5pt solid {$ink200}; height: 24pt; margin: 0 0 4pt; }
        .md-drawbox { border: 0.5pt dashed {$ink200}; height: 240pt; margin: 8pt 0; text-align: center; color: #b6bccd; }
        .md-match td { border: none; }
        .md-questions li, .md-tasks li { margin-bottom: 8pt; }
        .footer { text-align: center; font-size: 9pt; color: #888; margin-top: 14pt; }
    </style>
</head>
<body>
    <div class="type">{$typeLineEsc}</div>
    <h1>{$titleEsc}</h1>
    {$tagsHtml}
    {$imgHtml}
    {$descHtml}
    <div class="content">{$content}</div>
</body>
</html>
HTML;
    }

    private function buildDocument(string $title, string $body): string
    {
        $titleEsc = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

        $indigo600 = MaterialTheme::css(MaterialTheme::INDIGO_600);
        $indigo800 = MaterialTheme::css(MaterialTheme::INDIGO_800);
        $indigo100 = MaterialTheme::css(MaterialTheme::INDIGO_100);
        $indigo50  = MaterialTheme::css(MaterialTheme::INDIGO_50);
        $ink900    = MaterialTheme::css(MaterialTheme::INK_900);
        $ink700    = MaterialTheme::css(MaterialTheme::INK_700);
        $ink200    = MaterialTheme::css(MaterialTheme::INK_200);
        $ink50     = MaterialTheme::css(MaterialTheme::INK_50);

        return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>{$titleEsc}</title>
    <style>
        body { font-family: freesans, sans-serif; font-size: 12pt; line-height: 1.5; color: {$ink700}; }
        h1 {
            font-size: 19pt;
            margin: 0 0 14pt;
            padding: 10pt 12pt;
            background: {$indigo600};
            color: #fff;
            border-radius: 4pt;
        }
        h2 {
            font-size: 14pt;
            margin: 16pt 0 8pt;
            color: {$indigo800};
            border-bottom: 2px solid {$indigo100};
            padding-bottom: 4pt;
        }
        h2.md-part {
            font-size: 15pt; margin: 18pt 0 12pt; padding: 8pt 12pt;
            background: {$indigo50}; color: {$indigo800};
            border-left: 5pt solid {$indigo600}; border-bottom: none; border-radius: 4pt;
        }
        h2.md-part-student { page-break-before: always; }
        .md-signbar { width: 100%; border-collapse: collapse; margin: 4pt 0 14pt; }
        .md-signbar td { border: none; padding: 12pt 4pt 2pt; vertical-align: bottom; font-size: 11pt; }
        .md-signlabel { color: {$ink700}; padding-right: 6pt; }
        .md-signline { border-bottom: 0.7pt solid {$ink900}; }
        h3 { font-size: 12pt; margin: 12pt 0 6pt; color: {$ink900}; }
        p { margin: 0 0 8pt; }
        ul, ol { margin: 0 0 8pt; }
        strong { color: {$ink900}; }
        table { font-size: 10pt; width: 100%; border-collapse: collapse; margin: 0 0 12pt; }
        th, td { border: 0.5pt solid {$ink200}; padding: 5pt 7pt; text-align: left; vertical-align: top; }
        th { background: {$indigo50}; color: {$indigo800}; font-weight: bold; }
        .md-writelines { margin: 8pt 0 4pt; }
        .md-writeline { border-bottom: 0.5pt solid {$ink200}; height: 24pt; margin: 0 0 4pt; }
        .md-drawbox { border: 0.5pt dashed {$ink200}; height: 240pt; margin: 8pt 0; text-align: center; color: #b6bccd; }
        .md-match td { border: none; }
        .md-questions li, .md-tasks li { margin-bottom: 8pt; }
        .footer { text-align: center; font-size: 9pt; color: #888; margin-top: 12pt; }
    </style>
</head>
<body>
    {$body}
</body>
</html>
HTML;
    }

    private function ensureOutputPath(string $slug, string $ext): array
    {
        $year = date('Y');
        $month = date('m');
        $dir = $this->uploadsBase . "/{$year}/{$month}";
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Не удалось создать директорию {$dir}");
        }
        $safe = preg_replace('/[^a-z0-9-]+/i', '', $slug);
        if ($safe === '') {
            $safe = 'material';
        }
        $filename = $safe . '_' . substr(uniqid('', true), -8) . '.' . $ext;
        $absolute = $dir . '/' . $filename;
        $relative = "uploads/materials/{$year}/{$month}/{$filename}";
        return [$relative, $absolute];
    }
}
