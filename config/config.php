<?php
/**
 * Main Configuration File
 * Loads environment variables and defines application constants
 */

// Prevent multiple inclusion
if (defined('CONFIG_LOADED')) {
    return;
}
define('CONFIG_LOADED', true);

// Load .env file
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Store in $_ENV and define as constant
            $_ENV[$key] = $value;
            if (!defined($key)) {
                define($key, $value);
            }
        }
    }
}

// Database Configuration
if (!defined('DB_HOST')) define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', $_ENV['DB_NAME'] ?? 'pedagogy_platform');
if (!defined('DB_USER')) define('DB_USER', $_ENV['DB_USER'] ?? 'root');
if (!defined('DB_PASS')) define('DB_PASS', $_ENV['DB_PASS'] ?? '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

// Site Configuration
if (!defined('SITE_URL')) define('SITE_URL', $_ENV['SITE_URL'] ?? 'http://localhost');
// Публичный URL для ссылок в письмах и share-ссылок: письма уходят реальным адресатам
// даже из локальной среды, поэтому localhost из SITE_URL не должен утекать наружу.
if (!defined('PUBLIC_SITE_URL')) {
    $__publicSiteUrl = rtrim($_ENV['PUBLIC_SITE_URL'] ?? SITE_URL, '/');
    $__publicSiteHost = strtolower((string)(parse_url($__publicSiteUrl, PHP_URL_HOST) ?: ''));
    if ($__publicSiteHost === '' || $__publicSiteHost === 'localhost' || $__publicSiteHost === '127.0.0.1') {
        $__publicSiteUrl = 'https://fgos.pro';
    }
    define('PUBLIC_SITE_URL', $__publicSiteUrl);
    unset($__publicSiteUrl, $__publicSiteHost);
}
if (!defined('SITE_NAME')) define('SITE_NAME', $_ENV['SITE_NAME'] ?? 'Педагогический портал');
if (!defined('APP_ENV')) define('APP_ENV', $_ENV['APP_ENV'] ?? 'local');
if (!defined('BASE_PATH')) define('BASE_PATH', dirname(__DIR__));

// Yookassa Configuration
if (!defined('YOOKASSA_SHOP_ID')) define('YOOKASSA_SHOP_ID', $_ENV['YOOKASSA_SHOP_ID'] ?? '');
if (!defined('YOOKASSA_SECRET_KEY')) define('YOOKASSA_SECRET_KEY', $_ENV['YOOKASSA_SECRET_KEY'] ?? '');
if (!defined('YOOKASSA_MODE')) define('YOOKASSA_MODE', $_ENV['YOOKASSA_MODE'] ?? 'sandbox');

// ChatPush — отправка уведомлений в мессенджер «Макс» при оплате мероприятий.
// Канал (Макс / каскад) настраивается в кабинете ChatPush под токеном, в коде не указывается.
// Константа-флаг названа иначе, чем ключ .env (CHATPUSH_ENABLED), чтобы строки 'false'/'no'
// корректно парсились в bool — общий загрузчик .env определил бы одноимённую константу как
// truthy-строку (см. тот же приём для PRICING_AB ниже).
if (!defined('CHATPUSH_ACTIVE'))    define('CHATPUSH_ACTIVE', filter_var($_ENV['CHATPUSH_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOLEAN));
if (!defined('CHATPUSH_API_TOKEN')) define('CHATPUSH_API_TOKEN', $_ENV['CHATPUSH_API_TOKEN'] ?? '');
if (!defined('CHATPUSH_API_URL'))   define('CHATPUSH_API_URL', $_ENV['CHATPUSH_API_URL'] ?? 'https://api.chatpush.ru/api/v1/delivery');
// Секрет для верификации входящего вебхука ChatPush (ответы пользователей в «Макс»).
// Пусто = входящие отклоняются (fail-closed). Секрет зашивается в callback-URL (?secret=).
// См. api/webhook/chatpush.php и scripts/chatpush-register-webhook.php.
if (!defined('CHATPUSH_CALLBACK_SECRET')) define('CHATPUSH_CALLBACK_SECRET', $_ENV['CHATPUSH_CALLBACK_SECRET'] ?? '');

// Bitrix24 CRM Integration
if (!defined('BITRIX24_WEBHOOK_URL')) define('BITRIX24_WEBHOOK_URL', $_ENV['BITRIX24_WEBHOOK_URL'] ?? '');
if (!defined('BITRIX24_WEBINAR_PIPELINE_ID')) define('BITRIX24_WEBINAR_PIPELINE_ID', $_ENV['BITRIX24_WEBINAR_PIPELINE_ID'] ?? 102);
if (!defined('BITRIX24_COURSE_PIPELINE_ID')) define('BITRIX24_COURSE_PIPELINE_ID', $_ENV['BITRIX24_COURSE_PIPELINE_ID'] ?? 108);
// Источники Bitrix, которыми помечаются оффлайн-сделки fgos.pro в общем funnel ЦДО:
// 83 («ФГОС-практикум») / 87 («ФГОС-практикум ВК»). По ним считаем «нашу» оффлайн-
// выручку (рассрочки/счета), отделяя от оффлайн-бизнеса остального холдинга.
// (BITRIX24_CDO_PIPELINE_ID=4 определён ниже, в блоке мониторинга ЦДО.)
if (!defined('BITRIX24_FGOS_SOURCE_IDS')) define('BITRIX24_FGOS_SOURCE_IDS', $_ENV['BITRIX24_FGOS_SOURCE_IDS'] ?? '83,87');
if (!defined('BITRIX24_COURSE_STAGE_NEW')) define('BITRIX24_COURSE_STAGE_NEW', 'C108:NEW');
// Стадия успешно оплаченной сделки — воронка «ФГОС-Практикум (Курсы)»,
// этап «Оплаченная сделка» (C108:UC_8RO3WZ). Раньше использовался C108:WON
// («Сделка успешна»); оплаченные сделки переведены в отдельный этап.
if (!defined('BITRIX24_COURSE_STAGE_PAID')) {
    define('BITRIX24_COURSE_STAGE_PAID', $_ENV['BITRIX24_COURSE_STAGE_PAID'] ?? 'C108:UC_8RO3WZ');
}

// Bitrix24: стадии email-цепочки курсов (pipeline 108)
if (!defined('BITRIX24_COURSE_STAGE_15MIN'))   define('BITRIX24_COURSE_STAGE_15MIN', 'C108:UC_HWWIFQ');
if (!defined('BITRIX24_COURSE_STAGE_1H'))      define('BITRIX24_COURSE_STAGE_1H', 'C108:UC_1YOFLO');
if (!defined('BITRIX24_COURSE_STAGE_MANAGER')) define('BITRIX24_COURSE_STAGE_MANAGER', 'C108:UC_DLXNLQ');

// Рассрочка по курсам (без онлайн-оплаты, оформляется менеджером)
if (!defined('COURSE_INSTALLMENT_MIN_PRICE')) {
    define('COURSE_INSTALLMENT_MIN_PRICE', (int)($_ENV['COURSE_INSTALLMENT_MIN_PRICE'] ?? 10000));
}
if (!defined('COURSE_INSTALLMENT_MONTHS')) {
    define('COURSE_INSTALLMENT_MONTHS', (int)($_ENV['COURSE_INSTALLMENT_MONTHS'] ?? 12));
}
// Стадия Bitrix24 для заявок на рассрочку. ID создаётся пользователем вручную
// в воронке курсов; до настройки — fallback на менеджерскую стадию.
if (!defined('BITRIX24_COURSE_STAGE_INSTALLMENT')) {
    define('BITRIX24_COURSE_STAGE_INSTALLMENT',
        $_ENV['BITRIX24_COURSE_STAGE_INSTALLMENT'] ?? BITRIX24_COURSE_STAGE_MANAGER);
}

// Messenger Max — контакт менеджера для ускоренной обработки заявок на курсы.
// Используется в CTA-блоках после оплаты курса и подачи заявки на рассрочку.
if (!defined('MAX_MANAGER_PHONE')) define('MAX_MANAGER_PHONE', $_ENV['MAX_MANAGER_PHONE'] ?? '+7 922 304 44 13');
if (!defined('MAX_MANAGER_URL'))   define('MAX_MANAGER_URL',   $_ENV['MAX_MANAGER_URL']   ?? 'https://max.ru/u/f9LHodD0cOJKXZhXUQImrGumTp40Eiu4o40RTZGhnpMVWgNe6tGt0x0OSco');

// Bitrix24: воронка ЦДО (pipeline 4) — мониторинг для деактивации email-цепочки
if (!defined('BITRIX24_CDO_PIPELINE_ID'))       define('BITRIX24_CDO_PIPELINE_ID', 4);
if (!defined('BITRIX24_CDO_STAGE_DOCS'))        define('BITRIX24_CDO_STAGE_DOCS', 'C4:17');       // «Подготовка документов»
if (!defined('BITRIX24_CDO_STAGE_DOCS_SORT'))   define('BITRIX24_CDO_STAGE_DOCS_SORT', 80);       // SORT этой стадии

// Секрет для HMAC-подписи скидочных ссылок в email-цепочке курсов
if (!defined('COURSE_EMAIL_DISCOUNT_SECRET')) define('COURSE_EMAIL_DISCOUNT_SECRET', $_ENV['COURSE_EMAIL_DISCOUNT_SECRET'] ?? '');

// Секрет для HMAC-подписи скидочных ссылок в email-цепочке материалов ФОП (fallback на MAGIC_LINK_SECRET)
if (!defined('MATERIAL_EMAIL_DISCOUNT_SECRET')) define('MATERIAL_EMAIL_DISCOUNT_SECRET', $_ENV['MATERIAL_EMAIL_DISCOUNT_SECRET'] ?? '');

// Unisender Go (UniOne) Web API — транзакционный канал для писем олимпиад
// Документация: https://godocs.unisender.ru/web-api-ref
if (!defined('UNISENDER_API_KEY'))      define('UNISENDER_API_KEY',      $_ENV['UNISENDER_API_KEY']      ?? '');
if (!defined('UNISENDER_API_ENDPOINT')) define('UNISENDER_API_ENDPOINT', $_ENV['UNISENDER_API_ENDPOINT'] ?? 'https://go2.unisender.ru/ru/transactional/api/v1/');
if (!defined('UNISENDER_SENDER_EMAIL')) define('UNISENDER_SENDER_EMAIL', $_ENV['UNISENDER_SENDER_EMAIL'] ?? 'info@fgos.pro');
if (!defined('UNISENDER_SENDER_NAME'))  define('UNISENDER_SENDER_NAME',  $_ENV['UNISENDER_SENDER_NAME']  ?? 'ФГОС-Практикум');
// Секрет для проверки вебхука доставки (api/webhook/unisender.php). Пусто — проверка отключена.
if (!defined('UNISENDER_WEBHOOK_SECRET')) define('UNISENDER_WEBHOOK_SECRET', $_ENV['UNISENDER_WEBHOOK_SECRET'] ?? '');

// Yandex GPT AI Moderation
if (!defined('YANDEX_GPT_API_KEY')) define('YANDEX_GPT_API_KEY', $_ENV['YANDEX_GPT_API_KEY'] ?? '');
if (!defined('YANDEX_GPT_FOLDER_ID')) define('YANDEX_GPT_FOLDER_ID', $_ENV['YANDEX_GPT_FOLDER_ID'] ?? '');
if (!defined('YANDEX_GPT_MODEL')) define('YANDEX_GPT_MODEL', $_ENV['YANDEX_GPT_MODEL'] ?? 'yandexgpt-lite');
// Оформление загруженных публикаций требует точного следования инструкции (сохранить слова,
// только расставить разметку) — берём pro-модель, lite слишком вольно переписывает текст.
if (!defined('YANDEX_GPT_FORMAT_MODEL')) define('YANDEX_GPT_FORMAT_MODEL', $_ENV['YANDEX_GPT_FORMAT_MODEL'] ?? 'yandexgpt');

// YandexART — генерация обложек материалов (те же креды Yandex Cloud, что и GPT-модерация).
if (!defined('YANDEX_ART_ENABLED')) define('YANDEX_ART_ENABLED', ($_ENV['YANDEX_ART_ENABLED'] ?? '1') === '1');
if (!defined('YANDEX_ART_MODEL'))   define('YANDEX_ART_MODEL',   $_ENV['YANDEX_ART_MODEL'] ?? 'yandex-art/latest');
if (!defined('YANDEX_ART_TIMEOUT')) define('YANDEX_ART_TIMEOUT', (int)($_ENV['YANDEX_ART_TIMEOUT'] ?? 25));
// Максимум ИИ-иллюстраций на одну презентацию (генерируются для слайдов с image_prompt).
if (!defined('MATERIAL_SLIDE_IMAGES_MAX')) define('MATERIAL_SLIDE_IMAGES_MAX', (int)($_ENV['MATERIAL_SLIDE_IMAGES_MAX'] ?? 6));

// OpenRouter — генератор материалов ФОП. Используются дешёвые open-source модели.
if (!defined('OPENROUTER_API_KEY'))         define('OPENROUTER_API_KEY',         $_ENV['OPENROUTER_API_KEY']         ?? '');
if (!defined('OPENROUTER_MODEL_DEFAULT'))   define('OPENROUTER_MODEL_DEFAULT',   $_ENV['OPENROUTER_MODEL_DEFAULT']   ?? 'meta-llama/llama-3.3-70b-instruct');
if (!defined('OPENROUTER_MODEL_STRUCTURED'))define('OPENROUTER_MODEL_STRUCTURED',$_ENV['OPENROUTER_MODEL_STRUCTURED']?? 'qwen/qwen-2.5-72b-instruct');
if (!defined('OPENROUTER_MODEL_FAST'))      define('OPENROUTER_MODEL_FAST',      $_ENV['OPENROUTER_MODEL_FAST']      ?? 'google/gemini-2.0-flash-001');
// Сильная модель для методической самопроверки (второй проход): ловит фактические
// ошибки, несоответствие темы классу, неверные ключи. Дороже генерации, но используется
// только на ревью одного материала.
if (!defined('OPENROUTER_MODEL_REVIEW'))    define('OPENROUTER_MODEL_REVIEW',    $_ENV['OPENROUTER_MODEL_REVIEW']    ?? 'google/gemini-2.5-pro');
// Методическая самопроверка материалов (второй проход ИИ-методиста по чек-листу ФГОС/ФОП).
// Дороже по токенам — отключается значением 0/false/no в .env (по умолчанию включено).
if (!defined('MATERIAL_SELFCHECK_ENABLED')) define('MATERIAL_SELFCHECK_ENABLED', !in_array(strtolower((string)($_ENV['MATERIAL_SELFCHECK_ENABLED'] ?? '1')), ['0', 'false', 'no', 'off', ''], true));

// Пользователи без ограничений в генераторе материалов: не упираются в суточный лимит
// генераций и в баланс токенов (списание не выполняется — баланс остаётся прежним).
// Список e-mail (нижний регистр), через запятую в .env либо хардкод ниже.
if (!defined('MATERIAL_UNLIMITED_EMAILS')) {
    $__matUnlimited = array_filter(array_map(
        static fn($e) => strtolower(trim($e)),
        explode(',', (string)($_ENV['MATERIAL_UNLIMITED_EMAILS'] ?? 'es.dippel@gmail.com'))
    ));
    define('MATERIAL_UNLIMITED_EMAILS', $__matUnlimited);
    unset($__matUnlimited);
}

// Telegram Alerts (тех. уведомления в бот ИИ-консультанта)
// Тот же бот используется в ai-consultant/src/bootstrap.php (AI_TELEGRAM_BOT_TOKEN)
// Если TELEGRAM_BOT_TOKEN не задан — TelegramNotifier/AlertService молча отключают отправку.
if (!defined('TELEGRAM_BOT_TOKEN')) {
    define('TELEGRAM_BOT_TOKEN', $_ENV['TELEGRAM_BOT_TOKEN'] ?? '');
}
if (!defined('TELEGRAM_ALERT_CHAT_ID')) {
    define('TELEGRAM_ALERT_CHAT_ID', $_ENV['TELEGRAM_ALERT_CHAT_ID'] ?? '');
}

// Минимальный интервал между chain-письмами одному получателю (минуты). 0 = отключено.
if (!defined('CHAIN_MIN_INTERVAL_MINUTES')) define('CHAIN_MIN_INTERVAL_MINUTES', (int)($_ENV['CHAIN_MIN_INTERVAL_MINUTES'] ?? 0));

// Максимум chain-писем одному получателю за последние 24 часа. 0 = отключено.
// Считаются записи в email_events (т.е. фактически принятые Unisender'ом отправки).
if (!defined('CHAIN_DAILY_CAP_PER_RECIPIENT')) define('CHAIN_DAILY_CAP_PER_RECIPIENT', (int)($_ENV['CHAIN_DAILY_CAP_PER_RECIPIENT'] ?? 0));

// Все исходящие письма идут через Unisender Go (см. UNISENDER_* выше).
// SMTP_BULK_* пул и CHAINS_PAUSED_UNTIL удалены при миграции 2026-05-05.

// Legacy SMTP_FROM_* — оставлены, потому что некоторые места (AlertService и т.п.)
// используют их как «логический» from-адрес сайта. Указывают на Unisender-отправителя.
if (!defined('SMTP_FROM_EMAIL')) define('SMTP_FROM_EMAIL', $_ENV['SMTP_FROM_EMAIL'] ?? UNISENDER_SENDER_EMAIL);
if (!defined('SMTP_FROM_NAME'))  define('SMTP_FROM_NAME',  $_ENV['SMTP_FROM_NAME']  ?? UNISENDER_SENDER_NAME);

// IMAP (приём входящих писем поддержки — для cron/process-inbound-emails.php).
// Входящая почта продолжает приниматься Яндекс 360 на info@fgos.pro через MX —
// исходящая мигрировала на Unisender Go, на доставку входящей это не влияет.
if (!defined('IMAP_HOST')) define('IMAP_HOST', $_ENV['IMAP_HOST'] ?? 'imap.yandex.ru');
if (!defined('IMAP_PORT')) define('IMAP_PORT', (int)($_ENV['IMAP_PORT'] ?? 993));
if (!defined('IMAP_USERNAME')) define('IMAP_USERNAME', $_ENV['IMAP_USERNAME'] ?? '');
if (!defined('IMAP_PASSWORD')) define('IMAP_PASSWORD', $_ENV['IMAP_PASSWORD'] ?? '');
if (!defined('IMAP_ENCRYPTION')) define('IMAP_ENCRYPTION', $_ENV['IMAP_ENCRYPTION'] ?? 'ssl');
if (!defined('IMAP_MAILBOX')) define('IMAP_MAILBOX', $_ENV['IMAP_MAILBOX'] ?? 'INBOX');

// ВКонтакте Callback API (алерты из сообщений группы)
if (!defined('VK_CALLBACK_SECRET')) define('VK_CALLBACK_SECRET', $_ENV['VK_CALLBACK_SECRET'] ?? '');
if (!defined('VK_CONFIRMATION_STRING')) define('VK_CONFIRMATION_STRING', $_ENV['VK_CONFIRMATION_STRING'] ?? '');
if (!defined('VK_COMMUNITY_TOKEN')) define('VK_COMMUNITY_TOKEN', $_ENV['VK_COMMUNITY_TOKEN'] ?? '');
if (!defined('VK_GROUP_ID')) define('VK_GROUP_ID', (int)($_ENV['VK_GROUP_ID'] ?? 0));

// Session Configuration
if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', $_ENV['SESSION_LIFETIME'] ?? 86400);
if (!defined('COOKIE_LIFETIME')) define('COOKIE_LIFETIME', $_ENV['COOKIE_LIFETIME'] ?? 2592000); // 30 days

// Magic Link Configuration (auto-login from email links).
// Обязательная переменная — если не задана, magic-link можно подделать.
if (!defined('MAGIC_LINK_SECRET')) define('MAGIC_LINK_SECRET', $_ENV['MAGIC_LINK_SECRET'] ?? '');

// Competition Categories
if (!defined('COMPETITION_CATEGORIES')) {
    define('COMPETITION_CATEGORIES', [
        'methodology' => 'Методические разработки',
        'extracurricular' => 'Внеурочная деятельность',
        'student_projects' => 'Проекты учащихся',
        'creative' => 'Творческие конкурсы'
    ]);
}

// Маппинги URL-слагов для SEO-friendly URL
// internal key → URL slug
if (!defined('COMPETITION_CATEGORY_URL_MAP')) {
    define('COMPETITION_CATEGORY_URL_MAP', [
        'methodology' => 'metodika',
        'extracurricular' => 'vneurochnaya',
        'student_projects' => 'proekty',
        'creative' => 'tvorchestvo'
    ]);
}
// URL slug → internal key
if (!defined('COMPETITION_CATEGORY_URL_REVERSE')) {
    define('COMPETITION_CATEGORY_URL_REVERSE', [
        'metodika' => 'methodology',
        'vneurochnaya' => 'extracurricular',
        'proekty' => 'student_projects',
        'tvorchestvo' => 'creative'
    ]);
}
if (!defined('WEBINAR_STATUS_URL_MAP')) {
    define('WEBINAR_STATUS_URL_MAP', [
        'upcoming' => 'predstoyashchie',
        'recordings' => 'zapisi',
        'videolecture' => 'videolektsii'
    ]);
}
if (!defined('WEBINAR_STATUS_URL_REVERSE')) {
    define('WEBINAR_STATUS_URL_REVERSE', [
        'predstoyashchie' => 'upcoming',
        'zapisi' => 'recordings',
        'videolektsii' => 'videolecture'
    ]);
}

// Course Program Types
if (!defined('COURSE_PROGRAM_TYPES')) {
    define('COURSE_PROGRAM_TYPES', [
        'kpk' => 'Повышение квалификации',
        'pp'  => 'Профессиональная переподготовка'
    ]);
}

if (!defined('COURSE_TYPE_URL_MAP')) {
    define('COURSE_TYPE_URL_MAP', [
        'kpk' => 'povyshenie-kvalifikatsii',
        'pp'  => 'perepodgotovka'
    ]);
}
if (!defined('COURSE_TYPE_URL_REVERSE')) {
    define('COURSE_TYPE_URL_REVERSE', [
        'povyshenie-kvalifikatsii' => 'kpk',
        'perepodgotovka' => 'pp'
    ]);
}

// Карта «тег публикации → аудитория курса» для рекомендаций курсов в статьях.
// Ключ — slug тега (publication_tags.slug). Значение:
//   'spec'  — ID специализаций курсов (audience_specializations.id), сильный сигнал (предмет);
//   'type'  — ID типов аудитории (audience_types.id), сигнал ступени образования;
//   'group' — текст courses.course_group для прямого совпадения по направлению.
// Используется Publication::getRecommendedCourses(). Теги без записи дают только фолбэк.
if (!defined('PUBLICATION_TAG_AUDIENCE_MAP')) {
    define('PUBLICATION_TAG_AUDIENCE_MAP', [
        // subject-теги → специализации курсов
        'mathematics'        => ['spec' => [10, 18, 2]],
        'russian-literature' => ['spec' => [17, 8, 9]],
        'arts'               => ['spec' => [13, 14, 29, 40, 39]],
        'natural-sciences'   => ['spec' => [20, 21, 22, 23, 44, 11]],
        'history-social'     => ['spec' => [24, 25, 45]],
        'technology'         => ['spec' => [16, 30, 32]],
        'foreign-languages'  => ['spec' => [12, 26]],
        'physical-education'  => ['spec' => [15, 27, 42]],
        'life-safety'        => ['spec' => [28]],
        'informatics'        => ['spec' => [19, 37, 43]],
        // direction-теги → типы аудитории и/или course_group
        'preschool'          => ['type' => [1], 'group' => 'Дошкольное образование'],
        'primary-school'     => ['type' => [2], 'group' => 'Начальная школа'],
        'secondary-school'   => ['type' => [3]],
        'high-school'        => ['type' => [3]],
        'extra-education'    => ['type' => [5], 'group' => 'Дополнительное образование'],
        'special-education'  => ['spec' => [58, 48]],
        'psychology'         => ['spec' => [49]],
        'educational-work'   => ['spec' => [56], 'group' => 'Воспитательная работа'],
    ]);
}

// Audience Categories (Level 0) — основные группы аудитории
if (!defined('AUDIENCE_CATEGORIES')) {
    define('AUDIENCE_CATEGORIES', [
        'pedagogi' => 'Педагогам',
        'doshkolnikam' => 'Дошкольникам',
        'shkolnikam' => 'Школьникам',
        'studentam-spo' => 'Студентам СПО'
    ]);
}

// Аудитория в родительном падеже — для динамических H1/title каталогов.
// Используется buildAudiencePhrase() из includes/catalog-meta.php.
if (!defined('AUDIENCE_CATEGORY_GENITIVE_MAP')) {
    define('AUDIENCE_CATEGORY_GENITIVE_MAP', [
        'pedagogi'      => 'педагогов',
        'doshkolnikam'  => 'педагогов дошкольного образования',
        'shkolnikam'    => 'учителей школ',
        'studentam-spo' => 'преподавателей СПО',
    ]);
}

// Человеческие названия статусов вебинаров — для динамических заголовков.
if (!defined('WEBINAR_STATUS_LABELS')) {
    define('WEBINAR_STATUS_LABELS', [
        'upcoming'     => 'Предстоящие вебинары',
        'recordings'   => 'Записи вебинаров',
        'videolecture' => 'Видеолекции',
    ]);
}

// @deprecated Используйте audience_categories/audience_types из БД. Оставлено для обратной совместимости.
if (!defined('OLYMPIAD_AUDIENCES')) {
    define('OLYMPIAD_AUDIENCES', [
        'pedagogues_dou' => 'Для педагогов ДОУ',
        'pedagogues_school' => 'Для педагогов школ',
        'pedagogues_ovz' => 'Для педагогов, работающих с детьми с ОВЗ',
        'students' => 'Для школьников',
        'preschoolers' => 'Для дошкольников',
        'logopedists' => 'Для логопедов'
    ]);
}

// Olympiad Diploma Price
if (!defined('OLYMPIAD_DIPLOMA_PRICE')) define('OLYMPIAD_DIPLOMA_PRICE', 229);

// Publication Certificate Price
if (!defined('PUBLICATION_CERTIFICATE_PRICE')) define('PUBLICATION_CERTIFICATE_PRICE', 499);

// Автопродление подписок (Этап 2): параметры рекуррентных списаний.
//   MAX_ATTEMPTS  — сколько раз пытаемся списать в одном цикле, прежде чем дать подписке истечь.
//   LEAD_DAYS     — за сколько дней до expires_at начинаем списывать (activate стыкует период
//                   через GREATEST(expires_at, NOW()), поэтому дни не теряются).
//   NOTICE_DAYS   — за сколько дней до списания шлём письмо-предупреждение (>= LEAD_DAYS).
if (!defined('SUBSCRIPTION_RENEW_MAX_ATTEMPTS')) define('SUBSCRIPTION_RENEW_MAX_ATTEMPTS', (int)($_ENV['SUBSCRIPTION_RENEW_MAX_ATTEMPTS'] ?? 3));
if (!defined('SUBSCRIPTION_RENEW_LEAD_DAYS'))    define('SUBSCRIPTION_RENEW_LEAD_DAYS', (int)($_ENV['SUBSCRIPTION_RENEW_LEAD_DAYS'] ?? 1));
if (!defined('SUBSCRIPTION_RENEW_NOTICE_DAYS'))  define('SUBSCRIPTION_RENEW_NOTICE_DAYS', (int)($_ENV['SUBSCRIPTION_RENEW_NOTICE_DAYS'] ?? 3));
// Глобальный выключатель автопродления. Магазин YooKassa должен быть ПОДКЛЮЧЁН к
// рекуррентным платежам — иначе save_payment_method:true даёт 403 forbidden и платёж
// не создаётся вовсе. Пока рекуррент не включён менеджером YooMoney — держим false:
// чекбокс/обещание автопродления скрыты в UI, карта не сохраняется, подписка разовая.
// Когда YooMoney включит рекуррент — выставить SUBSCRIPTION_AUTORENEW_ENABLED=true в .env.
if (!defined('SUBSCRIPTION_AUTORENEW_ENABLED')) define('SUBSCRIPTION_AUTORENEW_ENABLED', filter_var($_ENV['SUBSCRIPTION_AUTORENEW_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOLEAN));

// Групповое участие: лимиты и прогрессивная скидка по размеру группы.
// Тарифы — массив {min, max, percent}; проценты легко менять здесь.
if (!defined('GROUP_MIN_PARTICIPANTS')) define('GROUP_MIN_PARTICIPANTS', 2);
if (!defined('GROUP_MAX_PARTICIPANTS')) define('GROUP_MAX_PARTICIPANTS', 30);
if (!defined('GROUP_DISCOUNT_TIERS')) define('GROUP_DISCOUNT_TIERS', json_encode([
    ['min' => 2,  'max' => 4,  'percent' => 10],
    ['min' => 5,  'max' => 9,  'percent' => 20],
    ['min' => 10, 'max' => 30, 'percent' => 30],
]));

// File Upload Paths
if (!defined('UPLOADS_DIR')) define('UPLOADS_DIR', BASE_PATH . '/uploads/diplomas/');
if (!defined('TEMPLATES_DIR')) define('TEMPLATES_DIR', BASE_PATH . '/assets/images/diplomas/templates/');
if (!defined('THUMBNAILS_DIR')) define('THUMBNAILS_DIR', BASE_PATH . '/assets/images/diplomas/thumbnails/');

// Ensure upload directories exist
if (!file_exists(UPLOADS_DIR)) {
    mkdir(UPLOADS_DIR, 0755, true);
}
if (!file_exists(TEMPLATES_DIR)) {
    mkdir(TEMPLATES_DIR, 0755, true);
}
if (!file_exists(THUMBNAILS_DIR)) {
    mkdir(THUMBNAILS_DIR, 0755, true);
}

// Session Security Settings (only if session not started)
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Strict');
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        ini_set('session.cookie_secure', 1);
    }
    ini_set('session.use_strict_mode', 1);
}

// Error Reporting (disable in production)
if (YOOKASSA_MODE === 'sandbox') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', BASE_PATH . '/logs/error.log');
}

// Timezone
date_default_timezone_set('Europe/Moscow');

// A/B-тест цен курсов (отключён, победил вариант C — зафиксирована скидка 55%)
if (!defined('COURSE_AB_TEST_ACTIVE')) define('COURSE_AB_TEST_ACTIVE', false);
if (!defined('COURSE_AB_TEST_SECRET')) define('COURSE_AB_TEST_SECRET', $_ENV['COURSE_AB_TEST_SECRET'] ?? '');
if (!defined('COURSE_AB_TEST_COOKIE')) define('COURSE_AB_TEST_COOKIE', 'cab_v');
if (!defined('COURSE_FIXED_DISCOUNT')) define('COURSE_FIXED_DISCOUNT', 55); // фолбэк, если program_type неизвестен
if (!defined('COURSE_FIXED_DISCOUNT_KPK')) define('COURSE_FIXED_DISCOUNT_KPK', 55); // повышение квалификации
if (!defined('COURSE_FIXED_DISCOUNT_PP')) define('COURSE_FIXED_DISCOUNT_PP', 10);   // переподготовка

// A/B-тест моделей оплаты: 'A' поштучно (control) vs 'B' только подписка (subscription).
// Выключен → весь трафик в 'A' (текущий сайт). Запустить тест: PRICING_AB_ENABLED=true в .env.
// Ключ .env назван иначе, чем константа, чтобы строка 'false' корректно парсилась в bool
// (общий загрузчик .env определил бы одноимённую константу как truthy-строку 'false').
if (!defined('PRICING_AB_ACTIVE')) define('PRICING_AB_ACTIVE', filter_var($_ENV['PRICING_AB_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOLEAN));
if (!defined('PRICING_AB_SECRET')) define('PRICING_AB_SECRET', $_ENV['PRICING_AB_SECRET'] ?? (defined('COURSE_AB_TEST_SECRET') ? COURSE_AB_TEST_SECRET : ''));
if (!defined('PRICING_AB_COOKIE')) define('PRICING_AB_COOKIE', 'pm_v');
// Граница эпохи теста (раунд 2): при перезапуске выставить PRICING_AB_EPOCH='YYYY-MM-DD' в .env —
// дашборд /admin/ab-test учитывает заказы только с этой даты, чтобы данные старого раунда (плохая
// B-корзина) не смешивались с новыми. Пусто → фильтра нет (поведение как раньше).
if (!defined('PRICING_AB_EPOCH')) define('PRICING_AB_EPOCH', trim((string)($_ENV['PRICING_AB_EPOCH'] ?? '')));

// E-mail трекинг
// Окно, в течение которого письмо считается причиной оплаты (дни)
if (!defined('EMAIL_ATTRIBUTION_WINDOW_DAYS')) define('EMAIL_ATTRIBUTION_WINDOW_DAYS', 7);
// Whitelist хостов для /api/email-track/click.php (защита от open-redirect)
if (!defined('EMAIL_TRACK_ALLOWED_HOSTS')) {
    $__siteHost = parse_url(SITE_URL, PHP_URL_HOST) ?: 'fgos.pro';
    define('EMAIL_TRACK_ALLOWED_HOSTS', [
        $__siteHost,
        'fgos.pro',
        'www.fgos.pro',
        'bizon365.ru',
        'bizon365.com',
        'yandex.ru',
        'kinescope.io',
        '2gis.ru',
        'vk.com', // кнопка «Поделиться ВКонтакте» в письмах о публикации
    ]);
}
