<?php
/**
 * Registration Class
 * Handles user registrations, cart logic, and promotion calculation
 */

class Registration {
    private $db;

    public function __construct($pdo) {
        $this->db = new Database($pdo);
    }

    /**
     * Create a new registration
     * Returns registration ID
     */
    public function create($data) {
        $insertData = [
            'user_id' => $data['user_id'],
            'competition_id' => $data['competition_id'],
            'nomination' => $data['nomination'],
            'work_title' => $data['work_title'] ?? null,
            'competition_type' => $data['competition_type'] ?? null,
            'placement' => $data['placement'] ?? null,
            'participation_date' => $data['participation_date'] ?? null,
            'diploma_template_id' => $data['diploma_template_id'] ?? null,
            'has_supervisor' => $data['has_supervisor'] ?? 0,
            'supervisor_name' => $data['supervisor_name'] ?? null,
            'supervisor_email' => $data['supervisor_email'] ?? null,
            'supervisor_organization' => $data['supervisor_organization'] ?? null,
            'status' => 'pending'
        ];

        return $this->db->insert('registrations', $insertData);
    }

    /**
     * Get registration by ID
     */
    public function getById($id) {
        return $this->db->queryOne(
            "SELECT r.*, c.title as competition_title, c.price as competition_price, c.category
             FROM registrations r
             JOIN competitions c ON r.competition_id = c.id
             WHERE r.id = ?",
            [$id]
        );
    }

    /**
     * Get user's registrations
     */
    public function getUserRegistrations($userId, $status = null) {
        if ($status) {
            return $this->db->query(
                "SELECT r.*, c.title as competition_title, c.price as competition_price
                 FROM registrations r
                 JOIN competitions c ON r.competition_id = c.id
                 WHERE r.user_id = ? AND r.status = ?
                 ORDER BY r.created_at DESC",
                [$userId, $status]
            );
        } else {
            return $this->db->query(
                "SELECT r.*, c.title as competition_title, c.price as competition_price
                 FROM registrations r
                 JOIN competitions c ON r.competition_id = c.id
                 WHERE r.user_id = ?
                 ORDER BY r.created_at DESC",
                [$userId]
            );
        }
    }

    /**
     * Update registration
     */
    public function update($id, $data) {
        $updateData = [];
        $allowedFields = [
            'nomination', 'work_title', 'competition_type', 'placement',
            'participation_date', 'diploma_template_id', 'has_supervisor',
            'supervisor_name', 'supervisor_email', 'supervisor_organization', 'status'
        ];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }

        if (empty($updateData)) {
            return 0;
        }

        return $this->db->update('registrations', $updateData, 'id = ?', [$id]);
    }

    /**
     * Calculate cart total with promotion (2+1 free)
     */
    public function calculateCartTotal($registrationIds) {
        if (empty($registrationIds)) {
            return [
                'items' => [],
                'subtotal' => 0,
                'discount' => 0,
                'total' => 0,
                'promotion_applied' => false
            ];
        }

        $items = [];
        $subtotal = 0;

        // Get all registration details
        foreach ($registrationIds as $regId) {
            $registration = $this->getById($regId);

            if (!$registration) {
                continue;
            }

            $items[] = [
                'registration_id' => $regId,
                'competition_name' => $registration['competition_title'],
                'nomination' => $registration['nomination'],
                'price' => (float)$registration['competition_price'],
                'is_free' => false
            ];

            $subtotal += (float)$registration['competition_price'];
        }

        // Apply 2+1 promotion
        $discount = 0;
        $itemCount = count($items);

        if ($itemCount >= 3) {
            // Sort by price descending to make cheapest items free
            usort($items, function($a, $b) {
                return $b['price'] <=> $a['price'];
            });

            // Calculate free items (every 3rd item)
            $freeItemCount = floor($itemCount / 3);

            for ($i = 0; $i < $freeItemCount; $i++) {
                $freeIndex = ($i + 1) * 3 - 1; // Indices: 2, 5, 8, ...
                if (isset($items[$freeIndex])) {
                    $items[$freeIndex]['is_free'] = true;
                    $discount += $items[$freeIndex]['price'];
                }
            }
        }

        return [
            'items' => $items,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total' => $subtotal - $discount,
            'promotion_applied' => $discount > 0
        ];
    }

    /**
     * Get competition details for a registration
     */
    public function getCompetition($competitionId) {
        return $this->db->queryOne(
            "SELECT * FROM competitions WHERE id = ?",
            [$competitionId]
        );
    }

    /**
     * Check if user already registered for competition
     */
    public function userHasRegistration($userId, $competitionId) {
        $result = $this->db->queryOne(
            "SELECT id FROM registrations WHERE user_id = ? AND competition_id = ?",
            [$userId, $competitionId]
        );

        return !empty($result);
    }

    /**
     * Delete registration
     */
    public function delete($id) {
        return $this->db->delete('registrations', 'id = ?', [$id]);
    }

    /**
     * Get all registrations (admin)
     */
    public function getAll($limit = 100, $offset = 0) {
        return $this->db->query(
            "SELECT r.*, u.email, u.full_name, c.title as competition_title
             FROM registrations r
             JOIN users u ON r.user_id = u.id
             JOIN competitions c ON r.competition_id = c.id
             ORDER BY r.created_at DESC
             LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
    }

    /**
     * Count registrations
     */
    public function count($status = null) {
        if ($status) {
            $result = $this->db->queryOne(
                "SELECT COUNT(*) as total FROM registrations WHERE status = ?",
                [$status]
            );
        } else {
            $result = $this->db->queryOne("SELECT COUNT(*) as total FROM registrations");
        }

        return $result['total'] ?? 0;
    }
}
