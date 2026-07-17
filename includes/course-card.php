<?php
/**
 * Карточка рекомендуемого курса в теле статьи/материала.
 *
 * Два варианта: 'inline' (компактная, вставляется в текст) и 'expanded'
 * (развёрнутая, под текстом). Используется в pages/publication.php и
 * pages/material-detail.php.
 *
 * Кнопка консультации открывает единственную на страницу модалку
 * (renderCourseCardModal), работает через делегирование по data-атрибутам —
 * поэтому карточка не содержит ни одного id и её можно рендерить дважды.
 */

require_once __DIR__ . '/../classes/Course.php';
require_once __DIR__ . '/../classes/CoursePriceAB.php';
require_once __DIR__ . '/installment-helper.php';

/** Учебная нагрузка в месяц, из которой считается срок обучения */
const CC_HOURS_PER_MONTH = 72;

/** Тело короче — инлайн-карточку не вставляем, иначе она длиннее текста */
const CC_MIN_BODY_CHARS = 800;

/** Насколько заголовок может отстоять от середины, чтобы встать на его границе */
const CC_HEADING_TOLERANCE = 0.2;

/** Минимальная доля текста после карточки — иначе она упрётся в нижний блок */
const CC_TAIL_MIN_SHARE = 0.15;

/** Минимальная доля текста до карточки — иначе она окажется в самом начале */
const CC_HEAD_MIN_SHARE = 0.25;

/**
 * Обогащает ряд courses всем, что нужно карточке.
 *
 * @param array $course Ряд courses: id, slug, title, hours, price, program_type
 * @param PDO   $pdo
 * @return array|null null, если курс пустой или без slug
 */
function buildCourseCardData(array $course, $pdo): ?array
{
    if (empty($course['id']) || empty($course['slug'])) {
        return null;
    }

    $programType = $course['program_type'] ?? 'kpk';
    $isPp        = $programType === 'pp';
    $hours       = (int)($course['hours'] ?? 0);
    $basePrice   = (float)($course['price'] ?? 0);

    $variant    = CoursePriceAB::getVariant();
    $finalPrice = CoursePriceAB::getAdjustedPrice($basePrice, $variant, $programType);
    $discount   = CoursePriceAB::getDiscountPercent($variant, $programType);

    // Эксперты редко меняются в рамках запроса, а карточек на странице до двух
    static $expertCache = [];
    $courseId = (int)$course['id'];
    if (!array_key_exists($courseId, $expertCache)) {
        $experts = (new Course($pdo))->getExperts($courseId);
        $expertCache[$courseId] = $experts[0] ?? null;
    }

    return [
        'id'               => $courseId,
        'slug'             => $course['slug'],
        'title'            => $course['title'] ?? '',
        'url'              => '/kursy/' . urlencode($course['slug']) . '/',
        'hours'            => $hours,
        'hours_label'      => $hours > 0 ? Course::formatHours($hours) : '',
        'months_label'     => $hours > 0 ? ccFormatMonths(ccMonthsFromHours($hours)) : '',
        'program_type'     => $programType,
        'program_label'    => Course::getProgramTypeLabel($programType),
        'credential'       => $isPp
            ? 'Диплом о профессиональной переподготовке'
            : 'Удостоверение о повышении квалификации',
        'qualification'    => $isPp
            ? 'Присваивается новая квалификация'
            : 'Подтверждение имеющейся квалификации',
        'base_price'       => (int)round($basePrice),
        'final_price'      => (int)round($finalPrice),
        'has_discount'     => $discount > 0 && $finalPrice < $basePrice,
        'discount_percent' => $discount,
        'installment'      => calculateInstallment($finalPrice),
        'expert'           => $expertCache[$courseId],
    ];
}

/** Срок обучения в месяцах, выведенный из объёма часов */
function ccMonthsFromHours(int $hours): int
{
    return max(1, min(24, (int)ceil($hours / CC_HOURS_PER_MONTH)));
}

/** «3 мес.» — коротко, как в карточках конкурентов */
function ccFormatMonths(int $months): string
{
    return $months . ' мес.';
}

/** Иконка мета-строки карточки */
function ccIcon(string $name): string
{
    $paths = [
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'cap'   => '<path d="M12 4 2 9l10 5 10-5-10-5z"/><path d="M6 11.5V16c0 1.5 3 3 6 3s6-1.5 6-3v-4.5"/>',
        'badge' => '<circle cx="12" cy="9" r="4"/><path d="M8.5 12.5 7 21l5-2.5L17 21l-1.5-8.5"/>',
        'user'  => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.5 3-6 7-6s7 2.5 7 6"/>',
    ];

    return '<svg class="cc-ico" width="18" height="18" viewBox="0 0 24 24" fill="none"'
        . ' stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"'
        . ' aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

/** Экранирование для HTML-контекста */
function ccEsc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * @param array  $card    Результат buildCourseCardData()
 * @param string $variant 'inline' | 'expanded'
 */
function renderCourseCard(array $card, string $variant = 'inline'): string
{
    if (empty($card['id'])) {
        return '';
    }

    $isExpanded = $variant === 'expanded';
    $url        = ccEsc($card['url']);
    $inst       = $card['installment'];

    $kicker = array_filter([$card['hours_label'], $card['months_label']]);

    $h = '<aside class="cc-card ' . ($isExpanded ? 'cc-card--expanded' : 'cc-card--inline') . '">';

    if ($isExpanded) {
        $h .= '<div class="cc-eyebrow">Курс по теме статьи</div>';
    }

    $h .= '<div class="cc-head">';
    $h .= '<div class="cc-head-main">';
    $h .= '<span class="cc-kind">' . ccEsc($card['program_label']) . '</span>';
    $h .= '<h3 class="cc-title"><a href="' . $url . '">' . ccEsc($card['title']) . '</a></h3>';
    $h .= '</div>';

    if (!empty($card['has_discount'])) {
        $h .= '<div class="cc-discount-badge">−' . (int)$card['discount_percent'] . '%</div>';
    }
    $h .= '</div>';

    // Мета-строки: часы/срок, документ, квалификация, эксперт
    $h .= '<ul class="cc-meta">';
    if ($kicker) {
        $h .= '<li class="cc-meta-item">' . ccIcon('clock') . ccEsc(implode(' / ', $kicker)) . '</li>';
    }
    $h .= '<li class="cc-meta-item">' . ccIcon('cap') . ccEsc($card['credential']) . '</li>';
    $h .= '<li class="cc-meta-item">' . ccIcon('badge') . ccEsc($card['qualification']) . '</li>';

    if (!empty($card['expert']['full_name'])) {
        $expert = $card['expert'];
        $h .= '<li class="cc-meta-item">' . ccIcon('user');
        $h .= '<span class="cc-expert-label">Эксперт-разработчик программы:</span> ';
        $h .= '<span class="cc-expert-name">' . ccEsc($expert['full_name']) . '</span>';
        if ($isExpanded && !empty($expert['credentials'])) {
            $h .= '<span class="cc-expert-cred">' . ccEsc($expert['credentials']) . '</span>';
        }
        $h .= '</li>';
    }
    $h .= '</ul>';

    // Цена
    $h .= '<div class="cc-price">';
    if ($inst['available']) {
        $h .= '<div class="cc-price-installment">';
        $h .= '<span class="cc-price-prefix">от</span> ';
        $h .= '<strong class="cc-price-monthly">' . ccEsc(formatRub($inst['monthly'])) . '</strong>';
        $h .= '<span class="cc-price-per">/мес</span>';
        $h .= '<span class="cc-price-months">частями на ' . (int)$inst['months'] . ' месяцев</span>';
        $h .= '</div>';
    }
    $h .= '<div class="cc-price-full">';
    if (!empty($card['has_discount'])) {
        $h .= '<span class="cc-price-old">' . ccEsc(formatRub($card['base_price'])) . '</span> ';
    }
    $h .= '<span class="cc-price-now">' . ccEsc(formatRub($card['final_price'])) . '</span>';
    $h .= '</div>';
    $h .= '</div>';

    // Действия
    $h .= '<div class="cc-actions">';
    $h .= '<a class="cc-link" href="' . $url . '">Подробнее о программе</a>';
    $h .= '<button type="button" class="cc-btn cc-btn-primary" data-cc-consult'
        . ' data-course-id="' . (int)$card['id'] . '"'
        . ' data-course-title="' . ccEsc($card['title']) . '">Получить консультацию</button>';
    $h .= '</div>';

    $h .= '</aside>';

    return $h;
}

/**
 * Модалка консультации. Печатается один раз на страницу — повторные вызовы
 * возвращают пустую строку, поэтому её можно звать при каждой карточке.
 */
function renderCourseCardModal(): string
{
    static $rendered = false;
    if ($rendered) {
        return '';
    }
    $rendered = true;

    ob_start();
    ?>
    <div class="cc-modal" id="ccConsultModal" aria-hidden="true">
      <div class="cc-modal-box" role="dialog" aria-modal="true" aria-labelledby="ccModalTitle">
        <button type="button" class="cc-modal-close" data-cc-close aria-label="Закрыть">&times;</button>

        <div class="cc-modal-form">
          <h3 id="ccModalTitle">Бесплатная консультация</h3>
          <p class="cc-modal-sub">Оставьте номер — мы перезвоним и ответим на вопросы о курсе.</p>
          <p class="cc-modal-course" data-cc-course-label></p>

          <form>
            <input type="hidden" name="course_id" value="">
            <input type="hidden" name="course_title" value="">

            <div class="cc-form-group">
              <label for="ccConsultPhone">Телефон</label>
              <input type="tel" id="ccConsultPhone" name="phone" required placeholder="+7 (___) ___-__-__">
            </div>

            <label class="cc-form-agreement">
              <input type="checkbox" name="agreement" required>
              <span>
                Я принимаю условия <a href="/polzovatelskoe-soglashenie/" target="_blank">Пользовательского соглашения</a>
                и даю согласие на обработку персональных данных в соответствии с
                <a href="/politika-konfidencialnosti/" target="_blank">Политикой конфиденциальности</a>.
              </span>
            </label>

            <div class="cc-form-error" data-cc-error hidden></div>
            <button type="submit" class="cc-btn cc-btn-primary cc-btn-block">Перезвоните мне</button>
          </form>
        </div>

        <div class="cc-modal-success" data-cc-success hidden>
          <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="#22a55a" stroke-width="2"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/></svg>
          <h3>Заявка отправлена!</h3>
          <p>Мы перезвоним вам в ближайшее время.</p>
          <button type="button" class="cc-btn cc-btn-primary" data-cc-close>Закрыть</button>
        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Вставляет карточку примерно в середину текста — по возможности на границе
 * раздела (после заголовка), иначе между абзацами.
 *
 * Заголовок берём, только если он попадает в CC_HEADING_TOLERANCE от середины:
 * у материала может быть один H2 в самом конце, и карточка после него встала бы
 * вплотную к нижнему блоку.
 *
 * Короткое тело (< CC_MIN_BODY_CHARS видимых символов) остаётся без карточки.
 *
 * Регулярки, а не DOMDocument: тело приходит из БД/ИИ-генератора «грязным»,
 * DOMDocument его переписывает.
 */
function ccInjectAfterMiddleHeading(string $html, string $cardHtml): string
{
    if ($html === '' || $cardHtml === '') {
        return $html;
    }

    $totalChars = mb_strlen(strip_tags($html));
    if ($totalChars < CC_MIN_BODY_CHARS) {
        return $html;
    }

    $heading = ccFindMiddleOffset($html, '/<\/h[23]>/i', $totalChars);

    // Заголовок рядом с серединой — лучшее место: карточка встаёт на границе раздела
    if ($heading !== null && $heading['diff'] <= $totalChars * CC_HEADING_TOLERANCE) {
        return substr_replace($html, $cardHtml, $heading['offset'], 0);
    }

    // Иначе — кто ближе к середине: заголовок или граница абзаца
    $paragraph = ccFindMiddleOffset($html, '/<\/p>/i', $totalChars);
    $best = ccClosest($heading, $paragraph);

    // Вставить некуда (сплошной текст, всё в хвосте) — оставляем тело без карточки:
    // приклеивать её в конец незачем, там уже стоит развёрнутый блок
    return $best !== null
        ? substr_replace($html, $cardHtml, $best['offset'], 0)
        : $html;
}

/** Из двух кандидатов — тот, что ближе к середине */
function ccClosest(?array $a, ?array $b): ?array
{
    if ($a === null) return $b;
    if ($b === null) return $a;
    return $b['diff'] < $a['diff'] ? $b : $a;
}

/**
 * Тег, ближайший к середине ВИДИМОГО текста. Считаем по strip_tags, а не по
 * длине HTML: разметка и атрибуты смещают центр.
 *
 * @return array{offset:int, diff:float}|null offset — байтовый, для substr_replace;
 *                                            diff — на сколько символов промахнулись
 *                                            мимо середины. null, если тегов нет.
 */
function ccFindMiddleOffset(string $html, string $pattern, int $totalChars): ?array
{
    if (!preg_match_all($pattern, $html, $matches, PREG_OFFSET_CAPTURE)) {
        return null;
    }

    $target = $totalChars / 2;
    $best   = null;

    foreach ($matches[0] as $match) {
        [$tag, $byteOffset] = $match;
        $end         = $byteOffset + strlen($tag);
        $charsBefore = mb_strlen(strip_tags(substr($html, 0, $end)));

        // Слишком рано — карточка окажется в начале текста;
        // слишком поздно — упрётся в нижний блок
        if ($charsBefore < $totalChars * CC_HEAD_MIN_SHARE
            || $charsBefore > $totalChars * (1 - CC_TAIL_MIN_SHARE)) {
            continue;
        }

        $diff = abs($charsBefore - $target);
        if ($best === null || $diff < $best['diff']) {
            $best = ['offset' => $end, 'diff' => $diff];
        }
    }

    return $best;
}
