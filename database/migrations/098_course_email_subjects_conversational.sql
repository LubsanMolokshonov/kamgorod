-- 098: Сабжи курсовых писем — в личный/разговорный тон
-- Цель: уйти из вкладки «Промоакции» Gmail. Убираем «скидка», «только для вас»,
-- «не упустите», восклицательные знаки и promo-эмодзи.

UPDATE course_email_touchpoints SET email_subject = 'Заявка на курс «{course_title}»' WHERE code = 'course_enroll_welcome';
UPDATE course_email_touchpoints SET email_subject = '{user_name}, по вашей записи на курс' WHERE code = 'course_enroll_15min';
UPDATE course_email_touchpoints SET email_subject = 'Уточняем по записи на курс «{course_title}»' WHERE code = 'course_enroll_1h';
UPDATE course_email_touchpoints SET email_subject = '{user_name}, подготовили для вас условия по курсу' WHERE code = 'course_enroll_24h';
UPDATE course_email_touchpoints SET email_subject = 'Напомним по вашей записи на курс' WHERE code = 'course_enroll_2d';
UPDATE course_email_touchpoints SET email_subject = '{user_name}, последнее напоминание по курсу' WHERE code = 'course_enroll_3d';
