# Инструкция по развертыванию интеграции ЮКассы

## 🎯 Чеклист перед развертыванием

- [x] Все файлы созданы локально
- [x] Боевые ключи ЮКассы добавлены в .env
- [ ] SMTP настроен в .env
- [ ] Файлы загружены на сервер
- [ ] Директории созданы на сервере
- [ ] Webhook настроен в ЮКассе
- [ ] Проведено тестирование

## 📤 Шаг 1: Загрузка файлов на сервер

### Вариант A: Через SFTP/SCP (рекомендуется)

Используйте FileZilla, Cyberduck или командную строку:

```bash
# Подключение к серверу
Server: 141.105.69.45
Username: root
Password: см. 1Password (запись «fgos.pro · root@141.105.69.45»)
Port: 22

# Загрузите следующие файлы:
- classes/Order.php
- includes/email-helper.php
- ajax/create-payment.php (заменить существующий)
- pages/payment-success.php
- pages/payment-failure.php
- api/webhook/yookassa.php
- api/check-payment.php
- .env (заменить существующий, НО СНАЧАЛА НАСТРОЙТЕ SMTP!)
```

### Вариант B: Через командную строку (SCP)

```bash
# Из директории проекта на вашем Mac
cd "/Users/LubsanMoloksonov1/Desktop/Педпортал каменный город"

# Загрузите файлы (замените /path/to/project на реальный путь на сервере)
scp classes/Order.php root@141.105.69.45:/path/to/project/classes/
scp includes/email-helper.php root@141.105.69.45:/path/to/project/includes/
scp ajax/create-payment.php root@141.105.69.45:/path/to/project/ajax/
scp pages/payment-success.php root@141.105.69.45:/path/to/project/pages/
scp pages/payment-failure.php root@141.105.69.45:/path/to/project/pages/
scp -r api root@141.105.69.45:/path/to/project/

# .env загружайте ПОСЛЕ настройки SMTP!
```

## 🔧 Шаг 2: Настройка на сервере

Подключитесь к серверу:

```bash
ssh root@141.105.69.45
# Пароль: см. 1Password
```

Выполните команды:

```bash
# 1. Перейдите в директорию проекта
cd /var/www/html  # или другой путь к проекту

# 2. Создайте бэкап (на всякий случай)
tar -czf backup_$(date +%Y%m%d_%H%M%S).tar.gz ajax/create-payment.php .env

# 3. Создайте необходимые директории
mkdir -p api/webhook
mkdir -p logs

# 4. Установите права доступа
chmod 755 api
chmod 755 api/webhook
chmod 644 api/webhook/yookassa.php
chmod 644 api/check-payment.php
chmod 644 ajax/create-payment.php
chmod 644 classes/Order.php
chmod 644 includes/email-helper.php
chmod 644 pages/payment-success.php
chmod 644 pages/payment-failure.php
chmod 777 logs
chmod 600 .env

# 5. Проверьте, что файлы на месте
ls -la classes/Order.php
ls -la api/webhook/yookassa.php
ls -la logs/
```

## 📧 Шаг 3: КРИТИЧНО - Настройка SMTP

Отредактируйте `.env` на сервере:

```bash
nano .env
```

Обновите секцию SMTP:

```bash
# Email SMTP Configuration
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USERNAME=ваш_реальный_email@gmail.com
SMTP_PASSWORD=ваш_app_password_здесь
SMTP_FROM_EMAIL=noreply@ваш-домен.ru
SMTP_FROM_NAME=Каменный город
```

### Получение App Password для Gmail:

1. Перейдите: https://myaccount.google.com/apppasswords
2. Войдите в аккаунт Gmail
3. Создайте новый App Password
4. Скопируйте 16-значный пароль (без пробелов)
5. Используйте его в SMTP_PASSWORD

**Сохраните** (Ctrl+O, Enter, Ctrl+X)

## 🔗 Шаг 4: Настройка webhook в ЮКассе

1. Откройте: https://yookassa.ru/my
2. Войдите с shopId: **1253458**
3. Перейдите: **Настройки → Уведомления → HTTP-уведомления**
4. Укажите URL: `https://141.105.69.45/api/webhook/yookassa.php`
5. Выберите события:
   - ✅ `payment.succeeded`
   - ✅ `payment.canceled`
   - ✅ `payment.waiting_for_capture`
   - ✅ `refund.succeeded`
6. **Сохраните**

## 🧪 Шаг 5: Тестирование

### Тест 1: Проверка доступности webhook

```bash
# На сервере или с локального компьютера
curl -X POST https://141.105.69.45/api/webhook/yookassa.php

# Ожидаемый результат: 403 Forbidden (это нормально, IP не доверенный)
# Плохой результат: 404 Not Found (значит файл не найден)
```

### Тест 2: Проверка API check-payment

```bash
curl https://141.105.69.45/api/check-payment.php?order_number=test

# Ожидаемый результат: {"success":false,"error":"Order not found"}
# Это означает, что API работает
```

### Тест 3: Тестовый платеж (МИНИМАЛЬНАЯ СУММА!)

1. Откройте сайт: https://141.105.69.45
2. Зарегистрируйтесь на конкурс (выберите самый дешевый)
3. Добавьте в корзину (лучше 3 шт для акции 2+1)
4. Перейдите к оплате
5. Нажмите "Оплатить"

**Ожидаемое поведение:**
- Создается заказ в БД
- Перенаправление на страницу ЮКассы
- После оплаты возврат на payment-success.php
- Webhook обрабатывает платеж
- Email приходит на почту
- Статус регистрации = 'paid'

### Тест 4: Проверка логов

```bash
# На сервере
tail -f logs/payment.log    # Должна быть запись о создании платежа
tail -f logs/webhook.log    # Должна быть запись о получении webhook
tail -f logs/email.log      # Должна быть запись об отправке email
tail -f logs/error.log      # Не должно быть ошибок
```

### Тест 5: Проверка БД

```bash
# На сервере, подключитесь к MySQL
mysql -u pedagogy_user -p pedagogy_platform

# Выполните запросы
SELECT * FROM orders ORDER BY created_at DESC LIMIT 1;
SELECT * FROM order_items WHERE order_id = (SELECT id FROM orders ORDER BY created_at DESC LIMIT 1);
SELECT * FROM registrations WHERE id IN (SELECT registration_id FROM order_items WHERE order_id = (SELECT id FROM orders ORDER BY created_at DESC LIMIT 1));

# Проверьте:
# - orders.payment_status = 'succeeded'
# - registrations.status = 'paid'
```

## 🔍 Мониторинг

### Первые 24 часа:

```bash
# Проверяйте логи каждые 2-3 часа
tail -50 logs/payment.log
tail -50 logs/webhook.log
tail -50 logs/error.log
```

### Метрики для отслеживания:

```sql
-- Статистика по платежам
SELECT payment_status, COUNT(*) as count, SUM(final_amount) as total
FROM orders
GROUP BY payment_status;

-- Конверсия платежей (за сегодня)
SELECT
    COUNT(*) as total_orders,
    SUM(CASE WHEN payment_status = 'succeeded' THEN 1 ELSE 0 END) as succeeded,
    SUM(CASE WHEN payment_status = 'failed' THEN 1 ELSE 0 END) as failed,
    SUM(CASE WHEN payment_status = 'pending' THEN 1 ELSE 0 END) as pending
FROM orders
WHERE DATE(created_at) = CURDATE();
```

## ⚠️ Troubleshooting

### Проблема: Webhook не приходит

**Диагностика:**
```bash
# 1. Проверьте, что файл существует
ls -la api/webhook/yookassa.php

# 2. Проверьте права доступа
chmod 644 api/webhook/yookassa.php

# 3. Проверьте логи веб-сервера
tail -50 /var/log/apache2/error.log  # или /var/log/nginx/error.log

# 4. Проверьте firewall
iptables -L -n | grep 443
```

**Решение:**
- Убедитесь, что webhook URL правильно настроен в ЮКассе
- Проверьте, что SSL сертификат валидный
- Проверьте, что порт 443 открыт

### Проблема: Email не отправляется

**Диагностика:**
```bash
# Проверьте лог
tail -50 logs/email.log

# Проверьте настройки SMTP в .env
cat .env | grep SMTP
```

**Решение:**
- Для Gmail: используйте App Password (не обычный пароль)
- Проверьте, что 2FA включена в Gmail (требуется для App Password)
- Попробуйте другой SMTP сервис (Mailgun, SendGrid)

### Проблема: Платеж не создается

**Диагностика:**
```bash
# Проверьте лог
tail -50 logs/payment.log
tail -50 logs/error.log

# Проверьте ключи ЮКассы
cat .env | grep YOOKASSA
```

**Решение:**
- Проверьте правильность YOOKASSA_SHOP_ID и YOOKASSA_SECRET_KEY
- Проверьте, что YOOKASSA_MODE=production
- Убедитесь, что в ЮКассе включен API доступ

### Проблема: Auto-login не работает

**Диагностика:**
```bash
# Проверьте cookies в браузере
# Должен быть cookie 'session_token'
```

**Решение:**
- Убедитесь, что SITE_URL в .env правильный (https://141.105.69.45)
- Проверьте, что SSL включен (cookie secure flag требует HTTPS)
- Очистите cookies и попробуйте снова

## 🚨 Критичные моменты

1. **HTTPS обязателен** - ЮКасса не работает через HTTP
2. **Webhook MUST return 200** - иначе ЮКасса будет повторять запросы
3. **Idempotency важна** - защита от дублирующих webhook
4. **Email не критичен** - если не настроен, платежи всё равно работают
5. **Логи - ваш друг** - всегда проверяйте логи при проблемах

## 📞 Поддержка ЮКассы

Если возникли проблемы с ЮКассой:
- Email: support@yookassa.ru
- Личный кабинет: https://yookassa.ru/my
- Документация: https://yookassa.ru/developers

## ✅ Финальный чеклист

После развертывания проверьте:

- [ ] Webhook URL настроен в ЮКассе
- [ ] SMTP настроен и email приходят
- [ ] Тестовый платеж прошел успешно
- [ ] Логи пишутся корректно
- [ ] Статусы в БД обновляются
- [ ] Auto-login работает
- [ ] Страницы success/failure отображаются
- [ ] Корзина очищается после оплаты
- [ ] Cabinet показывает оплаченные регистрации

---

**Интеграция готова к работе!** 🎉
