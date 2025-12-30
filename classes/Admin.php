<?php
/**
 * Admin Class
 * Handles admin authentication and management
 */

class Admin {
    private $db;

    public function __construct($pdo) {
        $this->db = $pdo;
    }

    /**
     * Authenticate admin user
     * @param string $username
     * @param string $password
     * @return array|false Admin data or false
     */
    public function authenticate($username, $password) {
        $stmt = $this->db->prepare("
            SELECT * FROM admins
            WHERE username = ? AND is_active = 1
        ");
        $stmt->execute([$username]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($admin && password_verify($password, $admin['password_hash'])) {
            // Update last login
            $this->updateLastLogin($admin['id']);

            // Return admin data without password
            unset($admin['password_hash']);
            return $admin;
        }

        return false;
    }

    /**
     * Create new admin
     * @param array $data
     * @return int Admin ID
     */
    public function create($data) {
        $passwordHash = password_hash($data['password'], PASSWORD_DEFAULT);

        $stmt = $this->db->prepare("
            INSERT INTO admins (username, email, password_hash, role, full_name)
            VALUES (?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $data['username'],
            $data['email'],
            $passwordHash,
            $data['role'] ?? 'admin',
            $data['full_name'] ?? null
        ]);

        return $this->db->lastInsertId();
    }

    /**
     * Update admin data
     */
    public function update($adminId, $data) {
        $updateFields = [];
        $params = [];

        $allowedFields = ['username', 'email', 'role', 'full_name', 'is_active'];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateFields[] = "$field = ?";
                $params[] = $data[$field];
            }
        }

        if (isset($data['password'])) {
            $updateFields[] = "password_hash = ?";
            $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
        }

        if (empty($updateFields)) {
            return false;
        }

        $params[] = $adminId;

        $sql = "UPDATE admins SET " . implode(', ', $updateFields) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);

        return $stmt->execute($params);
    }

    /**
     * Get admin by ID
     */
    public function getById($adminId) {
        $stmt = $this->db->prepare("SELECT * FROM admins WHERE id = ?");
        $stmt->execute([$adminId]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($admin) {
            unset($admin['password_hash']);
        }

        return $admin;
    }

    /**
     * Get all admins
     */
    public function getAll() {
        $stmt = $this->db->query("
            SELECT id, username, email, role, full_name, is_active, created_at, last_login_at
            FROM admins
            ORDER BY created_at DESC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update last login timestamp
     */
    private function updateLastLogin($adminId) {
        $stmt = $this->db->prepare("
            UPDATE admins
            SET last_login_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$adminId]);
    }

    /**
     * Check if username exists
     */
    public function usernameExists($username, $excludeId = null) {
        $sql = "SELECT COUNT(*) as count FROM admins WHERE username = ?";
        $params = [$username];

        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result['count'] > 0;
    }

    /**
     * Verify admin session
     */
    public static function verifySession() {
        session_start();

        if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_username'])) {
            header('Location: /admin/login.php');
            exit;
        }

        return [
            'id' => $_SESSION['admin_id'],
            'username' => $_SESSION['admin_username'],
            'role' => $_SESSION['admin_role'] ?? 'admin'
        ];
    }

    /**
     * Check if user has specific role
     */
    public static function hasRole($requiredRole) {
        if (!isset($_SESSION['admin_role'])) {
            return false;
        }

        $roles = ['admin', 'superadmin'];
        $currentRoleIndex = array_search($_SESSION['admin_role'], $roles);
        $requiredRoleIndex = array_search($requiredRole, $roles);

        return $currentRoleIndex >= $requiredRoleIndex;
    }
}
