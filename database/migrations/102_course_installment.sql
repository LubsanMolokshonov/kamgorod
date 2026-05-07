-- Миграция 102: Поддержка рассрочки 0% на 12 месяцев для курсов
-- Дата: 2026-05-07
-- Цель: дать пользователю выбор в личном кабинете между онлайн-оплатой и заявкой на рассрочку.
-- Реальной автоматической рассрочки нет — оформляется менеджером вручную через банк-партнёр.

-- 1. Расширяем enum статусов: добавляем installment_requested
ALTER TABLE course_enrollments
  MODIFY COLUMN status ENUM(
    'new',
    'contacted',
    'enrolled',
    'paid',
    'installment_requested',
    'cancelled'
  ) DEFAULT 'new';

-- 2. Способ оплаты, выбранный пользователем в кабинете, и метаданные рассрочки
ALTER TABLE course_enrollments
  ADD COLUMN payment_method ENUM('online','installment') NULL AFTER status,
  ADD COLUMN installment_requested_at DATETIME NULL AFTER payment_method,
  ADD COLUMN installment_monthly_amount DECIMAL(10,2) NULL AFTER installment_requested_at,
  ADD COLUMN bitrix_installment_deal_id VARCHAR(32) NULL AFTER bitrix_stage,
  ADD INDEX idx_ce_payment_method (payment_method);
