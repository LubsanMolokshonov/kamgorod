-- Migration: добавить 5-е касание (14 дней) в email-цепочку олимпиад
-- Created: 2026-04-23
-- Письмо отправляется через 14 дней после заказа диплома, если не оплачен.
-- В момент отправки пользователю выписывается персональная скидка 15% на 48 часов
-- через email_campaign_discounts (campaign_code='olymp_final_14d'), которая
-- автоматически применяется в корзине (cart.php, create-payment.php).

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET CHARACTER SET utf8mb4;

INSERT INTO olympiad_email_touchpoints (code, name, description, delay_hours, email_subject, email_template, display_order) VALUES
('olymp_pay_14d', 'Финальная скидка (14 дней)', 'Пятое касание через 14 дней. Персональная скидка 15% на 48 часов через EmailCampaignDiscount.', 336, 'Скидка 15% на ваш диплом — действует 48 часов', 'olympiad_pay_14d', 5);
