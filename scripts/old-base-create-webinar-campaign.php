#!/usr/bin/env php
<?php
/**
 * Разовый скрипт: создаёт кампанию-черновик old-base — приглашение на бесплатный
 * вебинар «Как критериальное оценивание делает оценки честными. 7 инструментов
 * для каждого урока» (вебинар #19, 21 мая 2026, 14:00 МСК).
 *
 * Аудитория — первые 1000 активных подписчиков старой базы (по порядку импорта).
 * Кампания создаётся в статусе draft; запуск и тест-отправка — из админки
 * /admin/old-base/campaign-view.php.
 *
 * Usage: php scripts/old-base-create-webinar-campaign.php
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only\n");
}

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/OldBaseCampaign.php';

// Первые 1000 активных подписчиков по порядку импорта (id ASC).
$stmt = $db->query(
    "SELECT email FROM old_base_subscribers WHERE status = 'active' ORDER BY id ASC LIMIT 1000"
);
$emails = $stmt->fetchAll(PDO::FETCH_COLUMN);
if (count($emails) < 1000) {
    fwrite(STDERR, 'Ожидали 1000 адресов, получили ' . count($emails) . " — прерываю.\n");
    exit(1);
}

$htmlBody = <<<'HTML'
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Приглашение на вебинар</title>
</head>
<body style="margin:0; padding:0; background:#ffffff;">
<div style="max-width:560px; margin:0 auto; padding:28px 22px; font-family:-apple-system,'Segoe UI',Roboto,Arial,sans-serif; font-size:16px; line-height:1.62; color:#1a1a1a;">

  <p style="margin:0 0 16px;">Здравствуйте!</p>

  <p style="margin:0 0 16px;">21 мая в 14:00 по Москве проводим бесплатный онлайн-вебинар для педагогов — <b>«Как критериальное оценивание делает оценки честными. 7 инструментов для каждого урока»</b>.</p>

  <p style="margin:0 0 16px;">Обновлённые ФГОС прямо ориентированы на критериальное оценивание, но на практике это часто оборачивается спорами с родителями и ощущением субъективности отметок. На вебинаре разберём 7 готовых инструментов, которые работают на любом уроке:</p>

  <ul style="margin:0 0 16px; padding-left:22px;">
    <li style="margin-bottom:6px;">чек-лист критериев — объяснить ученику оценку за 30 секунд;</li>
    <li style="margin-bottom:6px;">рубрикатор урока под предметные и метапредметные результаты;</li>
    <li style="margin-bottom:6px;">речевые скрипты для прозрачной обратной связи;</li>
    <li style="margin-bottom:6px;">приёмы самооценки и взаимооценивания;</li>
    <li style="margin-bottom:6px;">алгоритм работы с возражениями ученика и родителя;</li>
    <li style="margin-bottom:0;">как донести подход на родительском собрании.</li>
  </ul>

  <p style="margin:0 0 16px;">85% времени — практика и разбор реальных кейсов.</p>

  <p style="margin:0 0 24px;">Ведёт <b>Гангнус Наталия Андреевна</b> — кандидат педагогических наук, доцент, эксперт образовательного центра «Каменный город». Длительность около 90 минут.</p>

  <p style="margin:0 0 26px;">
    <a href="{{cta_url}}" style="display:inline-block; background:#1e3aa8; color:#ffffff; text-decoration:none; font-size:16px; font-weight:600; padding:14px 30px; border-radius:8px;">Зарегистрироваться на вебинар</a>
  </p>

  <p style="margin:0 0 16px;">Не сможете быть в эфире — всё равно зарегистрируйтесь, пришлём ссылку на запись. После вебинара можно оформить именной сертификат на 2 часа (по желанию).</p>

  <p style="margin:0 0 4px; color:#555555;">— Педпортал «Каменный город», <a href="https://fgos.pro" style="color:#1e3aa8;">fgos.pro</a></p>

  <p style="margin:18px 0 0; font-size:12px; color:#999999; border-top:1px solid #eeeeee; padding-top:14px;">
    Вы получили это письмо, потому что ваш адрес есть в базе образовательного центра «Каменный город». Если письмо пришло по ошибке — <a href="{{unsubscribe_url}}" style="color:#999999;">отписаться от рассылки</a>.
  </p>

</div>
</body>
</html>
HTML;

$plainBody = <<<'TEXT'
Здравствуйте!

21 мая в 14:00 по Москве проводим бесплатный онлайн-вебинар для педагогов —
«Как критериальное оценивание делает оценки честными. 7 инструментов для каждого урока».

Обновлённые ФГОС прямо ориентированы на критериальное оценивание, но на практике
это часто оборачивается спорами с родителями и ощущением субъективности отметок.
На вебинаре разберём 7 готовых инструментов, которые работают на любом уроке:

- чек-лист критериев — объяснить ученику оценку за 30 секунд;
- рубрикатор урока под предметные и метапредметные результаты;
- речевые скрипты для прозрачной обратной связи;
- приёмы самооценки и взаимооценивания;
- алгоритм работы с возражениями ученика и родителя;
- как донести подход на родительском собрании.

85% времени — практика и разбор реальных кейсов.

Ведёт Гангнус Наталия Андреевна — кандидат педагогических наук, доцент,
эксперт образовательного центра «Каменный город». Длительность около 90 минут.

Зарегистрироваться на вебинар: {{cta_url}}

Не сможете быть в эфире — всё равно зарегистрируйтесь, пришлём ссылку на запись.
После вебинара можно оформить именной сертификат на 2 часа (по желанию).

— Педпортал «Каменный город», fgos.pro

Вы получили это письмо, потому что ваш адрес есть в базе центра «Каменный город».
Отписаться от рассылки: {{unsubscribe_url}}
TEXT;

$campaign = new OldBaseCampaign($db);

$id = $campaign->create([
    'code'              => 'webinar-kriterialnoe-21may',
    'name'              => 'Вебинар «Критериальное оценивание» — старая база, 21 мая',
    'subject'           => '21 мая — вебинар о том, как сделать школьные оценки честными',
    'from_name'         => 'Команда Каменного города',
    'from_email'        => null,
    'html_body'         => $htmlBody,
    'plain_body'        => $plainBody,
    'cta_url'           => 'https://fgos.pro/vebinar/kriterialnoe-ocenivanie-7-instrumentov/',
    'auto_utm'          => 1,
    'audience_filter'   => ['type' => 'specific_emails', 'emails' => $emails],
    'start_date'        => '2026-05-19',
    'send_window_start' => '09:00:00',
    'send_window_end'   => '21:00:00',
    'timezone'          => 'Europe/Moscow',
    'ramp_schedule'     => [['day' => 1, 'quota' => 500], ['day' => 2, 'quota' => 500]],
    'created_by'        => null,
]);

echo "Кампания создана: id={$id}, code=webinar-kriterialnoe-21may, status=draft\n";
echo 'Адресов в фильтре аудитории: ' . count($emails) . "\n";
echo "UTM: utm_source=email&utm_medium=old_base&utm_campaign=webinar-kriterialnoe-21may\n";
echo "Просмотр: https://fgos.pro/admin/old-base/campaign-view.php?id={$id}\n";
