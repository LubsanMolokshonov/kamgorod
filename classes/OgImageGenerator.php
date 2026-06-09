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
    private const AD_COURSE_SIZE = 1080; // квадрат для рекламных баннеров курсов (выше качество скана)
    private const CACHE_TTL = 604800; // 7 дней

    // Маппинг типов контента на метки
    private const TYPE_LABELS = [
        'competition'  => 'КОНКУРС',
        'olympiad'     => 'ОЛИМПИАДА',
        'webinar'      => 'ВЕБИНАР',
        'course'       => 'КУРС',
        'publication'  => 'ПУБЛИКАЦИЯ',
    ];

    // Маппинг категорий аудитории → родительный падеж для рекламных картинок
    private const CATEGORY_AUDIENCE_MAP = [
        'Педагогам'      => 'ПЕДАГОГОВ',
        'Дошкольникам'   => 'ДОШКОЛЬНИКОВ',
        'Школьникам'     => 'ШКОЛЬНИКОВ',
        'Студентам СПО'  => 'СТУДЕНТОВ СПО',
    ];

    // Маппинг target_participants → родительный падеж (частичное совпадение)
    private const COMPETITION_AUDIENCE_MAP = [
        'Воспитатели'           => 'ВОСПИТАТЕЛЕЙ',
        'Учителя'               => 'УЧИТЕЛЕЙ',
        'Учитель'               => 'УЧИТЕЛЕЙ',
        'Преподаватели'         => 'ПРЕПОДАВАТЕЛЕЙ',
        'Классные руководители' => 'КЛАССНЫХ РУКОВОДИТЕЛЕЙ',
        'Педагоги-психологи'    => 'ПЕДАГОГОВ-ПСИХОЛОГОВ',
        'Педагоги-организаторы' => 'ПЕДАГОГОВ-ОРГАНИЗАТОРОВ',
        'Педагоги'              => 'ПЕДАГОГОВ',
        'Логопеды'              => 'ЛОГОПЕДОВ',
        'Учителя-логопеды'      => 'ЛОГОПЕДОВ',
        'Учителя-дефектологи'   => 'ДЕФЕКТОЛОГОВ',
        'Социальные педагоги'   => 'СОЦИАЛЬНЫХ ПЕДАГОГОВ',
        'Методисты'             => 'МЕТОДИСТОВ',
        'Тьюторы'               => 'ТЬЮТОРОВ',
        'Руководители'          => 'РУКОВОДИТЕЛЕЙ',
        'Заместители'           => 'РУКОВОДИТЕЛЕЙ',
        'Инструкторы'           => 'ИНСТРУКТОРОВ',
        'Музыкальные'           => 'МУЗЫКАЛЬНЫХ РУКОВОДИТЕЛЕЙ',
        'Кураторы'              => 'КУРАТОРОВ',
        'Тренеры'               => 'ТРЕНЕРОВ',
        'Библиотекари'          => 'БИБЛИОТЕКАРЕЙ',
        'Учащиеся'              => 'УЧАЩИХСЯ',
        'Ученики'               => 'УЧЕНИКОВ',
        'Студенты'              => 'СТУДЕНТОВ',
        'Дошкольники'           => 'ДОШКОЛЬНИКОВ',
        'Обучающиеся'           => 'ОБУЧАЮЩИХСЯ',
        'Воспитанники'          => 'ВОСПИТАННИКОВ',
        'Дети'                  => 'ДЕТЕЙ',
    ];

    // Маппинг специализаций → родительный падеж множ. числа для рекламных картинок курсов
    private const AUDIENCE_LABEL_MAP = [
        'Воспитатель'                       => 'ВОСПИТАТЕЛЕЙ',
        'Старший воспитатель'               => 'СТАРШИХ ВОСПИТАТЕЛЕЙ',
        'Младший воспитатель'               => 'МЛАДШИХ ВОСПИТАТЕЛЕЙ',
        'Учитель'                           => 'УЧИТЕЛЕЙ',
        'Администрация и управление'        => 'РУКОВОДИТЕЛЕЙ',
        'Педагог дополнительного образования'=> 'ПЕДАГОГОВ ДО',
        'Классное руководство'              => 'КЛАССНЫХ РУКОВОДИТЕЛЕЙ',
        'Педагог-психолог'                  => 'ПЕДАГОГОВ-ПСИХОЛОГОВ',
        'Работа с детьми с ОВЗ'             => 'ПЕДАГОГОВ ОВЗ',
        'Социальная педагогика'             => 'СОЦИАЛЬНЫХ ПЕДАГОГОВ',
        'Логопедия'                         => 'ЛОГОПЕДОВ',
        'Дефектология'                      => 'ДЕФЕКТОЛОГОВ',
        'Тьюторство'                        => 'ТЬЮТОРОВ',
        'Методист'                          => 'МЕТОДИСТОВ',
        'Библиотекарь'                      => 'БИБЛИОТЕКАРЕЙ',
        'Педагог-организатор'               => 'ПЕДАГОГОВ-ОРГАНИЗАТОРОВ',
        'Воспитатель ГПД'                   => 'ВОСПИТАТЕЛЕЙ ГПД',
        'Инструктор по физкультуре'         => 'ИНСТРУКТОРОВ ФИЗКУЛЬТУРЫ',
    ];

    // Предмет → родительный падеж для подписи «ДЛЯ УЧИТЕЛЕЙ {ПРЕДМЕТА}»
    private const SUBJECT_GENITIVE_MAP = [
        'Русский язык и литература'        => 'русского языка',
        'Математика'                       => 'математики',
        'Математика (алгебра, геометрия)'  => 'математики',
        'История'                          => 'истории',
        'Обществознание'                   => 'обществознания',
        'География'                        => 'географии',
        'Музыка'                           => 'музыки',
        'Английский язык'                  => 'английского языка',
        'Иностранные языки'                => 'иностранного языка',
        'Технология'                       => 'технологии',
    ];

    // Порядок выбора предмета (узкие/значимые — первыми)
    private const SUBJECT_PRIORITY = [
        'Русский язык и литература', 'Математика', 'Математика (алгебра, геометрия)',
        'История', 'Обществознание', 'География', 'Музыка',
        'Английский язык', 'Иностранные языки', 'Технология',
    ];

    // Приоритет role-специализаций для подписи (узкие — первыми)
    private const AUDIENCE_ROLE_PRIORITY = [
        'Логопедия', 'Дефектология', 'Педагог-психолог', 'Работа с детьми с ОВЗ',
        'Социальная педагогика', 'Тьюторство', 'Методист', 'Педагог-организатор',
        'Инструктор по физкультуре', 'Младший воспитатель', 'Старший воспитатель',
        'Педагог дополнительного образования', 'Воспитатель', 'Учитель',
        'Администрация и управление',
    ];

    // Точечные переопределения подписи по slug курса (родительный мн. ч.)
    private const COURSE_AUDIENCE_OVERRIDE = [
        'spetsialist-po-pozharnoy-profilaktike'                                                                          => 'СПЕЦИАЛИСТОВ ПО ПОЖАРНОЙ ПРОФИЛАКТИКЕ',
        'gosudarstvennoe-i-munitsipalnoe-upravlenie'                                                                     => 'СПЕЦИАЛИСТОВ ГМУ',
        'menedzhment-v-sfere-obrazovaniya'                                                                               => 'РУКОВОДИТЕЛЕЙ ОБРАЗОВАНИЯ',
        'muzykalnoe-obrazovanie-v-doshkolnoy-obrazovatelnoy-organizatsii-v-usloviyah-realizatsii-fgos-doshkolnogo-obrazovaniya' => 'МУЗЫКАЛЬНЫХ РУКОВОДИТЕЛЕЙ',
        'pedagog-predshkolnoy-podgotovki'                                                                                => 'ПЕДАГОГОВ ПРЕДШКОЛЬНОЙ ПОДГОТОВКИ',
        'prakticheskaya-psihologiya-psihologicheskoe-konsultirovanie-v-sfere-seksualnyh-otnosheniy'                       => 'ПСИХОЛОГОВ-КОНСУЛЬТАНТОВ',
        'psiholog-konsultant-v-oblasti-semeynyh-i-detsko-roditelskih-otnosheniy-semeynyy-psiholog'                        => 'СЕМЕЙНЫХ ПСИХОЛОГОВ',
        'pedagogicheskaya-deyatelnost-sovetnika-direktora-po-vospitaniyu-i-vzaimodeystviyu-s-detskimi-obschestvennymi-obedineniyami-v-obrazovatelnoy-organizatsii' => 'СОВЕТНИКОВ ДИРЕКТОРА',
        'pedagogika-i-metodika-fizicheskoy-kultury-i-sporta-trener-prepodavatel'                                          => 'ТРЕНЕРОВ-ПРЕПОДАВАТЕЛЕЙ',
    ];

    private string $fontBold;
    private string $fontRegular;
    private string $fontMontserratBold;
    private string $fontMontserratRegular;
    private string $logoPath;
    private string $logoDarkPath;
    private string $cacheDir;
    private string $diplomaFanTemplatePath;
    private string $skolkovoLogoPath;
    private string $razreshenieSkolkovoPath;
    private string $logoColorPath;

    public function __construct()
    {
        $this->fontBold    = __DIR__ . '/../vendor/mpdf/mpdf/ttfonts/DejaVuSans-Bold.ttf';
        $this->fontRegular = __DIR__ . '/../vendor/mpdf/mpdf/ttfonts/DejaVuSans.ttf';
        $this->fontMontserratBold    = __DIR__ . '/../assets/fonts/Montserrat-Bold.ttf';
        $this->fontMontserratRegular = __DIR__ . '/../assets/fonts/Montserrat-Regular.ttf';
        $this->logoPath    = __DIR__ . '/../assets/images/logo-white.png';
        $this->logoDarkPath = __DIR__ . '/../assets/images/logo.png';
        $this->cacheDir    = __DIR__ . '/../uploads/og-cache';
        $this->diplomaFanTemplatePath = __DIR__ . '/../assets/images/diploma-fan-template.png';
        $this->skolkovoLogoPath = __DIR__ . '/../assets/images/skolkovo-logo-white.png';
        $this->razreshenieSkolkovoPath = __DIR__ . '/../assets/images/razreshenie-skolkovo-068.png';
        $this->logoColorPath = __DIR__ . '/../assets/images/logo-color.png';

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

    // =============================================
    // РЕКЛАМНЫЕ КАРТИНКИ КУРСОВ (шаблон + аудитория)
    // =============================================

    /**
     * Построить текст аудитории из массива специализаций
     * @param array $specializations Массив из Course->getSpecializations()
     * @return string Например «ДЛЯ ВОСПИТАТЕЛЕЙ»
     */
    public static function buildAudienceLabel(array $specializations, string $slug = ''): string
    {
        // 1. Точечное переопределение по slug
        if ($slug !== '' && isset(self::COURSE_AUDIENCE_OVERRIDE[$slug])) {
            return 'ДЛЯ ' . self::COURSE_AUDIENCE_OVERRIDE[$slug];
        }

        if (empty($specializations)) {
            return 'ДЛЯ ПЕДАГОГОВ';
        }

        $names = array_map(fn($s) => $s['name'] ?? '', $specializations);

        // 2. Предметник: «Учитель» + предметная специализация
        if (in_array('Учитель', $names, true)) {
            if (in_array('Литературное чтение', $names, true) || in_array('Окружающий мир', $names, true)) {
                return 'ДЛЯ УЧИТЕЛЕЙ НАЧАЛЬНЫХ КЛАССОВ';
            }
            $subjects = [];
            foreach (self::SUBJECT_PRIORITY as $subject) {
                if (in_array($subject, $names, true) && isset(self::SUBJECT_GENITIVE_MAP[$subject])) {
                    $gen = self::SUBJECT_GENITIVE_MAP[$subject];
                    if (!in_array($gen, $subjects, true)) {
                        $subjects[] = $gen;
                    }
                }
            }
            if (!empty($subjects)) {
                if (in_array('истории', $subjects, true) && in_array('обществознания', $subjects, true)) {
                    return 'ДЛЯ УЧИТЕЛЕЙ ИСТОРИИ И ОБЩЕСТВОЗНАНИЯ';
                }
                return mb_strtoupper('ДЛЯ УЧИТЕЛЕЙ ' . $subjects[0], 'UTF-8');
            }
        }

        // 3. Role-специализация (узкие — первыми)
        foreach (self::AUDIENCE_ROLE_PRIORITY as $role) {
            if (in_array($role, $names, true) && isset(self::AUDIENCE_LABEL_MAP[$role])) {
                return 'ДЛЯ ' . self::AUDIENCE_LABEL_MAP[$role];
            }
        }

        return 'ДЛЯ ПЕДАГОГОВ';
    }

    /**
     * Получить рекламную картинку курса из кэша или сгенерировать
     * @return string Путь к JPG-файлу
     */
    public function getOrGenerateCourseAd(string $cacheKey, string $audienceLabel, string $programType = 'kpk'): string
    {
        $filePath = $this->cacheDir . '/ad-' . $cacheKey . '.jpg';

        if (file_exists($filePath) && (time() - filemtime($filePath)) < self::CACHE_TTL) {
            return $filePath;
        }

        $programTypeLabel = $programType === 'pp'
            ? 'КУРС ПРОФЕССИОНАЛЬНОЙ ПЕРЕПОДГОТОВКИ'
            : 'КУРС ПОВЫШЕНИЯ КВАЛИФИКАЦИИ';

        return $this->generateCourseAd($filePath, $programTypeLabel, $audienceLabel);
    }

    /**
     * Сгенерировать рекламный баннер курса переподготовки (квадрат 1:1).
     * Дизайн повторяет главную страницу fgos.pro: светлый сине-белый фон с сеткой,
     * индиго-типографика, белые карточки со скруглением и мягкой тенью, пилюли.
     * Safe-zone РСЯ: всё важное в центральных 60–70%.
     *   — логотип «ФГОС-Практикум» сверху;
     *   — пилюля «Профессиональная переподготовка» + крупная подпись аудитории (индиго);
     *   — главный объект: скан разрешения Сколково в белой карточке с тенью, по центру;
     *   — пилюля доверия снизу (Сколково · ФРДО · диплом гос. образца).
     * @return string Путь к файлу
     */
    public function generateCourseAd(string $outputPath, string $programTypeLabel, string $audienceLabel): string
    {
        if (!file_exists($this->razreshenieSkolkovoPath)) {
            return $this->generateAd($outputPath, 'course', $audienceLabel);
        }
        $scan = imagecreatefrompng($this->razreshenieSkolkovoPath);
        if (!$scan) {
            return $this->generateAd($outputPath, 'course', $audienceLabel);
        }

        $size = self::AD_COURSE_SIZE;
        $img = imagecreatetruecolor($size, $size);
        imagealphablending($img, true);
        imagesavealpha($img, true);

        // Палитра главной страницы
        $indigo600 = imagecolorallocate($img, 30, 58, 168);   // #1e3aa8
        $indigo700 = imagecolorallocate($img, 24, 47, 138);   // #182f8a
        $ink700    = imagecolorallocate($img, 42, 48, 86);    // #2a3056
        $white     = imagecolorallocate($img, 255, 255, 255);

        // Светлый вертикальный градиент #edf0ff → #fbfbfd + тонкая сетка
        $this->drawVerticalGradient($img, $size, $size, 237, 240, 255, 251, 251, 253);
        $this->drawDecorGrid($img, $size, $size);

        $fontBold = file_exists($this->fontMontserratBold) ? $this->fontMontserratBold : $this->fontBold;
        $maxTextW = (int)($size * 0.84);

        // --- Замеры для вертикального центрирования всего блока ---
        // Логотип
        $logo = file_exists($this->logoColorPath) ? imagecreatefrompng($this->logoColorPath) : false;
        $logoH = 64;
        $logoW = 0;
        if ($logo) {
            $logoW = (int)(imagesx($logo) * ($logoH / imagesy($logo)));
        }

        // Подпись аудитории (крупно, авто-подбор кегля)
        $audFs = 32; $audLines = [];
        foreach ([58, 50, 42, 36, 32] as $fs) {
            $lines = $this->wrapText($audienceLabel, $fs, $maxTextW, $fontBold);
            if (count($lines) <= 2) { $audFs = $fs; $audLines = $lines; break; }
        }
        if (empty($audLines)) { $audFs = 32; $audLines = $this->wrapText($audienceLabel, 32, $maxTextW, $fontBold); }
        $audLh = (int)($audFs * 1.16);

        // Скан (главный объект) в белой карточке
        $scanOrigW = imagesx($scan); $scanOrigH = imagesy($scan);
        $scanH = (int)($size * 0.42);
        $scanW = (int)($scanOrigW * ($scanH / $scanOrigH));
        $cardPad = 18;
        $cardW = $scanW + $cardPad * 2;
        $cardH = $scanH + $cardPad * 2;

        // Высоты пилюль
        $eyebrowFs = 21; $eyebrowH = $eyebrowFs + 26;
        $trustFs   = 23; $trustH   = $trustFs + 30;

        $gAfterLogo = 30; $gAfterEyebrow = 26; $gAfterAud = 38; $gAfterCard = 34;

        $blockH = ($logo ? $logoH + $gAfterLogo : 0)
            + $eyebrowH + $gAfterEyebrow
            + count($audLines) * $audLh + $gAfterAud
            + $cardH + $gAfterCard
            + $trustH;

        $y = (int)(($size - $blockH) / 2);
        if ($y < 46) { $y = 46; }
        $cx = (int)($size / 2);

        // --- 1) Логотип ---
        if ($logo) {
            imagecopyresampled($img, $logo, $cx - (int)($logoW / 2), $y, 0, 0, $logoW, $logoH, imagesx($logo), imagesy($logo));
            imagedestroy($logo);
            $y += $logoH + $gAfterLogo;
        }

        // --- 2) Пилюля «Профессиональная переподготовка» (индиго-50 фон, индиго-700 текст) ---
        $this->drawPill($img, $cx, $y, 'ПРОФЕССИОНАЛЬНАЯ ПЕРЕПОДГОТОВКА', $eyebrowFs, $fontBold,
            [236, 239, 255], $indigo700, null, false);
        $y += $eyebrowH + $gAfterEyebrow;

        // --- 3) Подпись аудитории (индиго, крупно) ---
        $y += $audFs;
        foreach ($audLines as $line) {
            $this->drawCenteredText($img, $line, $audFs, $y, $indigo600, $fontBold, $size);
            $y += $audLh;
        }
        $y += $gAfterAud - $audFs;

        // --- 4) Скан Сколково в белой карточке (скругление + мягкая индиго-тень) ---
        $cardX = $cx - (int)($cardW / 2);
        $cardY = $y;
        $this->drawCardShadow($img, $cardX, $cardY, $cardX + $cardW, $cardY + $cardH, 24);
        $this->drawRoundedRect($img, $cardX, $cardY, $cardX + $cardW, $cardY + $cardH, 24, $white);
        imagecopyresampled($img, $scan, $cardX + $cardPad, $cardY + $cardPad, 0, 0, $scanW, $scanH, $scanOrigW, $scanOrigH);
        imagedestroy($scan);
        $y = $cardY + $cardH + $gAfterCard;

        // --- 5) Пилюля доверия (белая, с бордером, тенью и бирюзовыми точками) ---
        $this->drawTrustPill($img, $cx, $y, ['Сколково', 'ФРДО', 'диплом гос. образца'], $trustFs, $fontBold, $ink700);

        imagejpeg($img, $outputPath, 92);
        imagedestroy($img);

        return $outputPath;
    }

    /**
     * Нарисовать строку текста по центру холста.
     */
    private function drawCenteredText(\GdImage $img, string $text, int $fontSize, int $y, int $color, string $font, int $canvasW): void
    {
        $bbox = imagettfbbox($fontSize, 0, $font, $text);
        $lineW = abs($bbox[2] - $bbox[0]);
        $x = (int)(($canvasW - $lineW) / 2);
        imagettftext($img, $fontSize, 0, $x, $y, $color, $font, $text);
    }

    /**
     * Вертикальный градиент (сверху вниз).
     */
    private function drawVerticalGradient(\GdImage $img, int $w, int $h, int $r1, int $g1, int $b1, int $r2, int $g2, int $b2): void
    {
        for ($y = 0; $y < $h; $y++) {
            $t = $y / $h;
            $color = imagecolorallocate($img,
                (int)($r1 + $t * ($r2 - $r1)),
                (int)($g1 + $t * ($g2 - $g1)),
                (int)($b1 + $t * ($b2 - $b1)));
            imageline($img, 0, $y, $w, $y, $color);
        }
    }

    /**
     * Тонкая декоративная сетка (как .rd-grid-bg на главной), затухающая книзу.
     */
    private function drawDecorGrid(\GdImage $img, int $w, int $h): void
    {
        $step = 46;
        for ($x = $step; $x < $w; $x += $step) {
            $alpha = 108; // полупрозрачная линия (0 — непрозр., 127 — прозр.)
            $c = imagecolorallocatealpha($img, 205, 212, 240, $alpha);
            imageline($img, $x, 0, $x, $h, $c);
        }
        for ($y = $step; $y < $h; $y += $step) {
            $alpha = 108 + (int)(($y / $h) * 18); // книзу чуть прозрачнее
            if ($alpha > 127) { $alpha = 127; }
            $c = imagecolorallocatealpha($img, 205, 212, 240, $alpha);
            imageline($img, 0, $y, $w, $y, $c);
        }
    }

    /**
     * Закруглённый прямоугольник (заливка).
     */
    private function drawRoundedRect(\GdImage $img, int $x1, int $y1, int $x2, int $y2, int $r, int $color): void
    {
        imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2 - $r, $color);
        $d = $r * 2;
        imagefilledellipse($img, $x1 + $r, $y1 + $r, $d, $d, $color);
        imagefilledellipse($img, $x2 - $r, $y1 + $r, $d, $d, $color);
        imagefilledellipse($img, $x1 + $r, $y2 - $r, $d, $d, $color);
        imagefilledellipse($img, $x2 - $r, $y2 - $r, $d, $d, $color);
    }

    /**
     * Мягкая тень под карточкой (несколько расширяющихся полупрозрачных индиго-слоёв).
     */
    private function drawCardShadow(\GdImage $img, int $x1, int $y1, int $x2, int $y2, int $r): void
    {
        // Мягкая тень со смещением вниз (без ореола сверху)
        for ($s = 28; $s >= 6; $s -= 4) {
            $col = imagecolorallocatealpha($img, 40, 64, 150, 123); // приглушённый индиго, очень прозрачный
            $top = $y1 - (int)($s * 0.30) + 20;
            $this->drawRoundedRect($img, $x1 - $s, $top, $x2 + $s, $y2 + $s + 22, $r + $s, $col);
        }
    }

    /**
     * Пилюля с текстом по центру (фон + опц. бордер). Возвращает ширину пилюли.
     */
    private function drawPill(\GdImage $img, int $cx, int $y, string $text, int $fontSize, string $font,
        array $bgRgb, int $textColor, ?array $borderRgb, bool $shadow): int
    {
        $bbox = imagettfbbox($fontSize, 0, $font, $text);
        $textW = abs($bbox[2] - $bbox[0]);
        $padX = (int)($fontSize * 1.5);
        $padY = 13;
        $pillW = $textW + $padX * 2;
        $pillH = $fontSize + $padY * 2;
        $x1 = $cx - (int)($pillW / 2);
        $x2 = $x1 + $pillW;
        $r = (int)($pillH / 2);

        if ($shadow) {
            for ($s = 14; $s >= 4; $s -= 3) {
                $a = 120; if ($a > 127) { $a = 127; }
                $sc = imagecolorallocatealpha($img, 46, 77, 217, $a);
                $this->drawRoundedRect($img, $x1 - $s, $y - $s + 8, $x2 + $s, $y + $pillH + $s + 8, $r + $s, $sc);
            }
        }
        if ($borderRgb !== null) {
            $bc = imagecolorallocate($img, $borderRgb[0], $borderRgb[1], $borderRgb[2]);
            $this->drawRoundedRect($img, $x1 - 2, $y - 2, $x2 + 2, $y + $pillH + 2, $r + 2, $bc);
        }
        $bg = imagecolorallocate($img, $bgRgb[0], $bgRgb[1], $bgRgb[2]);
        $this->drawRoundedRect($img, $x1, $y, $x2, $y + $pillH, $r, $bg);

        $ty = $y + $padY + $fontSize - 2;
        imagettftext($img, $fontSize, 0, $cx - (int)($textW / 2), $ty, $textColor, $font, $text);

        return $pillW;
    }

    /**
     * Белая пилюля доверия с бирюзовыми точками: «• Сколково  • ФРДО  • диплом гос. образца».
     */
    private function drawTrustPill(\GdImage $img, int $cx, int $y, array $items, int $fontSize, string $font, int $textColor): void
    {
        $dot = 12; $dotGap = 12; $itemGap = 34;

        // Замеряем ширину контента
        $contentW = 0; $widths = [];
        foreach ($items as $i => $it) {
            $bb = imagettfbbox($fontSize, 0, $font, $it);
            $w = abs($bb[2] - $bb[0]);
            $widths[$i] = $w;
            $contentW += $dot + $dotGap + $w;
            if ($i < count($items) - 1) { $contentW += $itemGap; }
        }
        $padX = 38; $padY = 15;
        $pillW = $contentW + $padX * 2;
        $pillH = $fontSize + $padY * 2;
        $x1 = $cx - (int)($pillW / 2);
        $x2 = $x1 + $pillW;
        $r  = (int)($pillH / 2);

        // тень + бордер + белый фон
        for ($s = 14; $s >= 4; $s -= 3) {
            $sc = imagecolorallocatealpha($img, 46, 77, 217, 122);
            $this->drawRoundedRect($img, $x1 - $s, $y - $s + 8, $x2 + $s, $y + $pillH + $s + 8, $r + $s, $sc);
        }
        $border = imagecolorallocate($img, 221, 224, 236); // #dde0ec
        $this->drawRoundedRect($img, $x1 - 2, $y - 2, $x2 + 2, $y + $pillH + 2, $r + 2, $border);
        $white = imagecolorallocate($img, 255, 255, 255);
        $this->drawRoundedRect($img, $x1, $y, $x2, $y + $pillH, $r, $white);

        // контент
        $teal = imagecolorallocate($img, 24, 184, 154); // #18b89a
        $cyMid = $y + (int)($pillH / 2);
        $ty = $y + $padY + $fontSize - 2;
        $x = $x1 + $padX;
        foreach ($items as $i => $it) {
            imagefilledellipse($img, $x + (int)($dot / 2), $cyMid, $dot, $dot, $teal);
            $x += $dot + $dotGap;
            imagettftext($img, $fontSize, 0, $x, $ty, $textColor, $font, $it);
            $x += $widths[$i] + $itemGap;
        }
    }

    // =============================================
    // РЕКЛАМНЫЕ КАРТИНКИ КОНКУРСОВ/ОЛИМПИАД/ВЕБИНАРОВ
    // (градиентный фон + веер дипломов + аудитория)
    // =============================================

    /**
     * Построить текст аудитории из target_participants конкурса
     * Берёт первый элемент через запятую и ищет совпадение в маппинге
     */
    public static function buildCompetitionAudienceLabel(string $targetParticipants): string
    {
        if (empty($targetParticipants)) {
            return 'ДЛЯ ПЕДАГОГОВ';
        }

        $parts = array_map('trim', explode(',', $targetParticipants));
        $first = $parts[0];

        // Ищем по началу строки (ключи маппинга — начало target_participants)
        foreach (self::COMPETITION_AUDIENCE_MAP as $prefix => $label) {
            if (mb_stripos($first, $prefix) === 0) {
                return 'ДЛЯ ' . $label;
            }
        }

        // Fallback: берём первый элемент как есть, обрезаем если слишком длинный
        $fallback = mb_strtoupper(trim($first));
        if (mb_strlen($fallback) > 30) {
            $fallback = mb_substr($fallback, 0, 27) . '...';
        }

        return 'ДЛЯ ' . $fallback;
    }

    /**
     * Построить текст аудитории из массива audience_categories
     * @param array $categories Массив из getAudienceCategories() — [{id, name, slug}, ...]
     * @return string Например «ДЛЯ ПЕДАГОГОВ»
     */
    public static function buildCategoryAudienceLabel(array $categories): string
    {
        if (empty($categories)) {
            return 'ДЛЯ ПЕДАГОГОВ';
        }

        if (count($categories) === 1) {
            $name = $categories[0]['name'] ?? '';
            $label = self::CATEGORY_AUDIENCE_MAP[$name] ?? null;
            return $label ? 'ДЛЯ ' . $label : 'ДЛЯ ПЕДАГОГОВ';
        }

        if (count($categories) === 2) {
            $label1 = self::CATEGORY_AUDIENCE_MAP[$categories[0]['name'] ?? ''] ?? null;
            $label2 = self::CATEGORY_AUDIENCE_MAP[$categories[1]['name'] ?? ''] ?? null;
            if ($label1 && $label2) {
                return 'ДЛЯ ' . $label1 . ' И ' . $label2;
            }
        }

        return 'ДЛЯ ПЕДАГОГОВ';
    }

    /**
     * Получить рекламную картинку контента (конкурс/олимпиада/вебинар) из кэша или сгенерировать
     * @return string Путь к JPG-файлу
     */
    public function getOrGenerateContentAd(string $cacheKey, string $typeLabel, string $audienceLabel): string
    {
        $filePath = $this->cacheDir . '/ad-' . $cacheKey . '.jpg';

        if (file_exists($filePath) && (time() - filemtime($filePath)) < self::CACHE_TTL) {
            return $filePath;
        }

        return $this->generateContentAd($filePath, $typeLabel, $audienceLabel);
    }

    /**
     * Сгенерировать рекламную картинку 600×600:
     * Градиент #2C3E50→#34495E (135°) + веер дипломов + тип + аудитория
     * @return string Путь к файлу
     */
    public function generateContentAd(string $outputPath, string $typeLabel, string $audienceLabel): string
    {
        $img = imagecreatetruecolor(self::AD_WIDTH, self::AD_HEIGHT);
        imagealphablending($img, true);
        imagesavealpha($img, true);

        // Градиент 135° (#2C3E50 → #34495E)
        $this->drawDiagonalGradient($img, 44, 62, 80, 52, 73, 94);

        // Накладываем веер дипломов
        $this->drawDiplomaFan($img);

        // Текст: тип продукта + аудитория (белым на тёмном фоне)
        $this->drawContentAdText($img, $typeLabel, $audienceLabel);

        // Логотип Сколково в правом нижнем углу
        $this->drawSkolkovoLogo($img);

        imagejpeg($img, $outputPath, 92);
        imagedestroy($img);

        return $outputPath;
    }

    /**
     * Диагональный градиент 135° (от верхнего левого к нижнему правому)
     * Оптимизация: рисуем построчно со средним цветом по строке
     */
    private function drawDiagonalGradient(\GdImage $img, int $r1, int $g1, int $b1, int $r2, int $g2, int $b2): void
    {
        $w = self::AD_WIDTH;
        $h = self::AD_HEIGHT;
        $maxDist = $w + $h;

        for ($y = 0; $y < $h; $y++) {
            // Средний ratio для строки (центр строки по X)
            $ratio = ($w / 2 + $y) / $maxDist;
            $r = (int)($r1 + $ratio * ($r2 - $r1));
            $g = (int)($g1 + $ratio * ($g2 - $g1));
            $b = (int)($b1 + $ratio * ($b2 - $b1));
            $color = imagecolorallocate($img, $r, $g, $b);
            imageline($img, 0, $y, $w, $y, $color);
        }
    }

    /**
     * Наложить веер дипломов из шаблона
     */
    private function drawDiplomaFan(\GdImage $img): void
    {
        if (!file_exists($this->diplomaFanTemplatePath)) {
            return;
        }

        $fan = imagecreatefrompng($this->diplomaFanTemplatePath);
        if (!$fan) {
            return;
        }

        $origW = imagesx($fan);
        $origH = imagesy($fan);

        // Масштабируем веер в нижнюю часть (занимает ~65% высоты)
        $targetH = (int)(self::AD_HEIGHT * 0.65);
        $targetW = (int)($origW * ($targetH / $origH));

        // Ограничиваем ширину
        if ($targetW > self::AD_WIDTH) {
            $targetW = self::AD_WIDTH;
            $targetH = (int)($origH * ($targetW / $origW));
        }

        $dstX = (int)((self::AD_WIDTH - $targetW) / 2);
        $dstY = self::AD_HEIGHT - $targetH;

        imagealphablending($img, true);
        imagecopyresampled($img, $fan, $dstX, $dstY, 0, 0, $targetW, $targetH, $origW, $origH);
        imagedestroy($fan);
    }

    /**
     * Логотип Сколково в правом нижнем углу
     * Используется предобработанный PNG с прозрачным фоном и белым текстом
     */
    private function drawSkolkovoLogo(\GdImage $img): void
    {
        if (!file_exists($this->skolkovoLogoPath)) {
            return;
        }

        $logo = imagecreatefrompng($this->skolkovoLogoPath);
        if (!$logo) {
            return;
        }

        $origW = imagesx($logo);
        $origH = imagesy($logo);

        $targetH = 70;
        $targetW = (int)($origW * ($targetH / $origH));

        $dstX = self::AD_WIDTH - $targetW - 15;
        $dstY = self::AD_HEIGHT - $targetH - 10;

        imagealphablending($img, true);
        imagecopyresampled($img, $logo, $dstX, $dstY, 0, 0, $targetW, $targetH, $origW, $origH);
        imagedestroy($logo);
    }

    /**
     * Нарисовать текст на рекламной картинке контента:
     * 1) Тип продукта (например «ВСЕРОССИЙСКИЙ КОНКУРС») — красным
     * 2) Аудитория (например «ДЛЯ ВОСПИТАТЕЛЕЙ») — белым
     */
    private function drawContentAdText(\GdImage $img, string $typeLabel, string $audienceLabel): void
    {
        $accentColor = imagecolorallocate($img, 74, 222, 128);  // #4ade80
        $whiteColor = imagecolorallocate($img, 255, 255, 255); // белый (на тёмном фоне)
        $maxWidth = self::AD_WIDTH - 40 * 2; // 520px

        $fontBold = file_exists($this->fontMontserratBold) ? $this->fontMontserratBold : $this->fontBold;

        // Вычисляем высоту блока текста для центрирования
        $typeFontSize = 16;
        $typeLines = $this->wrapText($typeLabel, $typeFontSize, $maxWidth, $fontBold);
        $typeLineHeight = (int)($typeFontSize * 1.5);
        $gapBetween = 14;

        $audFontSize = 15;
        $audLines = [];
        foreach ([26, 22, 18, 15] as $fs) {
            $lines = $this->wrapText($audienceLabel, $fs, $maxWidth, $fontBold);
            if (count($lines) <= 2) {
                $audFontSize = $fs;
                $audLines = $lines;
                break;
            }
        }
        $audLineHeight = (int)($audFontSize * 1.5);

        $totalH = count($typeLines) * $typeLineHeight + $gapBetween + count($audLines) * $audLineHeight;

        // Центрируем в верхней области (y=60..200)
        $areaTop = 60;
        $areaBottom = 200;
        $areaCenter = ($areaTop + $areaBottom) / 2;
        $startY = (int)($areaCenter - $totalH / 2) + $typeFontSize;

        // Строка 1: тип продукта акцентным цветом
        $y = $startY;
        foreach ($typeLines as $line) {
            $bbox = imagettfbbox($typeFontSize, 0, $fontBold, $line);
            $lineW = abs($bbox[2] - $bbox[0]);
            $x = (int)((self::AD_WIDTH - $lineW) / 2);
            imagettftext($img, $typeFontSize, 0, $x, $y, $accentColor, $fontBold, $line);
            $y += $typeLineHeight;
        }

        $y += $gapBetween;

        // Строка 2: аудитория белым, крупнее
        foreach ([26, 22, 18, 15] as $fontSize) {
            $lines = $this->wrapText($audienceLabel, $fontSize, $maxWidth, $fontBold);

            if (count($lines) <= 2) {
                $lineHeight = (int)($fontSize * 1.5);
                foreach ($lines as $line) {
                    $bbox = imagettfbbox($fontSize, 0, $fontBold, $line);
                    $lineW = abs($bbox[2] - $bbox[0]);
                    $x = (int)((self::AD_WIDTH - $lineW) / 2);
                    imagettftext($img, $fontSize, 0, $x, $y, $whiteColor, $fontBold, $line);
                    $y += $lineHeight;
                }
                return;
            }
        }

        // Fallback
        $fontSize = 15;
        $bbox = imagettfbbox($fontSize, 0, $fontBold, $audienceLabel);
        $lineW = abs($bbox[2] - $bbox[0]);
        $x = (int)((self::AD_WIDTH - $lineW) / 2);
        imagettftext($img, $fontSize, 0, max(30, $x), $y, $whiteColor, $fontBold, $audienceLabel);
    }
}
