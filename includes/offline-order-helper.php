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

if (!function_exists('materializeOfflineCourseOrder')) {

    /**
     * Создать синтетический заказ за выигранную в CRM оффлайн-сделку по курсу.
     *
     * @param Database $db          Обёртка PDO
     * @param int      $enrollmentId ID записи в course_enrollments
     * @param array    $deal        Сделка из Bitrix (crm.deal.get): ID, OPPORTUNITY, CLOSEDATE
     * @return int|null ID заказа (новый или уже существующий), либо null если не создан
     */
    function materializeOfflineCourseOrder(Database $db, int $enrollmentId, array $deal): ?int {
        $dealId = (int)($deal['ID'] ?? 0);
        if ($dealId <= 0) {
            return null;
        }

        $marker = 'bitrix:' . $dealId;

        // Идемпотентность: заказ за эту сделку уже материализован?
        $existing = $db->queryOne(
            "SELECT id FROM orders WHERE yookassa_payment_id = ? LIMIT 1",
            [$marker]
        );
        if ($existing) {
            return (int)$existing['id'];
        }

        $enr = $db->queryOne(
            "SELECT id, user_id, visit_id, utm_source, utm_medium, utm_campaign, utm_content, utm_term
             FROM course_enrollments WHERE id = ?",
            [$enrollmentId]
        );
        if (!$enr) {
            return null;
        }

        // orders.user_id NOT NULL — без пользователя заказ не создать.
        if (empty($enr['user_id'])) {
            error_log("[offline-order] enrollment #{$enrollmentId} без user_id — пропуск сделки {$dealId}");
            return null;
        }

        // Уже есть реальная (Yookassa) оплата по этой заявке — не дублируем выручку.
        $hasOrder = $db->queryOne(
            "SELECT 1 FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE oi.course_enrollment_id = ? AND o.payment_status = 'succeeded'
             LIMIT 1",
            [$enrollmentId]
        );
        if ($hasOrder) {
            return null;
        }

        $amount = (float)($deal['OPPORTUNITY'] ?? 0);
        if ($amount <= 0) {
            error_log("[offline-order] сделка {$dealId} с нулевой суммой — пропуск (enrollment #{$enrollmentId})");
            return null;
        }

        $closeRaw = (string)($deal['CLOSEDATE'] ?? '');
        $ts       = $closeRaw !== '' ? strtotime($closeRaw) : false;
        $paidAt   = $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
        $now      = date('Y-m-d H:i:s');

        $orderId = $db->insert('orders', [
            'user_id'             => (int)$enr['user_id'],
            'order_number'        => 'BX-' . $dealId,
            'total_amount'        => $amount,
            'final_amount'        => $amount,
            'payment_status'      => 'succeeded',
            'paid_at'             => $paidAt,
            'metrika_sent_at'     => $now,
            'yookassa_payment_id' => $marker,
            'utm_source'          => $enr['utm_source'] ?: null,
            'utm_medium'          => $enr['utm_medium'] ?: null,
            'utm_campaign'        => $enr['utm_campaign'] ?: null,
            'utm_content'         => $enr['utm_content'] ?: null,
            'utm_term'            => $enr['utm_term'] ?: null,
            'visit_id'            => $enr['visit_id'] ?: null,
        ]);
        if (!$orderId) {
            return null;
        }

        $db->insert('order_items', [
            'order_id'                => (int)$orderId,
            'course_enrollment_id'    => (int)$enr['id'],
            'price'                   => $amount,
            'covered_by_subscription' => 0,
        ]);

        return (int)$orderId;
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
            "SELECT yookassa_payment_id FROM orders WHERE yookassa_payment_id LIKE 'bitrix:%'"
        );
        foreach ($marked as $row) {
            $ids[(int)substr($row['yookassa_payment_id'], 7)] = true;
        }

        return array_keys($ids);
    }
}
