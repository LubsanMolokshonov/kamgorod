<?php
/**
 * Серверная атрибуция UTM для заявок (записи на курс, консультации).
 *
 * Проблема: фронт ([appendTrackingData] в course-detail.php) шлёт utm_* только
 * если они есть в URL/sessionStorage на момент сабмита. У прямого/органического/
 * вернувшегося трафика их часто нет, и заявка падает в «без источника» — хотя
 * визит с источником лежит в таблице `visits`.
 *
 * Этот хелпер восстанавливает атрибуцию из визита по visit_id или ym_uid, а в
 * последнюю очередь — из cookie _fgos_utm_*. Возвращает массив utm-полей,
 * пригодный для слияния с данными заявки. visits.session_id хранится с префиксом
 * `ym_` (см. assets/js/visit-tracker.js) — учитываем это при поиске по ym_uid.
 */

if (!function_exists('resolveLeadUtm')) {
    /**
     * @param Database $dbObj  обёртка Database (queryOne/…)
     * @param array    $post   обычно $_POST (нужны visit_id, ym_uid)
     * @param array    $cookie обычно $_COOKIE
     * @return array{utm_source:?string,utm_medium:?string,utm_campaign:?string,utm_content:?string,utm_term:?string}
     *               Все поля null, если источник не найден.
     */
    function resolveLeadUtm(Database $dbObj, array $post, array $cookie): array
    {
        $empty = [
            'utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null,
            'utm_content' => null, 'utm_term' => null,
        ];

        $clip = static function ($v) {
            $v = mb_substr(trim((string)$v), 0, 255);
            return $v !== '' ? $v : null;
        };

        // 0. Явно пришедшие с формы utm_* — наивысший приоритет.
        if (!empty($post['utm_source'])) {
            return [
                'utm_source' => $clip($post['utm_source']),
                'utm_medium' => $clip($post['utm_medium'] ?? ''),
                'utm_campaign' => $clip($post['utm_campaign'] ?? ''),
                'utm_content' => $clip($post['utm_content'] ?? ''),
                'utm_term' => $clip($post['utm_term'] ?? ''),
            ];
        }

        $cols = 'utm_source, utm_medium, utm_campaign, utm_content, utm_term';

        // 1. По visit_id (самый надёжный — конкретный визит этой сессии).
        $visitId = intval($post['visit_id'] ?? 0);
        if ($visitId > 0) {
            $row = $dbObj->queryOne(
                "SELECT {$cols} FROM visits WHERE id = ? AND utm_source IS NOT NULL AND utm_source != ''",
                [$visitId]
            );
            if ($row && !empty($row['utm_source'])) {
                return $row;
            }
        }

        // 2. По ym_uid → visits.session_id = 'ym_<uid>'. Первый клик с UTM.
        $ymUid = trim((string)($post['ym_uid'] ?? ''));
        if ($ymUid !== '') {
            $row = $dbObj->queryOne(
                "SELECT {$cols} FROM visits
                 WHERE session_id = CONCAT('ym_', ?) AND utm_source IS NOT NULL AND utm_source != ''
                 ORDER BY started_at ASC LIMIT 1",
                [$ymUid]
            );
            if ($row && !empty($row['utm_source'])) {
                return $row;
            }
        }

        // 3. Cookie _fgos_utm_* (90 дней) — переживает закрытие браузера.
        if (!empty($cookie['_fgos_utm_source'])) {
            return [
                'utm_source' => $clip($cookie['_fgos_utm_source']),
                'utm_medium' => $clip($cookie['_fgos_utm_medium'] ?? ''),
                'utm_campaign' => $clip($cookie['_fgos_utm_campaign'] ?? ''),
                'utm_content' => $clip($cookie['_fgos_utm_content'] ?? ''),
                'utm_term' => $clip($cookie['_fgos_utm_term'] ?? ''),
            ];
        }

        return $empty;
    }
}
