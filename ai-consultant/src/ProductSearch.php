<?php
declare(strict_types=1);

/**
 * Динамический поиск по каталогу педпортала.
 * По запросу пользователя возвращает 5-10 релевантных товаров
 * для подстановки в промпт YandexGPT.
 */
class ProductSearch
{
    private PDO $pdo;

    /** Маппинг ключевых слов → slug аудитории в БД */
    private const AUDIENCE_KEYWORDS = [
        'dou' => ['доу', 'детский сад', 'дошкольн', 'воспитател', 'ясли'],
        'nachalnaya-shkola' => ['начальная школа', 'начальн', 'младшие классы', '1 класс', '2 класс', '3 класс', '4 класс'],
        'srednyaya-starshaya-shkola' => ['средн', 'старш', 'школа', 'учител', '5 класс', '9 класс', '10 класс', '11 класс', 'огэ', 'егэ'],
        'spo' => ['спо', 'колледж', 'техникум', 'студент'],
    ];

    /**
     * Regex-паттерны для детекции явно указанного типа продукта.
     * Порядок важен: competitions/olympiads/webinars проверяются первыми,
     * т.к. "курс" — подстрока от "конкурс" и без word-boundary ломает детект.
     * `(?<![\p{L}])` — отрицательный lookbehind: не буква перед (word-start для Unicode).
     */
    private const PRODUCT_TYPE_PATTERNS = [
        'competitions' => '/(?<![\p{L}])конкурс/u',
        'olympiads'    => '/(?<![\p{L}])олимпиад/u',
        'webinars'     => '/(?<![\p{L}])(вебинар|видеолекци)/u',
        'courses'      => '/(?<![\p{L}])(курс|повышени|квалификаци|переподготовк|кпк|удостоверени)/u',
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Найти до $limit релевантных товаров по тексту запроса.
     *
     * @return array<int, array{type:string, id:int, title:string, slug:string, price:float, url:string, meta:string}>
     */
    public function search(string $query, int $limit = 8): array
    {
        $queryLower = mb_strtolower($query);
        $audienceSlugs = $this->detectAudience($queryLower);
        $keywords = $this->extractKeywords($queryLower);
        $productType = $this->detectProductType($queryLower);

        // Маппинг таблица → url-префикс для однозначного типа
        $tableMap = [
            'competitions' => 'konkursy',
            'olympiads'    => 'olimpiady',
            'webinars'     => 'vebinar',
            'courses'      => 'kursy',
        ];

        // Если тип явно указан в запросе — ищем только там, с полным лимитом.
        // Если по ключевым словам не нашлось (бывает при нестандартных окончаниях) —
        // повторяем без keyword-фильтра, чтобы вернуть хоть какой-то топ из этого типа.
        if ($productType !== null) {
            $rows = $this->searchTable($productType, $tableMap[$productType], $keywords, $audienceSlugs, $limit);
            if (empty($rows) && !empty($keywords)) {
                $rows = $this->searchTable($productType, $tableMap[$productType], [], $audienceSlugs, $limit);
            }
            return $rows;
        }

        // Иначе — во всех четырёх таблицах с разделением лимита
        $results = [];
        $perCategory = max(1, (int)ceil($limit / 4));
        foreach ($tableMap as $table => $urlPrefix) {
            $results = array_merge(
                $results,
                $this->searchTable($table, $urlPrefix, $keywords, $audienceSlugs, $perCategory)
            );
        }

        return array_slice($results, 0, $limit);
    }

    /**
     * Определить явно указанный тип продукта в запросе. Возвращает null, если нет.
     */
    private function detectProductType(string $query): ?string
    {
        foreach (self::PRODUCT_TYPE_PATTERNS as $table => $pattern) {
            if (preg_match($pattern, $query)) {
                return $table;
            }
        }
        return null;
    }

    /**
     * Поиск в конкретной таблице продуктов.
     */
    private function searchTable(string $table, string $urlPrefix, array $keywords, array $audienceSlugs, int $limit): array
    {
        $priceCol = match ($table) {
            'olympiads' => 'diploma_price',
            'webinars' => 'certificate_price',
            default => 'price',
        };

        $junctionTable = $table === 'courses' ? 'course_audience_types' : rtrim($table, 's') . '_audience_types';
        $fkColumn = rtrim($table, 's') . '_id';
        if ($table === 'courses') $fkColumn = 'course_id';

        $conditions = ['t.is_active = 1'];
        $params = [];

        // Расчёт релевантности по ключевым словам (MAX-обёртка для совместимости с ONLY_FULL_GROUP_BY в MySQL 8)
        $scoreExpr = '0';
        if (!empty($keywords)) {
            $scoreParts = [];
            foreach ($keywords as $kw) {
                $scoreParts[] = '(CASE WHEN LOWER(t.title) LIKE ? THEN 3 ELSE 0 END)';
                $params[] = '%' . $kw . '%';
            }
            $scoreExpr = 'MAX(' . implode(' + ', $scoreParts) . ')';
        }

        // Фильтр по аудитории
        $audienceJoin = '';
        if (!empty($audienceSlugs)) {
            $placeholders = implode(',', array_fill(0, count($audienceSlugs), '?'));
            $audienceJoin = "
                LEFT JOIN {$junctionTable} j ON t.id = j.{$fkColumn}
                LEFT JOIN audience_types at ON j.audience_type_id = at.id AND at.slug IN ($placeholders)
            ";
            $params = array_merge($params, $audienceSlugs);
            $audienceScore = 'MAX(CASE WHEN at.id IS NOT NULL THEN 2 ELSE 0 END)';
            $scoreExpr = $scoreExpr === '0' ? $audienceScore : $scoreExpr . ' + ' . $audienceScore;
        }

        // webinars: только активные статусы
        if ($table === 'webinars') {
            $conditions[] = "t.status IN ('completed','videolecture','scheduled')";
        }

        $whereClause = implode(' AND ', $conditions);
        $limitSafe = (int)$limit;

        $sql = "
            SELECT t.id, t.title, t.slug, t.{$priceCol} AS price,
                   {$scoreExpr} AS score
            FROM {$table} t
            {$audienceJoin}
            WHERE {$whereClause}
            GROUP BY t.id
            ORDER BY score DESC, t.id DESC
            LIMIT {$limitSafe}
        ";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
        } catch (Throwable $e) {
            ai_log('SEARCH', "Query failed for {$table}", ['error' => $e->getMessage()]);
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            // Пропустить товары с нулевой релевантностью когда были ключевые слова
            if (!empty($keywords) && (int)$row['score'] === 0 && empty($audienceSlugs)) continue;

            $typeLabel = match ($table) {
                'competitions' => 'Конкурс',
                'olympiads' => 'Олимпиада',
                'webinars' => 'Вебинар',
                'courses' => 'Курс',
            };

            $url = $table === 'webinars'
                ? AI_SITE_URL . '/' . $urlPrefix . '/' . $row['slug'] . '/'
                : AI_SITE_URL . '/' . $urlPrefix . '/' . $row['slug'] . '/';

            $out[] = [
                'type' => rtrim($table, 's'),
                'id' => (int)$row['id'],
                'title' => (string)$row['title'],
                'slug' => (string)$row['slug'],
                'price' => (float)$row['price'],
                'url' => $url,
                'meta' => $typeLabel,
            ];
        }
        return $out;
    }

    /**
     * Извлечь ключевые слова из запроса (lowercase, без стоп-слов, длиной >=3).
     */
    private function extractKeywords(string $query): array
    {
        $stopWords = [
            'и','в','на','с','по','для','от','к','о','а','но','же','как','что','это',
            'мне','я','мы','ты','вы','он','она','они','у','за','из','при','до','не','ни',
            'есть','быть','мой','моя','моё','наш','ваш','тот','эта','это','этот','вот','там',
            'нужен','нужно','нужна','хочу','хочется','можно','помогите','подскажите','расскажите'
        ];
        $cleaned = preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $query) ?? '';
        $words = preg_split('/\s+/u', trim($cleaned)) ?: [];
        $kw = [];
        foreach ($words as $w) {
            if (mb_strlen($w) < 3) continue;
            if (in_array($w, $stopWords, true)) continue;
            $kw[] = $w;
        }
        return array_values(array_unique($kw));
    }

    /**
     * Определить slug аудитории по тексту запроса.
     */
    private function detectAudience(string $query): array
    {
        $slugs = [];
        foreach (self::AUDIENCE_KEYWORDS as $slug => $keywords) {
            foreach ($keywords as $kw) {
                if (mb_strpos($query, $kw) !== false) {
                    $slugs[] = $slug;
                    break;
                }
            }
        }
        return $slugs;
    }

    /**
     * Получить конкретные продукты по списку {type,id} (для допродажи в корзине).
     */
    public function getByIds(array $items): array
    {
        $out = [];
        $grouped = [];
        foreach ($items as $it) {
            $type = $it['type'] ?? '';
            $id = (int)($it['id'] ?? 0);
            if ($id <= 0) continue;
            $grouped[$type][] = $id;
        }

        $map = [
            'competition' => ['competitions', 'price', 'konkursy'],
            'olympiad' => ['olympiads', 'diploma_price', 'olimpiady'],
            'webinar' => ['webinars', 'certificate_price', 'vebinar'],
            'course' => ['courses', 'price', 'kursy'],
        ];

        foreach ($grouped as $type => $ids) {
            if (!isset($map[$type])) continue;
            [$table, $priceCol, $urlPrefix] = $map[$type];
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            try {
                $stmt = $this->pdo->prepare("SELECT id, title, slug, {$priceCol} AS price FROM {$table} WHERE id IN ($placeholders)");
                $stmt->execute($ids);
                foreach ($stmt->fetchAll() as $row) {
                    $out[] = [
                        'type' => $type,
                        'id' => (int)$row['id'],
                        'title' => (string)$row['title'],
                        'slug' => (string)$row['slug'],
                        'price' => (float)$row['price'],
                        'url' => AI_SITE_URL . '/' . $urlPrefix . '/' . $row['slug'] . '/',
                    ];
                }
            } catch (Throwable $e) {
                ai_log('SEARCH', 'getByIds failed', ['table' => $table, 'error' => $e->getMessage()]);
            }
        }
        return $out;
    }
}
