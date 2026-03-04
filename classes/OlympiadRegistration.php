<?php
/**
 * OlympiadRegistration Class
 * Handles olympiad diploma orders
 */

class OlympiadRegistration {
    private $db;

    public function __construct($pdo) {
        $this->db = new Database($pdo);
    }

    /**
     * Create a new olympiad diploma registration
     */
    public function create($data) {
        return $this->db->insert('olympiad_registrations', [
            'user_id' => $data['user_id'],
            'olympiad_id' => $data['olympiad_id'],
            'olympiad_result_id' => $data['olympiad_result_id'],
            'diploma_template_id' => $data['diploma_template_id'] ?? 1,
            'placement' => $data['placement'],
            'score' => $data['score'],
            'organization' => $data['organization'] ?? '',
            'city' => $data['city'] ?? '',
            'competition_type' => $data['competition_type'] ?? 'всероссийская',
            'participation_date' => $data['participation_date'] ?? date('Y-m-d'),
            'has_supervisor' => $data['has_supervisor'] ?? false,
            'supervisor_name' => $data['supervisor_name'] ?? null,
            'supervisor_email' => $data['supervisor_email'] ?? null,
            'supervisor_organization' => $data['supervisor_organization'] ?? null,
            'status' => 'pending'
        ]);
    }

    /**
     * Get registration by ID with olympiad data
     */
    public function getById($id) {
        return $this->db->queryOne(
            "SELECT r.*, o.title as olympiad_title, o.slug as olympiad_slug,
                    o.diploma_price, o.target_audience,
                    u.full_name, u.email
             FROM olympiad_registrations r
             JOIN olympiads o ON r.olympiad_id = o.id
             JOIN users u ON r.user_id = u.id
             WHERE r.id = ?",
            [$id]
        );
    }

    /**
     * Get registrations by user
     */
    public function getByUser($userId, $status = null) {
        $sql = "SELECT r.*, o.title as olympiad_title, o.slug as olympiad_slug, o.diploma_price
                FROM olympiad_registrations r
                JOIN olympiads o ON r.olympiad_id = o.id
                WHERE r.user_id = ?";
        $params = [$userId];

        if ($status) {
            $sql .= " AND r.status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY r.created_at DESC";

        return $this->db->query($sql, $params);
    }

    /**
     * Update registration
     */
    public function update($id, $data) {
        $allowedFields = [
            'diploma_template_id', 'organization', 'city', 'competition_type',
            'participation_date', 'has_supervisor', 'supervisor_name',
            'supervisor_email', 'supervisor_organization', 'status'
        ];

        $updateData = [];
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }

        if (empty($updateData)) {
            return 0;
        }

        return $this->db->update('olympiad_registrations', $updateData, 'id = ?', [$id]);
    }

    /**
     * Delete registration
     */
    public function delete($id) {
        return $this->db->delete('olympiad_registrations', 'id = ?', [$id]);
    }

    /**
     * Calculate cart total for olympiad registrations
     */
    public function calculateCartTotal($registrationIds) {
        if (empty($registrationIds)) {
            return ['total' => 0, 'items' => []];
        }

        $items = [];
        $total = 0;

        foreach ($registrationIds as $id) {
            $reg = $this->getById($id);
            if ($reg) {
                $price = floatval($reg['diploma_price'] ?? OLYMPIAD_DIPLOMA_PRICE);
                $items[] = [
                    'id' => $reg['id'],
                    'name' => $reg['olympiad_title'],
                    'price' => $price
                ];
                $total += $price;
            }
        }

        return ['total' => $total, 'items' => $items];
    }
}
