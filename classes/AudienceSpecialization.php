<?php
/**
 * AudienceSpecialization Class
 * Управление специализациями аудитории (Level 2)
 * Предметы и роли, связанные с типами аудитории через junction-таблицу (many-to-many)
 * Обратно совместим: работает и до, и после миграции 040/041
 */

class AudienceSpecialization {
    private $db;
    private $v2Ready = null;

    public function __construct($pdo) {
        $this->db = new Database($pdo);
    }

    /**
     * Проверить, применена ли миграция v2
     */
    private function isV2() {
        if ($this->v2Ready === null) {
            try {
                $this->db->queryOne("SELECT 1 FROM audience_type_specializations LIMIT 1");
                $this->v2Ready = true;
            } catch (\Exception $e) {
                $this->v2Ready = false;
            }
        }
        return $this->v2Ready;
    }

    /**
     * Проверить, есть ли колонка specialization_type
     */
    private function hasSpecType() {
        return $this->isV2();
    }

    /**
     * Получить специализацию по ID
     */
    public function getById($id) {
        return $this->db->queryOne(
            "SELECT * FROM audience_specializations WHERE id = ?",
            [$id]
        );
    }

    /**
     * Получить специализацию по slug
     */
    public function getBySlug($slug) {
        return $this->db->queryOne(
            "SELECT * FROM audience_specializations WHERE slug = ? AND is_active = 1",
            [$slug]
        );
    }

    /**
     * Получить специализацию по slug и типу аудитории (через junction)
     */
    public function getBySlugAndAudienceType($audienceTypeId, $slug) {
        if ($this->isV2()) {
            return $this->db->queryOne(
                "SELECT s.*
                 FROM audience_specializations s
                 JOIN audience_type_specializations ats ON s.id = ats.specialization_id
                 WHERE ats.audience_type_id = ? AND s.slug = ? AND s.is_active = 1",
                [$audienceTypeId, $slug]
            );
        }

        // Fallback: прямой FK
        return $this->db->queryOne(
            "SELECT * FROM audience_specializations WHERE audience_type_id = ? AND slug = ? AND is_active = 1",
            [$audienceTypeId, $slug]
        );
    }

    /**
     * Получить все специализации для типа аудитории (через junction)
     */
    public function getByAudienceType($audienceTypeId, $activeOnly = true) {
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
     * Получить все специализации
     */
    public function getAll($activeOnly = true) {
        $sql = "SELECT s.* FROM audience_specializations s";
        if ($activeOnly) {
            $sql .= " WHERE s.is_active = 1";
        }

        if ($this->hasSpecType()) {
            $sql .= " ORDER BY s.specialization_type ASC, s.display_order ASC, s.name ASC";
        } else {
            $sql .= " ORDER BY s.display_order ASC, s.name ASC";
        }

        return $this->db->query($sql);
    }

    /**
     * Получить все специализации определённого типа
     */
    public function getByType($type, $activeOnly = true) {
        if (!$this->hasSpecType()) {
            return $this->getAll($activeOnly);
        }

        $sql = "SELECT * FROM audience_specializations WHERE specialization_type = ?";
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " ORDER BY display_order ASC, name ASC";

        return $this->db->query($sql, [$type]);
    }

    /**
     * Получить типы аудитории, к которым привязана специализация
     */
    public function getAudienceTypes($specializationId) {
        if ($this->isV2()) {
            return $this->db->query(
                "SELECT at.*, ac.name as category_name, ac.slug as category_slug
                 FROM audience_types at
                 JOIN audience_type_specializations ats ON at.id = ats.audience_type_id
                 LEFT JOIN audience_categories ac ON at.category_id = ac.id
                 WHERE ats.specialization_id = ? AND at.is_active = 1
                 ORDER BY at.display_order ASC",
                [$specializationId]
            );
        }

        // Fallback: обратный поиск по прямому FK
        $spec = $this->getById($specializationId);
        if ($spec && !empty($spec['audience_type_id'])) {
            return $this->db->query(
                "SELECT * FROM audience_types WHERE id = ? AND is_active = 1",
                [$spec['audience_type_id']]
            );
        }
        return [];
    }

    /**
     * Создать специализацию
     */
    public function create($data) {
        $insertData = [
            'slug' => $data['slug'],
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
            'display_order' => $data['display_order'] ?? 0,
            'is_active' => $data['is_active'] ?? 1
        ];

        // Поля v2
        if ($this->hasSpecType()) {
            $insertData['specialization_type'] = $data['specialization_type'] ?? 'subject';
            $insertData['icon'] = $data['icon'] ?? null;
        }

        // Legacy FK
        if (!$this->isV2() && isset($data['audience_type_id'])) {
            $insertData['audience_type_id'] = $data['audience_type_id'];
        }

        $id = $this->db->insert('audience_specializations', $insertData);

        // Если v2 и указаны типы аудитории, создать junction-записи
        if ($this->isV2() && !empty($data['audience_type_ids']) && is_array($data['audience_type_ids'])) {
            foreach ($data['audience_type_ids'] as $typeId) {
                $this->db->insert('audience_type_specializations', [
                    'audience_type_id' => $typeId,
                    'specialization_id' => $id,
                    'display_order' => $data['display_order'] ?? 0
                ]);
            }
        }

        return $id;
    }

    /**
     * Обновить специализацию
     */
    public function update($id, $data) {
        $updateData = [];
        $allowedFields = ['slug', 'name', 'description', 'display_order', 'is_active'];

        if ($this->hasSpecType()) {
            $allowedFields[] = 'specialization_type';
            $allowedFields[] = 'icon';
        }

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }

        if (empty($updateData)) {
            return 0;
        }

        return $this->db->update('audience_specializations', $updateData, 'id = ?', [$id]);
    }

    /**
     * Удалить специализацию
     */
    public function delete($id) {
        return $this->db->delete('audience_specializations', 'id = ?', [$id]);
    }

    /**
     * Подсчитать количество конкурсов для специализации
     */
    public function getCompetitionCount($specializationId) {
        $result = $this->db->queryOne(
            "SELECT COUNT(DISTINCT cs.competition_id) as total
             FROM competition_specializations cs
             JOIN competitions c ON cs.competition_id = c.id
             WHERE cs.specialization_id = ? AND c.is_active = 1",
            [$specializationId]
        );

        return $result['total'] ?? 0;
    }

    /**
     * Привязать специализацию к типам аудитории
     */
    public function setAudienceTypes($specializationId, $audienceTypeIds) {
        if (!$this->isV2()) {
            return;
        }

        $this->db->delete('audience_type_specializations', 'specialization_id = ?', [$specializationId]);

        foreach ($audienceTypeIds as $typeId) {
            $this->db->insert('audience_type_specializations', [
                'audience_type_id' => $typeId,
                'specialization_id' => $specializationId,
                'display_order' => 0
            ]);
        }
    }
}
