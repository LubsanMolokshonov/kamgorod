<?php
/**
 * User Class
 * Handles user CRUD operations and authentication
 */

class User {
    private $db;

    public function __construct($pdo) {
        $this->db = new Database($pdo);
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

        return $this->db->insert('users', $insertData);
    }

    /**
     * Update user data
     * Returns number of affected rows
     */
    public function update($userId, $data) {
        $updateData = [];

        // Only update provided fields
        $allowedFields = ['full_name', 'phone', 'city', 'organization', 'profession', 'session_token', 'institution_type_id'];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }

        if (empty($updateData)) {
            return 0;
        }

        return $this->db->update('users', $updateData, 'id = ?', [$userId]);
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
