<?php
/**
 * User Class
 * Handles user CRUD operations and authentication
 */

class User {
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
                $this->db->queryOne("SELECT 1 FROM user_specializations LIMIT 1");
                $this->v2Ready = true;
            } catch (\Exception $e) {
                $this->v2Ready = false;
            }
        }
        return $this->v2Ready;
    }

    /**
     * Выдать пользователю статус пожизненной скидки.
     * Идемпотентно: возвращает true только если флаг реально был проставлен
     * этим вызовом (используется как сигнал «только что выдано» — нужен для
     * однократной отправки приветственного письма).
     */
    public function grantLifetimeDiscount(int $userId): bool {
        try {
            $affected = $this->db->execute(
                "UPDATE users
                 SET has_lifetime_discount = 1,
                     lifetime_discount_granted_at = NOW()
                 WHERE id = ? AND has_lifetime_discount = 0",
                [$userId]
            );
            return (int)$affected > 0;
        } catch (\Exception $e) {
            // Миграция 085 ещё не применена — молча игнорируем.
            return false;
        }
    }

    /**
     * Find user by email
     */
    public function findByEmail($email) {
        return $this->db->queryOne(
            "SELECT * FROM users WHERE email = ?",
            [$email]
        );
    }

    /**
     * Find user by ID
     */
    public function getById($id) {
        return $this->db->queryOne(
            "SELECT * FROM users WHERE id = ?",
            [$id]
        );
    }

    /**
     * Получить «незавершённые покупки» пользователя — pending-записи
     * (webinar_certificates / publication_certificates / olympiad_registrations),
     * которые он клал в корзину (есть строка в order_items), но так и не оплатил.
     *
     * Цена берётся из родительского товара (webinars.certificate_price /
     * publications.price / olympiads.diploma_price), а не из pending-записи,
     * чтобы при изменении цены пользователь увидел актуальную.
     */
    public function getUnfinishedPurchases($userId) {
        $userId = (int)$userId;
        if ($userId <= 0) return [];

        $items = [];

        // Вебинары
        $webinars = $this->db->query(
            "SELECT wc.id AS item_id,
                    w.title,
                    w.slug,
                    COALESCE(w.certificate_price, wc.price) AS price,
                    wc.created_at
             FROM webinar_certificates wc
             JOIN webinars w ON w.id = wc.webinar_id
             JOIN order_items oi ON oi.webinar_certificate_id = wc.id
             WHERE wc.user_id = ?
               AND wc.status = 'pending'
               AND NOT EXISTS (
                   SELECT 1 FROM orders o2
                   JOIN order_items oi2 ON oi2.order_id = o2.id
                   WHERE oi2.webinar_certificate_id = wc.id
                     AND o2.payment_status = 'succeeded'
               )
               AND NOT EXISTS (
                   SELECT 1 FROM dismissed_pending_items d
                   WHERE d.user_id = wc.user_id
                     AND d.item_type = 'webinar_certificate'
                     AND d.item_id = wc.id
               )
             GROUP BY wc.id
             ORDER BY wc.created_at DESC",
            [$userId]
        );
        foreach ($webinars as $row) {
            $items[] = [
                'type' => 'webinar',
                'item_id' => (int)$row['item_id'],
                'title' => $row['title'],
                'price' => (float)$row['price'],
                'url' => '/vebinary/' . $row['slug'] . '/',
                'created_at' => $row['created_at'],
            ];
        }

        // Публикации
        $publications = $this->db->query(
            "SELECT pc.id AS item_id,
                    p.title,
                    p.slug,
                    COALESCE(pc.price, 149.00) AS price,
                    pc.created_at
             FROM publication_certificates pc
             JOIN publications p ON p.id = pc.publication_id
             JOIN order_items oi ON oi.certificate_id = pc.id
             WHERE pc.user_id = ?
               AND pc.status = 'pending'
               AND NOT EXISTS (
                   SELECT 1 FROM orders o2
                   JOIN order_items oi2 ON oi2.order_id = o2.id
                   WHERE oi2.certificate_id = pc.id
                     AND o2.payment_status = 'succeeded'
               )
               AND NOT EXISTS (
                   SELECT 1 FROM dismissed_pending_items d
                   WHERE d.user_id = pc.user_id
                     AND d.item_type = 'publication_certificate'
                     AND d.item_id = pc.id
               )
             GROUP BY pc.id
             ORDER BY pc.created_at DESC",
            [$userId]
        );
        foreach ($publications as $row) {
            $items[] = [
                'type' => 'publication',
                'item_id' => (int)$row['item_id'],
                'title' => $row['title'],
                'price' => (float)$row['price'],
                'url' => '/zhurnal/' . $row['slug'] . '/',
                'created_at' => $row['created_at'],
            ];
        }

        // Олимпиады
        $olympiads = $this->db->query(
            "SELECT oreg.id AS item_id,
                    ol.title,
                    ol.slug,
                    ol.diploma_price AS price,
                    oreg.created_at
             FROM olympiad_registrations oreg
             JOIN olympiads ol ON ol.id = oreg.olympiad_id
             JOIN order_items oi ON oi.olympiad_registration_id = oreg.id
             WHERE oreg.user_id = ?
               AND oreg.status = 'pending'
               AND NOT EXISTS (
                   SELECT 1 FROM orders o2
                   JOIN order_items oi2 ON oi2.order_id = o2.id
                   WHERE oi2.olympiad_registration_id = oreg.id
                     AND o2.payment_status = 'succeeded'
               )
               AND NOT EXISTS (
                   SELECT 1 FROM dismissed_pending_items d
                   WHERE d.user_id = oreg.user_id
                     AND d.item_type = 'olympiad_registration'
                     AND d.item_id = oreg.id
               )
             GROUP BY oreg.id
             ORDER BY oreg.created_at DESC",
            [$userId]
        );
        foreach ($olympiads as $row) {
            $items[] = [
                'type' => 'olympiad',
                'item_id' => (int)$row['item_id'],
                'title' => $row['title'],
                'price' => (float)$row['price'],
                'url' => '/olimpiady/' . $row['slug'] . '/',
                'created_at' => $row['created_at'],
            ];
        }

        // Сортировка по дате (свежие сверху)
        usort($items, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));

        return $items;
    }

    /**
     * Find user by session token
     */
    public function findBySessionToken($token) {
        return $this->db->queryOne(
            "SELECT * FROM users WHERE session_token = ?",
            [$token]
        );
    }

    /**
     * Create a new user
     * Returns user ID
     */
    public function create($data) {
        $insertData = [
            'email' => $data['email'],
            'full_name' => $data['full_name'],
            'phone' => $data['phone'] ?? null,
            'city' => $data['city'] ?? null,
            'organization' => $data['organization'] ?? null,
            'profession' => $data['profession'] ?? null,
            'institution_type_id' => $data['institution_type_id'] ?? null
        ];

        if ($this->isV2() && isset($data['audience_category_id'])) {
            $insertData['audience_category_id'] = $data['audience_category_id'];
        }

        $userId = $this->db->insert('users', $insertData);

        // Сохранить специализации пользователя (v2)
        if ($this->isV2() && !empty($data['specialization_ids']) && is_array($data['specialization_ids'])) {
            $this->setSpecializations($userId, $data['specialization_ids']);
        }

        return $userId;
    }

    /**
     * Update user data
     * Returns number of affected rows
     */
    public function update($userId, $data) {
        $updateData = [];

        // Only update provided fields
        $allowedFields = ['full_name', 'phone', 'city', 'organization', 'profession', 'session_token', 'institution_type_id',
                          'author_bio', 'avatar_path', 'social_vk', 'social_telegram'];

        if ($this->isV2()) {
            $allowedFields[] = 'audience_category_id';
        }

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }

        if (!empty($updateData)) {
            $this->db->update('users', $updateData, 'id = ?', [$userId]);
        }

        // Обновить специализации (v2)
        if ($this->isV2() && isset($data['specialization_ids']) && is_array($data['specialization_ids'])) {
            $this->setSpecializations($userId, $data['specialization_ids']);
        }

        return !empty($updateData) ? 1 : 0;
    }

    /**
     * Получить специализации пользователя
     */
    public function getSpecializations($userId) {
        if (!$this->isV2()) {
            return [];
        }

        return $this->db->query(
            "SELECT s.*
             FROM audience_specializations s
             JOIN user_specializations us ON s.id = us.specialization_id
             WHERE us.user_id = ? AND s.is_active = 1
             ORDER BY s.specialization_type ASC, s.display_order ASC",
            [$userId]
        );
    }

    /**
     * Установить специализации пользователя
     */
    public function setSpecializations($userId, $specializationIds) {
        if (!$this->isV2()) {
            return;
        }

        $this->db->delete('user_specializations', 'user_id = ?', [$userId]);

        foreach ($specializationIds as $specId) {
            $this->db->insert('user_specializations', [
                'user_id' => $userId,
                'specialization_id' => $specId
            ]);
        }
    }

    /**
     * Добавить специализации пользователю (аддитивно, без удаления существующих).
     * Используется для автоматического обогащения профиля при оплате заказов.
     */
    public function addSpecializations($userId, array $specializationIds): int
    {
        if (!$this->isV2() || empty($specializationIds)) {
            return 0;
        }

        $added = 0;
        foreach ($specializationIds as $specId) {
            $specId = (int)$specId;
            if ($specId <= 0) continue;

            try {
                $this->db->execute(
                    "INSERT IGNORE INTO user_specializations (user_id, specialization_id) VALUES (?, ?)",
                    [$userId, $specId]
                );
                $added++;
            } catch (\Exception $e) {
                // FK violation (invalid specialization_id) — skip
            }
        }
        return $added;
    }

    /**
     * Получить полный профиль аудитории пользователя
     */
    public function getAudienceProfile($userId) {
        $user = $this->getById($userId);
        if (!$user) return null;

        $profile = [
            'category' => null,
            'type' => null,
            'specializations' => []
        ];

        if ($this->isV2() && !empty($user['audience_category_id'])) {
            $profile['category'] = $this->db->queryOne(
                "SELECT * FROM audience_categories WHERE id = ?",
                [$user['audience_category_id']]
            );
        }

        if (!empty($user['institution_type_id'])) {
            $profile['type'] = $this->db->queryOne(
                "SELECT * FROM audience_types WHERE id = ?",
                [$user['institution_type_id']]
            );
        }

        $profile['specializations'] = $this->getSpecializations($userId);

        return $profile;
    }

    /**
     * Generate and save session token for auto-login
     * Returns the token
     */
    public function generateSessionToken($userId) {
        $token = bin2hex(random_bytes(32));

        $this->update($userId, ['session_token' => $token]);

        return $token;
    }

    /**
     * Clear session token (logout)
     */
    public function clearSessionToken($userId) {
        return $this->db->update('users', ['session_token' => null], 'id = ?', [$userId]);
    }

    /**
     * Get all users (admin function)
     */
    public function getAll($limit = 100, $offset = 0) {
        return $this->db->query(
            "SELECT * FROM users ORDER BY created_at DESC LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
    }

    /**
     * Get user count (admin function)
     */
    public function count() {
        $result = $this->db->queryOne("SELECT COUNT(*) as total FROM users");
        return $result['total'] ?? 0;
    }

    /**
     * Validate email format
     */
    public static function validateEmail($email) {
        // Check basic format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        // Check for Cyrillic characters
        if (preg_match('/[А-Яа-яЁё]/u', $email)) {
            return false;
        }

        return true;
    }

    /**
     * Sanitize user input
     *
     * Только trim. HTML-экранирование — на выводе.
     */
    public static function sanitize($data) {
        if (is_array($data)) {
            return array_map([self::class, 'sanitize'], $data);
        }

        return is_string($data) ? trim($data) : $data;
    }
}
