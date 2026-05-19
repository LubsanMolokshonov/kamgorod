<?php
/**
 * Хелпер для FAQ-блоков с микроразметкой Schema.org/FAQPage.
 *
 * Единый источник истины — массив $faqItems вида:
 *   [ ['q' => 'Вопрос?', 'a' => 'Ответ.'], ... ]
 * из него рендерятся и видимый HTML (с микроразметкой itemscope/itemprop),
 * и JSON-LD типа FAQPage. Текст в трёх местах гарантированно совпадает.
 */

/**
 * Собирает JSON-LD объект FAQPage из массива вопросов-ответов.
 * Результат кладётся в $jsonLd / $jsonLdArray и выводится в includes/header.php.
 *
 * @param array $faqItems  [ ['q' => ..., 'a' => ...], ... ]
 * @return array
 */
function buildFaqJsonLd(array $faqItems): array
{
    $entities = [];
    foreach ($faqItems as $item) {
        // В JSON-LD текст должен быть «чистым»: убираем теги и раскодируем
        // HTML-сущности (например &nbsp;), которые встречаются в вёрстке.
        $name = trim(html_entity_decode(strip_tags($item['q']), ENT_QUOTES, 'UTF-8'));
        $text = trim(html_entity_decode($item['a'], ENT_QUOTES, 'UTF-8'));
        $entities[] = [
            '@type' => 'Question',
            'name' => $name,
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => $text,
            ],
        ];
    }

    return [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => $entities,
    ];
}

/**
 * Рендерит список FAQ — <div class="rd-faq-list"> с микроразметкой FAQPage.
 * Структура классов (rd-faq-list, rd-faq-item, rd-faq-q, pm, rd-faq-a)
 * сохранена байт-в-байт со старой вёрсткой — CSS и JS-аккордеон не затронуты.
 *
 * @param array  $faqItems    [ ['q' => ..., 'a' => ...], ... ]
 * @param string $extraClass  доп. классы для контейнера (по умолчанию reveal-stagger)
 * @param string $extraAttrs  доп. атрибуты для контейнера (например inline-style)
 */
function renderFaqList(array $faqItems, string $extraClass = 'reveal-stagger', string $extraAttrs = ''): void
{
    $listClass = trim('rd-faq-list ' . $extraClass);
    ?>
      <div class="<?php echo htmlspecialchars($listClass, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $extraAttrs ? ' ' . $extraAttrs : ''; ?> itemscope itemtype="https://schema.org/FAQPage">
<?php foreach ($faqItems as $item): ?>
        <div class="rd-faq-item" itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">
          <button class="rd-faq-q"><span itemprop="name"><?php echo $item['q']; ?></span> <span class="pm">+</span></button>
          <div class="rd-faq-a" itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer"><div itemprop="text"><?php echo $item['a']; ?></div></div>
        </div>
<?php endforeach; ?>
      </div>
<?php
}
