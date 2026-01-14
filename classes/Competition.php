<?php
/**
 * Competition Class
 * Handles competition CRUD operations
 */

class Competition {
    private $db;

    public function __construct($pdo) {
        $this->db = new Database($pdo);
    }

    /**
     * Get all active competitions
     * Optional category filter
     */
    public function getActiveCompetitions($category = 'all') {
        if ($category === 'all' || empty($category)) {
            return $this->db->query(
                "SELECT * FROM competitions WHERE is_active = 1 ORDER BY display_order ASC, created_at DESC"
            );
        } else {
            return $this->db->query(
                "SELECT * FROM competitions WHERE is_active = 1 AND category = ? ORDER BY display_order ASC, created_at DESC",
                [$category]
            );
        }
    }

    /**
     * Get competition by slug
     */
    public function getBySlug($slug) {
        return $this->db->queryOne(
            "SELECT * FROM competitions WHERE slug = ? AND is_active = 1",
            [$slug]
        );
    }

    /**
     * Get competition by ID
     */
    public function getById($id) {
        return $this->db->queryOne(
            "SELECT * FROM competitions WHERE id = ?",
            [$id]
        );
    }

    /**
     * Create a new competition
     * Returns competition ID
     */
    public function create($data) {
        $insertData = [
            'title' => $data['title'],
            'slug' => $data['slug'] ?? $this->generateSlug($data['title']),
            'description' => $data['description'] ?? '',
            'target_participants' => $data['target_participants'] ?? '',
            'award_structure' => $data['award_structure'] ?? '',
            'academic_year' => $data['academic_year'] ?? '',
            'category' => $data['category'],
            'nomination_options' => is_array($data['nomination_options'])
                ? json_encode($data['nomination_options'], JSON_UNESCAPED_UNICODE)
                : $data['nomination_options'],
            'price' => $data['price'],
            'is_active' => $data['is_active'] ?? 1,
            'display_order' => $data['display_order'] ?? 0
        ];

        return $this->db->insert('competitions', $insertData);
    }

    /**
     * Update competition
     * Returns number of affected rows
     */
    public function update($id, $data) {
        $updateData = [];

        $allowedFields = [
            'title', 'slug', 'description', 'target_participants', 'award_structure',
            'academic_year', 'category', 'nomination_options', 'price', 'is_active', 'display_order'
        ];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                if ($field === 'nomination_options' && is_array($data[$field])) {
                    $updateData[$field] = json_encode($data[$field], JSON_UNESCAPED_UNICODE);
                } else {
                    $updateData[$field] = $data[$field];
                }
            }
        }

        if (empty($updateData)) {
            return 0;
        }

        return $this->db->update('competitions', $updateData, 'id = ?', [$id]);
    }

    /**
     * Delete competition
     */
    public function delete($id) {
        return $this->db->delete('competitions', 'id = ?', [$id]);
    }

    /**
     * Get all competitions (for admin)
     */
    public function getAll($limit = 100, $offset = 0) {
        return $this->db->query(
            "SELECT * FROM competitions ORDER BY display_order ASC, created_at DESC LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
    }

    /**
     * Get competitions by category
     */
    public function getByCategory($category) {
        return $this->db->query(
            "SELECT * FROM competitions WHERE category = ? AND is_active = 1 ORDER BY display_order ASC",
            [$category]
        );
    }

    /**
     * Get nomination options for a competition
     */
    public function getNominationOptions($competitionId) {
        $competition = $this->getById($competitionId);

        if (!$competition || empty($competition['nomination_options'])) {
            return [];
        }

        $nominations = json_decode($competition['nomination_options'], true);

        return is_array($nominations) ? $nominations : [];
    }

    /**
     * Generate URL-friendly slug from title
     */
    private function generateSlug($title) {
        // Convert to lowercase
        $slug = mb_strtolower($title, 'UTF-8');

        // Transliterate Cyrillic to Latin
        $transliteration = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
            'е' => 'e', 'ё' => 'yo', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
            'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
            'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
            'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch',
            'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
            'э' => 'e', 'ю' => 'yu', 'я' => 'ya'
        ];

        $slug = strtr($slug, $transliteration);

        // Replace non-alphanumeric characters with hyphens
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);

        // Remove leading/trailing hyphens
        $slug = trim($slug, '-');

        // Ensure uniqueness
        $originalSlug = $slug;
        $counter = 1;

        while ($this->slugExists($slug)) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Check if slug exists
     */
    private function slugExists($slug) {
        $result = $this->db->queryOne(
            "SELECT id FROM competitions WHERE slug = ?",
            [$slug]
        );

        return !empty($result);
    }

    /**
     * Get competition count
     */
    public function count() {
        $result = $this->db->queryOne("SELECT COUNT(*) as total FROM competitions");
        return $result['total'] ?? 0;
    }

    /**
     * Get category label
     */
    public static function getCategoryLabel($category) {
        $categories = COMPETITION_CATEGORIES;
        return $categories[$category] ?? $category;
    }

    /**
     * Привязать конкурс к типам аудитории
     *
     * @param int $competitionId ID конкурса
     * @param array $audienceTypeIds Массив ID типов аудитории
     * @return void
     */
    public function setAudienceTypes($competitionId, $audienceTypeIds) {
        // Удалить существующие связи
        $this->db->delete('competition_audience_types', 'competition_id = ?', [$competitionId]);

        // Добавить новые связи
        foreach ($audienceTypeIds as $typeId) {
            $this->db->insert('competition_audience_types', [
                'competition_id' => $competitionId,
                'audience_type_id' => $typeId
            ]);
        }
    }

    /**
     * Привязать конкурс к специализациям
     *
     * @param int $competitionId ID конкурса
     * @param array $specializationIds Массив ID специализаций
     * @return void
     */
    public function setSpecializations($competitionId, $specializationIds) {
        // Удалить существующие связи
        $this->db->delete('competition_specializations', 'competition_id = ?', [$competitionId]);

        // Добавить новые связи
        foreach ($specializationIds as $specId) {
            $this->db->insert('competition_specializations', [
                'competition_id' => $competitionId,
                'specialization_id' => $specId
            ]);
        }
    }

    /**
     * Получить типы аудитории для конкурса
     *
     * @param int $competitionId ID конкурса
     * @return array Массив типов аудитории
     */
    public function getAudienceTypes($competitionId) {
        return $this->db->query(
            "SELECT at.* FROM audience_types at
             JOIN competition_audience_types cat ON at.id = cat.audience_type_id
             WHERE cat.competition_id = ? AND at.is_active = 1
             ORDER BY at.display_order ASC",
            [$competitionId]
        );
    }

    /**
     * Получить специализации для конкурса
     *
     * @param int $competitionId ID конкурса
     * @return array Массив специализаций
     */
    public function getSpecializations($competitionId) {
        return $this->db->query(
            "SELECT s.*, at.name as audience_type_name, at.slug as audience_type_slug
             FROM audience_specializations s
             JOIN competition_specializations cs ON s.id = cs.specialization_id
             JOIN audience_types at ON s.audience_type_id = at.id
             WHERE cs.competition_id = ? AND s.is_active = 1
             ORDER BY s.display_order ASC",
            [$competitionId]
        );
    }

    /**
     * Получить конкурсы по типу аудитории
     *
     * @param string $audienceTypeSlug Slug типа аудитории
     * @param string $category Категория конкурса (по умолчанию 'all')
     * @return array Массив конкурсов
     */
    public function getByAudienceType($audienceTypeSlug, $category = 'all') {
        $sql = "SELECT DISTINCT c.* FROM competitions c
                JOIN competition_audience_types cat ON c.id = cat.competition_id
                JOIN audience_types at ON cat.audience_type_id = at.id
                WHERE c.is_active = 1 AND at.slug = ? AND at.is_active = 1";

        $params = [$audienceTypeSlug];

        if ($category !== 'all') {
            $sql .= " AND c.category = ?";
            $params[] = $category;
        }

        $sql .= " ORDER BY c.display_order ASC, c.created_at DESC";

        return $this->db->query($sql, $params);
    }

    /**
     * Получить конкурсы по специализации
     *
     * @param string $specializationSlug Slug специализации
     * @param string $category Категория конкурса (по умолчанию 'all')
     * @return array Массив конкурсов
     */
    public function getBySpecialization($specializationSlug, $category = 'all') {
        $sql = "SELECT DISTINCT c.* FROM competitions c
                JOIN competition_specializations cs ON c.id = cs.competition_id
                JOIN audience_specializations s ON cs.specialization_id = s.id
                WHERE c.is_active = 1 AND s.slug = ? AND s.is_active = 1";

        $params = [$specializationSlug];

        if ($category !== 'all') {
            $sql .= " AND c.category = ?";
            $params[] = $category;
        }

        $sql .= " ORDER BY c.display_order ASC, c.created_at DESC";

        return $this->db->query($sql, $params);
    }

    /**
     * Расширенная фильтрация конкурсов
     *
     * @param array $filters Массив фильтров (audience_type, specialization, category)
     * @return array Массив конкурсов
     */
    public function getFilteredCompetitions($filters = []) {
        $sql = "SELECT DISTINCT c.* FROM competitions c";
        $joins = [];
        $wheres = ["c.is_active = 1"];
        $params = [];

        // Фильтр по типу аудитории
        if (!empty($filters['audience_type'])) {
            $joins[] = "LEFT JOIN competition_audience_types cat ON c.id = cat.competition_id";
            $joins[] = "LEFT JOIN audience_types at ON cat.audience_type_id = at.id";
            $wheres[] = "at.slug = ?";
            $params[] = $filters['audience_type'];
        }

        // Фильтр по специализации
        if (!empty($filters['specialization'])) {
            $joins[] = "LEFT JOIN competition_specializations cs ON c.id = cs.competition_id";
            $joins[] = "LEFT JOIN audience_specializations s ON cs.specialization_id = s.id";
            $wheres[] = "s.slug = ?";
            $params[] = $filters['specialization'];
        }

        // Фильтр по категории
        if (!empty($filters['category']) && $filters['category'] !== 'all') {
            $wheres[] = "c.category = ?";
            $params[] = $filters['category'];
        }

        // Собрать SQL запрос
        if (!empty($joins)) {
            $sql .= " " . implode(" ", array_unique($joins));
        }

        $sql .= " WHERE " . implode(" AND ", $wheres);
        $sql .= " ORDER BY c.display_order ASC, c.created_at DESC";

        return $this->db->query($sql, $params);
    }
}
