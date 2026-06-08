<?php
/**
 * Универсальный блок отзывов для страниц-деталок продуктов.
 *
 * Ожидает переменные (задать ПЕРЕД include):
 *   $reviewEntityType — competition|olympiad|webinar|course|publication|material
 *   $reviewEntityId   — int, id продукта
 *   $reviewStats      — ['avg'=>float, 'count'=>int]  (Review::getStats)
 *   $reviewList       — массив одобренных отзывов       (Review::getApproved)
 *
 * CSS/JS подключаются страницей через $additionalCSS / $additionalJS.
 * Для прелогина имени берёт $_SESSION['user_id'] → users.full_name (если есть).
 */

if (empty($reviewEntityType) || empty($reviewEntityId)) {
    return;
}

$rsAvg = (float)($reviewStats['avg'] ?? 0);
$rsCount = (int)($reviewStats['count'] ?? 0);
$rsList = is_array($reviewList ?? null) ? $reviewList : [];
$rsCsrf = function_exists('generateCSRFToken') ? generateCSRFToken() : '';

// Предзаполнение имени для залогиненного пользователя.
$rsUserName = '';
if (!empty($_SESSION['user_id']) && isset($db)) {
    try {
        $rsU = (new Database($db))->queryOne("SELECT full_name FROM users WHERE id = ?", [(int)$_SESSION['user_id']]);
        $rsUserName = $rsU ? trim((string)$rsU['full_name']) : '';
    } catch (Exception $e) {
        $rsUserName = '';
    }
}

// Средняя оценка — целое число полных звёзд для визуального индикатора.
$rsFullStars = $rsCount > 0 ? (int)round($rsAvg) : 0;

/** Отрисовать строку из 5 звёзд по числу заполненных. */
if (!function_exists('rsRenderStars')) {
    function rsRenderStars(int $filled): string {
        $out = '';
        for ($i = 1; $i <= 5; $i++) {
            $out .= '<span class="rs-star' . ($i <= $filled ? ' rs-star--on' : '') . '">★</span>';
        }
        return $out;
    }
}
?>
<section class="rs-section" id="reviews"
         data-entity-type="<?= htmlspecialchars($reviewEntityType, ENT_QUOTES, 'UTF-8') ?>"
         data-entity-id="<?= (int)$reviewEntityId ?>"
         data-ajax-url="/ajax/submit-review.php">
    <div class="rs-container">
        <div class="rs-head">
            <h2 class="rs-title">Отзывы</h2>
            <?php if ($rsCount > 0): ?>
                <div class="rs-summary">
                    <span class="rs-summary-value"><?= number_format($rsAvg, 1, ',', '') ?></span>
                    <span class="rs-stars rs-stars--summary"><?= rsRenderStars($rsFullStars) ?></span>
                    <span class="rs-summary-count"><?= $rsCount ?> <?=
                        // склонение «отзыв/отзыва/отзывов»
                        (function ($n) {
                            $n = abs($n) % 100; $n1 = $n % 10;
                            if ($n > 10 && $n < 20) return 'отзывов';
                            if ($n1 > 1 && $n1 < 5) return 'отзыва';
                            if ($n1 === 1) return 'отзыв';
                            return 'отзывов';
                        })($rsCount)
                    ?></span>
                </div>
            <?php else: ?>
                <p class="rs-empty">Пока нет отзывов. Будьте первым!</p>
            <?php endif; ?>
        </div>

        <!-- Форма отзыва -->
        <form class="rs-form" id="rs-form" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($rsCsrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="entity_type" value="<?= htmlspecialchars($reviewEntityType, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="entity_id" value="<?= (int)$reviewEntityId ?>">
            <input type="hidden" name="rating" id="rs-rating-input" value="0">
            <!-- honeypot: реальные пользователи это поле не видят -->
            <div class="rs-hp" aria-hidden="true">
                <label>Сайт<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
            </div>

            <div class="rs-field">
                <span class="rs-label">Ваша оценка</span>
                <div class="rs-rating-picker" id="rs-rating-picker" role="radiogroup" aria-label="Оценка от 1 до 5">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <button type="button" class="rs-rating-star" data-value="<?= $i ?>"
                                role="radio" aria-checked="false" aria-label="<?= $i ?> из 5">★</button>
                    <?php endfor; ?>
                </div>
            </div>

            <div class="rs-field">
                <label class="rs-label" for="rs-name">Ваше имя</label>
                <input type="text" id="rs-name" name="author_name" maxlength="120"
                       value="<?= htmlspecialchars($rsUserName, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="Как вас представить">
            </div>

            <div class="rs-field">
                <label class="rs-label" for="rs-text">Отзыв <span class="rs-optional">(необязательно)</span></label>
                <textarea id="rs-text" name="review_text" maxlength="2000" rows="4"
                          placeholder="Поделитесь впечатлением о продукте"></textarea>
            </div>

            <div class="rs-form-footer">
                <button type="submit" class="rs-submit" id="rs-submit">Отправить отзыв</button>
                <p class="rs-message" id="rs-message" role="status" aria-live="polite"></p>
            </div>
        </form>

        <!-- Список одобренных отзывов -->
        <?php
        $rsWithText = array_values(array_filter($rsList, fn($r) => trim((string)($r['review_text'] ?? '')) !== ''));
        if (!empty($rsWithText)):
        ?>
            <ul class="rs-list" id="rs-list">
                <?php foreach ($rsWithText as $idx => $r):
                    $hidden = $idx >= 5 ? ' rs-item--hidden' : '';
                    $rDate = !empty($r['created_at']) ? date('d.m.Y', strtotime($r['created_at'])) : '';
                ?>
                    <li class="rs-item<?= $hidden ?>">
                        <div class="rs-item-head">
                            <span class="rs-item-author"><?= htmlspecialchars($r['author_name'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="rs-stars rs-stars--item"><?= rsRenderStars((int)$r['rating']) ?></span>
                            <?php if ($rDate): ?><time class="rs-item-date"><?= $rDate ?></time><?php endif; ?>
                        </div>
                        <p class="rs-item-text"><?= nl2br(htmlspecialchars($r['review_text'], ENT_QUOTES, 'UTF-8')) ?></p>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if (count($rsWithText) > 5): ?>
                <button type="button" class="rs-more" id="rs-more">Показать ещё отзывы</button>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
