<?php
require_once __DIR__ . '/order-fulfillment.php';

/** Применяет подтверждённый полный возврат к тому же платежу и выданным им правам. */
function refundPurchaseOrder(PDO $db, int $orderId, string $paymentId): bool
{
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT payment_status, yookassa_payment_id, subscription_plan_id FROM orders WHERE id=? FOR UPDATE');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order || $order['yookassa_payment_id'] !== $paymentId || $order['payment_status'] === 'refunded'
            || !empty($order['subscription_plan_id'])) {
            $db->commit();
            return false;
        }
        $db->prepare("UPDATE orders SET payment_status='refunded' WHERE id=?")->execute([$orderId]);
        $db->prepare('UPDATE cart_items SET reserved_in_order_id=NULL WHERE reserved_in_order_id=?')->execute([$orderId]);
        if ($order['payment_status'] === 'succeeded') {
            foreach (ORDER_DOCUMENT_TABLES + ['course_enrollment_id' => 'course_enrollments'] as $column => $table) {
                $status = $column === 'course_enrollment_id' ? 'new' : 'pending';
                $update = $db->prepare("UPDATE $table t JOIN order_items oi ON oi.$column=t.id
                    SET t.status=? WHERE oi.order_id=? AND NOT EXISTS (
                    SELECT 1 FROM order_items other JOIN orders o ON o.id=other.order_id
                    WHERE other.$column=t.id AND o.payment_status='succeeded' AND o.id<>?)");
                $update->execute([$status, $orderId, $orderId]);
            }
            $db->prepare("UPDATE publications p JOIN publication_certificates pc ON pc.publication_id=p.id
                JOIN order_items oi ON oi.certificate_id=pc.id SET p.certificate_status='pending'
                WHERE oi.order_id=? AND pc.status='pending' AND NOT EXISTS (
                    SELECT 1 FROM publication_certificates held WHERE held.publication_id=p.id AND held.status IN ('paid','ready')
                )")->execute([$orderId]);
        }
        $db->prepare("UPDATE pending_delayed_emails SET failed_at=NOW(), last_error='Заказ возвращён'
                      WHERE order_id=? AND email_type='payment_success' AND sent_at IS NULL AND failed_at IS NULL")->execute([$orderId]);
        $db->commit();
        return true;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}
