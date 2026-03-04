<?php
/**
 * AudienceType Class
 * Управление типами аудитории (Level 1)
 * ДОУ, Начальная школа, Средняя/старшая школа, СПО, ДО, типы для детей
 * Обратно совместим: работает и до, и после миграции 040/041
 */

class AudienceType {
    private $db;
    private $v2Ready = null;

    public function __construct($pdo) {
        $this->db = new Database($pdo);
    }

    /**
     * Проверить, применена ли миграция v2 (audience_categories существует)
     */
    private function isV2() {
        if ($this->v2Ready === null) {
            try {
                $this->db->queryOne("SELECT 1 FROM audience_categories LIMIT 1");
                $this->v2Ready = true;
            } catch (\Exception $e) {
                $this->v2Ready = false;
            }
        }
        return $this->v2Ready;
    }

    /**
     * Получить все активные типы аудитории
     */
    public function getAll($activeOnly = true) {
        if ($this->isV2()) {
            $sql = "SELECT at.*, ac.name as category_name, ac.slug as category_slug
                    FROM audience_types at
                    LEFT JOIN audience_categories ac ON at.category_id = ac.id";
        } else {
            $sql = "SELECT * FROM audience_types at";
        }
        if ($activeOnly) {
            $sql .= " WHERE at.is_active = 1";
        }
        $sql .= " ORDER BY at.display_order ASC, at.name ASC";

        return $this->db->query($sql);
    }

    /**
     * Получить типы аудитории по категории
     */
    public function getByCategory($categoryId, $activeOnly = true) {
        $sql = "SELECT * FROM audience_types WHERE category_id = ?";
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " ORDER BY display_order ASC, name ASC";

        return $this->db->query($sql, [$categoryId]);
    }

    /**
     * Получить типы по slug категории
     */
    public function getByCategorySlug($categorySlug, $activeOnly = true) {
        $sql = "SELECT at.* FROM audience_types at
                JOIN audience_categories ac ON at.category_id = ac.id
                WHERE ac.slug = ?";
        if ($activeOnly) {
            $sql .= " AND at.is_active = 1 AND ac.is_active = 1";
        }
        $sql .= " ORDER BY at.display_order ASC, at.name ASC";

        return $this->db->query($sql, [$categorySlug]);
    }

    /**
     * Получить тип аудитории по slug
     */
    public function getBySlug($slug) {
        if ($this->isV2()) {
            return $this->db->queryOne(
                "SELECT at.*, ac.name as category_name, ac.slug as category_slug
                 FROM audience_types at
                 LEFT JOIN audience_categories ac ON at.category_id = ac.id
                 WHERE at.slug = ? AND at.is_active = 1",
                [$slug]
            );
        }
        return $this->db->queryOne(
            "SELECT * FROM audience_types WHERE slug = ? AND is_active = 1",
            [$slug]
        );
    }

    /**
     * Получить тип аудитории по ID
     */
    public function getById($id) {
        if ($this->isV2()) {
            return $this->db->queryOne(
                "SELECT at.*, ac.name as category_name, ac.slug as category_slug
                 FROM audience_types at
                 LEFT JOIN audience_categories ac ON at.category_id = ac.id
                 WHERE at.id = ?",
                [$id]
            );
        }
        return $this->db->queryOne(
            "SELECT * FROM audience_types WHERE id = ?",
            [$id]
        );
    }

    /**
     * Получить специализации для типа аудитории
     * v2: через junction-таблицу, fallback: через прямой FK
     */
    public function getSpecializations($audienceTypeId, $activeOnly = true) {
        if ($this->isV2()) {
            $sql = "SELECT s.*, ats.display_order as junction_order
                    FROM audience_specializations s
                    JOIN audience_type_specializations ats ON s.id = ats.specialization_id
                    WHERE ats.audience_type_id = ?";
            if ($activeOnly) {
                $sql .= " AND s.is_active = 1";
            }
            $sql .= " ORDER BY s.specialization_type ASC, ats.display_order ASC, s.display_order ASC, s.name ASC";
            return $this->db->query($sql, [$audienceTypeId]);
        }

        // Fallback: прямой FK
        $sql = "SELECT * FROM audience_specializations WHERE audience_type_id = ?";
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " ORDER BY display_order ASC, name ASC";
        return $this->db->query($sql, [$audienceTypeId]);
    }

    /**
     * Получить только предметные специализации
     */
    public function getSubjectSpecializations($audienceTypeId) {
        if (!$this->isV2()) {
            return $this->getSpecializations($audienceTypeId);
        }
        return $this->db->query(
            "SELECT s.*, ats.display_order as junction_order
             FROM audience_specializations s
             JOIN audience_type_specializations ats ON s.id = ats.specialization_id
             WHERE ats.audience_type_id = ? AND s.is_active = 1 AND s.specialization_type = 'subject'
             ORDER BY ats.display_order ASC, s.display_order ASC, s.name ASC",
            [$audienceTypeId]
        );
    }

    /**
     * Получить только роли
     */
    public function getRoleSpecializations($audienceTypeId) {
        if (!$this->isV2()) {
            return [];
        }
        return $this->db->query(
            "SELECT s.*, ats.display_order as junction_order
             FROM audience_specializations s
             JOIN audience_type_specializations ats ON s.id = ats.specialization_id
             WHERE ats.audience_type_id = ? AND s.is_active = 1 AND s.specialization_type = 'role'
             ORDER BY ats.display_order ASC, s.display_order ASC, s.name ASC",
            [$audienceTypeId]
        );
    }

    /**
     * Создать новый тип аудитории
     */
    public function create($data) {
        $insertData = [
            'slug' => $data['slug'],
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
            'display_order' => $data['display_order'] ?? 0,
            'is_active' => $data['is_active'] ?? 1
        ];

        if ($this->isV2()) {
            $insertData['category_id'] = $data['category_id'] ?? null;
        }

        if (isset($data['target_participants_genitive'])) {
            $insertData['target_participants_genitive'] = $data['target_participants_genitive'];
        }

        return $this->db->insert('audience_types', $insertData);
    }

    /**
     * Обновить тип аудитории
     */
    public function update($id, $data) {
        $updateData = [];
        $allowedFields = ['slug', 'name', 'description', 'target_participants_genitive', 'display_order', 'is_active'];

        if ($this->isV2()) {
            $allowedFields[] = 'category_id';
        }

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }

        if (empty($updateData)) {
            return 0;
        }

        return $this->db->update('audience_types', $updateData, 'id = ?', [$id]);
    }

    /**
     * Удалить тип аудитории
     */
    public function delete($id) {
        return $this->db->delete('audience_types', 'id = ?', [$id]);
    }

    /**
     * Подсчитать количество конкурсов для типа аудитории
     */
    public function getCompetitionCount($audienceTypeId) {
        $result = $this->db->queryOne(
            "SELECT COUNT(DISTINCT cat.competition_id) as total
             FROM competition_audience_types cat
             JOIN competitions c ON cat.competition_id = c.id
             WHERE cat.audience_type_id = ? AND c.is_active = 1",
            [$audienceTypeId]
        );

        return $result['total'] ?? 0;
    }
}
