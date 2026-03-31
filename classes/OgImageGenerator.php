<?php
/**
 * Генератор OG-картинок для соцсетей (1200×630)
 * и рекламных картинок для Яндекс Директ (600×600)
 * Использует GD для создания брендированных карточек
 */
class OgImageGenerator
{
    private const WIDTH = 1200;
    private const HEIGHT = 630;
    private const AD_WIDTH = 600;
    private const AD_HEIGHT = 600;
    private const CACHE_TTL = 604800; // 7 дней

    // Маппинг типов контента на метки
    private const TYPE_LABELS = [
        'competition'  => 'КОНКУРС',
        'olympiad'     => 'ОЛИМПИАДА',
        'webinar'      => 'ВЕБИНАР',
        'course'       => 'КУРС',
        'publication'  => 'ПУБЛИКАЦИЯ',
    ];

    private string $fontBold;
    private string $fontRegular;
    private string $logoPath;
    private string $logoDarkPath;
    private string $cacheDir;

    public function __construct()
    {
        $this->fontBold    = __DIR__ . '/../vendor/mpdf/mpdf/ttfonts/DejaVuSans-Bold.ttf';
        $this->fontRegular = __DIR__ . '/../vendor/mpdf/mpdf/ttfonts/DejaVuSans.ttf';
        $this->logoPath    = __DIR__ . '/../assets/images/logo-white.png';
        $this->logoDarkPath = __DIR__ . '/../assets/images/logo.png';
        $this->cacheDir    = __DIR__ . '/../uploads/og-cache';

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Получить картинку из кэша или сгенерировать новую
     * @return string Путь к JPG-файлу
     */
    public function getOrGenerate(string $cacheKey, string $type, string $title, string $subtitle = ''): string
    {
        $filePath = $this->cacheDir . '/' . $cacheKey . '.jpg';

        if (file_exists($filePath) && (time() - filemtime($filePath)) < self::CACHE_TTL) {
            return $filePath;
        }

        return $this->generate($filePath, $type, $title, $subtitle);
    }

    /**
     * Сгенерировать OG-картинку
     * @return string Путь к файлу
     */
    public function generate(string $outputPath, string $type, string $title, string $subtitle = ''): string
    {
        $img = imagecreatetruecolor(self::WIDTH, self::HEIGHT);

        $this->drawGradientBackground($img);
        $this->drawDecorations($img);
        $this->drawLogo($img);

        $nextY = 200;

        // Badge типа контента
        if (isset(self::TYPE_LABELS[$type])) {
            $nextY = $this->drawTypeBadge($img, self::TYPE_LABELS[$type]);
        }

        // Заголовок
        $nextY = $this->drawTitle($img, $title, $nextY);

        // Подзаголовок
        if (!empty($subtitle)) {
            $this->drawSubtitle($img, $subtitle, $nextY + 20);
        }

        // Футер
        $this->drawFooter($img);

        imagejpeg($img, $outputPath, 92);
        imagedestroy($img);

        return $outputPath;
    }

    /**
     * Вертикальный градиент (фиолетовая тема)
     */
    private function drawGradientBackground(\GdImage $img): void
    {
        for ($y = 0; $y < self::HEIGHT; $y++) {
            $ratio = $y / self::HEIGHT;
            // Градиент от тёмно-фиолетового (#2D1B69) к синему (#1a3a8a)
            $r = (int)(45 + $ratio * (26 - 45));
            $g = (int)(27 + $ratio * (58 - 27));
            $b = (int)(105 + $ratio * (138 - 105));
            $color = imagecolorallocate($img, max(0, $r), max(0, $g), max(0, $b));
            imageline($img, 0, $y, self::WIDTH, $y, $color);
        }
    }

    /**
     * Декоративные элементы
     */
    private function drawDecorations(\GdImage $img): void
    {
        // Большой полупрозрачный круг справа вверху
        $circleColor = imagecolorallocatealpha($img, 255, 255, 255, 115); // ~10% opacity
        imagefilledellipse($img, 1100, 80, 350, 350, $circleColor);

        // Маленький круг слева внизу
        $circleColor2 = imagecolorallocatealpha($img, 255, 255, 255, 120);
        imagefilledellipse($img, 100, 580, 180, 180, $circleColor2);

        // Тонкая горизонтальная линия-разделитель для футера
        $lineColor = imagecolorallocatealpha($img, 255, 255, 255, 100);
        imageline($img, 80, 545, self::WIDTH - 80, 545, $lineColor);
    }

    /**
     * Логотип в левом верхнем углу
     */
    private function drawLogo(\GdImage $img): void
    {
        if (!file_exists($this->logoPath)) {
            return;
        }

        $logo = imagecreatefrompng($this->logoPath);
        if (!$logo) {
            return;
        }

        $origW = imagesx($logo);
        $origH = imagesy($logo);

        // Масштабируем логотип до высоты 50px
        $targetH = 50;
        $targetW = (int)($origW * ($targetH / $origH));

        imagealphablending($img, true);
        imagecopyresampled($img, $logo, 80, 50, 0, 0, $targetW, $targetH, $origW, $origH);
        imagedestroy($logo);
    }

    /**
     * Badge с типом контента (КОНКУРС, ОЛИМПИАДА и т.д.)
     * @return int Y-координата после badge
     */
    private function drawTypeBadge(\GdImage $img, string $label): int
    {
        $fontSize = 16;
        $x = 80;
        $y = 170;

        // Измеряем текст
        $bbox = imagettfbbox($fontSize, 0, $this->fontBold, $label);
        $textW = abs($bbox[2] - $bbox[0]);
        $textH = abs($bbox[7] - $bbox[1]);

        // Фон badge
        $padX = 16;
        $padY = 8;
        $bgColor = imagecolorallocatealpha($img, 255, 255, 255, 95); // ~25% opacity
        imagefilledrectangle(
            $img,
            $x - $padX,
            $y - $textH - $padY,
            $x + $textW + $padX,
            $y + $padY,
            $bgColor
        );

        // Текст badge
        $white = imagecolorallocate($img, 255, 255, 255);
        imagettftext($img, $fontSize, 0, $x, $y, $white, $this->fontBold, $label);

        return $y + $padY + 40;
    }

    /**
     * Заголовок с автопереносом (до 3 строк, адаптивный размер)
     * @return int Y-координата после последней строки
     */
    private function drawTitle(\GdImage $img, string $title, int $startY): int
    {
        $white = imagecolorallocate($img, 255, 255, 255);
        $maxWidth = self::WIDTH - 80 - 160; // padding left + right margin
        $maxLines = 3;
        $lineHeight = 1.3;

        // Пробуем разные размеры шрифта
        foreach ([42, 36, 30] as $fontSize) {
            $lines = $this->wrapText($title, $fontSize, $maxWidth, $this->fontBold);

            if (count($lines) <= $maxLines) {
                $y = $startY;
                foreach ($lines as $line) {
                    imagettftext($img, $fontSize, 0, 80, $y, $white, $this->fontBold, $line);
                    $y += (int)($fontSize * $lineHeight);
                }
                return $y;
            }
        }

        // Если всё равно не помещается — обрезаем до 3 строк
        $fontSize = 30;
        $lines = $this->wrapText($title, $fontSize, $maxWidth, $this->fontBold);
        $lines = array_slice($lines, 0, $maxLines);
        $lines[$maxLines - 1] = mb_substr($lines[$maxLines - 1], 0, -3) . '...';

        $y = $startY;
        foreach ($lines as $line) {
            imagettftext($img, $fontSize, 0, 80, $y, $white, $this->fontBold, $line);
            $y += (int)($fontSize * $lineHeight);
        }

        return $y;
    }

    /**
     * Подзаголовок (описание) — до 2 строк
     */
    private function drawSubtitle(\GdImage $img, string $subtitle, int $startY): void
    {
        $fontSize = 20;
        $maxWidth = self::WIDTH - 80 - 200;
        $maxLines = 2;
        $lineHeight = 1.4;

        $lightWhite = imagecolorallocatealpha($img, 255, 255, 255, 30); // ~75% opacity

        $lines = $this->wrapText($subtitle, $fontSize, $maxWidth, $this->fontRegular);

        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $last = $lines[$maxLines - 1];
            if (mb_strlen($last) > 3) {
                $lines[$maxLines - 1] = mb_substr($last, 0, -3) . '...';
            }
        }

        $y = $startY;
        foreach ($lines as $line) {
            imagettftext($img, $fontSize, 0, 80, $y, $lightWhite, $this->fontRegular, $line);
            $y += (int)($fontSize * $lineHeight);
        }
    }

    /**
     * Футер — название сайта
     */
    private function drawFooter(\GdImage $img): void
    {
        $footerColor = imagecolorallocatealpha($img, 255, 255, 255, 50); // ~60% opacity
        imagettftext($img, 18, 0, 80, 585, $footerColor, $this->fontRegular, 'fgos.pro — Педагогический портал');
    }

    /**
     * Перенос текста по ширине
     * @return string[] Массив строк
     */
    private function wrapText(string $text, float $fontSize, int $maxWidth, string $font): array
    {
        $words = preg_split('/\s+/', trim($text));
        $lines = [];
        $currentLine = '';

        foreach ($words as $word) {
            $testLine = $currentLine === '' ? $word : $currentLine . ' ' . $word;
            $bbox = imagettfbbox($fontSize, 0, $font, $testLine);
            $lineWidth = abs($bbox[2] - $bbox[0]);

            if ($lineWidth > $maxWidth && $currentLine !== '') {
                $lines[] = $currentLine;
                $currentLine = $word;
            } else {
                $currentLine = $testLine;
            }
        }

        if ($currentLine !== '') {
            $lines[] = $currentLine;
        }

        return $lines;
    }

    // =============================================
    // РЕКЛАМНЫЕ КАРТИНКИ ДЛЯ ЯНДЕКС ДИРЕКТ (600×600)
    // =============================================

    /**
     * Получить рекламную картинку из кэша или сгенерировать новую
     * @return string Путь к JPG-файлу
     */
    public function getOrGenerateAd(string $cacheKey, string $type, string $title, string $price = '', string $badge = ''): string
    {
        $filePath = $this->cacheDir . '/ad-' . $cacheKey . '.jpg';

        if (file_exists($filePath) && (time() - filemtime($filePath)) < self::CACHE_TTL) {
            return $filePath;
        }

        return $this->generateAd($filePath, $type, $title, $price, $badge);
    }

    /**
     * Сгенерировать рекламную картинку 600×600 (белый фон, цена, бейдж)
     * @return string Путь к файлу
     */
    public function generateAd(string $outputPath, string $type, string $title, string $price = '', string $badge = ''): string
    {
        $img = imagecreatetruecolor(self::AD_WIDTH, self::AD_HEIGHT);
        imagesavealpha($img, true);
        imagealphablending($img, true);

        // Белый фон
        $white = imagecolorallocate($img, 255, 255, 255);
        imagefilledrectangle($img, 0, 0, self::AD_WIDTH, self::AD_HEIGHT, $white);

        // Фиолетовая полоса-хедер сверху (60px)
        $headerH = 60;
        for ($y = 0; $y < $headerH; $y++) {
            $ratio = $y / $headerH;
            $r = (int)(45 + $ratio * (58 - 45));
            $g = (int)(27 + $ratio * (40 - 27));
            $b = (int)(105 + $ratio * (130 - 105));
            $color = imagecolorallocate($img, $r, $g, $b);
            imageline($img, 0, $y, self::AD_WIDTH, $y, $color);
        }

        // Логотип в хедере (белый, маленький)
        $this->drawAdLogo($img, true);

        // Тип продукта справа в хедере
        $headerWhite = imagecolorallocate($img, 255, 255, 255);
        $typeLabel = self::TYPE_LABELS[$type] ?? '';
        if ($typeLabel) {
            $bbox = imagettfbbox(11, 0, $this->fontBold, $typeLabel);
            $tw = abs($bbox[2] - $bbox[0]);
            imagettftext($img, 11, 0, self::AD_WIDTH - $tw - 25, 38, $headerWhite, $this->fontBold, $typeLabel);
        }

        // Бейдж (например «ВСЕРОССИЙСКИЙ») под хедером
        $contentY = 90;
        if (!empty($badge)) {
            $contentY = $this->drawAdBadge($img, $badge, $contentY);
        }

        // Заголовок (тёмный текст, по центру)
        $contentY = $this->drawAdTitle($img, $title, $contentY);

        // Блок с ценой внизу
        if (!empty($price)) {
            $this->drawAdPrice($img, $price);
        }

        // Футер: fgos.pro
        $footerColor = imagecolorallocate($img, 160, 160, 160);
        $footerText = 'fgos.pro';
        $bbox = imagettfbbox(12, 0, $this->fontRegular, $footerText);
        $fw = abs($bbox[2] - $bbox[0]);
        imagettftext($img, 12, 0, (int)((self::AD_WIDTH - $fw) / 2), self::AD_HEIGHT - 15, $footerColor, $this->fontRegular, $footerText);

        imagejpeg($img, $outputPath, 92);
        imagedestroy($img);

        return $outputPath;
    }

    /**
     * Логотип в рекламной картинке (в хедере)
     */
    private function drawAdLogo(\GdImage $img, bool $useWhite = true): void
    {
        $path = $useWhite ? $this->logoPath : $this->logoDarkPath;
        if (!file_exists($path)) {
            return;
        }

        $logo = imagecreatefrompng($path);
        if (!$logo) {
            return;
        }

        $origW = imagesx($logo);
        $origH = imagesy($logo);
        $targetH = 30;
        $targetW = (int)($origW * ($targetH / $origH));

        imagealphablending($img, true);
        imagecopyresampled($img, $logo, 20, 15, 0, 0, $targetW, $targetH, $origW, $origH);
        imagedestroy($logo);
    }

    /**
     * Бейдж-метка в рекламной картинке (например «Всероссийский»)
     * @return int Y-координата после бейджа
     */
    private function drawAdBadge(\GdImage $img, string $badge, int $y): int
    {
        $fontSize = 12;
        $bbox = imagettfbbox($fontSize, 0, $this->fontBold, $badge);
        $textW = abs($bbox[2] - $bbox[0]);
        $textH = abs($bbox[7] - $bbox[1]);

        $padX = 12;
        $padY = 6;
        $x = (int)((self::AD_WIDTH - $textW - $padX * 2) / 2);

        // Фон бейджа — светло-фиолетовый
        $bgColor = imagecolorallocate($img, 237, 231, 246);
        imagefilledrectangle($img, $x, $y - $padY, $x + $textW + $padX * 2, $y + $textH + $padY, $bgColor);

        // Текст бейджа — тёмно-фиолетовый
        $textColor = imagecolorallocate($img, 69, 39, 134);
        imagettftext($img, $fontSize, 0, $x + $padX, $y + $textH, $textColor, $this->fontBold, $badge);

        return $y + $textH + $padY * 2 + 20;
    }

    /**
     * Заголовок рекламной картинки (тёмный текст, по центру, до 4 строк)
     * @return int Y-координата после заголовка
     */
    private function drawAdTitle(\GdImage $img, string $title, int $startY): int
    {
        $darkColor = imagecolorallocate($img, 33, 33, 33);
        $maxWidth = self::AD_WIDTH - 60; // 30px padding с каждой стороны
        $maxLines = 4;
        $lineHeight = 1.35;

        // Адаптивный размер шрифта
        foreach ([32, 28, 24, 20] as $fontSize) {
            $lines = $this->wrapText($title, $fontSize, $maxWidth, $this->fontBold);

            if (count($lines) <= $maxLines) {
                // Центрируем блок текста вертикально в доступном пространстве
                $blockH = count($lines) * (int)($fontSize * $lineHeight);
                $availableH = self::AD_HEIGHT - $startY - 130; // место для цены и футера
                $y = $startY + max(0, (int)(($availableH - $blockH) / 2));

                foreach ($lines as $line) {
                    // Горизонтальное центрирование каждой строки
                    $bbox = imagettfbbox($fontSize, 0, $this->fontBold, $line);
                    $lineW = abs($bbox[2] - $bbox[0]);
                    $x = (int)((self::AD_WIDTH - $lineW) / 2);
                    imagettftext($img, $fontSize, 0, $x, $y, $darkColor, $this->fontBold, $line);
                    $y += (int)($fontSize * $lineHeight);
                }
                return $y;
            }
        }

        // Fallback: обрезаем до maxLines
        $fontSize = 20;
        $lines = $this->wrapText($title, $fontSize, $maxWidth, $this->fontBold);
        $lines = array_slice($lines, 0, $maxLines);
        if (mb_strlen($lines[$maxLines - 1]) > 3) {
            $lines[$maxLines - 1] = mb_substr($lines[$maxLines - 1], 0, -3) . '...';
        }

        $y = $startY + 10;
        foreach ($lines as $line) {
            $bbox = imagettfbbox($fontSize, 0, $this->fontBold, $line);
            $lineW = abs($bbox[2] - $bbox[0]);
            $x = (int)((self::AD_WIDTH - $lineW) / 2);
            imagettftext($img, $fontSize, 0, $x, $y, $darkColor, $this->fontBold, $line);
            $y += (int)($fontSize * $lineHeight);
        }

        return $y;
    }

    /**
     * Блок с ценой в нижней части рекламной картинки
     */
    private function drawAdPrice(\GdImage $img, string $price): void
    {
        $priceText = $price . ' ₽';
        $fontSize = 28;

        $bbox = imagettfbbox($fontSize, 0, $this->fontBold, $priceText);
        $textW = abs($bbox[2] - $bbox[0]);
        $textH = abs($bbox[7] - $bbox[1]);

        $padX = 24;
        $padY = 12;
        $blockW = $textW + $padX * 2;
        $blockH = $textH + $padY * 2;
        $blockX = (int)((self::AD_WIDTH - $blockW) / 2);
        $blockY = self::AD_HEIGHT - 70 - $blockH;

        // Фиолетовый фон блока цены
        $bgColor = imagecolorallocate($img, 69, 39, 134);
        // Рисуем скруглённый прямоугольник (имитация через filled rect)
        imagefilledrectangle($img, $blockX, $blockY, $blockX + $blockW, $blockY + $blockH, $bgColor);

        // Белый текст цены
        $white = imagecolorallocate($img, 255, 255, 255);
        $textX = $blockX + $padX;
        $textY = $blockY + $padY + $textH;
        imagettftext($img, $fontSize, 0, $textX, $textY, $white, $this->fontBold, $priceText);
    }

    /**
     * Построить ключ кэша для рекламной картинки
     */
    public static function buildAdCacheKey(string $type, string $slug): string
    {
        return $type . '-' . md5($slug);
    }

    /**
     * Инвалидировать кэш для конкретной записи
     */
    public function invalidateCache(string $cacheKey): bool
    {
        $filePath = $this->cacheDir . '/' . $cacheKey . '.jpg';
        if (file_exists($filePath)) {
            return unlink($filePath);
        }
        return false;
    }

    /**
     * Построить ключ кэша
     */
    public static function buildCacheKey(string $type, string $slug): string
    {
        return $type . '-' . md5($slug);
    }

    /**
     * Отдать картинку в браузер с HTTP-заголовками
     */
    public function serve(string $filePath): void
    {
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=' . self::CACHE_TTL);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($filePath)) . ' GMT');

        $etag = '"' . md5_file($filePath) . '"';
        header('ETag: ' . $etag);

        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
            http_response_code(304);
            exit;
        }

        readfile($filePath);
        exit;
    }
}
