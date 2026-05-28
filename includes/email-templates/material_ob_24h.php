<?php
/**
 * Онбординг, 24 часа: показываем конкретный сценарий — техкарта за 30 секунд.
 * Переменные: $user_name, $balance, $generator_url, $_sender_name,
 *             $unsubscribe_url, $site_url, $footer_reason
 */
$senderName = $_sender_name ?? 'Команда ФГОС-Практикум';
ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo logo-text">ФГОС-Практикум</div>
        <h1>Техкарта урока за 30 секунд</h1>
        <p>Этапы, цели, УУД и деятельность — по требованиям ФГОС</p>
    </div>
</div>
<div class="email-content">
    <p class="greeting">Здравствуйте, <?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?>!</p>
    <p>
        Самый частый запрос педагогов — технологическая карта урока. В генераторе это три шага:
    </p>
    <ul class="benefits-list">
        <li>Указываете предмет, класс и тему урока</li>
        <li>Выбираете длительность и особенности группы</li>
        <li>Получаете готовый файл DOCX — остаётся только распечатать</li>
    </ul>

    <div class="info-card">
        <div class="info-card-content">
            <h4>На вашем счёте <?= (int)$balance ?> токенов</h4>
            <p>Этого достаточно, чтобы прямо сейчас собрать несколько материалов к ближайшим урокам.</p>
        </div>
    </div>

    <div class="text-center">
        <a href="<?= htmlspecialchars($generator_url, ENT_QUOTES, 'UTF-8') ?>" class="cta-button">
            Сделать техкарту →
        </a>
    </div>

    <p class="text-muted text-small">
        С уважением, <?= htmlspecialchars($senderName, ENT_QUOTES, 'UTF-8') ?>, ФГОС-Практикум.
    </p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';
