<?php
if (php_sapi_name() !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(403); die('CLI only'); }
/** Общий рендер карточек: серверная страница и AJAX используют одну разметку. */
require_once __DIR__ . '/url-helper.php';
require_once __DIR__ . '/../classes/Course.php';
require_once __DIR__ . '/../classes/CoursePriceAB.php';
require_once __DIR__ . '/../classes/Olympiad.php';
require_once __DIR__ . '/installment-helper.php';
function renderCatalogCards(string $section, array $items): string {
    $abVariant = $section === 'kursy' ? CoursePriceAB::getVariant() : null;
    ob_start();
?>
<?php if ($section === 'kursy'): $courses = $items; ?>
<?php foreach ($courses as $course):
                $basePrice = (float)$course['price'];
                $coursePT = $course['program_type'] ?? null;
                $abPrice = CoursePriceAB::getAdjustedPrice($basePrice, $abVariant, $coursePT);
                $itemDiscountPercent = CoursePriceAB::getDiscountPercent($abVariant, $coursePT);
                $ptLabel = Course::getProgramTypeLabel($course['program_type']);
                $hoursLabel = Course::formatHours($course['hours']);
                $cardUrl = getCourseUrl($course['slug'], $course['id']);
            ?>
              <<?= $cardUrl ? 'a' : 'div' ?> class="rd-card" <?= $cardUrl ? 'href="' . htmlspecialchars($cardUrl, ENT_QUOTES, 'UTF-8') . '"' : '' ?> data-course-id="<?php echo $course['id']; ?>">
                <div class="rd-card-pat"></div>
                <div class="rd-card-tags">
                  <span class="rd-tag indigo"><?php echo htmlspecialchars($ptLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                  <span class="rd-tag"><?php echo htmlspecialchars($hoursLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <h4><?php echo htmlspecialchars($course['title'], ENT_QUOTES, 'UTF-8'); ?></h4>
                <div class="rd-card-meta">
                  <?php echo htmlspecialchars(mb_substr(strip_tags($course['description'] ?? ''), 0, 120), ENT_QUOTES, 'UTF-8'); ?>…
                </div>
                <?php $installment = calculateInstallment($abPrice); ?>
                <div class="rd-card-foot">
                  <div class="rd-card-price-block">
                    <div class="rd-price-now">
                      <?php if ($itemDiscountPercent > 0): ?>
                        <span class="rd-price-old"><?php echo number_format($basePrice, 0, ',', ' '); ?> ₽</span><?php echo number_format($abPrice, 0, ',', ' '); ?> ₽
                      <?php else: ?>
                        <?php echo number_format($abPrice, 0, ',', ' '); ?> ₽
                      <?php endif; ?>
                    </div>
                    <?php if ($installment['available']): ?>
                      <div class="rd-price-installment">
                        <span class="rd-price-prefix">от</span><strong><?php echo formatRub($installment['monthly']); ?>/мес</strong>
                        <span class="rd-installment-badge">рассрочка 0%</span>
                      </div>
                    <?php endif; ?>
                  </div>
                  <span class="rd-join-btn">К программе</span>
                </div>
              </<?= $cardUrl ? 'a' : 'div' ?>>
            <?php endforeach; ?>
<?php endif; ?>
<?php if ($section === 'olimpiady'): $olympiads = $items; ?>
<?php foreach ($olympiads as $olympiad):
                $audLabel = Olympiad::getAudienceLabel($olympiad['target_audience'] ?? '');
                $oUrl     = buildProductUrl('olimpiady', $olympiad['slug'], $olympiad['id']);
                $oPrice   = (int)($olympiad['diploma_price'] ?? 229);
            ?>
              <<?= $oUrl ? 'a' : 'div' ?> class="rd-card" data-product-id="<?= (int)$olympiad['id'] ?>" <?= $oUrl ? 'href="' . htmlspecialchars($oUrl, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
                <div class="rd-card-pat"></div>
                <div class="rd-card-tags">
                  <?php if (!empty($audLabel)): ?>
                    <span class="rd-tag indigo"><?php echo htmlspecialchars($audLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                  <?php endif; ?>
                  <?php if (!empty($olympiad['subject'])): ?>
                    <span class="rd-tag"><?php echo htmlspecialchars($olympiad['subject'], ENT_QUOTES, 'UTF-8'); ?></span>
                  <?php endif; ?>
                </div>
                <h4><?php echo htmlspecialchars($olympiad['title'], ENT_QUOTES, 'UTF-8'); ?></h4>
                <div class="rd-card-meta">
                  <?php echo htmlspecialchars(mb_substr(strip_tags($olympiad['description'] ?? ''), 0, 120), ENT_QUOTES, 'UTF-8'); ?>…
                </div>
                <div class="rd-card-foot">
                  <div class="rd-price-now">Бесплатное участие</div>
                  <span class="rd-join-btn">Пройти →</span>
                </div>
              </<?= $oUrl ? 'a' : 'div' ?>>
            <?php endforeach; ?>
<?php endif; ?>
<?php if ($section === 'publikacii'): $publications = $items; ?>
<?php foreach ($publications as $pub):
                $cardUrl = buildProductUrl(($pub['source'] ?? '') === 'blog' ? 'blog' : 'publikaciya', $pub['slug'], $pub['id']);
                $pubDate = date('d.m.Y', strtotime($pub['published_at'] ?? $pub['created_at']));
            ?>
              <<?= $cardUrl ? 'a' : 'div' ?> class="rd-card" data-product-id="<?= (int)$pub['id'] ?>" <?= $cardUrl ? 'href="' . htmlspecialchars($cardUrl, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
                <div class="rd-card-pat"></div>
                <?php if (!empty($pub['type_name'])): ?>
                <div class="rd-card-tags">
                  <span class="rd-tag indigo"><?php echo htmlspecialchars($pub['type_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <?php endif; ?>
                <h4><?php echo htmlspecialchars($pub['title'], ENT_QUOTES, 'UTF-8'); ?></h4>
                <div class="rd-card-meta">
                  <?php if (!empty($pub['author_name'])): ?>
                    <?php echo htmlspecialchars($pub['author_name'], ENT_QUOTES, 'UTF-8'); ?> · <?php echo $pubDate; ?>
                  <?php else: ?>
                    <?php echo $pubDate; ?>
                  <?php endif; ?>
                  <?php if (!empty($pub['annotation'])): ?>
                    <br><?php echo htmlspecialchars(mb_substr($pub['annotation'], 0, 120), ENT_QUOTES, 'UTF-8'); ?><?php echo mb_strlen($pub['annotation']) > 120 ? '…' : ''; ?>
                  <?php endif; ?>
                </div>
                <div class="rd-card-foot">
                  <span style="font-size:13px;color:var(--ink-500);display:flex;gap:10px;align-items:center;">
                    <?php if ((int)($pub['rating_count'] ?? 0) > 0): ?>
                      <span class="rd-card-rating">★ <?php echo number_format((float)$pub['rating_avg'], 1, '.', ''); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($pub['views_count']) && $pub['views_count'] > 0): ?>
                      <span>👁 <?php echo (int)$pub['views_count']; ?></span>
                    <?php endif; ?>
                  </span>
                  <span class="rd-join-btn">Читать</span>
                </div>
              </<?= $cardUrl ? 'a' : 'div' ?>>
            <?php endforeach; ?>
<?php endif; ?>
<?php
    return ob_get_clean();
}
