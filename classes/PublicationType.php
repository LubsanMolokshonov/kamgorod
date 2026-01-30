<?php
/**
 * PublicationType Class
 * Handles publication types (methodology, article, research, etc.)
 */

class PublicationType {
    private $db;

    public function __construct($pdo) {
        $this->db = new Database($pdo);
    }

    /**
     * Get all active types
     * @return array Types
     */
    public function getAll() {
        return $this->db->query(
            "SELECT * FROM publication_types WHERE is_active = 1 ORDER BY display_order ASC"
        );
    }

    /**
     * Get type by ID
     * @param int $id Type ID
     * @return array|null Type data
     */
    public function getById($id) {
        return $this->db->queryOne(
            "SELECT * FROM publication_types WHERE id = ?",
            [$id]
        );
    }

    /**
     * Get type by slug
     * @param string $slug Type slug
     * @return array|null Type data
     */
    public function getBySlug($slug) {
        return $this->db->queryOne(
            "SELECT * FROM publication_types WHERE slug = ? AND is_active = 1",
            [$slug]
        );
    }

    /**
     * Get types with publication counts
     * @return array Types with counts
     */
    public function getWithCounts() {
        return $this->db->query(
            "SELECT pt.*, COUNT(p.id) as publications_count
             FROM publication_types pt
             LEFT JOIN publications p ON pt.id = p.publication_type_id AND p.status = 'published'
             WHERE pt.is_active = 1
             GROUP BY pt.id
             ORDER BY pt.display_order ASC"
        );
    }

    /**
     * Create a new type
     * @param array $data Type data
     * @return int Type ID
     */
    public function create($data) {
        return $this->db->insert('publication_types', [
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? '',
            'icon' => $data['icon'] ?? 'fa-file-alt',
            'display_order' => $data['display_order'] ?? 0,
            'is_active' => $data['is_active'] ?? 1
        ]);
    }

    /**
     * Update type
     * @param int $id Type ID
     * @param array $data Update data
     * @return int Affected rows
     */
    public function update($id, $data) {
        $allowedFields = ['name', 'slug', 'description', 'icon', 'display_order', 'is_active'];

        $updateData = [];
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }

        if (empty($updateData)) {
            return 0;
        }

        return $this->db->update('publication_types', $updateData, 'id = ?', [$id]);
    }

    /**
     * Delete type
     * @param int $id Type ID
     * @return int Affected rows
     */
    public function delete($id) {
        return $this->db->delete('publication_types', 'id = ?', [$id]);
    }
}
