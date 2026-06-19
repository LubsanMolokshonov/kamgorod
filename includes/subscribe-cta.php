<?php
/**
 * Партиал CTA «оформить подписку» для варианта B (subscription-only).
 *
 * Показывается вместо блока поштучной оплаты не-подписчику, когда
 * PricingMode::isSubscriptionOnly() === true. Подписчик видит обычный путь «получить 0 ₽».
 *
 * Перед include можно задать (все опциональны):
 *   $ctaHeading — заголовок (по умолчанию универсальный про документы);
 *   $ctaText    — пояснение;
 *   $ctaButton  — текст кнопки;
 *   $ctaReturn  — URL для возврата после оформления (добавляется как ?from=).
 */
$ctaHeading = $ctaHeading ?? 'Документы — по подписке';
$ctaText    = $ctaText    ?? 'Оформите подписку и получайте все дипломы, сертификаты и свидетельства для портфолио без доплат.';
$ctaButton  = $ctaButton  ?? 'Оформить подписку';
$ctaHref    = '/podpiska/';
if (!empty($ctaReturn)) {
    $ctaHref .= '?from=' . rawurlencode($ctaReturn);
}
?>
<div class="subonly-cta" style="border:1px solid #d8d2f7;background:linear-gradient(180deg,#f7f5ff,#fff);border-radius:16px;padding:22px 24px;margin:18px 0;">
  <div style="display:flex;align-items:flex-start;gap:14px;flex-wrap:wrap;">
    <div style="font-size:30px;line-height:1;">⭐</div>
    <div style="flex:1;min-width:220px;">
      <div style="font-size:18px;font-weight:700;color:#1c2033;margin-bottom:6px;">
        <?php echo htmlspecialchars($ctaHeading, ENT_QUOTES, 'UTF-8'); ?>
      </div>
      <div style="color:#5b6178;font-size:15px;line-height:1.5;">
        <?php echo htmlspecialchars($ctaText, ENT_QUOTES, 'UTF-8'); ?>
      </div>
    </div>
    <a href="<?php echo htmlspecialchars($ctaHref, ENT_QUOTES, 'UTF-8'); ?>"
       style="display:inline-block;background:#6c5ce7;color:#fff;font-weight:700;font-size:16px;text-decoration:none;border-radius:12px;padding:13px 22px;white-space:nowrap;">
      <?php echo htmlspecialchars($ctaButton, ENT_QUOTES, 'UTF-8'); ?>
    </a>
  </div>
</div>
<?php
// Сбрасываем, чтобы повторный include на странице не унаследовал прежние тексты.
unset($ctaHeading, $ctaText, $ctaButton, $ctaReturn, $ctaHref);
?>
