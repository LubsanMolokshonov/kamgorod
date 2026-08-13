-- Миграция 163: бэкфилл UTM-атрибуции курсовых заказов.
--
-- Проблема: ajax/create-course-payment.php (в отличие от create-payment.php) никогда
-- не проставлял заказу utm_*/visit_id, хотя заявка course_enrollments метки хранит.
-- Из-за этого ВСЯ выручка по курсам в РНП падала в канал «Другое»: по Директу были
-- заявки и расход, но 0 оплат — CPA/ROMI по курсам посчитать было нельзя.
-- Код починен (includes/course-order-attribution.php), здесь чиним историю.
--
-- Порядок источников — как в новой цепочке атрибуции:
--   1) utm заявки; 2) utm визита, с которого оставлена заявка.
-- Уже заполненные заказы не трогаем — миграция идемпотентна.

-- 1. Из самой заявки.
UPDATE orders o
JOIN order_items oi ON oi.order_id = o.id AND oi.course_enrollment_id IS NOT NULL
JOIN course_enrollments ce ON ce.id = oi.course_enrollment_id
SET o.utm_source   = ce.utm_source,
    o.utm_medium   = ce.utm_medium,
    o.utm_campaign = ce.utm_campaign,
    o.utm_content  = ce.utm_content,
    o.utm_term     = ce.utm_term
WHERE (o.utm_source IS NULL OR o.utm_source = '')
  AND ce.utm_source IS NOT NULL AND ce.utm_source <> '';

-- 2. Из визита, с которого оставлена заявка.
UPDATE orders o
JOIN order_items oi ON oi.order_id = o.id AND oi.course_enrollment_id IS NOT NULL
JOIN course_enrollments ce ON ce.id = oi.course_enrollment_id
JOIN visits v ON v.id = ce.visit_id
SET o.utm_source   = v.utm_source,
    o.utm_medium   = v.utm_medium,
    o.utm_campaign = v.utm_campaign,
    o.utm_content  = v.utm_content,
    o.utm_term     = v.utm_term
WHERE (o.utm_source IS NULL OR o.utm_source = '')
  AND v.utm_source IS NOT NULL AND v.utm_source <> '';

-- 3. visit_id заказа — для сквозной аналитики визит→оплата.
UPDATE orders o
JOIN order_items oi ON oi.order_id = o.id AND oi.course_enrollment_id IS NOT NULL
JOIN course_enrollments ce ON ce.id = oi.course_enrollment_id
SET o.visit_id = ce.visit_id
WHERE o.visit_id IS NULL AND ce.visit_id IS NOT NULL;
