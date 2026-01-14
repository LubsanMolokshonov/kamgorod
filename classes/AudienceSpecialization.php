<?php
/**
 * AudienceSpecialization Class
 * Управление специализациями аудитории (предметы, направления работы)
 */

class AudienceSpecialization {
    private $db;

    public function __construct($pdo) {
        $this->db = new Database($pdo);
    }

    /**
     * Получить специализацию по ID
     *
     * @param int $id ID специализации
     * @return array|null Данные специализации или null
     */
    public function getById($id) {
        return $this->db->queryOne(
            "SELECT s.*, at.name as audience_type_name, at.slug as audience_type_slug
             FROM audience_specializations s
             JOIN audience_types at ON s.audience_type_id = at.id
             WHERE s.id = ?",
            [$id]
        );
    }

    /**
     * Получить специализацию по slug и типу аудитории
     *
     * @param int $audienceTypeId ID типа аудитории
     * @param string $slug Slug специализации
     * @return array|null Данные специализации или null
     */
    public function getBySlug($audienceTypeId, $slug) {
        return $this->db->queryOne(
            "SELECT s.*, at.name as audience_type_name, at.slug as audience_type_slug
             FROM audience_specializations s
             JOIN audience_types at ON s.audience_type_id = at.id
             WHERE s.audience_type_id = ? AND s.slug = ? AND s.is_active = 1",
            [$audienceTypeId, $slug]
        );
    }

    /**
     * Получить все специализации для типа аудитории
     *
     * @param int $audienceTypeId ID типа аудитории
     * @param bool $activeOnly Если true, возвращает только активные
     * @return array Массив специализаций
     */
    public function getByAudienceType($audienceTypeId, $activeOnly = true) {
        $sql = "SELECT * FROM audience_specializations WHERE audience_type_id = ?";
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " ORDER BY display_order ASC, name ASC";

        return $this->db->query($sql, [$audienceTypeId]);
    }

    /**
     * Получить все специализации
     *
     * @param bool $activeOnly Если true, возвращает только активные
     * @return array Массив всех специализаций с типами аудитории
     */
    public function getAll($activeOnly = true) {
        $sql = "SELECT s.*, at.name as audience_type_name, at.slug as audience_type_slug
                FROM audience_specializations s
                JOIN audience_types at ON s.audience_type_id = at.id";
        if ($activeOnly) {
            $sql .= " WHERE s.is_active = 1 AND at.is_active = 1";
        }
        $sql .= " ORDER BY at.display_order ASC, s.display_order ASC, s.name ASC";

        return $this->db->query($sql);
    }

    /**
     * Создать специализацию
     *
     * @param array $data Данные новой специализации
     * @return int ID созданной специализации
     */
    public function create($data) {
        return $this->db->insert('audience_specializations', [
            'audience_type_id' => $data['audience_type_id'],
            'slug' => $data['slug'],
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
            'display_order' => $data['display_order'] ?? 0,
            'is_active' => $data['is_active'] ?? 1
        ]);
    }

    /**
     * Обновить специализацию
     *
     * @param int $id ID специализации для обновления
     * @param array $data Новые данные
     * @return int Количество затронутых строк
     */
    public function update($id, $data) {
        $updateData = [];
        $allowedFields = ['audience_type_id', 'slug', 'name', 'description', 'display_order', 'is_active'];

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
     *
     * @param int $id ID специализации для удаления
     * @return int Количество удаленных строк
     */
    public function delete($id) {
        return $this->db->delete('audience_specializations', 'id = ?', [$id]);
    }

    /**
     * Подсчитать количество конкурсов для специализации
     *
     * @param int $specializationId ID специализации
     * @return int Количество конкурсов
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
}
