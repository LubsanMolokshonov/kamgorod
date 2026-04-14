<?php
/**
 * Генератор YML-фидов для Яндекс Директ (товарная реклама)
 * Формирует фиды для конкурсов, олимпиад, курсов и вебинаров
 */
class YmlFeedGenerator
{
    private Database $db;
    private string $baseUrl;

    // Категории для каждого типа фида
    private const COMPETITION_CATEGORIES = [
        'methodology'    => ['id' => 11, 'name' => 'Методические конкурсы'],
        'extracurricular' => ['id' => 12, 'name' => 'Конкурсы внеурочной деятельности'],
        'student_projects' => ['id' => 13, 'name' => 'Конкурсы проектов учащихся'],
        'creative'       => ['id' => 14, 'name' => 'Творческие конкурсы'],
    ];

    private const COURSE_CATEGORIES = [
        'kpk' => ['id' => 31, 'name' => 'Повышение квалификации'],
        'pp'  => ['id' => 32, 'name' => 'Профессиональная переподготовка'],
    ];

    private const WEBINAR_CATEGORIES = [
        'upcoming'     => ['id' => 41, 'name' => 'Предстоящие вебинары'],
        'recordings'   => ['id' => 42, 'name' => 'Записи вебинаров'],
        'videolecture' => ['id' => 43, 'name' => 'Видеолекции'],
    ];

    // Маппинг специализаций → короткие ярлыки для рекламных заголовков (дательный падеж)
    private const SPECIALIZATION_HEADLINE_MAP = [
        'Логопедия'                       => 'логопедов',
        'Инструктор по физкультуре'       => 'инструкторов физкультуры',
        'Педагог-психолог'                => 'психологов',
        'Работа с детьми с ОВЗ'           => 'педагогов ОВЗ',
        'Социальная педагогика'           => 'соц. педагогов',
        'Классное руководство'            => 'кл. руководителей',
        'Младший воспитатель'             => 'мл. воспитателей',
        'Педагог дополнительного образования' => 'педагогов доп. образования',
        'Администрация и управление'      => 'руководителей ОО',
        'Старший воспитатель'             => 'ст. воспитателей',
        'Учитель'                         => 'учителей',
        'Воспитатель'                     => 'воспитателей',
    ];

    // Приоритет специализаций: чем меньше индекс, тем выше приоритет (узкие — первыми)
    // Воспитатель выше Старшего — более широкая аудитория для рекламы
    private const SPECIALIZATION_PRIORITY = [
        'Логопедия',
        'Инструктор по физкультуре',
        'Педагог-психолог',
        'Работа с детьми с ОВЗ',
        'Социальная педагогика',
        'Классное руководство',
        'Младший воспитатель',
        'Педагог дополнительного образования',
        'Воспитатель',
        'Учитель',
        'Администрация и управление',
        'Старший воспитатель',
    ];

    public function __construct($pdo)
    {
        $this->db = new Database($pdo);
        $this->baseUrl = rtrim(SITE_URL, '/');
    }

    /**
     * Сгенерировать полный YML-документ для указанного типа
     */
    public function generate(string $type): string
    {
        $date = date('Y-m-d H:i');

        $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
        $xml .= '<yml_catalog date="' . $date . '">' . "\n";
        $xml .= '<shop>' . "\n";
        $xml .= '<name>Педагогический портал «Каменный город»</name>' . "\n";
        $xml .= '<company>АНО ДПО «Каменный город»</company>' . "\n";
        $xml .= '<url>' . $this->xmlEscape($this->baseUrl) . '/</url>' . "\n";
        $xml .= '<currencies><currency id="RUB" rate="1"/></currencies>' . "\n";

        // Категории
        $xml .= $this->buildCategoriesXml($type);

        // Офферы
        $xml .= '<offers>' . "\n";

        switch ($type) {
            case 'competitions':
                $xml .= $this->buildCompetitionOffers();
                break;
            case 'olympiads':
                $xml .= $this->buildOlympiadOffers();
                break;
            case 'courses':
                $xml .= $this->buildCourseOffers();
                break;
            case 'courses-ad':
                $xml .= $this->buildCourseAdOffers();
                break;
            case 'webinars':
                $xml .= $this->buildWebinarOffers();
                break;
        }

        $xml .= '</offers>' . "\n";
        $xml .= '</shop>' . "\n";
        $xml .= '</yml_catalog>';

        return $xml;
    }

    /**
     * Блок <categories> для указанного типа фида
     */
    private function buildCategoriesXml(string $type): string
    {
        $xml = '<categories>' . "\n";

        switch ($type) {
            case 'competitions':
                $xml .= '<category id="1">Конкурсы для педагогов</category>' . "\n";
                foreach (self::COMPETITION_CATEGORIES as $key => $cat) {
                    $xml .= '<category id="' . $cat['id'] . '" parentId="1">' . $this->xmlEscape($cat['name']) . '</category>' . "\n";
                }
                break;

            case 'olympiads':
                $xml .= '<category id="2">Олимпиады</category>' . "\n";
                // Загружаем категории аудитории из БД
                $categories = $this->db->query(
                    "SELECT id, name, slug FROM audience_categories WHERE is_active = 1 ORDER BY display_order"
                );
                foreach ($categories as $cat) {
                    $xml .= '<category id="2' . $cat['id'] . '" parentId="2">' . $this->xmlEscape('Олимпиады — ' . $cat['name']) . '</category>' . "\n";
                }
                break;

            case 'courses':
            case 'courses-ad':
                $xml .= '<category id="3">Курсы для педагогов</category>' . "\n";
                foreach (self::COURSE_CATEGORIES as $key => $cat) {
                    $xml .= '<category id="' . $cat['id'] . '" parentId="3">' . $this->xmlEscape($cat['name']) . '</category>' . "\n";
                }
                break;

            case 'webinars':
                $xml .= '<category id="4">Вебинары для педагогов</category>' . "\n";
                foreach (self::WEBINAR_CATEGORIES as $key => $cat) {
                    $xml .= '<category id="' . $cat['id'] . '" parentId="4">' . $this->xmlEscape($cat['name']) . '</category>' . "\n";
                }
                break;
        }

        $xml .= '</categories>' . "\n";
        return $xml;
    }

    // =============================================
    // КОНКУРСЫ
    // =============================================

    private function buildCompetitionOffers(): string
    {
        $competitions = $this->db->query(
            "SELECT * FROM competitions WHERE is_active = 1 ORDER BY display_order ASC, created_at DESC"
        );

        $xml = '';
        foreach ($competitions as $comp) {
            $categoryId = self::COMPETITION_CATEGORIES[$comp['category']]['id'] ?? 1;
            $description = $this->buildCompetitionDescription($comp);

            $xml .= $this->buildOfferXml([
                'id'          => 'comp-' . $comp['id'],
                'url'         => $this->baseUrl . '/konkursy/' . $comp['slug'] . '/',
                'price'       => $comp['price'] ?? '',
                'categoryId'  => $categoryId,
                'picture'     => $this->baseUrl . '/og-image/ad/competition/' . $comp['slug'] . '.jpg',
                'name'        => $comp['title'],
                'description' => $description,
                'sales_notes' => 'Результат и диплом — в день отправки работы',
                'params'      => [
                    ['name' => 'Тип', 'value' => 'Конкурс'],
                    ['name' => 'Уровень', 'value' => 'Всероссийский'],
                    ['name' => 'Участники', 'value' => $comp['target_participants'] ?? 'Педагоги'],
                    ['name' => 'Документ', 'value' => 'Диплом'],
                    ['name' => 'Формат', 'value' => 'Дистанционный'],
                ],
            ]);
        }

        return $xml;
    }

    private function buildCompetitionDescription(array $comp): string
    {
        $desc = 'Всероссийский педагогический конкурс «' . $comp['title'] . '»';

        if (!empty($comp['target_participants'])) {
            $desc .= ' для ' . mb_strtolower($comp['target_participants_genitive'] ?? $comp['target_participants']);
        }

        $desc .= '. ';

        if (!empty($comp['description'])) {
            $desc .= $this->extractSentences($comp['description'], 2) . ' ';
        }

        $desc .= 'Результат и именной диплом — в день отправки работы! Принимаем работы круглосуточно.';

        return $this->cleanText($desc);
    }

    // =============================================
    // ОЛИМПИАДЫ
    // =============================================

    private function buildOlympiadOffers(): string
    {
        $olympiads = $this->db->query(
            "SELECT o.*, GROUP_CONCAT(DISTINCT oac.category_id) as category_ids
             FROM olympiads o
             LEFT JOIN olympiad_audience_categories oac ON o.id = oac.olympiad_id
             WHERE o.is_active = 1
             GROUP BY o.id
             ORDER BY o.display_order ASC, o.created_at DESC"
        );

        $xml = '';
        foreach ($olympiads as $oly) {
            // Определяем категорию в фиде
            $firstCatId = 2; // дефолт
            if (!empty($oly['category_ids'])) {
                $catIds = explode(',', $oly['category_ids']);
                $firstCatId = '2' . $catIds[0];
            }

            $description = $this->buildOlympiadDescription($oly);

            $xml .= $this->buildOfferXml([
                'id'          => 'oly-' . $oly['id'],
                'url'         => $this->baseUrl . '/olimpiady/' . $oly['slug'] . '/',
                'price'       => $oly['diploma_price'] ?? '169',
                'categoryId'  => $firstCatId,
                'picture'     => $this->baseUrl . '/og-image/ad/olympiad/' . $oly['slug'] . '.jpg',
                'name'        => $oly['title'],
                'description' => $description,
                'sales_notes' => 'Участие бесплатное. Диплом — ' . ($oly['diploma_price'] ?? '169') . ' ₽',
                'params'      => [
                    ['name' => 'Тип', 'value' => 'Олимпиада'],
                    ['name' => 'Уровень', 'value' => 'Всероссийский'],
                    ['name' => 'Предмет', 'value' => $oly['subject'] ?? ''],
                    ['name' => 'Документ', 'value' => 'Диплом'],
                    ['name' => 'Формат', 'value' => 'Дистанционный'],
                ],
            ]);
        }

        return $xml;
    }

    private function buildOlympiadDescription(array $oly): string
    {
        $desc = 'Всероссийская олимпиада «' . $oly['title'] . '»';

        if (!empty($oly['subject'])) {
            $desc .= ' по предмету «' . $oly['subject'] . '»';
        }

        $desc .= '. ';

        if (!empty($oly['description'])) {
            $desc .= $this->extractSentences($oly['description'], 2) . ' ';
        }

        $desc .= 'Участие бесплатное! Именной диплом — ' . ($oly['diploma_price'] ?? '169') . ' ₽. Результат — сразу после прохождения.';

        return $this->cleanText($desc);
    }

    // =============================================
    // КУРСЫ
    // =============================================

    private function buildCourseOffers(): string
    {
        $courses = $this->db->query(
            "SELECT * FROM courses WHERE is_active = 1 ORDER BY display_order ASC, created_at DESC"
        );

        $xml = '';
        foreach ($courses as $course) {
            $categoryId = self::COURSE_CATEGORIES[$course['program_type']]['id'] ?? 31;
            $description = $this->buildCourseDescription($course);
            $programLabel = $course['program_type'] === 'pp' ? 'Профессиональная переподготовка' : 'Повышение квалификации';
            $docLabel = $course['program_type'] === 'pp' ? 'Диплом' : 'Удостоверение';

            $xml .= $this->buildOfferXml([
                'id'          => 'crs-' . $course['id'],
                'url'         => $this->baseUrl . '/kursy/' . $course['slug'] . '/',
                'price'       => $course['price'] ?? '',
                'categoryId'  => $categoryId,
                'picture'     => $this->baseUrl . '/og-image/ad/course/' . $course['slug'] . '.jpg',
                'name'        => $course['title'],
                'description' => $description,
                'sales_notes' => $docLabel . ' установленного образца. ' . ($course['hours'] ?? '') . ' ч.',
                'params'      => [
                    ['name' => 'Тип', 'value' => $programLabel],
                    ['name' => 'Часы', 'value' => (string)($course['hours'] ?? ''), 'unit' => 'ч'],
                    ['name' => 'Документ', 'value' => $docLabel . ' установленного образца'],
                    ['name' => 'Формат', 'value' => 'Дистанционный'],
                    ['name' => 'Уровень', 'value' => 'Всероссийский'],
                ],
            ]);
        }

        return $xml;
    }

    private function buildCourseDescription(array $course): string
    {
        $programLabel = $course['program_type'] === 'pp' ? 'Профессиональная переподготовка' : 'Повышение квалификации';
        $docLabel = $course['program_type'] === 'pp' ? 'Диплом' : 'Удостоверение';

        $desc = $programLabel . ' «' . $course['title'] . '»';

        if (!empty($course['hours'])) {
            $desc .= ', ' . $course['hours'] . ' часов';
        }

        $desc .= '. ';

        if (!empty($course['description'])) {
            $desc .= $this->extractSentences($course['description'], 2) . ' ';
        }

        $desc .= 'Дистанционно. ' . $docLabel . ' установленного образца. Лицензия на образовательную деятельность.';

        return $this->cleanText($desc);
    }

    // =============================================
    // КУРСЫ (рекламный фид courses-ad)
    // =============================================

    private function buildCourseAdOffers(): string
    {
        $courses = $this->db->query(
            "SELECT c.*,
                    GROUP_CONCAT(DISTINCT asp.name ORDER BY asp.name) as specializations
             FROM courses c
             LEFT JOIN course_specializations cs ON c.id = cs.course_id
             LEFT JOIN audience_specializations asp ON cs.specialization_id = asp.id
             WHERE c.is_active = 1
             GROUP BY c.id
             ORDER BY c.display_order ASC, c.created_at DESC"
        );

        $xml = '';
        foreach ($courses as $course) {
            $categoryId = self::COURSE_CATEGORIES[$course['program_type']]['id'] ?? 31;
            $docLabel = $course['program_type'] === 'pp' ? 'Диплом' : 'Удостоверение';
            $specs = !empty($course['specializations']) ? explode(',', $course['specializations']) : [];
            $primarySpec = $this->getPrimarySpecialization($specs);

            $headline = $this->buildCourseAdHeadline($course, $primarySpec);
            $description = $this->buildCourseAdDescription($course);

            $hours = $course['hours'] ?? '';
            $salesNotes = $docLabel . ' гос. образца · ' . $hours . ' ч';

            $params = [
                ['name' => 'Тип', 'value' => $course['program_type'] === 'pp' ? 'Профессиональная переподготовка' : 'Повышение квалификации'],
                ['name' => 'Часы', 'value' => (string)$hours, 'unit' => 'ч'],
                ['name' => 'Документ', 'value' => $docLabel . ' установленного образца'],
                ['name' => 'Формат', 'value' => 'Дистанционный'],
                ['name' => 'Уровень', 'value' => 'Всероссийский'],
                ['name' => 'Аудитория', 'value' => $primarySpec ?: 'Педагоги'],
            ];

            $xml .= $this->buildOfferXml([
                'id'          => 'crs-' . $course['id'],
                'url'         => $this->baseUrl . '/kursy/' . $course['slug'] . '/',
                'price'       => $course['price'] ?? '',
                'categoryId'  => $categoryId,
                'picture'     => $this->baseUrl . '/og-image/ad/course/' . $course['slug'] . '.jpg',
                'name'        => $headline,
                'description' => $description,
                'sales_notes' => $salesNotes,
                'params'      => $params,
            ]);
        }

        return $xml;
    }

    /**
     * Выбрать наиболее приоритетную (узкую) специализацию
     */
    private function getPrimarySpecialization(array $specs): string
    {
        if (empty($specs)) {
            return '';
        }

        $specs = array_map('trim', $specs);
        $bestIndex = PHP_INT_MAX;
        $bestSpec = $specs[0];

        foreach ($specs as $spec) {
            $index = array_search($spec, self::SPECIALIZATION_PRIORITY, true);
            if ($index !== false && $index < $bestIndex) {
                $bestIndex = $index;
                $bestSpec = $spec;
            }
        }

        return $bestSpec;
    }

    /**
     * Построить рекламный заголовок для курса (до 56 символов)
     */
    private function buildCourseAdHeadline(array $course, string $primarySpec): string
    {
        $label = self::SPECIALIZATION_HEADLINE_MAP[$primarySpec] ?? 'педагогов';
        $hours = $course['hours'] ?? '';
        $programPrefix = $course['program_type'] === 'pp' ? 'ПП' : 'КПК';

        // Вариант 1: полный — "КПК для логопедов. 72 ч. Сколково + ФРДО"
        $headline = $programPrefix . ' для ' . $label . '. ' . $hours . ' ч. Сколково + ФРДО';
        if (mb_strlen($headline) <= 56) {
            return $headline;
        }

        // Вариант 2: без Сколково — "КПК для логопедов. 72 ч. ФРДО"
        $headline = $programPrefix . ' для ' . $label . '. ' . $hours . ' ч. ФРДО';
        if (mb_strlen($headline) <= 56) {
            return $headline;
        }

        // Вариант 3: минимальный — "КПК для логопедов. 72 ч"
        $headline = $programPrefix . ' для ' . $label . '. ' . $hours . ' ч';
        if (mb_strlen($headline) <= 56) {
            return $headline;
        }

        // Вариант 4: обрезка
        return mb_substr($headline, 0, 56);
    }

    /**
     * Построить рекламное описание для курса
     * Первые 81 символ — видимая часть в объявлении Яндекс Директ
     */
    private function buildCourseAdDescription(array $course): string
    {
        $programLabel = $course['program_type'] === 'pp' ? 'Профессиональная переподготовка' : 'Повышение квалификации';
        $docLabel = $course['program_type'] === 'pp' ? 'Диплом' : 'Удостоверение';
        $hours = $course['hours'] ?? '';

        // Видимая часть (до 81 символа) — ключевые преимущества
        $desc = $docLabel . ' гос. образца · Сколково · ФИС ФРДО. Для аттестации! ';

        // Развёрнутая часть для алгоритма Яндекса
        $desc .= $programLabel . ' «' . $course['title'] . '»';

        if (!empty($hours)) {
            $desc .= ', ' . $hours . ' часов';
        }

        $desc .= '. ';

        if (!empty($course['description'])) {
            $desc .= $this->extractSentences($course['description'], 2) . ' ';
        }

        $desc .= 'Дистанционно. Лицензия на образовательную деятельность. Данные вносятся в ФИС ФРДО. Начните обучение в любое время.';

        return $this->cleanText($desc);
    }

    // =============================================
    // ВЕБИНАРЫ
    // =============================================

    private function buildWebinarOffers(): string
    {
        // Все активные вебинары с нужными статусами + спикеры
        $webinars = $this->db->query(
            "SELECT w.*, s.full_name as speaker_name
             FROM webinars w
             LEFT JOIN speakers s ON w.speaker_id = s.id
             WHERE w.is_active = 1
               AND (
                   w.status IN ('scheduled', 'live', 'videolecture')
                   OR (w.status = 'completed' AND w.video_url IS NOT NULL)
               )
             ORDER BY w.scheduled_at DESC"
        );

        $xml = '';
        foreach ($webinars as $web) {
            // Определяем категорию
            $categoryId = 41; // предстоящие по умолчанию
            if (in_array($web['status'], ['scheduled', 'live'])) {
                $categoryId = 41;
            } elseif ($web['status'] === 'completed') {
                $categoryId = 42;
            } elseif ($web['status'] === 'videolecture') {
                $categoryId = 43;
            }

            $description = $this->buildWebinarDescription($web);

            // Картинка: cover_image или генерированная
            $picture = !empty($web['cover_image'])
                ? $this->baseUrl . $web['cover_image']
                : $this->baseUrl . '/og-image/ad/webinar/' . $web['slug'] . '.jpg';

            $price = $web['certificate_price'] ?? '200';

            $params = [
                ['name' => 'Тип', 'value' => $web['status'] === 'videolecture' ? 'Видеолекция' : 'Вебинар'],
                ['name' => 'Документ', 'value' => 'Сертификат'],
                ['name' => 'Формат', 'value' => 'Дистанционный'],
            ];

            if (!empty($web['certificate_hours'])) {
                $params[] = ['name' => 'Часы', 'value' => (string)$web['certificate_hours'], 'unit' => 'ч'];
            }

            if (!empty($web['speaker_name'])) {
                $params[] = ['name' => 'Спикер', 'value' => $web['speaker_name']];
            }

            if (!empty($web['scheduled_at']) && in_array($web['status'], ['scheduled', 'live'])) {
                $params[] = ['name' => 'Дата', 'value' => date('d.m.Y', strtotime($web['scheduled_at']))];
            }

            $salesNotes = 'Сертификат ' . ($web['certificate_hours'] ?? 2) . ' ч. — ' . $price . ' ₽';

            $xml .= $this->buildOfferXml([
                'id'          => 'web-' . $web['id'],
                'url'         => $this->baseUrl . '/vebinar/' . $web['slug'] . '/',
                'price'       => $price,
                'categoryId'  => $categoryId,
                'picture'     => $picture,
                'name'        => $web['title'],
                'description' => $description,
                'sales_notes' => $salesNotes,
                'params'      => $params,
            ]);
        }

        return $xml;
    }

    private function buildWebinarDescription(array $web): string
    {
        $typeLabel = $web['status'] === 'videolecture' ? 'Видеолекция' : 'Вебинар';
        $desc = $typeLabel . ' «' . $web['title'] . '»';

        if (!empty($web['speaker_name'])) {
            $desc .= '. Спикер: ' . $web['speaker_name'];
        }

        $desc .= '. ';

        $textSource = !empty($web['short_description']) ? $web['short_description'] : ($web['description'] ?? '');
        if (!empty($textSource)) {
            $desc .= $this->extractSentences($textSource, 2) . ' ';
        }

        $hours = $web['certificate_hours'] ?? 2;
        $desc .= 'Сертификат ' . $hours . ' ч. для аттестации.';

        if (in_array($web['status'], ['scheduled', 'live']) && !empty($web['scheduled_at'])) {
            $desc .= ' Дата: ' . date('d.m.Y', strtotime($web['scheduled_at'])) . '.';
        }

        return $this->cleanText($desc);
    }

    // =============================================
    // ОБЩИЕ ХЕЛПЕРЫ
    // =============================================

    /**
     * Построить XML одного <offer>
     */
    private function buildOfferXml(array $data): string
    {
        $xml = '<offer id="' . $this->xmlEscape($data['id']) . '" available="true">' . "\n";
        $xml .= '  <url>' . $this->xmlEscape($data['url']) . '</url>' . "\n";

        if (!empty($data['price']) && (float)$data['price'] > 0) {
            $xml .= '  <price>' . number_format((float)$data['price'], 2, '.', '') . '</price>' . "\n";
            $xml .= '  <currencyId>RUB</currencyId>' . "\n";
        }

        $xml .= '  <categoryId>' . $this->xmlEscape((string)$data['categoryId']) . '</categoryId>' . "\n";
        $xml .= '  <picture>' . $this->xmlEscape($data['picture']) . '</picture>' . "\n";
        $xml .= '  <name>' . $this->xmlEscape($data['name']) . '</name>' . "\n";

        if (!empty($data['description'])) {
            $xml .= '  <description>' . $this->xmlEscape($data['description']) . '</description>' . "\n";
        }

        if (!empty($data['sales_notes'])) {
            $xml .= '  <sales_notes>' . $this->xmlEscape($data['sales_notes']) . '</sales_notes>' . "\n";
        }

        if (!empty($data['params'])) {
            foreach ($data['params'] as $param) {
                if (empty($param['value'])) {
                    continue;
                }
                $unit = !empty($param['unit']) ? ' unit="' . $this->xmlEscape($param['unit']) . '"' : '';
                $xml .= '  <param name="' . $this->xmlEscape($param['name']) . '"' . $unit . '>' . $this->xmlEscape($param['value']) . '</param>' . "\n";
            }
        }

        $xml .= '</offer>' . "\n";
        return $xml;
    }

    /**
     * Очистить текст от HTML и лишних пробелов, обрезать по длине
     */
    private function cleanText(string $html, int $maxLen = 3000): string
    {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        if (mb_strlen($text) > $maxLen) {
            $text = mb_substr($text, 0, $maxLen - 3) . '...';
        }

        return $text;
    }

    /**
     * Извлечь первые N предложений из текста
     */
    private function extractSentences(string $html, int $count = 2): string
    {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', trim($text));

        // Разбиваем по точкам, вопросительным и восклицательным знакам
        $sentences = preg_split('/(?<=[.!?])\s+/', $text, $count + 1);
        $result = array_slice($sentences, 0, $count);

        return implode(' ', $result);
    }

    /**
     * Экранировать спецсимволы для XML
     */
    private function xmlEscape(string $str): string
    {
        return htmlspecialchars($str, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
