<?php
/**
 * Order Class
 * Handles order CRUD operations and Yookassa payment integration
 */

class Order {
    private $db;

    public function __construct($pdo) {
        $this->db = new Database($pdo);
    }

    /**
     * Create new order from cart
     * Returns order ID
     * @param int $userId User ID
     * @param array $cartData Cart data with items, subtotal, discount, total
     * @param array $certificatesData Optional array of certificate data
     * @param float $grandTotal Optional grand total including certificates
     */
    public function createFromCart($userId, $cartData, $certificatesData = [], $grandTotal = null, $webinarCertificatesData = [], $olympiadRegistrationsData = []) {
        $orderNumber = self::generateOrderNumber();

        // Use totals from cartData (already includes all items with unified 2+1 promotion)
        $subtotal = $cartData['subtotal'] ?? 0;
        $discount = $cartData['discount'] ?? 0;
        $finalAmount = $grandTotal ?? ($cartData['total'] ?? 0);

        $insertData = [
            'user_id' => $userId,
            'order_number' => $orderNumber,
            'total_amount' => $subtotal,
            'discount_amount' => $discount,
            'final_amount' => $finalAmount,
            'promotion_applied' => ($cartData['promotion_applied'] ?? false) ? 1 : 0,
            'payment_status' => 'pending'
        ];

        $orderId = $this->db->insert('orders', $insertData);

        // Create order items for registrations
        if ($orderId && !empty($cartData['items'])) {
            foreach ($cartData['items'] as $item) {
                $this->db->insert('order_items', [
                    'order_id' => $orderId,
                    'registration_id' => $item['registration_id'],
                    'price' => $item['price'],
                    'is_free_promotion' => $item['is_free'] ? 1 : 0
                ]);
            }
        }

        // Create order items for certificates (with promotion support)
        if ($orderId && !empty($certificatesData)) {
            foreach ($certificatesData as $cert) {
                $this->db->insert('order_items', [
                    'order_id' => $orderId,
                    'certificate_id' => $cert['id'],
                    'price' => $cert['price'] ?? 299,
                    'is_free_promotion' => !empty($cert['is_free']) ? 1 : 0
                ]);
            }
        }

        // Create order items for webinar certificates (with promotion support)
        if ($orderId && !empty($webinarCertificatesData)) {
            foreach ($webinarCertificatesData as $webCert) {
                $this->db->insert('order_items', [
                    'order_id' => $orderId,
                    'webinar_certificate_id' => $webCert['id'],
                    'price' => $webCert['price'] ?? 200,
                    'is_free_promotion' => !empty($webCert['is_free']) ? 1 : 0
                ]);
            }
        }

        // Create order items for olympiad registrations (with promotion support)
        if ($orderId && !empty($olympiadRegistrationsData)) {
            foreach ($olympiadRegistrationsData as $olympReg) {
                $this->db->insert('order_items', [
                    'order_id' => $orderId,
                    'olympiad_registration_id' => $olympReg['id'],
                    'price' => $olympReg['diploma_price'] ?? OLYMPIAD_DIPLOMA_PRICE,
                    'is_free_promotion' => !empty($olympReg['is_free']) ? 1 : 0
                ]);
            }
        }

        return $orderId;
    }

    /**
     * Create order for course enrollment (direct payment, no cart)
     */
    public function createForCourseEnrollment($userId, $enrollmentId, $courseTitle, $price, $discountAmount = 0) {
        $orderNumber = self::generateOrderNumber();
        $finalAmount = $price - $discountAmount;

        $orderId = $this->db->insert('orders', [
            'user_id' => $userId,
            'order_number' => $orderNumber,
            'total_amount' => $price,
            'discount_amount' => $discountAmount,
            'final_amount' => $finalAmount,
            'promotion_applied' => $discountAmount > 0 ? 1 : 0,
            'payment_status' => 'pending'
        ]);

        if ($orderId) {
            $this->db->insert('order_items', [
                'order_id' => $orderId,
                'course_enrollment_id' => $enrollmentId,
                'price' => $finalAmount,
                'is_free_promotion' => 0
            ]);

            $this->log("CREATE | User {$userId} | Course order {$orderNumber} | Amount {$finalAmount} RUB (discount {$discountAmount})");
        }

        return $orderId;
    }

    /**
     * Get order by ID with items
     */
    public function getById($orderId) {
        $order = $this->db->queryOne(
            "SELECT o.*, u.email, u.full_name
             FROM orders o
             JOIN users u ON o.user_id = u.id
             WHERE o.id = ?",
            [$orderId]
        );

        if ($order) {
            $order['items'] = $this->getOrderItems($orderId);
        }

        return $order;
    }

    /**
     * Get order by order number
     */
    public function getByOrderNumber($orderNumber) {
        $order = $this->db->queryOne(
            "SELECT o.*, u.email, u.full_name
             FROM orders o
             JOIN users u ON o.user_id = u.id
             WHERE o.order_number = ?",
            [$orderNumber]
        );

        if ($order) {
            $order['items'] = $this->getOrderItems($order['id']);
        }

        return $order;
    }

    /**
     * Get order by Yookassa payment ID
     */
    public function getByPaymentId($paymentId) {
        $order = $this->db->queryOne(
            "SELECT o.*, u.email, u.full_name
             FROM orders o
             JOIN users u ON o.user_id = u.id
             WHERE o.yookassa_payment_id = ?",
            [$paymentId]
        );

        if ($order) {
            $order['items'] = $this->getOrderItems($order['id']);
        }

        return $order;
    }

    /**
     * Get order items with registration and certificate details
     */
    public function getOrderItems($orderId) {
        return $this->db->query(
            "SELECT oi.*,
                    r.competition_id, r.nomination, r.work_title,
                    c.title as competition_title, c.category,
                    pc.publication_id, pc.author_name as cert_author_name,
                    p.title as publication_title,
                    wc.webinar_id, wc.full_name as webinar_cert_name,
                    wc.certificate_number as webinar_cert_number,
                    wc.hours as webinar_cert_hours, wc.price as webinar_cert_price,
                    w.title as webinar_title,
                    olr.olympiad_id, olr.placement as olympiad_placement,
                    olr.score as olympiad_score,
                    ol.title as olympiad_title,
                    ce.course_id as ce_course_id, ce.full_name as ce_full_name,
                    crs.title as course_title, crs.program_type as course_program_type
             FROM order_items oi
             LEFT JOIN registrations r ON oi.registration_id = r.id
             LEFT JOIN competitions c ON r.competition_id = c.id
             LEFT JOIN publication_certificates pc ON oi.certificate_id = pc.id
             LEFT JOIN publications p ON pc.publication_id = p.id
             LEFT JOIN webinar_certificates wc ON oi.webinar_certificate_id = wc.id
             LEFT JOIN webinars w ON wc.webinar_id = w.id
             LEFT JOIN olympiad_registrations olr ON oi.olympiad_registration_id = olr.id
             LEFT JOIN olympiads ol ON olr.olympiad_id = ol.id
             LEFT JOIN course_enrollments ce ON oi.course_enrollment_id = ce.id
             LEFT JOIN courses crs ON ce.course_id = crs.id
             WHERE oi.order_id = ?",
            [$orderId]
        );
    }

    /**
     * Update order payment status
     */
    public function updatePaymentStatus($orderId, $status, $paidAt = null) {
        $updateData = ['payment_status' => $status];

        if ($paidAt) {
            $updateData['paid_at'] = $paidAt;
        }

        return $this->db->update('orders', $updateData, 'id = ?', [$orderId]);
    }

    /**
     * Update order with Yookassa payment details
     */
    public function updateYookassaDetails($orderId, $paymentId, $confirmationUrl) {
        return $this->db->update('orders', [
            'yookassa_payment_id' => $paymentId,
            'yookassa_confirmation_url' => $confirmationUrl
        ], 'id = ?', [$orderId]);
    }

    /**
     * Get user orders
     */
    public function getUserOrders($userId, $limit = 10) {
        return $this->db->query(
            "SELECT * FROM orders
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT ?",
            [$userId, $limit]
        );
    }

    /**
     * Check if payment already processed (idempotency)
     */
    public function isProcessed($paymentId) {
        $order = $this->db->queryOne(
            "SELECT payment_status FROM orders WHERE yookassa_payment_id = ?",
            [$paymentId]
        );

        return $order && $order['payment_status'] === 'succeeded';
    }

    /**
     * Mark order registrations as paid
     */
    public function markRegistrationsAsPaid($orderId) {
        return $this->db->execute("
            UPDATE registrations r
            INNER JOIN order_items oi ON r.id = oi.registration_id
            SET r.status = 'paid'
            WHERE oi.order_id = ?
        ", [$orderId]);
    }

    /**
     * Count orders by status
     */
    public function countByStatus($status = null) {
        if ($status) {
            $result = $this->db->queryOne(
                "SELECT COUNT(*) as total FROM orders WHERE payment_status = ?",
                [$status]
            );
        } else {
            $result = $this->db->queryOne("SELECT COUNT(*) as total FROM orders");
        }

        return $result['total'] ?? 0;
    }

    /**
     * Generate unique order number
     */
    public static function generateOrderNumber() {
        return 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
    }

    /**
     * Log order operation
     */
    private function log($message) {
        $logFile = BASE_PATH . '/logs/payment.log';
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}\n";

        // Ensure logs directory exists
        $logDir = dirname($logFile);
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }

        error_log($logMessage, 3, $logFile);
    }

    /**
     * Create order with transaction
     */
    public function createOrderTransaction($userId, $cartData) {
        try {
            $this->db->beginTransaction();

            $orderId = $this->createFromCart($userId, $cartData);

            if (!$orderId) {
                throw new Exception('Failed to create order');
            }

            $this->db->commit();
            $this->log("CREATE | User {$userId} | Order created | Amount {$cartData['total']} RUB");

            return $orderId;

        } catch (Exception $e) {
            $this->db->rollBack();
            $this->log("ERROR | User {$userId} | Order creation failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Получить все ID специализаций из мероприятий в заказе.
     * Объединяет specialization_id из 4 junction-таблиц.
     *
     * @param int $orderId
     * @return int[]
     */
    public function getSpecializationIdsForOrder($orderId): array
    {
        $rows = $this->db->query("
            SELECT DISTINCT s.specialization_id
            FROM (
                SELECT cs.specialization_id
                FROM order_items oi
                JOIN registrations r ON oi.registration_id = r.id
                JOIN competition_specializations cs ON r.competition_id = cs.competition_id
                WHERE oi.order_id = ? AND oi.registration_id IS NOT NULL

                UNION

                SELECT os.specialization_id
                FROM order_items oi
                JOIN olympiad_registrations olr ON oi.olympiad_registration_id = olr.id
                JOIN olympiad_specializations os ON olr.olympiad_id = os.olympiad_id
                WHERE oi.order_id = ? AND oi.olympiad_registration_id IS NOT NULL

                UNION

                SELECT ws.specialization_id
                FROM order_items oi
                JOIN webinar_certificates wc ON oi.webinar_certificate_id = wc.id
                JOIN webinar_specializations ws ON wc.webinar_id = ws.webinar_id
                WHERE oi.order_id = ? AND oi.webinar_certificate_id IS NOT NULL

                UNION

                SELECT ps.specialization_id
                FROM order_items oi
                JOIN publication_certificates pc ON oi.certificate_id = pc.id
                JOIN publication_specializations ps ON pc.publication_id = ps.publication_id
                WHERE oi.order_id = ? AND oi.certificate_id IS NOT NULL
            ) s
        ", [$orderId, $orderId, $orderId, $orderId]);

        return array_map(fn($r) => (int)$r['specialization_id'], $rows);
    }
}
