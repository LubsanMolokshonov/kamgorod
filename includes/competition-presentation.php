<?php
if (php_sapi_name() !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(403); die('CLI only'); }

/** Факты для карточки, FAQ и положения. Пустое поле не заменяется обещанием. */
function competitionFacts(PDO $pdo, int $id): array {
    try {
        $q = $pdo->prepare('SELECT * FROM competition_catalog_facts WHERE competition_id=?');
        $q->execute([$id]); $row = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S02') throw $e;
        return [];
    }
    foreach (['participant_roles','education_stages','subject_slugs','content_json'] as $key) $row[$key] = json_decode($row[$key] ?? '{}', true) ?: [];
    return $row;
}

function competitionProcessText(): string {
    return 'В заявке указываются участник, номинация, название работы и выбранное заявителем место. Файл в этой форме не загружается. Оформление не включает новую независимую экспертную оценку работы. Перед оплатой проверьте данные и итоговую сумму.';
}

function competitionPortfolioText(array $roles = []): string {
    $text = 'Диплом можно включить в портфолио участника.';
    if (in_array('pedagogi', $roles, true)) $text .= ' Возможность его учета уточните по требованиям вашей аттестационной комиссии.';
    return $text;
}

function competitionDiscountText(): string {
    require_once __DIR__ . '/group-pricing.php';
    $groups = [];
    foreach (groupDiscountTiers() as $tier) $groups[] = $tier['min'] . '–' . $tier['max'] . ' участников — ' . $tier['percent'] . '%';
    return 'Акция 2+1 применяется к одиночным позициям общей корзины: после сортировки по убыванию цены бесплатна каждая третья позиция. Групповые позиции в акции 2+1 не участвуют. Скидка группы: ' . implode('; ', $groups) . '. Итоговая сумма с доступными скидками показывается до оплаты.';
}

function competitionDeadline(array $content): string {
    if (!empty($content['enrollment_end'])) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $content['enrollment_end']);
        if ($date && $date->format('Y-m-d') === $content['enrollment_end']) return 'Прием до ' . $date->format('d.m.Y');
    }
    return !empty($content['year_round']) ? 'Круглогодичный прием' : '';
}

function competitionFaq(array $competition, array $nominations, array $roles = []): array {
    $items = [
        ['q' => 'Кто участвует и кто оформляет заявку?', 'a' => 'Участники: ' . $competition['target_participants'] . '. Заявку на ребенка оформляет взрослый. Данные участника и руководителя указываются отдельно.'],
        ['q' => 'Какие номинации доступны?', 'a' => $nominations ? implode('; ', $nominations) . '.' : 'Уточните доступные номинации у поддержки перед оформлением.'],
        ['q' => 'Как оформляется участие?', 'a' => competitionProcessText()],
        ['q' => 'Когда доступен документ?', 'a' => 'Электронный диплом доступен в личном кабинете после подтверждения оплаты или оформления по условиям вашей подписки.'],
        ['q' => 'Как узнать итоговую стоимость?', 'a' => 'Цена выбранного варианта и дополнительных документов показывается в форме и корзине до оплаты.'],
        ['q' => 'Как действуют акция 2+1 и скидка группы?', 'a' => competitionDiscountText()],
        ['q' => 'Можно ли использовать диплом в портфолио?', 'a' => competitionPortfolioText($roles)],
    ];
    return array_map(static fn($item) => array_map(static fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8'), $item), $items);
}

function competitionRelatedLinks(string $path): string {
    require_once __DIR__ . '/competition-index.php';
    $registry = competitionRegistry();
    $html = '';
    foreach ($registry['links'] as $link) {
        if ($link['from'] !== $path) continue;
        $html .= '<li><a href="' . htmlspecialchars($link['to'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($link['anchor'], ENT_QUOTES, 'UTF-8') . '</a></li>';
    }
    return $html ? '<nav aria-label="Связанные направления"><ul>' . $html . '</ul></nav>' : '';
}
