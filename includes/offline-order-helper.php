<?php
/**
 * Материализация «оффлайн»-оплат курсов (рассрочка / счёт), закрытых менеджером
 * в Bitrix24 CRM как WON, в синтетический заказ orders + order_items.
 *
 * Зачем: когда клиент платит НЕ через Yookassa на сайте, а, например, по рассрочке,
 * которую оформляет менеджер, строки в orders не появляется — и все отчёты,
 * считающие выручку по orders (UTM-аналитика, РНП, разбивка по продуктам в
 * дашборде), такую продажу не видят. Здесь мы заводим за неё заказ, наследуя
 * UTM-метки заявки, чтобы атрибуция сохранилась.
 *
 * Синтетический заказ сделан «инертным» (см. сайд-эффекты orders):
 *  - payment_status='succeeded' сразу → reconcile-payments его не трогает
 *    (он опрашивает только pending-заказы);
 *  - metrika_sent_at=NOW() → ecommerce-replay не отправит цель в Метрику;
 *  - yookassa_payment_id='bitrix:<dealId>' → не похож на реальный YK-id (reconcile
 *    его проигнорирует) и служит ключом идемпотентности;
 *  - fulfillOrderItems() НЕ вызывается, письма НЕ планируются — выдача доступа
 *    к курсу остаётся на стороне менеджера/Bitrix.
 *
 * Курсовые order_items (только course_enrollment_id) не подпадают под условия
 * retry-unsent-documents (там нужны registration/certificate-поля), так что
 * генерация PDF тоже не запускается.
 */

require_once __DIR__ . '/../classes/CourseCheckout.php';
require_once __DIR__ . '/../classes/Bitrix24Integration.php';

if (!function_exists('materializeOfflineCourseOrder')) {

    /** Применить подтверждённую офлайн-оплату под той же блокировкой, что и онлайн. */
    function materializeOfflineCourseOrder(Database $db, int $enrollmentId, array $deal): ?int {
        $dealId = (int)($deal['ID'] ?? 0);
        $amount = (float)($deal['OPPORTUNITY'] ?? 0);
        if ($dealId <= 0 || $amount <= 0 || !Bitrix24Integration::isFgosPaidDeal($deal)) {
            return null;
        }

        return CourseCheckout::withEnrollmentLock($db->getPDO(), $enrollmentId, static function () use (
            $db, $enrollmentId, $deal, $dealId, $amount
        ): ?int {
            $enrollment = $db->queryOne('SELECT * FROM course_enrollments WHERE id = ?', [$enrollmentId]);
            if (!$enrollment || (int)$enrollment['bitrix_lead_id'] !== $dealId || empty($enrollment['user_id'])) {
                return null;
            }

            $marker = 'bitrix:' . $dealId;
            $orders = $db->query(
                "SELECT o.id, o.user_id, o.payment_status, o.yookassa_payment_id, a.attempt_payload,
                        (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) AS item_count
                 FROM orders o
                 JOIN order_items oi ON oi.order_id = o.id
                 LEFT JOIN course_checkout_payments a ON a.order_id = o.id
                 WHERE oi.course_enrollment_id = ?
                 LIMIT 2",
                [$enrollmentId]
            );
            if (count($orders) > 1) {
                error_log("[offline-order] неоднозначная история заказов заявки #{$enrollmentId}");
                return null;
            }

            $order = $orders[0] ?? null;
            if ($order && ((int)$order['user_id'] !== (int)$enrollment['user_id'] || (int)$order['item_count'] !== 1)) {
                return null;
            }
            if ($order && $order['payment_status'] === 'succeeded' && $order['yookassa_payment_id'] === $marker) {
                $db->update('course_enrollments', ['status' => 'paid'], 'id = ?', [$enrollmentId]);
                return (int)$order['id'];
            }
            if ($order) {
                // Отложить сверку, пока есть активный платёж или результат запроса неизвестен.
                // Отменённый ЮKassa-заказ или прежний синтетический заказ можно использовать повторно.
                $paymentId = (string)$order['yookassa_payment_id'];
                if ($order['payment_status'] !== 'failed' || $order['attempt_payload'] !== null
                    || $paymentId === '' || (str_starts_with($paymentId, 'bitrix:') && $paymentId !== $marker)) {
                    return null;
                }
            }

            $paidAt = strtotime((string)($deal['CLOSEDATE'] ?? '')) ?: time();
            $orderData = [
                'total_amount'        => $amount,
                'final_amount'        => $amount,
                'payment_status'      => 'succeeded',
                'paid_at'             => date('Y-m-d H:i:s', $paidAt),
                'metrika_sent_at'     => date('Y-m-d H:i:s'),
                'yookassa_payment_id' => $marker,
            ];
            $db->beginTransaction();
            try {
                if ($order) {
                    $orderId = (int)$order['id'];
                    $db->update('orders', $orderData + [
                        'discount_amount'          => 0,
                        'loyalty_discount_amount'  => 0,
                        'promotion_applied'        => 0,
                        'yookassa_confirmation_url' => null,
                    ], 'id = ?', [$orderId]);
                    $db->update('order_items', ['price' => $amount], 'order_id = ?', [$orderId]);
                } else {
                    $orderId = (int)$db->insert('orders', $orderData + [
                        'user_id'       => (int)$enrollment['user_id'],
                        'order_number'  => 'BX-' . $dealId,
                        'utm_source'    => $enrollment['utm_source'] ?: null,
                        'utm_medium'    => $enrollment['utm_medium'] ?: null,
                        'utm_campaign'  => $enrollment['utm_campaign'] ?: null,
                        'utm_content'   => $enrollment['utm_content'] ?: null,
                        'utm_term'      => $enrollment['utm_term'] ?: null,
                        'visit_id'      => $enrollment['visit_id'] ?: null,
                    ]);
                    $db->insert('order_items', [
                        'order_id'              => $orderId,
                        'course_enrollment_id'  => $enrollmentId,
                        'price'                 => $amount,
                        'covered_by_subscription' => 0,
                    ]);
                }
                $db->update('course_enrollments', ['status' => 'paid'], 'id = ?', [$enrollmentId]);
                $db->commit();
                return $orderId;
            } catch (Throwable $e) {
                $db->rollback();
                throw $e;
            }
        });
    }
}

if (!function_exists('fgosMaterializedDealIds')) {

    /**
     * ID сделок Bitrix, уже учтённых в orders (синтетические оффлайн-заказы ИЛИ
     * реальные Yookassa-оплаты по привязанной заявке). Используется отчётами как
     * exclude-список для CRM-слоя, чтобы не задвоить выручку.
     *
     * @param Database $db
     * @return int[]
     */
    function fgosMaterializedDealIds(Database $db): array {
        $ids = [];

        // 1) Привязанные заявки с любой succeeded-оплатой (синтетика + Yookassa).
        $rows = $db->query(
            "SELECT DISTINCT ce.bitrix_lead_id AS deal_id
             FROM course_enrollments ce
             JOIN order_items oi ON oi.course_enrollment_id = ce.id
             JOIN orders o ON o.id = oi.order_id
             WHERE o.payment_status = 'succeeded' AND ce.bitrix_lead_id IS NOT NULL"
        );
        foreach ($rows as $row) {
            $ids[(int)$row['deal_id']] = true;
        }

        // 2) Любой синтетический заказ-маркер bitrix:<dealId> (на случай отвязки заявки).
        $marked = $db->query(
            "SELECT yookassa_payment_id
             FROM orders
             WHERE yookassa_payment_id LIKE 'bitrix:%'
               AND payment_status = 'succeeded'"
        );
        foreach ($marked as $row) {
            $ids[(int)substr($row['yookassa_payment_id'], 7)] = true;
        }

        return array_keys($ids);
    }
}
