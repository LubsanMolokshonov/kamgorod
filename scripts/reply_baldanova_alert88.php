<?php
/**
 * Разовый ответ Балдановой Б.Х. по alert #88 — невидимые дипломы «Времена года».
 * Причина: квиз был пройден под inbox.ru (user 5920), регистрация и оплата — под yandex.ru (user 5921).
 * Результат 2845 перепривязан к 5921; письмо отправляем с magic-link на /kabinet/.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
set_time_limit(0);

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/includes/magic-link-helper.php';
require_once BASE_PATH . '/classes/EmailDispatcher.php';

$toEmail = 'baldanova.bazhigma@yandex.ru';
$toName  = 'Бажигма Хубисхаловна';
$userId  = 5921;

$magicUrl = generateMagicUrl($userId, '/kabinet/', 14, [
    'utm_source'   => 'support',
    'utm_medium'   => 'email',
    'utm_campaign' => 'alert_reply',
    'utm_content'  => 'alert_88',
]);

$subject = 'Дипломы по олимпиаде «Времена года» — доступны в вашем кабинете';

$html = '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"></head>'
    . '<body style="font-family:Arial,Helvetica,sans-serif;line-height:1.6;color:#333;font-size:15px;max-width:640px;margin:0 auto;padding:16px;">'
    . '<p>Здравствуйте, Бажигма Хубисхаловна!</p>'
    . '<p>Спасибо за сообщение через форму обратной связи — разобрались с дипломами Тамира по олимпиаде «Времена года».</p>'
    . '<p><strong>Что произошло:</strong> олимпиаду Тамир проходил, когда был залогинен под адресом <strong>baldanova.bazhigma@inbox.ru</strong>, а диплом оплачивали уже с другого аккаунта — <strong>baldanova.bazhigma@yandex.ru</strong>. Из-за этого карточка «Времена года» (диплом участника + ваш диплом руководителя) была привязана к первому email и в кабинете под yandex.ru не показывалась. Виден был только диплом по «Миру вокруг нас».</p>'
    . '<p><strong>Что мы сделали:</strong> объединили данные. Теперь в одном кабинете под yandex.ru доступны все три диплома:</p>'
    . '<ul>'
    . '<li>Олимпиада «Времена года» — диплом участника (Тамир, 1 место)</li>'
    . '<li>Олимпиада «Времена года» — диплом руководителя (на ваше имя)</li>'
    . '<li>Олимпиада «Мир вокруг нас» — диплом участника (Тамир, 1 место)</li>'
    . '</ul>'
    . '<p>Чтобы быстро их скачать, нажмите кнопку ниже — вход в личный кабинет будет автоматическим, без пароля:</p>'
    . '<p style="text-align:center;margin:28px 0;">'
    . '<a href="' . htmlspecialchars($magicUrl, ENT_QUOTES, 'UTF-8') . '" '
    . 'style="display:inline-block;background:#667eea;color:#fff;text-decoration:none;'
    . 'padding:14px 28px;border-radius:8px;font-weight:600;font-size:16px;">'
    . 'Открыть мой кабинет и скачать дипломы'
    . '</a>'
    . '</p>'
    . '<p style="font-size:13px;color:#666;">Ссылка действует 14 дней и работает только для вашего адреса. Если кнопка не открывается, скопируйте адрес в браузер:<br>'
    . '<span style="word-break:break-all;color:#667eea;">' . htmlspecialchars($magicUrl, ENT_QUOTES, 'UTF-8') . '</span></p>'
    . '<p>Если что-то ещё не отображается или возникнут вопросы — просто ответьте на это письмо, мы поможем.</p>'
    . '<p>С уважением,<br>служба поддержки педпортала «Каменный город»<br>'
    . '<a href="' . SITE_URL . '" style="color:#667eea;">fgos.pro</a></p>'
    . '</body></html>';

try {
    $result = EmailDispatcher::send([
        'to_email' => $toEmail,
        'to_name'  => $toName,
        'subject'  => $subject,
        'html'     => $html,
        'reply_to' => defined('SUPPORT_EMAIL') ? SUPPORT_EMAIL : 'info@fgos.pro',
        'reply_to_name' => 'Поддержка fgos.pro',
        'meta' => [
            'email_type'      => 'other',
            'touchpoint_code' => 'support_reply_alert_88',
            'user_id'         => $userId,
        ],
    ]);
    echo "[OK] sent to {$toEmail}, message_id=" . ($result['message_id'] ?? '-') . "\n";
} catch (Throwable $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    exit(1);
}
