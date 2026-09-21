-- 181: Защита от повторного зачисления одного платежа токенов.
-- NULL payment_id может повторяться в MySQL, поэтому обычные списания
-- и бонусы без платёжного ID остаются без ограничений.

ALTER TABLE token_transactions
    ADD UNIQUE KEY uk_token_transactions_payment_reason (payment_id, reason);
