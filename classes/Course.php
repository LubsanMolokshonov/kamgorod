<?php
/**
 * Course Class
 * Handles course CRUD operations and audience-based filtering
 * Follows Competition.php pattern
 */

class Course {
    private $db;

    public function __construct($pdo) {
        $this->db = new Database($pdo);
    }

    /**
     * Get all active courses
     */
    public function getActiveCourses($programType = 'all') {
        if ($programType === 'all' || empty($programType)) {
            return $this->db->query(
                "SELECT * FROM courses WHERE is_active = 1 ORDER BY display_order ASC, created_at DESC"
            );
        } else {
            return $this->db->query(
                "SELECT * FROM courses WHERE is_active = 1 AND program_type = ? ORDER BY display_order ASC, created_at DESC",
                [$programType]
            );
        }
    }

    /**
     * Get course by slug
     */
    public function getBySlug($slug) {
        return $this->db->queryOne(
            "SELECT * FROM courses WHERE slug = ? AND is_active = 1",
            [$slug]
        );
    }

    /**
     * Get course by ID
     */
    public function getById($id) {
        return $this->db->queryOne(
            "SELECT * FROM courses WHERE id = ?",
            [$id]
        );
    }

    /**
     * Create a new course
     */
    public function create($data) {
        $insertData = [
            'title' => $data['title'],
            'slug' => $data['slug'] ?? $this->generateSlug($data['title']),
            'description' => $data['description'] ?? '',
            'target_audience_text' => $data['target_audience_text'] ?? '',
            'course_group' => $data['course_group'] ?? '',
            'hours' => $data['hours'] ?? 72,
            'program_type' => $data['program_type'] ?? 'kpk',
            'learning_format' => $data['learning_format'] ?? 'Заочная с применением дистанционных образовательных технологий',
            'price' => $data['price'] ?? 0,
            'modules_json' => is_array($data['modules_json'] ?? null)
                ? json_encode($data['modules_json'], JSON_UNESCAPED_UNICODE)
                : ($data['modules_json'] ?? null),
            'outcomes_json' => is_array($data['outcomes_json'] ?? null)
                ? json_encode($data['outcomes_json'], JSON_UNESCAPED_UNICODE)
                : ($data['outcomes_json'] ?? null),
            'federal_registry_info' => $data['federal_registry_info'] ?? null,
            'is_active' => $data['is_active'] ?? 1,
            'display_order' => $data['display_order'] ?? 0
        ];

        return $this->db->insert('courses', $insertData);
    }

    /**
     * Update course
     */
    public function update($id, $data) {
        $updateData = [];
        $allowedFields = [
            'title', 'slug', 'description', 'target_audience_text', 'course_group',
            'hours', 'program_type', 'learning_format', 'price',
            'modules_json', 'outcomes_json', 'federal_registry_info',
            'is_active', 'display_order'
        ];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                if (in_array($field, ['modules_json', 'outcomes_json']) && is_array($data[$field])) {
                    $updateData[$field] = json_encode($data[$field], JSON_UNESCAPED_UNICODE);
                } else {
                    $updateData[$field] = $data[$field];
                }
            }
        }

        if (empty($updateData)) {
            return 0;
        }

        return $this->db->update('courses', $updateData, 'id = ?', [$id]);
    }

    /**
     * Delete course
     */
    public function delete($id) {
        return $this->db->delete('courses', 'id = ?', [$id]);
    }

    /**
     * Get all courses (for admin)
     */
    public function getAll($limit = 100, $offset = 0) {
        return $this->db->query(
            "SELECT * FROM courses ORDER BY display_order ASC, created_at DESC LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
    }

    /**
     * Get course count
     */
    public function count($programType = null) {
        if ($programType) {
            $result = $this->db->queryOne(
                "SELECT COUNT(*) as total FROM courses WHERE is_active = 1 AND program_type = ?",
                [$programType]
            );
        } else {
            $result = $this->db->queryOne("SELECT COUNT(*) as total FROM courses WHERE is_active = 1");
        }
        return $result['total'] ?? 0;
    }

    /**
     * Count courses matching filters (same logic as getFilteredCourses but returns count)
     */
    public function countByFilters($filters = []) {
        $sql = "SELECT COUNT(DISTINCT c.id) as total FROM courses c";
        $joins = [];
        $wheres = ["c.is_active = 1"];
        $params = [];

        if (!empty($filters['audience_category'])) {
            $joins[] = "JOIN course_audience_categories cac ON c.id = cac.course_id";
            $wheres[] = "cac.category_id = ?";
            $params[] = $filters['audience_category'];
        }

        if (!empty($filters['audience_type'])) {
            $joins[] = "JOIN course_audience_types cat ON c.id = cat.course_id";
            $joins[] = "JOIN audience_types at ON cat.audience_type_id = at.id";
            $wheres[] = "at.slug = ?";
            $params[] = $filters['audience_type'];
        }

        if (!empty($filters['specialization'])) {
            $joins[] = "JOIN course_specializations cs ON c.id = cs.course_id";
            $joins[] = "JOIN audience_specializations s ON cs.specialization_id = s.id";
            $wheres[] = "s.slug = ?";
            $params[] = $filters['specialization'];
        }

        if (!empty($filters['program_type']) && $filters['program_type'] !== 'all') {
            $wheres[] = "c.program_type = ?";
            $params[] = $filters['program_type'];
        }

        if (!empty($joins)) {
            $sql .= " " . implode(" ", array_unique($joins));
        }

        $sql .= " WHERE " . implode(" AND ", $wheres);
        $result = $this->db->queryOne($sql, $params);
        return $result['total'] ?? 0;
    }

    // =========================================
    // Audience Segmentation (3-level)
    // =========================================

    /**
     * Set audience categories for course (Level 0)
     */
    public function setAudienceCategories($courseId, $categoryIds) {
        $this->db->delete('course_audience_categories', 'course_id = ?', [$courseId]);
        foreach ($categoryIds as $catId) {
            $this->db->insert('course_audience_categories', [
                'course_id' => $courseId,
                'category_id' => $catId
            ]);
        }
    }

    /**
     * Set audience types for course (Level 1)
     */
    public function setAudienceTypes($courseId, $audienceTypeIds) {
        $this->db->delete('course_audience_types', 'course_id = ?', [$courseId]);
        foreach ($audienceTypeIds as $typeId) {
            $this->db->insert('course_audience_types', [
                'course_id' => $courseId,
                'audience_type_id' => $typeId
            ]);
        }
    }

    /**
     * Set specializations for course (Level 2)
     */
    public function setSpecializations($courseId, $specializationIds) {
        $this->db->delete('course_specializations', 'course_id = ?', [$courseId]);
        foreach ($specializationIds as $specId) {
            $this->db->insert('course_specializations', [
                'course_id' => $courseId,
                'specialization_id' => $specId
            ]);
        }
    }

    /**
     * Get audience categories for course (Level 0)
     */
    public function getAudienceCategories($courseId) {
        return $this->db->query(
            "SELECT ac.*
             FROM audience_categories ac
             JOIN course_audience_categories cac ON ac.id = cac.category_id
             WHERE cac.course_id = ? AND ac.is_active = 1
             ORDER BY ac.display_order ASC",
            [$courseId]
        );
    }

    /**
     * Get audience types for course (Level 1)
     */
    public function getAudienceTypes($courseId) {
        return $this->db->query(
            "SELECT at.* FROM audience_types at
             JOIN course_audience_types cat ON at.id = cat.audience_type_id
             WHERE cat.course_id = ? AND at.is_active = 1
             ORDER BY at.display_order ASC",
            [$courseId]
        );
    }

    /**
     * Get specializations for course (Level 2)
     */
    public function getSpecializations($courseId) {
        return $this->db->query(
            "SELECT s.*
             FROM audience_specializations s
             JOIN course_specializations cs ON s.id = cs.specialization_id
             WHERE cs.course_id = ? AND s.is_active = 1
             ORDER BY s.specialization_type ASC, s.display_order ASC",
            [$courseId]
        );
    }

    /**
     * Filtered courses by audience and program type
     */
    public function getFilteredCourses($filters = []) {
        $sql = "SELECT DISTINCT c.* FROM courses c";
        $joins = [];
        $wheres = ["c.is_active = 1"];
        $params = [];

        if (!empty($filters['audience_category'])) {
            $joins[] = "JOIN course_audience_categories cac ON c.id = cac.course_id";
            $wheres[] = "cac.category_id = ?";
            $params[] = $filters['audience_category'];
        }

        if (!empty($filters['audience_type'])) {
            $joins[] = "JOIN course_audience_types cat ON c.id = cat.course_id";
            $joins[] = "JOIN audience_types at ON cat.audience_type_id = at.id";
            $wheres[] = "at.slug = ?";
            $params[] = $filters['audience_type'];
        }

        if (!empty($filters['specialization'])) {
            $joins[] = "JOIN course_specializations cs ON c.id = cs.course_id";
            $joins[] = "JOIN audience_specializations s ON cs.specialization_id = s.id";
            $wheres[] = "s.slug = ?";
            $params[] = $filters['specialization'];
        }

        if (!empty($filters['program_type']) && $filters['program_type'] !== 'all') {
            $wheres[] = "c.program_type = ?";
            $params[] = $filters['program_type'];
        }

        if (!empty($joins)) {
            $sql .= " " . implode(" ", array_unique($joins));
        }

        $sql .= " WHERE " . implode(" AND ", $wheres);
        $sql .= " ORDER BY c.display_order ASC, c.created_at DESC";

        return $this->db->query($sql, $params);
    }

    // =========================================
    // Course-specific methods
    // =========================================

    /**
     * Get experts for a course
     */
    public function getExperts($courseId) {
        return $this->db->query(
            "SELECT ce.*, cea.role, cea.display_order as assignment_order
             FROM course_experts ce
             JOIN course_expert_assignments cea ON ce.id = cea.expert_id
             WHERE cea.course_id = ? AND ce.is_active = 1
             ORDER BY cea.display_order ASC",
            [$courseId]
        );
    }

    /**
     * Get modules from JSON
     */
    public function getModules($course) {
        if (empty($course['modules_json'])) {
            return [];
        }
        $modules = json_decode($course['modules_json'], true);
        return is_array($modules) ? $modules : [];
    }

    /**
     * Get outcomes from JSON
     */
    public function getOutcomes($course) {
        if (empty($course['outcomes_json'])) {
            return [];
        }
        $outcomes = json_decode($course['outcomes_json'], true);
        return is_array($outcomes) ? $outcomes : [];
    }

    // =========================================
    // Slug generation (same as Competition.php)
    // =========================================

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

        // Limit slug length
        if (mb_strlen($slug) > 120) {
            $slug = mb_substr($slug, 0, 120);
            $slug = rtrim($slug, '-');
        }

        $originalSlug = $slug;
        $counter = 1;

        while ($this->slugExists($slug)) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function slugExists($slug) {
        $result = $this->db->queryOne(
            "SELECT id FROM courses WHERE slug = ?",
            [$slug]
        );
        return !empty($result);
    }

    // =========================================
    // User enrollments
    // =========================================

    /**
     * Get user's course enrollments by email
     */
    public function getEnrollmentsByEmail($email) {
        return $this->db->query(
            "SELECT ce.id as enrollment_id, ce.status as enrollment_status, ce.created_at as enrolled_at,
                    c.id as course_id, c.title, c.slug, c.hours, c.price, c.program_type
             FROM course_enrollments ce
             JOIN courses c ON ce.course_id = c.id
             WHERE ce.email = ? AND ce.status != 'cancelled'
             ORDER BY ce.created_at DESC",
            [$email]
        );
    }

    /**
     * Get single enrollment with course data by enrollment ID
     */
    public function getEnrollmentById($enrollmentId) {
        return $this->db->queryOne(
            "SELECT ce.id as enrollment_id, ce.user_id, ce.status as enrollment_status,
                    ce.created_at as enrolled_at, ce.email, ce.full_name, ce.phone,
                    c.id as course_id, c.title, c.slug, c.hours, c.price, c.program_type
             FROM course_enrollments ce
             JOIN courses c ON ce.course_id = c.id
             WHERE ce.id = ?",
            [$enrollmentId]
        );
    }

    /**
     * Проверка: действует ли скидка 10% (в течение 10 минут после записи)
     */
    public function isDiscountEligible($enrollment) {
        $field = $enrollment['enrolled_at'] ?? $enrollment['created_at'];
        // MySQL хранит в UTC, PHP в Europe/Moscow
        $dt = new \DateTime($field, new \DateTimeZone('UTC'));
        $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $deadline = $dt->getTimestamp() + 600; // 10 минут
        return time() <= $deadline;
    }

    /**
     * Проверить, есть ли уже pending/succeeded заказ для этого enrollment
     */
    public function hasExistingOrder($enrollmentId) {
        return $this->db->queryOne(
            "SELECT o.id, o.payment_status, o.yookassa_confirmation_url
             FROM orders o
             JOIN order_items oi ON o.id = oi.order_id
             WHERE oi.course_enrollment_id = ? AND o.payment_status IN ('pending', 'processing', 'succeeded')
             ORDER BY o.created_at DESC
             LIMIT 1",
            [$enrollmentId]
        );
    }

    // =========================================
    // Static helpers
    // =========================================

    public static function getProgramTypeLabel($type) {
        $types = defined('COURSE_PROGRAM_TYPES') ? COURSE_PROGRAM_TYPES : [];
        return $types[$type] ?? $type;
    }

    public static function formatHours($hours) {
        return $hours . ' ' . self::pluralHours($hours);
    }

    private static function pluralHours($n) {
        $n = abs($n) % 100;
        $n1 = $n % 10;
        if ($n > 10 && $n < 20) return 'часов';
        if ($n1 > 1 && $n1 < 5) return 'часа';
        if ($n1 == 1) return 'час';
        return 'часов';
    }
}
