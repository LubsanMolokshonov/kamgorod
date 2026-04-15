<?php
/**
 * Олимпиада: 1 час после регистрации — не начал тест
 * Напоминание пройти тест
 */
$footer_reason = 'зарегистрировались на олимпиаду на нашем портале';
$utm = 'utm_source=email&utm_campaign=olympiad-reg-1h';
ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo SITE_URL; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px;">
        </div>
        <h1>Олимпиада ждёт вас!</h1>
        <p>Вы ещё не начали тест</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Вы зарегистрировались на олимпиаду <strong>"<?php echo htmlspecialchars($olympiad_title); ?>"</strong>, но ещё не начали тест.</p>

    <p>Это займёт всего <strong>5–10 минут</strong> — 10 вопросов с вариантами ответов, без ограничения по времени.</p>

    <div class="competition-card">
        <span class="badge badge-orange">Ожидает прохождения</span>
        <h3><?php echo htmlspecialchars($olympiad_title); ?></h3>
        <div class="competition-details">
            <p>Наберите 7+ баллов и получите призовое место с официальным дипломом!</p>
        </div>
    </div>

    <div class="text-center">
        <?php $oly_link = $olympiad_url . (strpos($olympiad_url, '?') !== false ? '&' : '?') . $utm; ?>
        <a href="<?php echo htmlspecialchars($oly_link); ?>" class="cta-button">
            Пройти тест сейчас
        </a>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';
