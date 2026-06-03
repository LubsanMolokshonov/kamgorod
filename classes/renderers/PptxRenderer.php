<?php
/**
 * PptxRenderer — рендер презентации в .pptx через PHPPresentation.
 *
 * Ожидает в $data ключ 'slides' (массив [{number, title, bullets[], notes}]).
 * Если slides отсутствуют — fallback: создаёт один титульный слайд с title
 * и dump'ом ключей материала.
 *
 * Слайды формата 16:9, 33.867×19.05 см. Текст в Calibri (близкий к стандарту PPT).
 */

require_once __DIR__ . '/MaterialHtmlRenderer.php';
require_once __DIR__ . '/MaterialTheme.php';

class PptxRenderer
{
    // Размер слайда 16:9 в пикселях (как их трактует PHPPresentation): 960×540.
    private const SLIDE_W = 960;
    private const SLIDE_H = 540;

    private string $uploadsBase;

    public function __construct(?string $uploadsBase = null)
    {
        $this->uploadsBase = $uploadsBase
            ?? (dirname(__DIR__, 2) . '/uploads/materials');
    }

    public function render(array $data, string $title, string $slug): array
    {
        if (!class_exists('\\PhpOffice\\PhpPresentation\\PhpPresentation')) {
            require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
        }

        $pres = new \PhpOffice\PhpPresentation\PhpPresentation();
        $pres->getDocumentProperties()
            ->setCreator(defined('SITE_NAME') ? SITE_NAME : 'fgos.pro')
            ->setTitle($title);

        $pres->getLayout()
            ->setDocumentLayout(\PhpOffice\PhpPresentation\DocumentLayout::LAYOUT_SCREEN_16X9);

        $slides = $data['slides'] ?? null;
        if (!is_array($slides) || empty($slides)) {
            // Fallback: один титульный слайд
            $slides = [[
                'number' => 1,
                'title'  => $title,
                'bullets' => array_filter([
                    !empty($data['intro']) ? (string)$data['intro'] : null,
                    !empty($data['goal'])  ? 'Цель: ' . (string)$data['goal'] : null,
                ]),
                'notes' => 'Слайды от ИИ не были возвращены — fallback-титул.',
            ]];
        }

        $defaultSlide = $pres->getActiveSlide();
        $firstSlideUsed = false;

        foreach ($slides as $idx => $s) {
            $slide = $firstSlideUsed ? $pres->createSlide() : $defaultSlide;
            $firstSlideUsed = true;

            $this->renderSlide($slide, $s, $idx + 1);
        }

        [$relativePath, $absolutePath] = $this->ensureOutputPath($slug, 'pptx');

        $writer = \PhpOffice\PhpPresentation\IOFactory::createWriter($pres, 'PowerPoint2007');
        $writer->save($absolutePath);

        return [
            'file_path' => $relativePath,
            'file_abs' => $absolutePath,
            'file_size' => filesize($absolutePath) ?: 0,
            'file_format' => 'pptx',
        ];
    }

    private function renderSlide(\PhpOffice\PhpPresentation\Slide $slide, array $data, int $fallbackNumber): void
    {
        $font = MaterialTheme::DOC_FONT;
        $colorWhite = new \PhpOffice\PhpPresentation\Style\Color('FF' . MaterialTheme::WHITE);
        $colorInk   = new \PhpOffice\PhpPresentation\Style\Color('FF' . MaterialTheme::INK_700);
        $colorIndigo = new \PhpOffice\PhpPresentation\Style\Color('FF' . MaterialTheme::INDIGO_600);

        // Светлый фон слайда
        $bg = new \PhpOffice\PhpPresentation\Slide\Background\Color();
        $bg->setColor((new \PhpOffice\PhpPresentation\Style\Color())->setRGB(MaterialTheme::INK_50));
        $slide->setBackground($bg);

        $number = (int)($data['number'] ?? $fallbackNumber);
        $titleText = (string)($data['title'] ?? ('Слайд ' . $fallbackNumber));

        // Залитый title-бар во всю ширину
        $bar = $slide->createRichTextShape()
            ->setHeight(86)
            ->setWidth(self::SLIDE_W)
            ->setOffsetX(0)
            ->setOffsetY(0);
        $bar->getFill()
            ->setFillType(\PhpOffice\PhpPresentation\Style\Fill::FILL_SOLID)
            ->setStartColor($colorIndigo)
            ->setEndColor($colorIndigo);
        $bar->setInsetLeft(46)->setInsetRight(46)->setInsetTop(18)->setInsetBottom(12);
        $titleRun = $bar->createTextRun($titleText);
        $titleRun->getFont()->setBold(true)->setSize(26)->setName($font)->setColor($colorWhite);

        // Есть ли картинка для этого слайда
        $imageAbs = !empty($data['_image_abs']) && is_file($data['_image_abs']) ? $data['_image_abs'] : null;
        $bodyWidth = $imageAbs ? 470 : 860;

        // Буллеты
        if (!empty($data['bullets']) && is_array($data['bullets'])) {
            $bulletShape = $slide->createRichTextShape()
                ->setHeight(380)
                ->setWidth($bodyWidth)
                ->setOffsetX(46)
                ->setOffsetY(118);
            foreach ($data['bullets'] as $bullet) {
                $paragraph = $bulletShape->createParagraph();
                $paragraph->getBulletStyle()
                    ->setBulletType(\PhpOffice\PhpPresentation\Style\Bullet::TYPE_BULLET)
                    ->setBulletChar('•')
                    ->setBulletColor($colorIndigo);
                $paragraph->getAlignment()->setMarginLeft(28)->setIndent(-18);
                $run = $paragraph->createTextRun((string)$bullet);
                $run->getFont()->setSize(18)->setName($font)->setColor($colorInk);
            }
        }

        // Иллюстрация справа (если сгенерирована — фаза 2)
        if ($imageAbs) {
            $drawing = $slide->createDrawingShape();
            $drawing->setPath($imageAbs)
                ->setWidth(380)
                ->setOffsetX(540)
                ->setOffsetY(120);
        }

        // Заметки докладчика
        if (!empty($data['notes'])) {
            $notes = $slide->getNote();
            $textShape = $notes->createRichTextShape()
                ->setHeight(400)
                ->setWidth(700);
            $textShape->createTextRun((string)$data['notes'])
                ->getFont()->setSize(12)->setName($font);
        }

        // Фирменный логотип портала внизу слева (на светлом фоне слайда)
        $logoPath = MaterialTheme::logoColorPath();
        if ($logoPath !== '') {
            $logo = $slide->createDrawingShape();
            $logo->setPath($logoPath)
                ->setWidth(150)
                ->setOffsetX(46)
                ->setOffsetY(self::SLIDE_H - 52);
        }

        // Чип номера слайда внизу справа
        $footer = $slide->createRichTextShape()
            ->setHeight(30)
            ->setWidth(60)
            ->setOffsetX(self::SLIDE_W - 90)
            ->setOffsetY(self::SLIDE_H - 46);
        $footRun = $footer->createTextRun((string)$number);
        $footRun->getFont()->setSize(11)->setBold(true)->setName($font)->setColor($colorIndigo);
        $footer->getActiveParagraph()->getAlignment()
            ->setHorizontal(\PhpOffice\PhpPresentation\Style\Alignment::HORIZONTAL_RIGHT);
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
