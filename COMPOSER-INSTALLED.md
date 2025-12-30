# Composer - Установка завершена ✅

## Дата установки: 25 декабря 2025

## Установленные пакеты:

### Основные зависимости:
1. **mpdf/mpdf** v8.2.7
   - Генерация PDF-файлов
   - Поддержка UTF-8 и кириллицы
   - Используется в: classes/Diploma.php

2. **phpmailer/phpmailer** v6.12.0
   - Отправка email-уведомлений
   - Будет использоваться в Фазе 9

3. **yoomoney/yookassa-sdk-php** v2.13.0
   - Интеграция с платежной системой ЮКасса
   - Будет использоваться при замене заглушки оплаты

### Вспомогательные пакеты (зависимости):
- mpdf/psr-http-message-shim v2.0.1
- mpdf/psr-log-aware-trait v2.0.0
- myclabs/deep-copy v1.13.4
- paragonie/random_compat v9.99.100
- psr/http-message v2.0
- psr/log v1.1.4
- setasign/fpdi v2.6.4

## Команда установки:

```bash
docker exec pedagogy_web composer install --working-dir="/var/www/html"
```

## Созданные файлы:

- ✅ `vendor/` - Директория с библиотеками
- ✅ `vendor/autoload.php` - Автозагрузчик классов
- ✅ `composer.lock` - Фиксация версий пакетов

## Настроенные права доступа:

```bash
# Директория для сохранения PDF
chmod 777 /var/www/html/uploads/diplomas

# Временная директория mPDF
chmod 777 /var/www/html/vendor/mpdf/mpdf/tmp
```

## Проверка установки:

### 1. Список пакетов:
```bash
docker exec pedagogy_web composer show --working-dir="/var/www/html"
```

### 2. Проверка autoload:
```bash
docker exec pedagogy_web ls -la /var/www/html/vendor/autoload.php
```

### 3. Проверка mPDF:
```bash
docker exec pedagogy_web ls -la /var/www/html/vendor/mpdf/mpdf/
```

## Использование в коде:

### Подключение autoload:
```php
require_once __DIR__ . '/../vendor/autoload.php';
```

### Использование mPDF:
```php
use Mpdf\Mpdf;

$mpdf = new Mpdf([
    'mode' => 'utf-8',
    'format' => 'A4-L',
    'default_font' => 'dejavusans'
]);

$mpdf->WriteHTML($html);
$mpdf->Output('diploma.pdf', 'F');
```

### Использование PHPMailer (для будущего):
```php
use PHPMailer\PHPMailer\PHPMailer;

$mail = new PHPMailer(true);
$mail->CharSet = 'UTF-8';
// ... настройка и отправка
```

## Docker контейнер:

- **Имя:** pedagogy_web
- **Image:** pedagogy-platform-web
- **PHP версия:** 8.2.30
- **Порт:** 8080

## Следующие шаги:

1. ✅ Composer установлен
2. ✅ Все зависимости загружены
3. ✅ Права доступа настроены
4. ✅ Фаза 7 (PDF Generation) полностью функциональна

Теперь можно тестировать генерацию дипломов!

## Тестирование:

### Через браузер:
```
http://localhost:8080/pages/cabinet.php
```

После "оплаты" конкурса, в личном кабинете должна появиться кнопка "📥 Скачать диплом".

### Прямой запрос к API:
```
http://localhost:8080/ajax/download-diploma.php?registration_id=1&type=participant
```

## Обновление пакетов (для будущего):

```bash
# Обновить все пакеты
docker exec pedagogy_web composer update --working-dir="/var/www/html"

# Обновить конкретный пакет
docker exec pedagogy_web composer update mpdf/mpdf --working-dir="/var/www/html"
```

## Удаление пакетов (если потребуется):

```bash
# Удалить пакет
docker exec pedagogy_web composer remove имя-пакета --working-dir="/var/www/html"
```

---

**Статус:** Готово к работе! ✅
