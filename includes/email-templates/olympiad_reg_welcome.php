<?php
/**
 * Олимпиада: сразу после регистрации (до теста)
 * Приветствие + призыв начать тест
 */
$footer_reason = 'зарегистрировались на олимпиаду на нашем портале';
ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo SITE_URL; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px;">
        </div>
        <h1>Добро пожаловать на олимпиаду!</h1>
        <p>Проверьте свои знания прямо сейчас</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Вы зарегистрировались на олимпиаду <strong>"<?php echo htmlspecialchars($olympiad_title); ?>"</strong>. Отличный выбор!</p>

    <div class="competition-card">
        <span class="badge" style="background: #2563eb;">Олимпиада</span>
        <h3><?php echo htmlspecialchars($olympiad_title); ?></h3>
        <div class="competition-details">
            <p><strong>Формат:</strong> 10 вопросов с вариантами ответов</p>
            <p><strong>Время:</strong> не ограничено</p>
            <p><strong>Результат:</strong> сразу после прохождения</p>
        </div>
    </div>

    <h3 style="color: #1e40af; margin-top: 25px; font-weight: 600;">Как это работает:</h3>

    <ul class="benefits-list">
        <li>Ответьте на 10 вопросов по теме олимпиады</li>
        <li>Узнайте свой результат мгновенно</li>
        <li>При 7+ правильных ответах — получите призовое место</li>
        <li>Оформите официальный диплом олимпиады</li>
    </ul>

    <div class="text-center">
        <a href="<?php echo htmlspecialchars($olympiad_url); ?>" class="cta-button">
            Начать олимпиаду
        </a>
    </div>

    <p class="text-muted text-small" style="margin-top: 30px;">
        Тест можно пройти в любое удобное время. Удачи!
    </p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';
