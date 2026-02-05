<?php
/**
 * IcsGenerator Class
 * Генерирует файлы iCalendar (.ics) для добавления событий в календарь
 */

class IcsGenerator {

    /**
     * Генерирует ICS-контент для вебинара
     *
     * @param array $webinar Данные вебинара (title, scheduled_at, duration_minutes, broadcast_url, description)
     * @param string $organizerName Название организатора
     * @param string $organizerEmail Email организатора
     * @return string ICS-контент
     */
    public static function generateForWebinar($webinar, $organizerName = 'ФГОС-Практикум', $organizerEmail = 'info@fgos.pro') {
        $uid = 'webinar-' . ($webinar['id'] ?? uniqid()) . '@fgos.pro';

        // Парсим дату вебинара (предполагаем МСК)
        $startDateTime = new DateTime($webinar['scheduled_at'], new DateTimeZone('Europe/Moscow'));
        $duration = (int)($webinar['duration_minutes'] ?? 60);
        $endDateTime = clone $startDateTime;
        $endDateTime->add(new DateInterval('PT' . $duration . 'M'));

        // Форматируем даты в UTC для ICS
        $startDateTime->setTimezone(new DateTimeZone('UTC'));
        $endDateTime->setTimezone(new DateTimeZone('UTC'));

        $dtStart = $startDateTime->format('Ymd\THis\Z');
        $dtEnd = $endDateTime->format('Ymd\THis\Z');
        $dtStamp = gmdate('Ymd\THis\Z');

        // Очищаем текстовые поля для ICS
        $summary = self::escapeIcsText($webinar['title'] ?? 'Вебинар ФГОС-Практикум');
        $description = self::escapeIcsText(
            strip_tags($webinar['short_description'] ?? $webinar['description'] ?? 'Вебинар на портале ФГОС-Практикум')
        );
        $location = $webinar['broadcast_url'] ?? '';

        // Добавляем ссылку на трансляцию в описание
        if ($location) {
            $description .= '\n\nСсылка на трансляцию: ' . $location;
        }

        $ics = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//FGOS.PRO//Webinar Calendar//RU\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= "METHOD:PUBLISH\r\n";
        $ics .= "X-WR-CALNAME:ФГОС-Практикум Вебинары\r\n";
        $ics .= "BEGIN:VEVENT\r\n";
        $ics .= "UID:{$uid}\r\n";
        $ics .= "DTSTAMP:{$dtStamp}\r\n";
        $ics .= "DTSTART:{$dtStart}\r\n";
        $ics .= "DTEND:{$dtEnd}\r\n";
        $ics .= "SUMMARY:{$summary}\r\n";
        $ics .= "DESCRIPTION:{$description}\r\n";

        if ($location) {
            $ics .= "LOCATION:" . self::escapeIcsText($location) . "\r\n";
            $ics .= "URL:{$location}\r\n";
        }

        $ics .= "ORGANIZER;CN={$organizerName}:mailto:{$organizerEmail}\r\n";
        $ics .= "STATUS:CONFIRMED\r\n";

        // Напоминания: за 1 день и за 1 час
        $ics .= "BEGIN:VALARM\r\n";
        $ics .= "TRIGGER:-P1D\r\n";
        $ics .= "ACTION:DISPLAY\r\n";
        $ics .= "DESCRIPTION:Завтра вебинар: {$summary}\r\n";
        $ics .= "END:VALARM\r\n";

        $ics .= "BEGIN:VALARM\r\n";
        $ics .= "TRIGGER:-PT1H\r\n";
        $ics .= "ACTION:DISPLAY\r\n";
        $ics .= "DESCRIPTION:Через 1 час начнется вебинар: {$summary}\r\n";
        $ics .= "END:VALARM\r\n";

        $ics .= "END:VEVENT\r\n";
        $ics .= "END:VCALENDAR\r\n";

        return $ics;
    }

    /**
     * Отправляет ICS-файл как download
     *
     * @param string $icsContent ICS-контент
     * @param string $filename Имя файла (без расширения)
     */
    public static function sendAsDownload($icsContent, $filename = 'webinar') {
        $filename = self::sanitizeFilename($filename) . '.ics';

        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($icsContent));
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: 0');

        echo $icsContent;
        exit;
    }

    /**
     * Экранирует текст для ICS формата
     *
     * @param string $text Исходный текст
     * @return string Экранированный текст
     */
    private static function escapeIcsText($text) {
        // Удаляем HTML теги
        $text = strip_tags($text);
        // Заменяем переносы строк на \n
        $text = str_replace(["\r\n", "\r", "\n"], '\n', $text);
        // Экранируем специальные символы
        $text = str_replace(['\\', ';', ','], ['\\\\', '\;', '\,'], $text);
        // Ограничиваем длину строки (для совместимости)
        if (strlen($text) > 500) {
            $text = mb_substr($text, 0, 497) . '...';
        }
        return $text;
    }

    /**
     * Очищает имя файла от недопустимых символов
     *
     * @param string $filename Исходное имя
     * @return string Очищенное имя
     */
    private static function sanitizeFilename($filename) {
        // Транслитерация кириллицы
        $translitMap = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
            'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
            'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
            'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
            'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch',
            'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
            'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
            'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D',
            'Е' => 'E', 'Ё' => 'E', 'Ж' => 'Zh', 'З' => 'Z', 'И' => 'I',
            'Й' => 'Y', 'К' => 'K', 'Л' => 'L', 'М' => 'M', 'Н' => 'N',
            'О' => 'O', 'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T',
            'У' => 'U', 'Ф' => 'F', 'Х' => 'H', 'Ц' => 'C', 'Ч' => 'Ch',
            'Ш' => 'Sh', 'Щ' => 'Sch', 'Ъ' => '', 'Ы' => 'Y', 'Ь' => '',
            'Э' => 'E', 'Ю' => 'Yu', 'Я' => 'Ya'
        ];

        $filename = strtr($filename, $translitMap);
        $filename = preg_replace('/[^a-zA-Z0-9_-]/', '-', $filename);
        $filename = preg_replace('/-+/', '-', $filename);
        $filename = trim($filename, '-');

        return $filename ?: 'webinar';
    }
}
