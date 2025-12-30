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
}
