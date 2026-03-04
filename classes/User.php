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
        $allowedFields = ['full_name', 'phone', 'city', 'organization', 'profession', 'session_token', 'institution_type_id'];

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
     */
    public static function sanitize($data) {
        if (is_array($data)) {
            return array_map([self::class, 'sanitize'], $data);
        }

        return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
    }
}
