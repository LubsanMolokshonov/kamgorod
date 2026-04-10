<?php
/**
 * Olympiad Class
 * Handles olympiad CRUD operations and filtering
 * v2: Unified audience segmentation through junction tables
 */

class Olympiad {
    private $db;

    public function __construct($pdo) {
        $this->db = new Database($pdo);
    }

    /**
     * Get all active olympiads with optional audience filter
     * Supports both legacy ENUM (target_audience) and new junction tables
     */
    public function getActiveOlympiads($audience = 'all') {
        if ($audience === 'all' || empty($audience)) {
            return $this->db->query(
                "SELECT * FROM olympiads WHERE is_active = 1 ORDER BY display_order ASC, created_at DESC"
            );
        }

        // Support legacy ENUM values for backward compatibility
        if (in_array($audience, ['pedagogues_dou', 'pedagogues_school', 'pedagogues_ovz', 'students', 'preschoolers', 'logopedists'])) {
            return $this->db->query(
                "SELECT * FROM olympiads WHERE is_active = 1 AND target_audience = ? ORDER BY display_order ASC, created_at DESC",
                [$audience]
            );
        }

        // New system: filter by audience_type_id
        return $this->db->query(
            "SELECT DISTINCT o.* FROM olympiads o
             JOIN olympiad_audience_types oat ON o.id = oat.olympiad_id
             WHERE o.is_active = 1 AND oat.audience_type_id = ?
             ORDER BY o.display_order ASC, o.created_at DESC",
            [$audience]
        );
    }

    /**
     * Get olympiads by audience category (Level 0)
     */
    public function getByAudienceCategory($categoryId) {
        return $this->db->query(
            "SELECT DISTINCT o.* FROM olympiads o
             JOIN olympiad_audience_categories oac ON o.id = oac.olympiad_id
             WHERE o.is_active = 1 AND oac.category_id = ?
             ORDER BY o.display_order ASC, o.created_at DESC",
            [$categoryId]
        );
    }

    /**
     * Get olympiads by audience type (Level 1)
     */
    public function getByAudienceType($audienceTypeId) {
        return $this->db->query(
            "SELECT DISTINCT o.* FROM olympiads o
             JOIN olympiad_audience_types oat ON o.id = oat.olympiad_id
             WHERE o.is_active = 1 AND oat.audience_type_id = ?
             ORDER BY o.display_order ASC, o.created_at DESC",
            [$audienceTypeId]
        );
    }

    /**
     * Get olympiads by specialization (Level 2)
     */
    public function getBySpecialization($specializationId) {
        return $this->db->query(
            "SELECT DISTINCT o.* FROM olympiads o
             JOIN olympiad_specializations os ON o.id = os.olympiad_id
             WHERE o.is_active = 1 AND os.specialization_id = ?
             ORDER BY o.display_order ASC, o.created_at DESC",
            [$specializationId]
        );
    }

    /**
     * Get olympiad by slug
     */
    public function getBySlug($slug) {
        return $this->db->queryOne(
            "SELECT * FROM olympiads WHERE slug = ? AND is_active = 1",
            [$slug]
        );
    }

    /**
     * Get olympiad by ID
     */
    public function getById($id) {
        return $this->db->queryOne(
            "SELECT * FROM olympiads WHERE id = ?",
            [$id]
        );
    }

    /**
     * Update olympiad
     */
    public function update($id, $data) {
        $allowedFields = [
            'title', 'slug', 'description', 'seo_content',
            'target_audience', 'subject', 'grade',
            'diploma_price', 'academic_year', 'is_active', 'display_order'
        ];
        $updateData = [];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updateData[$field] = $data[$field];
            }
        }
        if (empty($updateData)) return 0;
        return $this->db->update('olympiads', $updateData, 'id = ?', [$id]);
    }

    /**
     * Get filtered olympiads (unified system)
     */
    public function getFilteredOlympiads($filters = []) {
        $sql = "SELECT DISTINCT o.* FROM olympiads o";
        $joins = [];
        $wheres = ["o.is_active = 1"];
        $params = [];

        // Filter by audience category (Level 0)
        if (!empty($filters['category_id'])) {
            $joins[] = "JOIN olympiad_audience_categories oac ON o.id = oac.olympiad_id";
            $wheres[] = "oac.category_id = ?";
            $params[] = $filters['category_id'];
        }

        // Filter by audience type (Level 1)
        if (!empty($filters['audience_type_id'])) {
            $joins[] = "JOIN olympiad_audience_types oat ON o.id = oat.olympiad_id";
            $wheres[] = "oat.audience_type_id = ?";
            $params[] = $filters['audience_type_id'];
        }

        // Filter by specialization (Level 2)
        if (!empty($filters['specialization_id'])) {
            $joins[] = "JOIN olympiad_specializations os ON o.id = os.olympiad_id";
            $wheres[] = "os.specialization_id = ?";
            $params[] = $filters['specialization_id'];
        }

        // Legacy: filter by ENUM audience
        if (!empty($filters['audience']) && $filters['audience'] !== 'all') {
            $wheres[] = "o.target_audience = ?";
            $params[] = $filters['audience'];
        }

        // Legacy: filter by subject string
        if (!empty($filters['subject'])) {
            $wheres[] = "o.subject = ?";
            $params[] = $filters['subject'];
        }

        // Legacy: filter by grade
        if (!empty($filters['grade'])) {
            $wheres[] = "o.grade = ?";
            $params[] = $filters['grade'];
        }

        if (!empty($joins)) {
            $sql .= " " . implode(" ", array_unique($joins));
        }

        $sql .= " WHERE " . implode(" AND ", $wheres);
        $sql .= " ORDER BY o.display_order ASC, o.created_at DESC";

        return $this->db->query($sql, $params);
    }

    /**
     * Get audience types for an olympiad (new system)
     */
    public function getAudienceTypes($olympiadId) {
        return $this->db->query(
            "SELECT at.*, ac.name as category_name, ac.slug as category_slug
             FROM audience_types at
             JOIN olympiad_audience_types oat ON at.id = oat.audience_type_id
             LEFT JOIN audience_categories ac ON at.category_id = ac.id
             WHERE oat.olympiad_id = ? AND at.is_active = 1
             ORDER BY at.display_order ASC",
            [$olympiadId]
        );
    }

    /**
     * Get specializations for an olympiad
     */
    public function getSpecializations($olympiadId) {
        return $this->db->query(
            "SELECT s.*
             FROM audience_specializations s
             JOIN olympiad_specializations os ON s.id = os.specialization_id
             WHERE os.olympiad_id = ? AND s.is_active = 1
             ORDER BY s.display_order ASC",
            [$olympiadId]
        );
    }

    /**
     * Set audience types for an olympiad
     */
    public function setAudienceTypes($olympiadId, $audienceTypeIds) {
        $this->db->delete('olympiad_audience_types', 'olympiad_id = ?', [$olympiadId]);

        foreach ($audienceTypeIds as $typeId) {
            $this->db->insert('olympiad_audience_types', [
                'olympiad_id' => $olympiadId,
                'audience_type_id' => $typeId
            ]);
        }
    }

    /**
     * Set specializations for an olympiad
     */
    public function setSpecializations($olympiadId, $specializationIds) {
        $this->db->delete('olympiad_specializations', 'olympiad_id = ?', [$olympiadId]);

        foreach ($specializationIds as $specId) {
            $this->db->insert('olympiad_specializations', [
                'olympiad_id' => $olympiadId,
                'specialization_id' => $specId
            ]);
        }
    }

    /**
     * Get audience categories for an olympiad (Level 0)
     */
    public function getAudienceCategories($olympiadId) {
        return $this->db->query(
            "SELECT ac.*
             FROM audience_categories ac
             JOIN olympiad_audience_categories oac ON ac.id = oac.category_id
             WHERE oac.olympiad_id = ? AND ac.is_active = 1
             ORDER BY ac.display_order ASC",
            [$olympiadId]
        );
    }

    /**
     * Set audience categories for an olympiad
     */
    public function setAudienceCategories($olympiadId, $categoryIds) {
        $this->db->delete('olympiad_audience_categories', 'olympiad_id = ?', [$olympiadId]);

        foreach ($categoryIds as $catId) {
            $this->db->insert('olympiad_audience_categories', [
                'olympiad_id' => $olympiadId,
                'category_id' => $catId
            ]);
        }
    }

    /**
     * Get unique subjects for an audience type
     */
    public function getSubjectsByAudience($audience) {
        return $this->db->query(
            "SELECT DISTINCT subject FROM olympiads WHERE is_active = 1 AND target_audience = ? AND subject IS NOT NULL ORDER BY subject ASC",
            [$audience]
        );
    }

    /**
     * Get unique grades for students
     */
    public function getGrades() {
        return $this->db->query(
            "SELECT DISTINCT grade FROM olympiads WHERE is_active = 1 AND target_audience = 'students' AND grade IS NOT NULL ORDER BY grade ASC"
        );
    }

    /**
     * Get olympiad count
     */
    public function count($audience = null) {
        if ($audience) {
            $result = $this->db->queryOne(
                "SELECT COUNT(*) as total FROM olympiads WHERE is_active = 1 AND target_audience = ?",
                [$audience]
            );
        } else {
            $result = $this->db->queryOne(
                "SELECT COUNT(*) as total FROM olympiads WHERE is_active = 1"
            );
        }
        return $result['total'] ?? 0;
    }

    /**
     * Get total participants count across all olympiads
     */
    public function getTotalParticipants() {
        $result = $this->db->queryOne(
            "SELECT COUNT(*) as total FROM olympiad_results"
        );
        return $result['total'] ?? 0;
    }

    /**
     * Get audience label (supports both legacy and new system)
     */
    public static function getAudienceLabel($audience) {
        $audiences = OLYMPIAD_AUDIENCES;
        return $audiences[$audience] ?? $audience;
    }

    /**
     * Get placement by score
     */
    public static function getPlacementByScore($score) {
        if ($score >= 9) return '1';
        if ($score == 8) return '2';
        if ($score == 7) return '3';
        return null;
    }

    /**
     * Get placement label
     */
    public static function getPlacementLabel($placement) {
        $labels = [
            '1' => '1 место',
            '2' => '2 место',
            '3' => '3 место'
        ];
        return $labels[$placement] ?? 'Участник';
    }

    /**
     * Generate URL-friendly slug from title
     */
    public function generateSlug($title) {
        $slug = mb_strtolower($title, 'UTF-8');

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
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        return $slug;
    }

    /**
     * Получить ТОП олимпиад по количеству регистраций
     */
    public function getTopOlympiads(int $limit = 5): array
    {
        return $this->db->query(
            "SELECT o.*, COUNT(r.id) as registrations_count
             FROM olympiads o
             LEFT JOIN olympiad_registrations r ON o.id = r.olympiad_id
             WHERE o.is_active = 1
             GROUP BY o.id
             ORDER BY registrations_count DESC
             LIMIT ?",
            [$limit]
        );
    }
}
