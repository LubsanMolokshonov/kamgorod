<?php
/**
 * Реактивация «молчащих» пользователей — личный тон.
 *
 * Subject формируется в SilentReengagementCampaign::sendOne() (без слов «скидка», «специально»).
 *
 * Переменные:
 *   $user_name, $site_url, $unsubscribe_url, $magic_login_url
 *   $discount_percent, $discount_expires_label
 *   $primary_cta_url, $primary_cta_label
 *   $segment_code (для UTM)
 *   $headline, $intro_text
 *   $recommendations: [ ['title', 'description', 'url', ...], ... ]
 */
$footer_reason   = $footer_reason ?? 'когда-то регистрировались на fgos.pro';
$sender_signature = $sender_signature ?? 'Анна, ФГОС-Практикум';

$utm = '?utm_source=email&utm_medium=campaign&utm_campaign=silent_reengagement_10&utm_content=' . urlencode($segment_code ?? 'na');
$cta_link = ($magic_login_url ?? $site_url) . (strpos(($magic_login_url ?? $site_url), '?') !== false ? '&' : '?')
            . 'utm_source=email&utm_medium=campaign&utm_campaign=silent_reengagement_10&utm_content=' . urlencode($segment_code ?? 'na');

ob_start();
?>
<p>Здравствуйте, <?php echo htmlspecialchars($user_name ?: 'коллега'); ?>.</p>

<p>Давно не виделись. Пишу уточнить — fgos.pro вам ещё актуален? У вас остался личный кабинет с заявками и прошлыми материалами.</p>

<?php if (!empty($intro_text)): ?>
<p><?php echo htmlspecialchars($intro_text); ?></p>
<?php endif; ?>

<p>До <?php echo htmlspecialchars($discount_expires_label); ?> при оформлении любой заявки в личном кабинете цена для вас будет ниже на <?php echo (int)$discount_percent; ?>%. Условие применится автоматически в корзине, ничего вводить не нужно.</p>

<p><a href="<?php echo htmlspecialchars($cta_link); ?>">Войти в личный кабинет</a></p>

<?php if (!empty($recommendations)): ?>
<p>Несколько материалов, которые могут пригодиться:</p>
<ul>
<?php foreach ($recommendations as $rec): ?>
    <li>
        <a href="<?php echo htmlspecialchars($rec['url'] . $utm); ?>"><?php echo htmlspecialchars($rec['title']); ?></a>
        <?php if (!empty($rec['description'])): ?>
            — <?php echo htmlspecialchars(mb_substr($rec['description'], 0, 140)); ?>
        <?php endif; ?>
    </li>
<?php endforeach; ?>
</ul>
<?php endif; ?>

<p>Если уже неинтересно — просто отпишитесь по ссылке ниже, я пойму. Если решите вернуться — буду рад.</p>
<?php
$content = ob_get_clean();
include __DIR__ . '/_personal_layout.php';
