<?php
if (php_sapi_name() !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(403); die('CLI only'); }
/** Ограниченные выборки каталогов для SSR, поиска и AJAX. */
require_once __DIR__ . '/Database.php';

class CatalogListing {
    private Database $db;
    private string $section;
    private string $from;
    private string $where;
    private array $params = [];
    private string $order;
    private string $columns;
    public const PAGE_SIZE = 24;

    public function __construct(PDO $pdo, string $section, array $options = [], string $search = '') {
        $this->db = new Database($pdo);
        $this->section = $section;
        $map = ['kursy' => ['course', 'courses'], 'olimpiady' => ['olympiad', 'olympiads'],
            'publikacii' => ['publication', 'publications'], 'konkursy' => ['competition', 'competitions'],
            'vebinary' => ['webinar', 'webinars']];
        if (!isset($map[$section])) throw new InvalidArgumentException('Неизвестный каталог');
        [$entity, $table] = $map[$section];
        $this->from = "$table p";
        $wheres = [$section === 'publikacii' ? "p.status = 'published'" : 'p.is_active = 1'];
        $this->columns = 'p.id, p.slug, p.title';
        $this->order = 'p.display_order ASC, p.created_at DESC, p.id DESC';
        if ($section === 'publikacii') {
            $this->from .= ' LEFT JOIN publication_types pt ON pt.id = p.publication_type_id LEFT JOIN users u ON u.id = p.user_id';
            $this->columns .= ', p.source, p.annotation, p.published_at, p.created_at, p.views_count, p.rating_count, p.rating_avg, pt.name AS type_name, u.full_name AS author_name';
            $this->order = 'p.published_at DESC, p.id DESC';
            // В публичном каталоге и поиске один и тот же доступный роботам набор.
            $wheres[] = "(p.redirect_to_slug IS NULL OR p.redirect_to_slug = '')";
            $wheres[] = 'p.noindex = 0 AND p.indexable_at IS NOT NULL AND p.indexable_at <= DATE_ADD(UTC_TIMESTAMP(), INTERVAL 3 HOUR)';
        } elseif ($section === 'kursy') {
            $this->columns .= ', p.description, p.price, p.program_type, p.hours';
        } elseif ($section === 'olimpiady') {
            $this->columns .= ', p.description, p.subject, p.target_audience, p.diploma_price';
        } elseif ($section === 'vebinary') {
            $this->order = 'p.scheduled_at DESC, p.id DESC';
        }
        foreach (['ac' => ['audience_categories', 'category_id', 'audience_categories'],
                  'at' => ['audience_types', 'audience_type_id', 'audience_types'],
                  'as' => ['specializations', 'specialization_id', 'audience_specializations']] as $key => [$junction, $foreign, $lookup]) {
            if (!empty($options[$key])) {
                $wheres[] = "EXISTS (SELECT 1 FROM {$entity}_{$junction} j JOIN $lookup a ON a.id = j.$foreign WHERE j.{$entity}_id = p.id AND a.slug = ? AND a.is_active = 1)";
                $this->params[] = $options[$key];
            }
        }
        if ($section === 'kursy' && !empty($options['program_type']) && $options['program_type'] !== 'all') {
            $wheres[] = 'p.program_type = ?'; $this->params[] = $options['program_type'];
        }
        if ($section === 'konkursy' && !empty($options['category']) && $options['category'] !== 'all') {
            $wheres[] = 'p.category = ?'; $this->params[] = $options['category'];
        }
        if ($section === 'vebinary') {
            $status = $options['status'] ?? '';
            if ($status === 'upcoming') {
                $wheres[] = "p.status IN ('scheduled', 'live') AND (p.scheduled_at >= DATE_ADD(UTC_TIMESTAMP(), INTERVAL 3 HOUR) OR p.status = 'live')";
            } elseif ($status === 'recordings') $wheres[] = "(p.status = 'videolecture' OR (p.status = 'completed' AND p.video_url IS NOT NULL))";
            elseif ($status === 'videolecture') $wheres[] = "p.status = 'videolecture'";
            else $wheres[] = "p.status IN ('scheduled', 'live', 'completed', 'videolecture')";
        }
        $search = mb_substr(trim($search), 0, 200);
        if ($search !== '') {
            $fields = $section === 'publikacii' ? ["p.title", "p.annotation", "u.full_name", "pt.name"] : ['p.title', 'p.description'];
            $haystack = "LOWER(REPLACE(CONCAT_WS(' ', " . implode(', ', $fields) . "), 'ё', 'е'))";
            foreach (array_slice(preg_split('/\s+/u', mb_strtolower(str_replace('ё', 'е', $search))), 0, 12) as $token) {
                $wheres[] = "$haystack LIKE ? ESCAPE '!'";
                $this->params[] = '%' . strtr($token, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            }
        }
        $this->where = implode(' AND ', $wheres);
    }

    public function count(): int {
        return (int)$this->db->queryOne("SELECT COUNT(*) AS total FROM {$this->from} WHERE {$this->where}", $this->params)['total'];
    }
    public function page(int $page): array {
        if ($page < 1) throw new InvalidArgumentException('Некорректная страница');
        return $this->db->query("SELECT {$this->columns} FROM {$this->from} WHERE {$this->where} ORDER BY {$this->order} LIMIT ? OFFSET ?",
            [...$this->params, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE]);
    }
    /** Небольшие метаданные для преподавателей, отзывов и диапазона цен. Без тел программ. */
    public function courseMetadata(): array {
        if ($this->section !== 'kursy') return [];
        return $this->db->query("SELECT p.id, p.price FROM {$this->from} WHERE {$this->where}", $this->params);
    }
    public function minimumOlympiadPrice(): float {
        return (float)($this->db->queryOne("SELECT MIN(NULLIF(p.diploma_price, 0)) AS price FROM {$this->from} WHERE {$this->where}", $this->params)['price'] ?? 229);
    }
}
