<?php
/**
 * PublicationTag Class
 * Handles publication tags (directions and subjects)
 */

class PublicationTag {
    private $db;

    public function __construct($pdo) {
        $this->db = new Database($pdo);
    }

    /**
     * Get all active tags
     * @param string|null $type Filter by type ('direction', 'subject', or null for all)
     * @return array Tags
     */
    public function getAll($type = null) {
        $sql = "SELECT * FROM publication_tags WHERE is_active = 1";
        $params = [];

        if ($type !== null) {
            $sql .= " AND tag_type = ?";
            $params[] = $type;
        }

        $sql .= " ORDER BY display_order ASC, name ASC";

        return $this->db->query($sql, $params);
    }

    /**
     * Get directions (main categories)
     * @return array Direction tags
     */
    public function getDirections() {
        return $this->getAll('direction');
    }

    /**
     * Get subjects (subject-specific tags)
     * @return array Subject tags
     */
    public function getSubjects() {
        return $this->getAll('subject');
    }

    /**
     * Get tag by ID
     * @param int $id Tag ID
     * @return array|null Tag data
     */
    public function getById($id) {
        return $this->db->queryOne(
            "SELECT * FROM publication_tags WHERE id = ?",
            [$id]
        );
    }

    /**
     * Get tag by slug
     * @param string $slug Tag slug
     * @return array|null Tag data
     */
    public function getBySlug($slug) {
        return $this->db->queryOne(
            "SELECT * FROM publication_tags WHERE slug = ? AND is_active = 1",
            [$slug]
        );
    }

    /**
     * Get popular tags
     * @param int $limit Limit
     * @return array Popular tags
     */
    public function getPopular($limit = 10) {
        return $this->db->query(
            "SELECT * FROM publication_tags
             WHERE is_active = 1 AND publications_count > 0
             ORDER BY publications_count DESC
             LIMIT ?",
            [$limit]
        );
    }

    /**
     * Get tags with publication counts
     * @return array Tags with counts
     */
    public function getWithCounts() {
        return $this->db->query(
            "SELECT t.*, COUNT(ptr.publication_id) as actual_count
             FROM publication_tags t
             LEFT JOIN publication_tag_relations ptr ON t.id = ptr.tag_id
             LEFT JOIN publications p ON ptr.publication_id = p.id AND p.status = 'published'
             WHERE t.is_active = 1
             GROUP BY t.id
             ORDER BY t.display_order ASC"
        );
    }

    /**
     * Create a new tag
     * @param array $data Tag data
     * @return int Tag ID
     */
    public function create($data) {
        return $this->db->insert('publication_tags', [
            'name' => $data['name'],
            'slug' => $data['slug'] ?? $this->generateSlug($data['name']),
            'description' => $data['description'] ?? '',
            'tag_type' => $data['tag_type'] ?? 'direction',
            'parent_id' => $data['parent_id'] ?? null,
            'color' => $data['color'] ?? '#3498DB',
            'icon' => $data['icon'] ?? null,
            'display_order' => $data['display_order'] ?? 0,
            'meta_title' => $data['meta_title'] ?? $data['name'],
            'meta_description' => $data['meta_description'] ?? ''
        ]);
    }

    /**
     * Update tag
     * @param int $id Tag ID
     * @param array $data Update data
     * @return int Affected rows
     */
    public function update($id, $data) {
        $allowedFields = [
            'name', 'slug', 'description', 'tag_type', 'parent_id',
            'color', 'icon', 'display_order', 'is_active', 'meta_title', 'meta_description'
        ];

        $updateData = [];
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }

        if (empty($updateData)) {
            return 0;
        }

        return $this->db->update('publication_tags', $updateData, 'id = ?', [$id]);
    }

    /**
     * Delete tag
     * @param int $id Tag ID
     * @return int Affected rows
     */
    public function delete($id) {
        return $this->db->delete('publication_tags', 'id = ?', [$id]);
    }

    /**
     * Generate slug from name
     * @param string $name Tag name
     * @return string Slug
     */
    private function generateSlug($name) {
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

        return $slug;
    }

    /**
     * Update publication count for a tag
     * @param int $tagId Tag ID
     */
    public function updatePublicationCount($tagId) {
        $this->db->execute(
            "UPDATE publication_tags SET publications_count = (
                SELECT COUNT(*) FROM publication_tag_relations ptr
                JOIN publications p ON ptr.publication_id = p.id
                WHERE ptr.tag_id = ? AND p.status = 'published'
            ) WHERE id = ?",
            [$tagId, $tagId]
        );
    }
}
