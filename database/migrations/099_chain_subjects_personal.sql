-- 099: Сабжи цепочек — в личный/нейтральный тон.
-- Цель: уйти из вкладки «Промоакции» Gmail для писем-напоминаний.
-- Убираем «последний шанс», «специально для вас», восклицательные знаки.
-- Дополняет миграцию 098 (курсы), здесь — конкурсы / олимпиады / автовебинары.

UPDATE email_journey_touchpoints
   SET email_subject = '{user_name}, нужен ли вам диплом конкурса?'
 WHERE code = 'touch_7d';

UPDATE autowebinar_email_touchpoints
   SET email_subject = 'Тест по видеолекции «{webinar_title}» так и не пройден'
 WHERE code = 'aw_quiz_7d';

UPDATE olympiad_email_touchpoints
   SET email_subject = 'Диплом за олимпиаду «{olympiad_title}» так и не оформлен'
 WHERE code = 'olymp_pay_7d';
