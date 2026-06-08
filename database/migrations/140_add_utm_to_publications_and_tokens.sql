-- UTM-атрибуция канала продаж для публикаций и материалов ФОП (токенов).
--
-- Проблема: в РНП (admin/rnp) продажи публикаций массово падали в канал «Другое»,
-- т.к. у их заказов не было utm_source (для публикаций не было fallback-источника,
-- в отличие от конкурсов/олимпиад). Покупки токенов ФОП вообще не попадали в РНП —
-- они идут мимо orders, а в token_transactions не было ни utm, ни суммы оплаты.
--
-- Решение:
--   1) publication_certificates.utm_* — фиксируем источник на момент создания
--      сертификата (см. PublicationCertificate::create), используется как fallback
--      в create-payment.php.
--   2) token_transactions.amount_paid + utm_* — сумма и источник покупки токенов,
--      чтобы РНП мог показать выручку ФОП с разбивкой по каналу.

ALTER TABLE publication_certificates
    ADD COLUMN utm_source   VARCHAR(255) NULL AFTER price,
    ADD COLUMN utm_medium   VARCHAR(255) NULL AFTER utm_source,
    ADD COLUMN utm_campaign VARCHAR(255) NULL AFTER utm_medium,
    ADD COLUMN utm_content  VARCHAR(255) NULL AFTER utm_campaign,
    ADD COLUMN utm_term     VARCHAR(255) NULL AFTER utm_content;

ALTER TABLE token_transactions
    ADD COLUMN amount_paid  DECIMAL(10,2) NULL AFTER package_id,
    ADD COLUMN utm_source   VARCHAR(255) NULL AFTER amount_paid,
    ADD COLUMN utm_medium   VARCHAR(255) NULL AFTER utm_source,
    ADD COLUMN utm_campaign VARCHAR(255) NULL AFTER utm_medium,
    ADD COLUMN utm_content  VARCHAR(255) NULL AFTER utm_campaign,
    ADD COLUMN utm_term     VARCHAR(255) NULL AFTER utm_content;
