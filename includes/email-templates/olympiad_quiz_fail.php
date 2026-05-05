<?php
/**
 * Олимпиада: неуспешное прохождение теста (<7 баллов)
 * Утешение + призыв попробовать другие олимпиады
 */
$footer_reason = 'прошли олимпиаду на нашем портале';
$utm = 'utm_source=email&utm_campaign=olympiad-quiz-fail';
ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo SITE_URL; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px;">
        </div>
        <h1>Спасибо за участие!</h1>
        <p>Результаты вашей олимпиады</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Вы прошли олимпиаду <strong>"<?php echo htmlspecialchars($olympiad_title); ?>"</strong> и набрали <strong><?php echo intval($score); ?> из 10 баллов</strong>.</p>

    <p>К сожалению, для получения призового места необходимо набрать минимум 7 баллов. Но не расстраивайтесь — у нас более <strong>700 олимпиад</strong> по разным темам!</p>

    <div class="competition-card">
        <span class="badge" style="background: #6b7280;">Ваш результат</span>
        <h3><?php echo htmlspecialchars($olympiad_title); ?></h3>
        <div class="competition-details">
            <p style="font-size: 20px; font-weight: bold; color: #6b7280; margin: 10px 0;"><?php echo intval($score); ?> из 10 баллов</p>
            <p style="color: #9ca3af;">Для призового места нужно 7+ баллов</p>
        </div>
    </div>

    <h3 style="color: #1e40af; margin-top: 25px; font-weight: 600;">Что можно сделать:</h3>

    <ul class="benefits-list">
        <li>Попробуйте олимпиады по другим темам — вопросы везде разные</li>
        <li>Каждая олимпиада — это возможность получить диплом</li>
        <li>Участвуйте в конкурсах — там не нужно проходить тест</li>
    </ul>

    <div class="text-center">
        <a href="<?php echo htmlspecialchars($site_url . '/olimpiady/?' . $utm); ?>" class="cta-button">
            Выбрать другую олимпиаду
        </a>

        <p style="margin-top: 15px;">
            <a href="<?php echo htmlspecialchars($site_url . '/konkursy/?' . $utm); ?>" style="color: #2563eb; text-decoration: none; font-weight: 500;">
                Посмотреть конкурсы &rarr;
            </a>
        </p>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';
