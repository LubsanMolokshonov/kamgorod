-- 100: Расширение поля «должность» (position) на сертификатах до varchar(255).
-- Причина: при добавлении сертификата в корзину длинная должность (>100 символов)
-- падала с ошибкой SQLSTATE[22001] "Data too long for column 'position'"
-- (зафиксировано в error.log 29.05.2026) — сертификат не добавлялся в корзину.
-- Затрагивает webinar_certificates и publication_certificates (обе были varchar(100)).

ALTER TABLE webinar_certificates    MODIFY position VARCHAR(255) DEFAULT NULL;
ALTER TABLE publication_certificates MODIFY position VARCHAR(255) DEFAULT NULL;
