#!/usr/bin/env php
<?php
/**
 * Разовый скрипт: создаёт кампанию-черновик old-base — приглашение протестировать
 * генератор педагогических статей (https://fgos.pro/generator-statej/) и опубликоваться
 * в электронном журнале с возможностью оформить свидетельство о публикации в СМИ.
 *
 * Аудитория — первые 10 000 активных подписчиков старой базы, ещё не получавших
 * ни одного письма (total_sent = 0), по порядку импорта (id ASC).
 *
 * Темп: 14 дней с прогревом домена (сумма ramp = 10 000).
 *
 * Кампания создаётся в статусе draft; запуск, правка start_date и тест-отправка —
 * из админки /admin/old-base/campaign-view.php.
 *
 * Usage: php scripts/old-base-create-generator-campaign.php
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only\n");
}

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/OldBaseCampaign.php';

// Первые 10 000 активных подписчиков, не получавших писем, по порядку импорта (id ASC).
$stmt = $db->query(
    "SELECT email FROM old_base_subscribers
     WHERE status = 'active' AND total_sent = 0
     ORDER BY id ASC
     LIMIT 10000"
);
$emails = $stmt->fetchAll(PDO::FETCH_COLUMN);
if (count($emails) < 10000) {
    fwrite(STDERR, 'Ожидали 10000 адресов, получили ' . count($emails) . " — прерываю.\n");
    exit(1);
}

$htmlBody = <<<'HTML'
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Генератор статей для педагогов</title>
</head>
<body style="margin:0; padding:0; background:#ffffff;">
<div style="max-width:560px; margin:0 auto; padding:28px 22px; font-family:-apple-system,'Segoe UI',Roboto,Arial,sans-serif; font-size:16px; line-height:1.62; color:#1a1a1a;">

  <p style="margin:0 0 16px;">{{greeting}}</p>

  <p style="margin:0 0 16px;">Меня зовут Родион Брехач, я главный редактор электронного журнала fgos.pro. Пишу вам по делу.</p>

  <p style="margin:0 0 16px;">Написание статьи для портфолио или аттестации обычно отнимает вечера: структура, формулировки, оформление. Мы сделали инструмент, который берёт это на себя.</p>

  <p style="margin:0 0 16px;"><b>Генератор статей на fgos.pro</b> — вы указываете тему и кратко описываете свои идеи, а нейросеть за 30 секунд пишет готовую педагогическую статью на 2000–4000 слов: с введением, разделами и заключением, в профессиональном стиле и с опорой на ФГОС.</p>

  <p style="margin:0 0 12px;">Как это работает:</p>

  <ul style="margin:0 0 16px; padding-left:22px;">
    <li style="margin-bottom:6px;">заполняете тему и короткое описание идей урока или опыта;</li>
    <li style="margin-bottom:6px;">ИИ генерирует статью — можно отредактировать любой раздел;</li>
    <li style="margin-bottom:6px;">публикуете материал в нашем электронном журнале;</li>
    <li style="margin-bottom:0;">оформляете свидетельство о публикации в СМИ — официальный документ для аттестации и портфолио (по желанию).</li>
  </ul>

  <p style="margin:0 0 26px;">Весь путь от идеи до публикации — около 3 минут. Генерация и размещение в журнале — бесплатно.</p>

  <p style="margin:0 0 26px;">
    <a href="{{cta_url}}" style="display:inline-block; background:#1e3aa8; color:#ffffff; text-decoration:none; font-size:16px; font-weight:600; padding:14px 30px; border-radius:8px;">Создать статью за 3 минуты</a>
  </p>

  <p style="margin:0 0 24px;">Попробуйте прямо сейчас — статья останется в журнале под вашим именем, а я лично увижу её в ленте новых материалов.</p>

  <p style="margin:0 0 2px;">С уважением,</p>
  <p style="margin:0 0 4px; color:#555555;">Родион Брехач — главный редактор журнала <a href="https://fgos.pro" style="color:#1e3aa8;">fgos.pro</a></p>

  <p style="margin:18px 0 0; font-size:12px; color:#999999; border-top:1px solid #eeeeee; padding-top:14px;">
    Вы получили это письмо, потому что ваш адрес есть в базе образовательного центра «Каменный город». Если письмо пришло по ошибке — <a href="{{unsubscribe_url}}" style="color:#999999;">отписаться от рассылки</a>.
  </p>

</div>
</body>
</html>
HTML;

$plainBody = <<<'TEXT'
{{greeting}}

Меня зовут Родион Брехач, я главный редактор электронного журнала fgos.pro.
Пишу вам по делу.

Написание статьи для портфолио или аттестации обычно отнимает вечера: структура,
формулировки, оформление. Мы сделали инструмент, который берёт это на себя.

Генератор статей на fgos.pro — вы указываете тему и кратко описываете свои идеи,
а нейросеть за 30 секунд пишет готовую педагогическую статью на 2000–4000 слов:
с введением, разделами и заключением, в профессиональном стиле и с опорой на ФГОС.

Как это работает:

- заполняете тему и короткое описание идей урока или опыта;
- ИИ генерирует статью — можно отредактировать любой раздел;
- публикуете материал в нашем электронном журнале;
- оформляете свидетельство о публикации в СМИ — официальный документ для
  аттестации и портфолио (по желанию).

Весь путь от идеи до публикации — около 3 минут. Генерация и размещение
в журнале — бесплатно.

Создать статью за 3 минуты: {{cta_url}}

Попробуйте прямо сейчас — статья останется в журнале под вашим именем,
а я лично увижу её в ленте новых материалов.

С уважением,
Родион Брехач — главный редактор журнала fgos.pro

Вы получили это письмо, потому что ваш адрес есть в базе центра «Каменный город».
Отписаться от рассылки: {{unsubscribe_url}}
TEXT;

// Ramp на 14 дней с прогревом домена. Сумма = 10 000.
$rampSchedule = [
    ['day' => 1,  'quota' => 150],
    ['day' => 2,  'quota' => 300],
    ['day' => 3,  'quota' => 500],
    ['day' => 4,  'quota' => 700],
    ['day' => 5,  'quota' => 850],
    ['day' => 6,  'quota' => 900],
    ['day' => 7,  'quota' => 900],
    ['day' => 8,  'quota' => 900],
    ['day' => 9,  'quota' => 900],
    ['day' => 10, 'quota' => 900],
    ['day' => 11, 'quota' => 900],
    ['day' => 12, 'quota' => 900],
    ['day' => 13, 'quota' => 900],
    ['day' => 14, 'quota' => 300],
];

$campaign = new OldBaseCampaign($db);

// Идемпотентность: если черновик с таким code уже есть — удаляем и пересоздаём.
// Удаляем только в статусе draft, чтобы не затронуть уже запущенную рассылку.
$existing = $db->query(
    "SELECT id, status FROM old_base_campaigns WHERE code = 'generator-statej-old-base'"
)->fetch(PDO::FETCH_ASSOC);
if ($existing) {
    if ($existing['status'] !== 'draft') {
        fwrite(STDERR, "Кампания generator-statej-old-base уже в статусе '{$existing['status']}' — прерываю.\n");
        exit(1);
    }
    $stmt = $db->prepare('DELETE FROM old_base_campaign_recipients WHERE campaign_id = ?');
    $stmt->execute([(int)$existing['id']]);
    $stmt = $db->prepare('DELETE FROM old_base_campaigns WHERE id = ?');
    $stmt->execute([(int)$existing['id']]);
    echo "Старый черновик id={$existing['id']} удалён, пересоздаю.\n";
}

$id = $campaign->create([
    'code'              => 'generator-statej-old-base',
    'name'              => 'Генератор статей — старая база, 10 000 (never_sent)',
    'subject'           => 'Педагогическая статья за 3 минуты — и публикация в журнале',
    'from_name'         => 'Родион Брехач, главный редактор fgos.pro',
    'from_email'        => 'rodion@fgos.pro',
    'html_body'         => $htmlBody,
    'plain_body'        => $plainBody,
    'cta_url'           => 'https://fgos.pro/generator-statej/',
    'auto_utm'          => 1,
    'audience_filter'   => ['type' => 'specific_emails', 'emails' => $emails],
    'start_date'        => '2026-05-22',
    'send_window_start' => '09:00:00',
    'send_window_end'   => '21:00:00',
    'timezone'          => 'Europe/Moscow',
    'ramp_schedule'     => $rampSchedule,
    'created_by'        => null,
]);

echo "Кампания создана: id={$id}, code=generator-statej-old-base, status=draft\n";
echo 'Адресов в фильтре аудитории: ' . count($emails) . "\n";
echo 'Сумма ramp за 14 дней: ' . array_sum(array_column($rampSchedule, 'quota')) . "\n";
echo "UTM: utm_source=email&utm_medium=old_base&utm_campaign=generator-statej-old-base\n";
echo "Просмотр: https://fgos.pro/admin/old-base/campaign-view.php?id={$id}\n";
echo "Перед запуском проверьте start_date в админке (дата фактического запуска).\n";
