<?php
/**
 * DocxRenderer — рендер материала в .docx через PHPWord.
 *
 * Использует HtmlConverter PHPWord для импорта общего HTML, который собирает
 * MaterialHtmlRenderer. Это позволяет переиспользовать те же шаблоны, что и в
 * PdfRenderer, без дублирования логики формирования контента.
 *
 * Подписан как обязательно требующий composer install с phpoffice/phpword.
 */

require_once __DIR__ . '/MaterialHtmlRenderer.php';
require_once __DIR__ . '/MaterialTheme.php';

class DocxRenderer
{
    private MaterialHtmlRenderer $html;
    private string $uploadsBase;

    public function __construct(?string $uploadsBase = null)
    {
        $this->html = new MaterialHtmlRenderer();
        $this->uploadsBase = $uploadsBase
            ?? (dirname(__DIR__, 2) . '/uploads/materials');
    }

    public function render(array $data, string $title, string $slug): array
    {
        if (!class_exists('\\PhpOffice\\PhpWord\\PhpWord')) {
            require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
        }

        $phpWord = new \PhpOffice\PhpWord\PhpWord();
        $phpWord->getCompatibility()->setOoxmlVersion(15);
        $phpWord->getSettings()->setThemeFontLang(new \PhpOffice\PhpWord\Style\Language(\PhpOffice\PhpWord\Style\Language::RU_RU));

        $phpWord->setDefaultFontName(MaterialTheme::DOC_FONT);
        $phpWord->setDefaultFontSize(11);

        // Стили заголовков — HtmlConverter мапит h1/h2/h3 на эти уровни.
        $phpWord->addTitleStyle(1, ['name' => MaterialTheme::DOC_FONT, 'size' => 18, 'bold' => true, 'color' => MaterialTheme::INDIGO_600], ['spaceAfter' => 200]);
        $phpWord->addTitleStyle(2, ['name' => MaterialTheme::DOC_FONT, 'size' => 14, 'bold' => true, 'color' => MaterialTheme::INDIGO_800], ['spaceBefore' => 240, 'spaceAfter' => 120]);
        $phpWord->addTitleStyle(3, ['name' => MaterialTheme::DOC_FONT, 'size' => 12, 'bold' => true, 'color' => MaterialTheme::INK_900], ['spaceBefore' => 180, 'spaceAfter' => 80]);

        $section = $phpWord->addSection([
            'marginTop'    => 1500,
            'marginBottom' => 1300,
            'marginLeft'   => 1100,
            'marginRight'  => 1100,
            'headerHeight' => 700,
            'footerHeight' => 700,
        ]);

        // Фирменный колонтитул: логотип портала сверху каждой страницы.
        $header = $section->addHeader();
        $logoPath = MaterialTheme::logoColorPath();
        if ($logoPath !== '') {
            $header->addImage($logoPath, [
                'width'         => 150,
                'height'        => 41,
                'alignment'     => \PhpOffice\PhpWord\SimpleType\Jc::START,
                'wrappingStyle' => 'inline',
            ]);
        } else {
            $header->addText(MaterialTheme::BRAND_LABEL, ['bold' => true, 'size' => 11, 'color' => MaterialTheme::INDIGO_700]);
        }

        // Фирменный подвал: название портала + номер страницы.
        $footer = $section->addFooter();
        $footerTable = $footer->addTable();
        $footerTable->addRow();
        $footerTable->addCell(7000)->addText(
            'Сгенерировано на ' . MaterialTheme::BRAND_SITE . ' · ' . MaterialTheme::BRAND_LABEL,
            ['size' => 8, 'color' => '8b90a8']
        );
        $footerTable->addCell(2000)->addPreserveText(
            'стр. {PAGE} из {NUMPAGES}',
            ['size' => 8, 'color' => '8b90a8'],
            ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::END]
        );

        $body = trim($this->html->render($data));
        // PHPWord HtmlConverter ожидает корневой блок-элемент.
        $wrapped = '<div>' . $this->normalizeVoidTags($body) . '</div>';

        // addHtml сам разберёт h1/h2/h3, ul/ol, table, p, strong, em. Если разметка всё же
        // не разобралась (DOMDocument::loadXML строгий) — НЕ оставляем .docx с одним
        // колонтитулом: добавляем текстовый фолбэк, чтобы файл не скачивался пустым.
        $bodyOk = false;
        if ($body !== '') {
            try {
                \PhpOffice\PhpWord\Shared\Html::addHtml($section, $wrapped, false, false);
                $bodyOk = true;
            } catch (\Throwable $e) {
                error_log('DocxRenderer: addHtml failed, fallback to plain text: ' . $e->getMessage());
            }
        }
        if (!$bodyOk) {
            $this->addPlainTextFallback($section, $body, $data, $title);
        }

        [$relativePath, $absolutePath] = $this->ensureOutputPath($slug, 'docx');

        $writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($absolutePath);

        return [
            'file_path' => $relativePath,
            'file_abs' => $absolutePath,
            'file_size' => filesize($absolutePath) ?: 0,
            'file_format' => 'docx',
        ];
    }

    /**
     * Текстовый фолбэк: когда HtmlConverter не смог разобрать разметку или тело пустое,
     * наполняем секцию хотя бы текстом — иначе .docx скачивается «пустым» (одни колонтитулы).
     * Берём текст из уже отрендеренного HTML (срезаем теги), а если и он пуст — линейно
     * дампим JSON-ответ ИИ парами «ключ: значение».
     */
    private function addPlainTextFallback($section, string $body, array $data, string $title): void
    {
        $section->addTitle($title !== '' ? $title : (string)($data['title'] ?? 'Материал'), 1);

        // 1) Пытаемся восстановить текст из HTML-тела (срезаем теги, восстанавливаем абзацы).
        if ($body !== '') {
            $text = preg_replace('~<(h[1-3]|p|li|tr|div|br)\b[^>]*>~i', "\n", $body);
            $text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
            $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn($l) => $l !== ''));
            if ($lines) {
                foreach ($lines as $line) {
                    $section->addText($line, ['size' => 11], ['spaceAfter' => 80]);
                }
                return;
            }
        }

        // 2) Крайний случай: HTML пуст — дампим сам JSON ответа ИИ.
        $this->dumpArray($section, $data, 0);
    }

    /** Рекурсивный текстовый дамп массива в DOCX-секцию (крайний фолбэк). */
    private function dumpArray($section, array $data, int $depth): void
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && $key !== '' && $key[0] === '_') {
                continue; // внутренние поля (_image_abs и т.п.)
            }
            $label = is_string($key) ? $key : ('#' . ((int)$key + 1));
            if (is_array($value)) {
                if ($depth < 3) {
                    $section->addText((string)$label . ':', ['bold' => true, 'size' => 11], ['spaceBefore' => 80]);
                    $this->dumpArray($section, $value, $depth + 1);
                }
            } else {
                $str = trim((string)$value);
                if ($str !== '') {
                    $section->addText($label . ': ' . $str, ['size' => 11], ['spaceAfter' => 60]);
                }
            }
        }
    }

    /**
     * PHPWord\Shared\Html парсит HTML строго как XML (DOMDocument::loadXML).
     * Незакрытый void-тег (`<br>`, `<hr>`, `<img>`) ломает разбор всего документа,
     * и в .docx молча попадает только колонтитул — тело теряется. Поэтому перед
     * передачей в addHtml приводим void-теги к XML-форме `<br/>`. Шаблоны и так
     * должны отдавать корректную разметку — это защитная сетка от регрессий.
     */
    private function normalizeVoidTags(string $html): string
    {
        return preg_replace(
            '/<(br|hr|img|input|col|wbr)((?:\s[^<>]*?)?)\s*(?<!\/)>/i',
            '<$1$2/>',
            $html
        ) ?? $html;
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
