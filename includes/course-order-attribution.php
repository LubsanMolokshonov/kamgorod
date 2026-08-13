<?php
/**
 * UTM-атрибуция курсовых заказов.
 *
 * Проблема (обнаружена 13.08.2026): create-payment.php (портал) после создания
 * заказа проставлял ему utm-метки и visit_id, а create-course-payment.php — нет.
 * В результате ВСЕ курсовые заказы уходили в БД с utm_source = NULL, и в РНП
 * вся выручка по курсам падала в канал «Другое»: по Директу висели заявки,
 * но 0 оплат, CPA и ROMI по курсам посчитать было нельзя.
 *
 * Здесь — та же цепочка first-click атрибуции, что в create-payment.php, но
 * отталкивается от конкретной заявки на курс:
 *   1) utm_* самой заявки (course_enrollments заполняет их при создании);
 *   2) визит, с которого заявка оставлена (course_enrollments.visit_id);
 *   3) первый визит пользователя с UTM (первый клик);
 *   4) cookie _fgos_utm_* (90 дней, ставит visit-tracker.js / magic-auth.php).
 * Синтетический источник email/trigger навешивается уже в вызывающем коде,
 * если после всей цепочки источник так и не нашёлся.
 */

if (!function_exists('applyCourseOrderAttribution')) {

    /**
     * Проставить заказу UTM-метки и visit_id, унаследованные от заявки на курс.
     *
     * @param PDO $pdo
     * @param int $orderId      Заказ, созданный под заявку
     * @param int $enrollmentId Заявка course_enrollments
     * @return bool true, если удалось определить utm_source
     */
    function applyCourseOrderAttribution(PDO $pdo, int $orderId, int $enrollmentId): bool
    {
        $db = new Database($pdo);

        // getEnrollmentById() в Course.php не тянет utm/visit — читаем поля отдельно.
        $enrollment = $db->queryOne(
            "SELECT user_id, visit_id, utm_source, utm_medium, utm_campaign, utm_content, utm_term
             FROM course_enrollments WHERE id = ?",
            [$enrollmentId]
        );
        if (!$enrollment) {
            return false;
        }

        $utm = null;

        // 1. UTM самой заявки.
        if (!empty($enrollment['utm_source'])) {
            $utm = [
                'utm_source'   => $enrollment['utm_source'],
                'utm_medium'   => $enrollment['utm_medium']   ?? null,
                'utm_campaign' => $enrollment['utm_campaign'] ?? null,
                'utm_content'  => $enrollment['utm_content']  ?? null,
                'utm_term'     => $enrollment['utm_term']     ?? null,
            ];
        }

        // 2. Визит, с которого оставлена заявка.
        $visitId = !empty($enrollment['visit_id']) ? (int)$enrollment['visit_id'] : null;
        if (!$utm && $visitId) {
            $utm = $db->queryOne(
                "SELECT utm_source, utm_medium, utm_campaign, utm_content, utm_term
                 FROM visits WHERE id = ? AND utm_source IS NOT NULL AND utm_source <> ''",
                [$visitId]
            ) ?: null;
        }

        // 3. Первый визит пользователя с UTM — атрибуция первого клика.
        $userId = !empty($enrollment['user_id']) ? (int)$enrollment['user_id'] : null;
        if (!$utm && $userId) {
            $utm = $db->queryOne(
                "SELECT utm_source, utm_medium, utm_campaign, utm_content, utm_term
                 FROM visits WHERE user_id = ? AND utm_source IS NOT NULL AND utm_source <> ''
                 ORDER BY started_at ASC LIMIT 1",
                [$userId]
            ) ?: null;
        }

        // 4. Cookie первого клика — переживает закрытие браузера и переход из почты.
        if (!$utm && !empty($_COOKIE['_fgos_utm_source'])) {
            $utm = [
                'utm_source'   => mb_substr(trim((string)$_COOKIE['_fgos_utm_source']), 0, 255),
                'utm_medium'   => mb_substr(trim((string)($_COOKIE['_fgos_utm_medium'] ?? '')), 0, 255) ?: null,
                'utm_campaign' => mb_substr(trim((string)($_COOKIE['_fgos_utm_campaign'] ?? '')), 0, 255) ?: null,
                'utm_content'  => mb_substr(trim((string)($_COOKIE['_fgos_utm_content'] ?? '')), 0, 255) ?: null,
                'utm_term'     => mb_substr(trim((string)($_COOKIE['_fgos_utm_term'] ?? '')), 0, 255) ?: null,
            ];
        }

        $update = [];
        if ($utm && !empty($utm['utm_source'])) {
            foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $f) {
                $update[$f] = isset($utm[$f]) && $utm[$f] !== '' ? mb_substr((string)$utm[$f], 0, 255) : null;
            }
        }
        if ($visitId) {
            $update['visit_id'] = $visitId;
        }

        if (!$update) {
            return false;
        }

        try {
            $db->update('orders', $update, 'id = ?', [$orderId]);
        } catch (Exception $e) {
            // Атрибуция не должна ронять оплату.
            error_log("[course-attribution] заказ #{$orderId}: " . $e->getMessage());
            return false;
        }

        return !empty($update['utm_source']);
    }
}
