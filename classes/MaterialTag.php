<?php
/**
 * MaterialTag — теги материалов: предметы (tag_type='subject') и
 * направления (tag_type='direction'). Поддерживает иерархию через parent_id.
 */

class MaterialTag
{
    private $db;

    public function __construct($pdo)
    {
        $this->db = new Database($pdo);
    }

    public function getAll(?string $type = null): array
    {
        $sql = "SELECT * FROM material_tags WHERE is_active = 1";
        $params = [];
        if ($type !== null) {
            $sql .= " AND tag_type = ?";
            $params[] = $type;
        }
        $sql .= " ORDER BY display_order ASC, name ASC";
        return $this->db->query($sql, $params);
    }

    public function getById(int $id): ?array
    {
        $row = $this->db->queryOne("SELECT * FROM material_tags WHERE id = ?", [$id]);
        return $row ?: null;
    }

    public function getBySlug(string $slug): ?array
    {
        $row = $this->db->queryOne(
            "SELECT * FROM material_tags WHERE slug = ? AND is_active = 1",
            [$slug]
        );
        return $row ?: null;
    }

    public function getChildren(int $parentId): array
    {
        return $this->db->query(
            "SELECT * FROM material_tags WHERE parent_id = ? AND is_active = 1 ORDER BY display_order ASC",
            [$parentId]
        );
    }

    public function getWithCounts(?string $type = null): array
    {
        $sql = "SELECT t.*, COUNT(DISTINCT r.material_id) AS materials_count
                FROM material_tags t
                LEFT JOIN material_tag_relations r ON t.id = r.tag_id
                LEFT JOIN materials m ON r.material_id = m.id AND m.status = 'published'
                WHERE t.is_active = 1";
        $params = [];
        if ($type !== null) {
            $sql .= " AND t.tag_type = ?";
            $params[] = $type;
        }
        $sql .= " GROUP BY t.id ORDER BY t.display_order ASC, t.name ASC";
        return $this->db->query($sql, $params);
    }

    public function create(array $data): int
    {
        return $this->db->insert('material_tags', [
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? '',
            'parent_id' => $data['parent_id'] ?? null,
            'tag_type' => $data['tag_type'] ?? 'subject',
            'icon' => $data['icon'] ?? null,
            'color' => $data['color'] ?? null,
            'display_order' => $data['display_order'] ?? 0,
            'is_active' => $data['is_active'] ?? 1,
            'meta_title' => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
        ]);
    }

    public function update(int $id, array $data): int
    {
        $allowed = [
            'name', 'slug', 'description', 'parent_id', 'tag_type',
            'icon', 'color', 'display_order', 'is_active',
            'meta_title', 'meta_description',
        ];
        $update = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) {
                $update[$f] = $data[$f];
            }
        }
        if (empty($update)) {
            return 0;
        }
        return $this->db->update('material_tags', $update, 'id = ?', [$id]);
    }

    public function delete(int $id): int
    {
        return $this->db->delete('material_tags', 'id = ?', [$id]);
    }
}
