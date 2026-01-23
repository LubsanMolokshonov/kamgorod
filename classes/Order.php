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
     */
    public function createFromCart($userId, $cartData) {
        $orderNumber = self::generateOrderNumber();

        $insertData = [
            'user_id' => $userId,
            'order_number' => $orderNumber,
            'total_amount' => $cartData['subtotal'],
            'discount_amount' => $cartData['discount'],
            'final_amount' => $cartData['total'],
            'promotion_applied' => $cartData['promotion_applied'] ? 1 : 0,
            'payment_status' => 'pending'
        ];

        $orderId = $this->db->insert('orders', $insertData);

        // Create order items
        if ($orderId) {
            foreach ($cartData['items'] as $item) {
                $this->db->insert('order_items', [
                    'order_id' => $orderId,
                    'registration_id' => $item['registration_id'],
                    'price' => $item['price'],
                    'is_free_promotion' => $item['is_free'] ? 1 : 0
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
     * Get order items with registration details
     */
    public function getOrderItems($orderId) {
        return $this->db->query(
            "SELECT oi.*, r.nomination, r.work_title,
                    c.title as competition_title, c.category
             FROM order_items oi
             JOIN registrations r ON oi.registration_id = r.id
             JOIN competitions c ON r.competition_id = c.id
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
