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
    public function createFromCart($userId, $cartData, $certificatesData = [], $grandTotal = null, $webinarCertificatesData = []) {
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
                    'price' => $cert['price'] ?? 149,
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
                    'price' => $webCert['price'] ?? 149,
                    'is_free_promotion' => !empty($webCert['is_free']) ? 1 : 0
                ]);
            }
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
                    r.nomination, r.work_title,
                    c.title as competition_title, c.category,
                    pc.publication_id, pc.author_name as cert_author_name,
                    p.title as publication_title,
                    wc.webinar_id, wc.full_name as webinar_cert_name,
                    wc.certificate_number as webinar_cert_number,
                    w.title as webinar_title
             FROM order_items oi
             LEFT JOIN registrations r ON oi.registration_id = r.id
             LEFT JOIN competitions c ON r.competition_id = c.id
             LEFT JOIN publication_certificates pc ON oi.certificate_id = pc.id
             LEFT JOIN publications p ON pc.publication_id = p.id
             LEFT JOIN webinar_certificates wc ON oi.webinar_certificate_id = wc.id
             LEFT JOIN webinars w ON wc.webinar_id = w.id
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
}
