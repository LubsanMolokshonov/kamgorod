<?php
/**
 * Max CTA — карточка/блок с призывом написать менеджеру в Messenger Max.
 * Используется после оплаты курса и подачи заявки на рассрочку, чтобы ускорить
 * ручную выдачу доступов и согласование рассрочки.
 *
 * Использование:
 *   $maxCtaContext = 'installment' | 'payment' | 'cabinet-payment';
 *   $maxCtaVariant = 'block' | 'modal-body';  // optional, default 'block'
 *   include __DIR__ . '/../includes/partials/max-cta.php';
 */

if (!defined('MAX_MANAGER_URL')) {
    require_once __DIR__ . '/../../config/config.php';
}

$ctx = $maxCtaContext ?? 'payment';
$variant = $maxCtaVariant ?? 'block';

switch ($ctx) {
    case 'installment':
        $title = 'Заявка принята! Напишите менеджеру в Max — это ускорит согласование';
        $lead  = 'Менеджер согласует график платежей в рабочее время. Чтобы быстрее — напишите ему в Messenger Max прямо сейчас.';
        break;
    case 'cabinet-payment':
        $title = 'Напишите менеджеру в Max, чтобы быстрее получить доступ';
        $lead  = 'Доступы к материалам курса выдаёт менеджер вручную. Напишите ему в Max — так доступ откроют значительно быстрее.';
        break;
    case 'payment':
    default:
        $title = 'Оплата принята! Напишите менеджеру в Max для ускоренной выдачи доступа';
        $lead  = 'Менеджер выдаёт доступ к учебным материалам вручную. Чтобы получить его быстрее — напишите ему в Messenger Max.';
        break;
}

$url   = MAX_MANAGER_URL;
$phone = MAX_MANAGER_PHONE;
$h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
$wrapperClass = $variant === 'modal-body' ? 'max-cta max-cta--modal' : 'max-cta';
?>
<div class="<?= $h($wrapperClass) ?>">
    <div class="max-cta__body">
        <div class="max-cta__icon" aria-hidden="true">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M12 2C6.477 2 2 6.03 2 11c0 2.78 1.4 5.26 3.6 6.92L4.5 22l4.6-2.4c.93.21 1.9.32 2.9.32 5.523 0 10-4.03 10-9S17.523 2 12 2z" fill="currentColor"/>
            </svg>
        </div>
        <div class="max-cta__text">
            <h3 class="max-cta__title"><?= $h($title) ?></h3>
            <p class="max-cta__lead"><?= $h($lead) ?></p>
            <div class="max-cta__actions">
                <a href="<?= $h($url) ?>" class="max-cta__btn" target="_blank" rel="noopener">
                    Написать в Max
                </a>
                <div class="max-cta__phone">
                    <span class="max-cta__phone-label">или по номеру:</span>
                    <a href="tel:<?= $h(preg_replace('/[^0-9+]/', '', $phone)) ?>" class="max-cta__phone-number"><?= $h($phone) ?></a>
                </div>
            </div>
            <p class="max-cta__hint max-cta__hint--mobile">Откроется приложение Max, если оно установлено.</p>
        </div>
        <div class="max-cta__qr-wrap" aria-hidden="true">
            <img src="/assets/images/max-qr.png" alt="QR-код для Messenger Max" class="max-cta__qr" width="160">
            <p class="max-cta__qr-hint">Сканируйте телефоном,<br>если читаете с компьютера</p>
        </div>
    </div>
</div>
