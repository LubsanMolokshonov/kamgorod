<?php
/**
 * AudienceCategory Class
 * Управление категориями аудитории (Level 0)
 * Педагогам, Школьникам, Дошкольникам, Студентам СПО
 */

class AudienceCategory {
    private $db;

    public function __construct($pdo) {
        $this->db = new Database($pdo);
    }

    /**
     * Получить все активные категории
     */
    public function getAll($activeOnly = true) {
        $sql = "SELECT * FROM audience_categories";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY display_order ASC, name ASC";

        return $this->db->query($sql);
    }

    /**
     * Получить категорию по slug
     */
    public function getBySlug($slug) {
        return $this->db->queryOne(
            "SELECT * FROM audience_categories WHERE slug = ? AND is_active = 1",
            [$slug]
        );
    }

    /**
     * Получить категорию по ID
     */
    public function getById($id) {
        return $this->db->queryOne(
            "SELECT * FROM audience_categories WHERE id = ?",
            [$id]
        );
    }

    /**
     * Получить типы аудитории для категории
     */
    public function getAudienceTypes($categoryId, $activeOnly = true) {
        $sql = "SELECT * FROM audience_types WHERE category_id = ?";
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " ORDER BY display_order ASC, name ASC";

        return $this->db->query($sql, [$categoryId]);
    }

    /**
     * Подсчитать количество мероприятий для категории (все типы)
     */
    public function getEventCounts($categoryId) {
        $competitions = $this->db->queryOne(
            "SELECT COUNT(DISTINCT cac.competition_id) as total
             FROM competition_audience_categories cac
             JOIN competitions c ON cac.competition_id = c.id
             WHERE cac.category_id = ? AND c.is_active = 1",
            [$categoryId]
        );

        $olympiads = $this->db->queryOne(
            "SELECT COUNT(DISTINCT oac.olympiad_id) as total
             FROM olympiad_audience_categories oac
             JOIN olympiads o ON oac.olympiad_id = o.id
             WHERE oac.category_id = ? AND o.is_active = 1",
            [$categoryId]
        );

        $webinars = $this->db->queryOne(
            "SELECT COUNT(DISTINCT wac.webinar_id) as total
             FROM webinar_audience_categories wac
             JOIN webinars w ON wac.webinar_id = w.id
             WHERE wac.category_id = ? AND w.is_active = 1",
            [$categoryId]
        );

        $publications = $this->db->queryOne(
            "SELECT COUNT(DISTINCT pac.publication_id) as total
             FROM publication_audience_categories pac
             JOIN publications p ON pac.publication_id = p.id
             WHERE pac.category_id = ? AND p.status = 'published'",
            [$categoryId]
        );

        $courses = $this->db->queryOne(
            "SELECT COUNT(DISTINCT cac.course_id) as total
             FROM course_audience_categories cac
             JOIN courses c ON cac.course_id = c.id
             WHERE cac.category_id = ? AND c.is_active = 1",
            [$categoryId]
        );

        return [
            'competitions' => $competitions['total'] ?? 0,
            'olympiads' => $olympiads['total'] ?? 0,
            'webinars' => $webinars['total'] ?? 0,
            'publications' => $publications['total'] ?? 0,
            'courses' => $courses['total'] ?? 0
        ];
    }
}
