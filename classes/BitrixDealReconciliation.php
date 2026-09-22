<?php
/**
 * Сверка Bitrix24 ↔ сайт по курсовым сделкам.
 *
 * Зачем: выручка по курсам живёт в двух местах — в `orders` (оплата на сайте через
 * Yookassa + синтетические заказы за оффлайн-оплаты) и в CRM (рассрочки/счета,
 * которые менеджер закрывает руками). Любой шов между ними ломается молча:
 * менеджер переносит сделку в чужую воронку, робот не проставляет источник,
 * заявка меняет статус и выпадает из выборки cron'а — и в отчётах (РНП, дашборд)
 * продажи нет, хотя в Bitrix сделка выиграна.
 *
 * Класс отвечает на два вопроса: «какие подтверждённые сделки не видит сайт» и
 * «не осталась ли синтетическая оплата после явного провала сделки в CRM».
 * Каждой WON-сделке присваивается состояние:
 *   - in_orders   — есть успешная оплата в orders (Yookassa или синтетический заказ);
 *   - crm_layer   — заказа нет, но сделка попадает в оффлайн-слой отчётов
 *                   (getFgosWonDeals) → в РНП её видно строкой «Курсы Другое»;
 *   - lost        — не видно нигде: ни заказа, ни попадания в оффлайн-слой.
 * Плюс обратная сверка: оплата на сайте есть, а сделка в CRM не закрыта
 * (deal_not_won) — воронка Bitrix занижена, менеджеру надо закрыть сделку.
 *
 * Используется:
 *   - cron/reconcile-bitrix-deals.php — ежедневная автосверка + алерт в Telegram;
 *   - admin/bitrix-recon/ — просмотр расхождений за период.
 *
 * @see Bitrix24Integration::getFgosWonDeals()
 * @see includes/offline-order-helper.php
 */

require_once __DIR__ . '/Bitrix24Integration.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/../includes/offline-order-helper.php';

class BitrixDealReconciliation
{
    /** Порог, ниже которого расхождение суммы по сделке считаем округлением. */
    private const AMOUNT_EPS = 1.0;

    private Database $db;
    private Bitrix24Integration $bitrix;
    /** @var array<int,array>|null Кэш выигранных сделок в пределах запроса */
    private ?array $wonCache = null;

    public function __construct(PDO $pdo, ?Bitrix24Integration $bitrix = null)
    {
        $this->db = new Database($pdo);
        $this->bitrix = $bitrix ?: new Bitrix24Integration();
    }

    /**
     * Сверка за период по дате закрытия сделки / дате оплаты заказа.
     *
     * @return array{
     *   available: bool,
     *   items: array<int,array>,
     *   missing: array<int,array>,
     *   not_won: array<int,array>,
     *   totals: array<string,float|int>
     * }
     */
    public function report(string $dateFrom, string $dateTo): array
    {
        $from = substr($dateFrom, 0, 10);
        $to   = substr($dateTo,   0, 10);

        $won = $this->wonDeals();
        if ($won === null) {
            return [
                'available' => false,
                'items'     => [],
                'missing'   => [],
                'not_won'   => [],
                'totals'    => $this->emptyTotals(),
            ];
        }

        $siteByDeal = $this->siteRecordsByDeal();
        $offlineIds = array_flip($this->offlineLayerDealIds());

        $items = [];
        foreach ($won as $deal) {
            if ($deal['closedate'] === '' || $deal['closedate'] < $from || $deal['closedate'] > $to) {
                continue;
            }
            $site = $siteByDeal[$deal['id']] ?? null;
            $paid = $site ? (float)$site['paid'] : 0.0;

            if ($paid > 0) {
                $state = 'in_orders';
            } elseif (isset($offlineIds[$deal['id']])) {
                $state = 'crm_layer';
            } else {
                $state = 'lost';
            }

            $items[] = [
                'deal_id'   => $deal['id'],
                'title'     => $deal['title'],
                'category'  => $deal['category'],
                'stage'     => $deal['stage'] ?? '',
                'source'    => $deal['source'] ?? '',
                'closedate' => $deal['closedate'],
                'created'   => $deal['created'],
                'amount'    => $deal['revenue'],
                'paid'      => $paid,
                'delta'     => $deal['revenue'] - $paid,
                'state'     => $state,
                'entity'    => $site['kind'] ?? null,   // enrollment | consultation
                'entity_id' => $site['entity_id'] ?? null,
                'status'    => $site['status'] ?? null,
                // Материализуем только заявки: у консультации нет пользователя,
                // а orders.user_id NOT NULL (см. offline-order-helper.php).
                'can_materialize' => $paid <= 0 && ($site['kind'] ?? null) === 'enrollment' && $deal['revenue'] > 0,
            ];
        }

        usort($items, fn($a, $b) => [$b['closedate'], $b['deal_id']] <=> [$a['closedate'], $a['deal_id']]);

        $missing = array_values(array_filter($items, fn($i) => $i['state'] !== 'in_orders'));
        $notWon  = $this->paidWithoutWonDeal($from, $to, $won);

        return [
            'available' => true,
            'items'     => $items,
            'missing'   => $missing,
            'not_won'   => $notWon,
            'totals'    => $this->totals($items, $notWon),
        ];
    }

    /**
     * Материализовать оффлайн-оплаты: создать синтетические заказы за WON-сделки,
     * по которым на сайте нет оплаты, и перевести заявку в статус paid.
     *
     * Идемпотентно (ключ — yookassa_payment_id='bitrix:<dealId>'), поэтому
     * безопасно гонять хоть каждый час.
     *
     * @param array $items Строки из report()['missing']
     * @return array<int,array{deal_id:int,enrollment_id:int,order_id:int,amount:float}>
     */
    public function materialize(array $items): array
    {
        $created = [];
        foreach ($items as $item) {
            if (empty($item['can_materialize'])) {
                continue;
            }
            $deal = [
                'ID'          => $item['deal_id'],
                'OPPORTUNITY' => $item['amount'],
                'CLOSEDATE'   => $item['closedate'],
            ];
            $orderId = materializeOfflineCourseOrder($this->db, (int)$item['entity_id'], $deal);
            if (!$orderId) {
                continue;
            }
            if (($item['status'] ?? null) !== 'paid') {
                $this->db->update(
                    'course_enrollments',
                    ['status' => 'paid', 'bitrix_stage_updated_at' => date('Y-m-d H:i:s')],
                    'id = ?',
                    [(int)$item['entity_id']]
                );
            }
            $created[] = [
                'deal_id'       => (int)$item['deal_id'],
                'enrollment_id' => (int)$item['entity_id'],
                'order_id'      => (int)$orderId,
                'amount'        => (float)$item['amount'],
            ];
        }
        return $created;
    }

    /**
     * Проверить уже созданные синтетические оплаты по текущему этапу Bitrix.
     *
     * Если сделка явно провалена, заказ не удаляется: он переводится в failed,
     * paid_at очищается, а заявка отменяется только при отсутствии другой успешной
     * оплаты. Так сохраняется полный аудит и заказ можно восстановить, если сделка
     * позднее снова перейдёт в подтверждённый оплаченный этап.
     *
     * C4:WON сам по себе не подтверждает оплату, но исторические заказы на этом
     * этапе массово не аннулируются без сверки с 1С. Новые заказы по C4:WON больше
     * не создаются; автоматически снимаются только сделки с семантикой провала F.
     *
     * @return array{
     *   checked:int,
     *   unavailable:int,
     *   invalidated:array<int,array{deal_id:int,order_id:int,enrollment_id:int,amount:float,stage:string}>
     * }
     */
    public function reconcileSyntheticOrders(int $limit = 500, ?int $staleHours = null, bool $apply = true): array
    {
        $limit = max(1, min(2000, $limit));
        $params = [];
        $staleSql = '';
        if ($staleHours !== null) {
            $threshold = date('Y-m-d H:i:s', strtotime('-' . max(1, $staleHours) . ' hours'));
            $staleSql = ' AND (ce.bitrix_stage_updated_at IS NULL OR ce.bitrix_stage_updated_at <= ?)';
            $params[] = $threshold;
        }

        $rows = $this->db->query(
            "SELECT o.id AS order_id, o.yookassa_payment_id, o.final_amount,
                    oi.course_enrollment_id AS enrollment_id,
                    ce.status AS enrollment_status
             FROM orders o
             LEFT JOIN order_items oi ON oi.order_id = o.id AND oi.course_enrollment_id IS NOT NULL
             LEFT JOIN course_enrollments ce ON ce.id = oi.course_enrollment_id
             WHERE o.payment_status = 'succeeded'
               AND o.yookassa_payment_id LIKE 'bitrix:%'{$staleSql}
             ORDER BY COALESCE(ce.bitrix_stage_updated_at, '1970-01-01') ASC, o.id ASC
             LIMIT {$limit}",
            $params
        );

        $checked = 0;
        $unavailable = 0;
        $invalidated = [];
        $seenOrders = [];

        foreach ($rows as $row) {
            $orderId = (int)$row['order_id'];
            if (isset($seenOrders[$orderId])) {
                continue;
            }
            $seenOrders[$orderId] = true;

            $marker = (string)$row['yookassa_payment_id'];
            $dealId = (int)substr($marker, 7);
            if ($dealId <= 0) {
                continue;
            }

            $deal = $this->bitrix->getDeal((string)$dealId);
            if (!$deal) {
                $unavailable++;
                continue;
            }
            $checked++;

            $stageId = (string)($deal['STAGE_ID'] ?? '');
            $enrollmentId = (int)($row['enrollment_id'] ?? 0);
            $isFailed = Bitrix24Integration::isFgosDealFailed($deal);

            if (!$apply) {
                if ($isFailed) {
                    $invalidated[] = [
                        'deal_id'       => $dealId,
                        'order_id'      => $orderId,
                        'enrollment_id' => $enrollmentId,
                        'amount'        => (float)$row['final_amount'],
                        'stage'         => $stageId,
                    ];
                }
                continue;
            }

            $this->db->beginTransaction();
            try {
                if ($enrollmentId > 0) {
                    $this->db->update(
                        'course_enrollments',
                        ['bitrix_stage' => $stageId, 'bitrix_stage_updated_at' => date('Y-m-d H:i:s')],
                        'id = ?',
                        [$enrollmentId]
                    );
                }

                if (!$isFailed) {
                    $this->db->commit();
                    continue;
                }

                $changed = $this->db->update(
                    'orders',
                    ['payment_status' => 'failed', 'paid_at' => null],
                    "id = ? AND payment_status = 'succeeded'",
                    [$orderId]
                );

                if ($changed > 0 && $enrollmentId > 0) {
                    $otherPaid = $this->db->queryOne(
                        "SELECT 1
                         FROM order_items oi
                         JOIN orders o ON o.id = oi.order_id
                         WHERE oi.course_enrollment_id = ?
                           AND o.id <> ?
                           AND o.payment_status = 'succeeded'
                         LIMIT 1",
                        [$enrollmentId, $orderId]
                    );
                    if (!$otherPaid && ($row['enrollment_status'] ?? '') === 'paid') {
                        $this->db->update(
                            'course_enrollments',
                            ['status' => 'cancelled'],
                            'id = ?',
                            [$enrollmentId]
                        );
                    }
                }

                $this->db->commit();

                if ($changed > 0) {
                    $invalidated[] = [
                        'deal_id'       => $dealId,
                        'order_id'      => $orderId,
                        'enrollment_id' => $enrollmentId,
                        'amount'        => (float)$row['final_amount'],
                        'stage'         => $stageId,
                    ];
                }
            } catch (Throwable $e) {
                $this->db->rollback();
                throw $e;
            }
        }

        return [
            'checked'     => $checked,
            'unavailable' => $unavailable,
            'invalidated' => $invalidated,
        ];
    }

    // ==================== внутреннее ====================

    /** @return array<int,array>|null */
    private function wonDeals(): ?array
    {
        if ($this->wonCache === null) {
            $this->wonCache = $this->bitrix->getFgosWonDeals();
        }
        return $this->wonCache;
    }

    /**
     * Записи сайта, привязанные к сделкам: заявки на курс и заявки на консультацию.
     * paid — сумма успешных оплат по заявке (0 у консультаций: заказов у них не бывает).
     *
     * @return array<int,array{kind:string,entity_id:int,status:string,paid:float,created:string}>
     */
    private function siteRecordsByDeal(): array
    {
        $map = [];

        $rows = $this->db->query(
            "SELECT ce.bitrix_lead_id AS deal_id, ce.id, ce.status, ce.created_at,
                    COALESCE(SUM(CASE WHEN o.payment_status = 'succeeded' THEN oi.price END), 0) AS paid
             FROM course_enrollments ce
             LEFT JOIN order_items oi ON oi.course_enrollment_id = ce.id
             LEFT JOIN orders o ON o.id = oi.order_id
             WHERE ce.bitrix_lead_id IS NOT NULL
             GROUP BY ce.id"
        );
        foreach ($rows as $r) {
            $map[(int)$r['deal_id']] = [
                'kind'      => 'enrollment',
                'entity_id' => (int)$r['id'],
                'status'    => (string)$r['status'],
                'paid'      => (float)$r['paid'],
                'created'   => substr((string)$r['created_at'], 0, 10),
            ];
        }

        $rows = $this->db->query(
            "SELECT bitrix_lead_id AS deal_id, id, status, created_at
             FROM course_consultations WHERE bitrix_lead_id IS NOT NULL"
        );
        foreach ($rows as $r) {
            $dealId = (int)$r['deal_id'];
            if (isset($map[$dealId])) {
                continue; // заявка на курс приоритетнее: у неё есть деньги
            }
            $map[$dealId] = [
                'kind'      => 'consultation',
                'entity_id' => (int)$r['id'],
                'status'    => (string)$r['status'],
                'paid'      => 0.0,
                'created'   => substr((string)$r['created_at'], 0, 10),
            ];
        }

        return $map;
    }

    /**
     * ID сделок, которые видит оффлайн-слой отчётов: всё, что вернул
     * getFgosWonDeals() минус уже учтённое в orders. Совпадает с логикой
     * RNPAnalytics::fetchOfflineCrmSplit — по ней и определяем состояние crm_layer.
     *
     * @return int[]
     */
    private function offlineLayerDealIds(): array
    {
        $won = $this->wonDeals() ?: [];
        $exclude = array_flip(fgosMaterializedDealIds($this->db));
        $ids = [];
        foreach ($won as $deal) {
            if (!isset($exclude[$deal['id']])) {
                $ids[] = $deal['id'];
            }
        }
        return $ids;
    }

    /**
     * Обратная сверка: оплата на сайте есть, а сделка в CRM не выиграна.
     * Не теряет денег в отчётах, но занижает воронку Bitrix — менеджеру
     * нужно закрыть сделку, иначе внутренняя отчётность CRM расходится с сайтом.
     *
     * @param array $won Выигранные сделки (из getFgosWonDeals)
     * @return array<int,array>
     */
    private function paidWithoutWonDeal(string $from, string $to, array $won): array
    {
        $wonIds = [];
        foreach ($won as $d) {
            $wonIds[$d['id']] = true;
        }

        $rows = $this->db->query(
            "SELECT ce.id, ce.bitrix_lead_id, ce.status, ce.bitrix_stage,
                    o.id AS order_id, DATE(o.paid_at) AS paid_date, SUM(oi.price) AS amount
             FROM course_enrollments ce
             JOIN order_items oi ON oi.course_enrollment_id = ce.id
             JOIN orders o ON o.id = oi.order_id
             WHERE o.payment_status = 'succeeded'
               AND o.yookassa_payment_id NOT LIKE 'bitrix:%'
               AND DATE(o.paid_at) BETWEEN ? AND ?
             GROUP BY o.id, ce.id",
            [$from, $to]
        );

        $out = [];
        foreach ($rows as $r) {
            $dealId = (int)$r['bitrix_lead_id'];
            if ($dealId > 0 && isset($wonIds[$dealId])) {
                continue;
            }
            $out[] = [
                'enrollment_id' => (int)$r['id'],
                'deal_id'       => $dealId ?: null,
                'order_id'      => (int)$r['order_id'],
                'paid_date'     => (string)$r['paid_date'],
                'amount'        => (float)$r['amount'],
                'status'        => (string)$r['status'],
                'stage'         => (string)($r['bitrix_stage'] ?? ''),
            ];
        }
        return $out;
    }

    private function totals(array $items, array $notWon): array
    {
        $t = $this->emptyTotals();
        foreach ($items as $i) {
            $t['won_count']++;
            $t['won_amount'] += $i['amount'];
            $t[$i['state'] . '_count']++;
            $t[$i['state'] . '_amount'] += $i['amount'];
            if ($i['state'] === 'in_orders' && abs($i['delta']) > self::AMOUNT_EPS) {
                $t['amount_mismatch_count']++;
                $t['amount_mismatch_delta'] += $i['delta'];
            }
        }
        $t['not_won_count']  = count($notWon);
        $t['not_won_amount'] = array_sum(array_column($notWon, 'amount'));
        return $t;
    }

    private function emptyTotals(): array
    {
        return [
            'won_count' => 0, 'won_amount' => 0.0,
            'in_orders_count' => 0, 'in_orders_amount' => 0.0,
            'crm_layer_count' => 0, 'crm_layer_amount' => 0.0,
            'lost_count' => 0, 'lost_amount' => 0.0,
            'amount_mismatch_count' => 0, 'amount_mismatch_delta' => 0.0,
            'not_won_count' => 0, 'not_won_amount' => 0.0,
        ];
    }
}
