<?php
/**
 * Material — каталог учебных материалов ФОП.
 *
 * Скелет по образцу Publication.php: CRUD, листинг с фильтрами по 3-уровневой
 * аудитории (category_id / audience_type_id / specialization_id), тегам и
 * программе (program_compliance). ИИ-генерация подключается в MaterialGenerator.
 */

class Material
{
    private $db;

    public function __construct($pdo)
    {
        $this->db = new Database($pdo);
    }

    public function create(array $data): int
    {
        return $this->db->insert('materials', [
            'user_id' => $data['user_id'] ?? null,
            'funnel_session_id' => $data['funnel_session_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? '',
            'content' => $data['content'] ?? '',
            'material_type_id' => $data['material_type_id'] ?? null,
            'file_path' => $data['file_path'] ?? null,
            'file_original_name' => $data['file_original_name'] ?? null,
            'file_size' => $data['file_size'] ?? null,
            'file_format' => $data['file_format'] ?? null,
            'preview_image_url' => $data['preview_image_url'] ?? null,
            'is_generated' => !empty($data['is_generated']) ? 1 : 0,
            'ai_model_used' => $data['ai_model_used'] ?? null,
            'ai_prompt' => $data['ai_prompt'] ?? null,
            'ai_params_json' => isset($data['ai_params']) ? json_encode($data['ai_params'], JSON_UNESCAPED_UNICODE) : null,
            'program_compliance' => $data['program_compliance'] ?? null,
            'token_cost' => $data['token_cost'] ?? 0,
            'is_unlocked' => array_key_exists('is_unlocked', $data) ? (int)(bool)$data['is_unlocked'] : 1,
            'unlock_token_cost' => $data['unlock_token_cost'] ?? 0,
            'slug' => $data['slug'] ?? $this->generateSlug($data['title']),
            'meta_title' => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
            'status' => $data['status'] ?? 'draft',
            'published_at' => ($data['status'] ?? 'draft') === 'published' ? date('Y-m-d H:i:s') : null,
        ]);
    }

    public function update(int $id, array $data): int
    {
        $allowed = [
            'title', 'description', 'content', 'material_type_id',
            'file_path', 'file_original_name', 'file_size', 'file_format', 'preview_image_url',
            'is_generated', 'ai_model_used', 'ai_prompt',
            'program_compliance', 'token_cost', 'is_unlocked', 'unlock_token_cost',
            'funnel_session_id',
            'slug', 'meta_title', 'meta_description',
            'status', 'moderation_comment', 'published_at',
        ];
        $update = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) {
                $update[$f] = $data[$f];
            }
        }
        if (isset($data['ai_params'])) {
            $update['ai_params_json'] = json_encode($data['ai_params'], JSON_UNESCAPED_UNICODE);
        }
        if (empty($update)) {
            return 0;
        }
        return $this->db->update('materials', $update, 'id = ?', [$id]);
    }

    public function delete(int $id): int
    {
        return $this->db->delete('materials', 'id = ?', [$id]);
    }

    public function getById(int $id): ?array
    {
        $row = $this->db->queryOne(
            "SELECT m.*, mt.name AS type_name, mt.slug AS type_slug, mt.output_format AS type_format
             FROM materials m
             LEFT JOIN material_types mt ON m.material_type_id = mt.id
             WHERE m.id = ?",
            [$id]
        );
        return $row ?: null;
    }

    public function getBySlug(string $slug): ?array
    {
        $row = $this->db->queryOne(
            "SELECT m.*, mt.name AS type_name, mt.slug AS type_slug, mt.output_format AS type_format
             FROM materials m
             LEFT JOIN material_types mt ON m.material_type_id = mt.id
             WHERE m.slug = ?",
            [$slug]
        );
        return $row ?: null;
    }

    /**
     * Каталог опубликованных материалов с фильтрами.
     * Поддерживаемые фильтры: type_id, tag_id, category_id, audience_type_id,
     * specialization_id, program (одно из значений SET program_compliance),
     * is_generated, sort=date|popular|downloads.
     */
    public function getPublished(int $limit = 20, int $offset = 0, array $filters = []): array
    {
        $sql = "SELECT m.*, mt.name AS type_name, mt.slug AS type_slug, mt.output_format AS type_format
                FROM materials m
                LEFT JOIN material_types mt ON m.material_type_id = mt.id";
        $wheres = ["m.status = 'published'"];
        $params = [];

        if (!empty($filters['tag_id'])) {
            $sql .= " JOIN material_tag_relations mtr ON m.id = mtr.material_id";
            $wheres[] = "mtr.tag_id = ?";
            $params[] = $filters['tag_id'];
        }
        if (!empty($filters['type_id'])) {
            $wheres[] = "m.material_type_id = ?";
            $params[] = $filters['type_id'];
        }
        if (!empty($filters['category_id'])) {
            $sql .= " JOIN material_audience_categories mac ON m.id = mac.material_id";
            $wheres[] = "mac.category_id = ?";
            $params[] = $filters['category_id'];
        }
        if (!empty($filters['audience_type_id'])) {
            $sql .= " JOIN material_audience_types mat ON m.id = mat.material_id";
            $wheres[] = "mat.audience_type_id = ?";
            $params[] = $filters['audience_type_id'];
        }
        if (!empty($filters['specialization_id'])) {
            $sql .= " JOIN material_specializations msp ON m.id = msp.material_id";
            $wheres[] = "msp.specialization_id = ?";
            $params[] = $filters['specialization_id'];
        }
        if (!empty($filters['program'])) {
            $wheres[] = "FIND_IN_SET(?, m.program_compliance) > 0";
            $params[] = $filters['program'];
        }
        if (isset($filters['is_generated'])) {
            $wheres[] = "m.is_generated = ?";
            $params[] = (int)(bool)$filters['is_generated'];
        }

        $sql .= " WHERE " . implode(" AND ", $wheres);

        $sort = $filters['sort'] ?? 'date';
        if ($sort === 'popular') {
            $sql .= " ORDER BY m.views_count DESC, m.published_at DESC";
        } elseif ($sort === 'downloads') {
            $sql .= " ORDER BY m.downloads_count DESC, m.published_at DESC";
        } else {
            $sql .= " ORDER BY m.published_at DESC";
        }

        $sql .= " LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->query($sql, $params);
    }

    public function countPublished(array $filters = []): int
    {
        $sql = "SELECT COUNT(DISTINCT m.id) AS cnt FROM materials m";
        $wheres = ["m.status = 'published'"];
        $params = [];

        if (!empty($filters['tag_id'])) {
            $sql .= " JOIN material_tag_relations mtr ON m.id = mtr.material_id";
            $wheres[] = "mtr.tag_id = ?";
            $params[] = $filters['tag_id'];
        }
        if (!empty($filters['type_id'])) {
            $wheres[] = "m.material_type_id = ?";
            $params[] = $filters['type_id'];
        }
        if (!empty($filters['category_id'])) {
            $sql .= " JOIN material_audience_categories mac ON m.id = mac.material_id";
            $wheres[] = "mac.category_id = ?";
            $params[] = $filters['category_id'];
        }
        if (!empty($filters['audience_type_id'])) {
            $sql .= " JOIN material_audience_types mat ON m.id = mat.material_id";
            $wheres[] = "mat.audience_type_id = ?";
            $params[] = $filters['audience_type_id'];
        }
        if (!empty($filters['specialization_id'])) {
            $sql .= " JOIN material_specializations msp ON m.id = msp.material_id";
            $wheres[] = "msp.specialization_id = ?";
            $params[] = $filters['specialization_id'];
        }
        if (!empty($filters['program'])) {
            $wheres[] = "FIND_IN_SET(?, m.program_compliance) > 0";
            $params[] = $filters['program'];
        }

        $sql .= " WHERE " . implode(" AND ", $wheres);
        $row = $this->db->queryOne($sql, $params);
        return (int)($row['cnt'] ?? 0);
    }

    public function search(string $query, array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $sql = "SELECT m.*, mt.name AS type_name, mt.slug AS type_slug,
                       MATCH(m.title, m.description) AGAINST(? IN NATURAL LANGUAGE MODE) AS relevance
                FROM materials m
                LEFT JOIN material_types mt ON m.material_type_id = mt.id
                WHERE m.status = 'published'
                  AND MATCH(m.title, m.description) AGAINST(? IN NATURAL LANGUAGE MODE)
                ORDER BY relevance DESC, m.published_at DESC
                LIMIT ? OFFSET ?";
        return $this->db->query($sql, [$query, $query, $limit, $offset]);
    }

    public function getByUser(int $userId, ?string $status = null): array
    {
        $sql = "SELECT m.*, mt.name AS type_name, mt.slug AS type_slug, mt.output_format
                FROM materials m
                LEFT JOIN material_types mt ON m.material_type_id = mt.id
                WHERE m.user_id = ?";
        $params = [$userId];
        if ($status !== null) {
            $sql .= " AND m.status = ?";
            $params[] = $status;
        }
        $sql .= " ORDER BY m.created_at DESC";
        return $this->db->query($sql, $params);
    }

    public function incrementViews(int $id): void
    {
        $this->db->execute("UPDATE materials SET views_count = views_count + 1 WHERE id = ?", [$id]);
    }

    public function incrementDownloads(int $id): void
    {
        $this->db->execute("UPDATE materials SET downloads_count = downloads_count + 1 WHERE id = ?", [$id]);
    }

    public function attachTags(int $materialId, array $tagIds): void
    {
        $this->db->execute("DELETE FROM material_tag_relations WHERE material_id = ?", [$materialId]);
        foreach (array_unique(array_map('intval', $tagIds)) as $tagId) {
            if ($tagId > 0) {
                $this->db->execute(
                    "INSERT IGNORE INTO material_tag_relations (material_id, tag_id) VALUES (?, ?)",
                    [$materialId, $tagId]
                );
            }
        }
    }

    public function attachAudience(int $materialId, array $categoryIds = [], array $typeIds = [], array $specIds = []): void
    {
        $this->db->execute("DELETE FROM material_audience_categories WHERE material_id = ?", [$materialId]);
        $this->db->execute("DELETE FROM material_audience_types WHERE material_id = ?", [$materialId]);
        $this->db->execute("DELETE FROM material_specializations WHERE material_id = ?", [$materialId]);

        foreach (array_unique(array_map('intval', $categoryIds)) as $cid) {
            if ($cid > 0) {
                $this->db->execute("INSERT IGNORE INTO material_audience_categories (material_id, category_id) VALUES (?, ?)", [$materialId, $cid]);
            }
        }
        foreach (array_unique(array_map('intval', $typeIds)) as $tid) {
            if ($tid > 0) {
                $this->db->execute("INSERT IGNORE INTO material_audience_types (material_id, audience_type_id) VALUES (?, ?)", [$materialId, $tid]);
            }
        }
        foreach (array_unique(array_map('intval', $specIds)) as $sid) {
            if ($sid > 0) {
                $this->db->execute("INSERT IGNORE INTO material_specializations (material_id, specialization_id) VALUES (?, ?)", [$materialId, $sid]);
            }
        }
    }

    public function getTags(int $materialId): array
    {
        return $this->db->query(
            "SELECT t.* FROM material_tags t
             JOIN material_tag_relations r ON t.id = r.tag_id
             WHERE r.material_id = ?
             ORDER BY t.tag_type, t.display_order",
            [$materialId]
        );
    }

    public function generateSlug(string $title): string
    {
        $slug = mb_strtolower($title, 'UTF-8');
        $map = [
            'а' => 'a','б' => 'b','в' => 'v','г' => 'g','д' => 'd','е' => 'e','ё' => 'e',
            'ж' => 'zh','з' => 'z','и' => 'i','й' => 'y','к' => 'k','л' => 'l','м' => 'm',
            'н' => 'n','о' => 'o','п' => 'p','р' => 'r','с' => 's','т' => 't','у' => 'u',
            'ф' => 'f','х' => 'h','ц' => 'c','ч' => 'ch','ш' => 'sh','щ' => 'shch','ъ' => '',
            'ы' => 'y','ь' => '','э' => 'e','ю' => 'yu','я' => 'ya',
        ];
        $slug = strtr($slug, $map);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        $slug = substr($slug, 0, 200);

        $base = $slug;
        $i = 2;
        while ($this->slugExists($slug)) {
            $slug = $base . '-' . $i;
            $i++;
        }
        return $slug;
    }

    private function slugExists(string $slug): bool
    {
        // Database::queryOne под капотом — PDOStatement::fetch(), который
        // возвращает false при отсутствии строки (не null). Используем !empty().
        $row = $this->db->queryOne("SELECT id FROM materials WHERE slug = ? LIMIT 1", [$slug]);
        return !empty($row);
    }

    public function publish(int $id): int
    {
        return $this->db->update(
            'materials',
            ['status' => 'published', 'published_at' => date('Y-m-d H:i:s')],
            'id = ?',
            [$id]
        );
    }
}
