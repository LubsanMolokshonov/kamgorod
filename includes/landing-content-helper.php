<?php
/**
 * Контент посадочных страниц: уникальный SEO-текст и витрина отзывов.
 *
 * Данные готовятся офлайн-скриптом scripts/seed-landing-content.php и хранятся в
 * таблицах landing_seo_content / landing_reviews (см. миграцию 160). Ключ — page_key
 * (canonical-путь без домена и хвостового слэша, напр. 'kursy/perepodgotovka/matematika').
 *
 * Аватар отзыва — инициалы в цветном кружке (фото не храним): цвет детерминирован
 * из имени, поэтому один автор всегда одного цвета.
 */

require_once __DIR__ . '/review-schema-helper.php';

if (!function_exists('landingPageKey')) {
    /** Нормализует путь в page_key: без ведущего/хвостового слэша, нижний регистр. */
    function landingPageKey(string $path): string {
        return trim(strtolower($path), '/');
    }
}

if (!function_exists('getLandingSeoHtml')) {
    /** Уникальный SEO-текст посадочной (безопасный HTML) или null. */
    function getLandingSeoHtml($db, string $pageKey): ?string {
        try {
            $row = (new Database($db))->queryOne(
                "SELECT seo_html FROM landing_seo_content WHERE page_key = ?",
                [$pageKey]
            );
        } catch (Exception $e) {
            return null;
        }
        if (!$row || trim((string)$row['seo_html']) === '') {
            return null;
        }
        // Текст генерируем сами, но на выводе защищаемся в глубину: оставляем только
        // безопасный набор тегов И срезаем ВСЕ атрибуты (on*, style, href и т.п.) —
        // на случай галлюцинации ИИ-генератора или ручной правки в БД.
        $html = strip_tags($row['seo_html'], '<p><br><ul><ol><li><strong><em><h2><h3>');
        $html = preg_replace('/<(\/?)(p|br|ul|ol|li|strong|em|h2|h3)(\s[^>]*)?>/i', '<$1$2>', $html);
        return $html;
    }
}

if (!function_exists('getLandingReviews')) {
    /** Витрина отзывов посадочной (до $limit шт), отсортированы по display_order. */
    function getLandingReviews($db, string $pageKey, int $limit = 12): array {
        try {
            $rows = (new Database($db))->query(
                "SELECT author_name, rating, review_text, review_date
                 FROM landing_reviews WHERE page_key = ?
                 ORDER BY display_order ASC, id ASC
                 LIMIT " . (int)$limit,
                [$pageKey]
            );
        } catch (Exception $e) {
            return [];
        }
        return is_array($rows) ? $rows : [];
    }
}

if (!function_exists('landingReviewsAggregate')) {
    /** ['avg'=>float, 'count'=>int] по витрине отзывов. */
    function landingReviewsAggregate(array $reviews): array {
        $count = count($reviews);
        if ($count === 0) {
            return ['avg' => 0.0, 'count' => 0];
        }
        $sum = 0;
        foreach ($reviews as $r) {
            $sum += (int)($r['rating'] ?? 5);
        }
        return ['avg' => round($sum / $count, 1), 'count' => $count];
    }
}

if (!function_exists('buildLandingReviewsProductJsonLd')) {
    /**
     * Единый Product-узел посадочной с реальными отзывами витрины.
     * Используется ВМЕСТО generic buildListingSchema, когда витрина есть, —
     * чтобы на странице был один Product, а aggregateRating/review были уникальны.
     */
    function buildLandingReviewsProductJsonLd(
        string $name, string $description, string $image, string $brand, array $reviews
    ): array {
        $agg = landingReviewsAggregate($reviews);
        // review_date → created_at для buildReviewNodes.
        $forNodes = array_map(function ($r) {
            return [
                'author_name' => $r['author_name'] ?? '',
                'rating'      => $r['rating'] ?? 5,
                'review_text' => $r['review_text'] ?? '',
                'created_at'  => $r['review_date'] ?? null,
            ];
        }, $reviews);
        $node = [
            '@context'        => 'https://schema.org',
            '@type'           => 'Product',
            'image'           => $image,
            'name'            => $name,
            'description'     => $description,
            'brand'           => $brand,
            'aggregateRating' => [
                '@type'       => 'AggregateRating',
                'bestRating'  => '5',
                'worstRating' => '1',
                'ratingValue' => number_format($agg['avg'], 1, '.', ''),
                'ratingCount' => $agg['count'],
            ],
        ];
        $reviewNodes = buildReviewNodes($forNodes, 12);
        if (!empty($reviewNodes)) {
            $node['review'] = $reviewNodes;
        }
        return $node;
    }
}

if (!function_exists('landingAvatar')) {
    /** Инициалы + цвет фона для аватара автора отзыва (детерминированно по имени). */
    function landingAvatar(string $name): array {
        $palette = ['#2563eb', '#7c3aed', '#db2777', '#059669', '#d97706', '#dc2626', '#0891b2', '#4f46e5'];
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
        $initials = '';
        foreach ($parts as $p) {
            $initials .= mb_strtoupper(mb_substr($p, 0, 1, 'UTF-8'), 'UTF-8');
            if (mb_strlen($initials, 'UTF-8') >= 2) break;
        }
        if ($initials === '') $initials = '—';
        return [
            'initials' => $initials,
            'color'    => $palette[crc32($name) % count($palette)],
        ];
    }
}

if (!function_exists('renderLandingReviews')) {
    /**
     * Видимый блок витрины отзывов (аватар-инициалы + имя + звёзды + дата + текст).
     * @param array  $reviews строки landing_reviews
     * @param string $title   заголовок секции, напр. «Отзывы о курсах по математике»
     */
    function renderLandingReviews(array $reviews, string $title): void {
        if (empty($reviews)) {
            return;
        }
        $agg = landingReviewsAggregate($reviews);
        $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $stars = function (int $filled): string {
            $out = '';
            for ($i = 1; $i <= 5; $i++) {
                $out .= '<span class="lr-star' . ($i <= $filled ? ' lr-star--on' : '') . '">★</span>';
            }
            return $out;
        };
        ?>
        <section class="lr-section">
          <div class="rd-wrap">
            <div class="lr-head">
              <h2 class="lr-title"><?= $esc($title) ?></h2>
              <div class="lr-summary">
                <span class="lr-summary-value"><?= number_format($agg['avg'], 1, ',', '') ?></span>
                <span class="lr-stars"><?= $stars((int)round($agg['avg'])) ?></span>
                <span class="lr-summary-count"><?= (int)$agg['count'] ?> отзывов</span>
              </div>
            </div>
            <div class="lr-grid">
              <?php foreach ($reviews as $idx => $r):
                  $av = landingAvatar((string)($r['author_name'] ?? ''));
                  $date = !empty($r['review_date']) ? date('d.m.Y', strtotime($r['review_date'])) : '';
                  $hidden = $idx >= 6 ? ' lr-card--hidden' : '';
              ?>
                <article class="lr-card<?= $hidden ?>">
                  <div class="lr-card-head">
                    <span class="lr-avatar" style="background:<?= $esc($av['color']) ?>"><?= $esc($av['initials']) ?></span>
                    <div class="lr-meta">
                      <span class="lr-author"><?= $esc($r['author_name']) ?></span>
                      <span class="lr-stars lr-stars--card"><?= $stars((int)($r['rating'] ?? 5)) ?></span>
                    </div>
                    <?php if ($date): ?><time class="lr-date"><?= $esc($date) ?></time><?php endif; ?>
                  </div>
                  <p class="lr-text"><?= nl2br($esc($r['review_text'])) ?></p>
                </article>
              <?php endforeach; ?>
            </div>
            <?php if (count($reviews) > 6): ?>
              <button type="button" class="lr-more" data-lr-more>Показать ещё отзывы</button>
            <?php endif; ?>
          </div>
        </section>
        <?php
    }
}
