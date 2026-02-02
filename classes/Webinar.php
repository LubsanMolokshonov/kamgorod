<?php
/**
 * Webinar Class
 * Управление вебинарами
 */

class Webinar {
    private $db;

    const STATUS_DRAFT = 'draft';
    const STATUS_SCHEDULED = 'scheduled';
    const STATUS_LIVE = 'live';
    const STATUS_COMPLETED = 'completed';
    const STATUS_AUTOWEBINAR = 'autowebinar';

    public function __construct($pdo) {
        $this->db = new Database($pdo);
    }

    // ==================== CRUD методы ====================

    /**
     * Создать вебинар
     *
     * @param array $data Данные вебинара
     * @return int ID созданного вебинара
     */
    public function create($data) {
        return $this->db->insert('webinars', [
            'title' => $data['title'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'short_description' => $data['short_description'] ?? null,
            'cover_image' => $data['cover_image'] ?? null,
            'video_url' => $data['video_url'] ?? null,
            'broadcast_url' => $data['broadcast_url'] ?? null,
            'scheduled_at' => $data['scheduled_at'],
            'duration_minutes' => $data['duration_minutes'] ?? 60,
            'timezone' => $data['timezone'] ?? 'Europe/Moscow',
            'speaker_id' => $data['speaker_id'] ?? null,
            'status' => $data['status'] ?? self::STATUS_DRAFT,
            'is_active' => $data['is_active'] ?? 1,
            'is_free' => $data['is_free'] ?? 1,
            'certificate_price' => $data['certificate_price'] ?? 149.00,
            'certificate_hours' => $data['certificate_hours'] ?? 2,
            'meta_title' => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null
        ]);
    }

    /**
     * Обновить вебинар
     *
     * @param int $id ID вебинара
     * @param array $data Новые данные
     * @return int Количество затронутых строк
     */
    public function update($id, $data) {
        $allowedFields = [
            'title', 'slug', 'description', 'short_description',
            'cover_image', 'video_url', 'broadcast_url',
            'scheduled_at', 'duration_minutes', 'timezone',
            'speaker_id', 'status', 'is_active', 'is_free',
            'certificate_price', 'certificate_hours',
            'meta_title', 'meta_description',
            'views_count', 'registrations_count'
        ];

        $updateData = [];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updateData[$field] = $data[$field];
            }
        }

        if (empty($updateData)) {
            return 0;
        }

        return $this->db->update('webinars', $updateData, 'id = ?', [$id]);
    }

    /**
     * Удалить вебинар
     *
     * @param int $id ID вебинара
     * @return int Количество удаленных строк
     */
    public function delete($id) {
        return $this->db->delete('webinars', 'id = ?', [$id]);
    }

    // ==================== Получение данных ====================

    /**
     * Получить вебинар по ID
     *
     * @param int $id ID вебинара
     * @return array|null Данные вебинара или null
     */
    public function getById($id) {
        return $this->db->queryOne(
            "SELECT w.*, s.full_name as speaker_name, s.position as speaker_position,
                    s.organization as speaker_organization, s.photo as speaker_photo, s.bio as speaker_bio
             FROM webinars w
             LEFT JOIN speakers s ON w.speaker_id = s.id
             WHERE w.id = ?",
            [$id]
        );
    }

    /**
     * Получить вебинар по slug
     *
     * @param string $slug Slug вебинара
     * @return array|null Данные вебинара или null
     */
    public function getBySlug($slug) {
        return $this->db->queryOne(
            "SELECT w.*, s.full_name as speaker_name, s.position as speaker_position,
                    s.organization as speaker_organization, s.photo as speaker_photo, s.bio as speaker_bio
             FROM webinars w
             LEFT JOIN speakers s ON w.speaker_id = s.id
             WHERE w.slug = ? AND w.is_active = 1",
            [$slug]
        );
    }

    /**
     * Получить все вебинары с фильтрацией
     *
     * @param array $filters Фильтры (status, audience_type_id, is_free)
     * @param int $limit Лимит
     * @param int $offset Смещение
     * @return array Массив вебинаров
     */
    public function getAll($filters = [], $limit = 20, $offset = 0) {
        $sql = "SELECT DISTINCT w.*, s.full_name as speaker_name, s.position as speaker_position,
                       s.photo as speaker_photo
                FROM webinars w
                LEFT JOIN speakers s ON w.speaker_id = s.id";

        $params = [];
        $where = ['w.is_active = 1'];

        // Join audience types if filtering
        if (!empty($filters['audience_type_id'])) {
            $sql .= " JOIN webinar_audience_types wat ON w.id = wat.webinar_id";
            $where[] = "wat.audience_type_id = ?";
            $params[] = $filters['audience_type_id'];
        }

        // Status filter
        if (!empty($filters['status'])) {
            if ($filters['status'] === 'upcoming') {
                $where[] = "w.status IN ('scheduled', 'live')";
            } elseif ($filters['status'] === 'recordings') {
                $where[] = "w.status = 'completed' AND w.video_url IS NOT NULL";
            } elseif ($filters['status'] === 'autowebinar') {
                $where[] = "w.status = 'autowebinar'";
            } else {
                $where[] = "w.status = ?";
                $params[] = $filters['status'];
            }
        }

        // Free filter
        if (isset($filters['is_free'])) {
            $where[] = "w.is_free = ?";
            $params[] = $filters['is_free'] ? 1 : 0;
        }

        $sql .= " WHERE " . implode(" AND ", $where);

        // Ordering
        if (!empty($filters['status']) && $filters['status'] === 'upcoming') {
            $sql .= " ORDER BY w.scheduled_at ASC";
        } else {
            $sql .= " ORDER BY w.scheduled_at DESC";
        }

        $sql .= " LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->query($sql, $params);
    }

    /**
     * Получить предстоящие вебинары
     *
     * @param int $limit Лимит
     * @return array Массив вебинаров
     */
    public function getUpcoming($limit = 10) {
        return $this->getAll(['status' => 'upcoming'], $limit);
    }

    /**
     * Получить записи вебинаров
     *
     * @param int $limit Лимит
     * @return array Массив вебинаров
     */
    public function getRecordings($limit = 10) {
        return $this->getAll(['status' => 'recordings'], $limit);
    }

    /**
     * Получить автовебинары
     *
     * @param int $limit Лимит
     * @return array Массив вебинаров
     */
    public function getAutowebinars($limit = 10) {
        return $this->getAll(['status' => 'autowebinar'], $limit);
    }

    // ==================== Типы аудитории ====================

    /**
     * Получить типы аудитории для вебинара
     *
     * @param int $webinarId ID вебинара
     * @return array Массив типов аудитории
     */
    public function getAudienceTypes($webinarId) {
        return $this->db->query(
            "SELECT at.* FROM audience_types at
             JOIN webinar_audience_types wat ON at.id = wat.audience_type_id
             WHERE wat.webinar_id = ?
             ORDER BY at.display_order",
            [$webinarId]
        );
    }

    /**
     * Установить типы аудитории для вебинара
     *
     * @param int $webinarId ID вебинара
     * @param array $audienceTypeIds Массив ID типов аудитории
     * @return void
     */
    public function setAudienceTypes($webinarId, $audienceTypeIds) {
        // Удалить существующие связи
        $this->db->delete('webinar_audience_types', 'webinar_id = ?', [$webinarId]);

        // Добавить новые
        foreach ($audienceTypeIds as $typeId) {
            $this->db->insert('webinar_audience_types', [
                'webinar_id' => $webinarId,
                'audience_type_id' => $typeId
            ]);
        }
    }

    // ==================== Статистика ====================

    /**
     * Увеличить счетчик просмотров
     *
     * @param int $id ID вебинара
     * @return void
     */
    public function incrementViews($id) {
        $this->db->execute(
            "UPDATE webinars SET views_count = views_count + 1 WHERE id = ?",
            [$id]
        );
    }

    /**
     * Подсчитать вебинары по статусу
     *
     * @return array Статистика по статусам
     */
    public function countByStatus() {
        $result = $this->db->query(
            "SELECT
                SUM(CASE WHEN status IN ('scheduled', 'live') THEN 1 ELSE 0 END) as upcoming,
                SUM(CASE WHEN status = 'completed' AND video_url IS NOT NULL THEN 1 ELSE 0 END) as recordings,
                SUM(CASE WHEN status = 'autowebinar' THEN 1 ELSE 0 END) as autowebinars
             FROM webinars
             WHERE is_active = 1"
        );

        return $result[0] ?? ['upcoming' => 0, 'recordings' => 0, 'autowebinars' => 0];
    }

    // ==================== Вспомогательные методы ====================

    /**
     * Генерировать уникальный slug
     *
     * @param string $title Заголовок
     * @return string Уникальный slug
     */
    public function generateSlug($title) {
        // Транслитерация
        $slug = $this->transliterate($title);
        $slug = strtolower($slug);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        // Проверить уникальность
        $baseSlug = $slug;
        $counter = 1;

        while ($this->slugExists($slug)) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Проверить существование slug
     *
     * @param string $slug Slug для проверки
     * @param int|null $excludeId ID для исключения
     * @return bool True если slug существует
     */
    public function slugExists($slug, $excludeId = null) {
        $sql = "SELECT id FROM webinars WHERE slug = ?";
        $params = [$slug];

        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        return $this->db->queryOne($sql, $params) !== false;
    }

    /**
     * Транслитерация текста
     *
     * @param string $text Текст для транслитерации
     * @return string Транслитерированный текст
     */
    private function transliterate($text) {
        $converter = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
            'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
            'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
            'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
            'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch',
            'ш' => 'sh', 'щ' => 'sch', 'ь' => '', 'ы' => 'y', 'ъ' => '',
            'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
            'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D',
            'Е' => 'E', 'Ё' => 'E', 'Ж' => 'Zh', 'З' => 'Z', 'И' => 'I',
            'Й' => 'Y', 'К' => 'K', 'Л' => 'L', 'М' => 'M', 'Н' => 'N',
            'О' => 'O', 'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T',
            'У' => 'U', 'Ф' => 'F', 'Х' => 'H', 'Ц' => 'C', 'Ч' => 'Ch',
            'Ш' => 'Sh', 'Щ' => 'Sch', 'Ь' => '', 'Ы' => 'Y', 'Ъ' => '',
            'Э' => 'E', 'Ю' => 'Yu', 'Я' => 'Ya',
        ];

        return strtr($text, $converter);
    }

    /**
     * Форматировать дату и время вебинара
     *
     * @param string $datetime Дата и время
     * @return array Форматированные данные
     */
    public static function formatDateTime($datetime) {
        $timestamp = strtotime($datetime);

        $months = [
            1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля',
            5 => 'мая', 6 => 'июня', 7 => 'июля', 8 => 'августа',
            9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря'
        ];

        $days = [
            0 => 'воскресенье', 1 => 'понедельник', 2 => 'вторник',
            3 => 'среда', 4 => 'четверг', 5 => 'пятница', 6 => 'суббота'
        ];

        return [
            'date' => date('j', $timestamp) . ' ' . $months[(int)date('n', $timestamp)],
            'date_full' => date('j', $timestamp) . ' ' . $months[(int)date('n', $timestamp)] . ' ' . date('Y', $timestamp),
            'time' => date('H:i', $timestamp),
            'day_of_week' => $days[(int)date('w', $timestamp)],
            'timestamp' => $timestamp,
            'iso' => date('c', $timestamp)
        ];
    }
}
