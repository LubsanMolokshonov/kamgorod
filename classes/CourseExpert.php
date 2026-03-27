<?php
/**
 * CourseExpert Class
 * Manages course instructors/experts
 */

class CourseExpert {
    private $db;

    public function __construct($pdo) {
        $this->db = new Database($pdo);
    }

    public function getById($id) {
        return $this->db->queryOne("SELECT * FROM course_experts WHERE id = ?", [$id]);
    }

    public function getBySlug($slug) {
        return $this->db->queryOne(
            "SELECT * FROM course_experts WHERE slug = ? AND is_active = 1",
            [$slug]
        );
    }

    public function getAll($activeOnly = true) {
        $sql = "SELECT * FROM course_experts";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY display_order ASC, full_name ASC";
        return $this->db->query($sql);
    }

    public function getByFullName($fullName) {
        return $this->db->queryOne(
            "SELECT * FROM course_experts WHERE full_name = ?",
            [$fullName]
        );
    }

    public function getCourses($expertId) {
        return $this->db->query(
            "SELECT c.* FROM courses c
             JOIN course_expert_assignments cea ON c.id = cea.course_id
             WHERE cea.expert_id = ? AND c.is_active = 1
             ORDER BY c.display_order ASC",
            [$expertId]
        );
    }

    public function create($data) {
        $insertData = [
            'full_name' => $data['full_name'],
            'slug' => $data['slug'] ?? $this->generateSlug($data['full_name']),
            'credentials' => $data['credentials'] ?? '',
            'experience' => $data['experience'] ?? '',
            'photo_url' => $data['photo_url'] ?? '/assets/images/experts/placeholder.svg',
            'is_active' => $data['is_active'] ?? 1,
            'display_order' => $data['display_order'] ?? 0
        ];

        return $this->db->insert('course_experts', $insertData);
    }

    public function update($id, $data) {
        $updateData = [];
        $allowedFields = ['full_name', 'slug', 'credentials', 'experience', 'photo_url', 'is_active', 'display_order'];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }

        if (empty($updateData)) {
            return 0;
        }

        return $this->db->update('course_experts', $updateData, 'id = ?', [$id]);
    }

    public function generateSlug($name) {
        $slug = mb_strtolower($name, 'UTF-8');

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
            "SELECT id FROM course_experts WHERE slug = ?",
            [$slug]
        );
        return !empty($result);
    }
}
