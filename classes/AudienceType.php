<?php
/**
 * AudienceType Class
 * Управление типами аудитории (ДОУ, Начальная школа, Средняя/старшая школа, СПО)
 */

class AudienceType {
    private $db;

    public function __construct($pdo) {
        $this->db = new Database($pdo);
    }

    /**
     * Получить все активные типы аудитории
     *
     * @param bool $activeOnly Если true, возвращает только активные типы
     * @return array Массив типов аудитории
     */
    public function getAll($activeOnly = true) {
        $sql = "SELECT * FROM audience_types";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY display_order ASC, name ASC";

        return $this->db->query($sql);
    }

    /**
     * Получить тип аудитории по slug
     *
     * @param string $slug Slug типа аудитории (например, 'dou', 'nachalnaya-shkola')
     * @return array|null Данные типа аудитории или null
     */
    public function getBySlug($slug) {
        return $this->db->queryOne(
            "SELECT * FROM audience_types WHERE slug = ? AND is_active = 1",
            [$slug]
        );
    }

    /**
     * Получить тип аудитории по ID
     *
     * @param int $id ID типа аудитории
     * @return array|null Данные типа аудитории или null
     */
    public function getById($id) {
        return $this->db->queryOne(
            "SELECT * FROM audience_types WHERE id = ?",
            [$id]
        );
    }

    /**
     * Получить специализации для типа аудитории
     *
     * @param int $audienceTypeId ID типа аудитории
     * @param bool $activeOnly Если true, возвращает только активные специализации
     * @return array Массив специализаций
     */
    public function getSpecializations($audienceTypeId, $activeOnly = true) {
        $sql = "SELECT * FROM audience_specializations WHERE audience_type_id = ?";
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " ORDER BY display_order ASC, name ASC";

        return $this->db->query($sql, [$audienceTypeId]);
    }

    /**
     * Создать новый тип аудитории
     *
     * @param array $data Данные нового типа
     * @return int ID созданного типа
     */
    public function create($data) {
        return $this->db->insert('audience_types', [
            'slug' => $data['slug'],
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
            'display_order' => $data['display_order'] ?? 0,
            'is_active' => $data['is_active'] ?? 1
        ]);
    }

    /**
     * Обновить тип аудитории
     *
     * @param int $id ID типа для обновления
     * @param array $data Новые данные
     * @return int Количество затронутых строк
     */
    public function update($id, $data) {
        $updateData = [];
        $allowedFields = ['slug', 'name', 'description', 'display_order', 'is_active'];

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
     *
     * @param int $id ID типа для удаления
     * @return int Количество удаленных строк
     */
    public function delete($id) {
        return $this->db->delete('audience_types', 'id = ?', [$id]);
    }

    /**
     * Подсчитать количество конкурсов для типа аудитории
     *
     * @param int $audienceTypeId ID типа аудитории
     * @return int Количество конкурсов
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
