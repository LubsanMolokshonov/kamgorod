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

/** Заявитель и автор работы — отдельные роли; неизвестные данные не угадываем. */
function competitionApplicantText(array $roles = []): string {
    $roles = array_values(array_unique($roles));
    if ($roles === ['pedagogi']) return 'Участник — педагог, представляющий собственную профессиональную работу. Он может оформить заявку сам. При групповом оформлении каждый автор указывается отдельным участником.';
    if ($roles && !array_diff($roles, ['doshkolnikam', 'shkolnikam'])) return 'Участник — ребенок, чья работа представлена на конкурс. Заявку оформляет взрослый: родитель или педагог. В данных участника указывается ребенок; данные заявителя и руководителя указываются отдельно.';
    return 'Участник — автор представленной работы. Заявитель оформляет участие. Указывайте ФИО участника отдельно от данных заявителя и руководителя; допустимый состав участников уточните в условиях конкурса.';
}

/** Словоформы только конкурсов; общая карта курсов и вебинаров не меняется. */
function competitionAudienceCategory(?array $category): ?array {
    if (!$category) return null;
    $names = ['pedagogi'=>'Педагоги', 'doshkolnikam'=>'Дошкольники', 'shkolnikam'=>'Школьники', 'studentam-spo'=>'Студенты СПО'];
    if (isset($names[$category['slug']])) $category['name'] = $names[$category['slug']];
    return $category;
}

function competitionAudienceSeoPhrase(?array $category, ?array $type, ?array $subject): string {
    require_once __DIR__ . '/catalog-meta.php';
    $names = ['pedagogi'=>'педагогов', 'doshkolnikam'=>'дошкольников', 'shkolnikam'=>'школьников', 'studentam-spo'=>'студентов СПО'];
    $slug = $category['slug'] ?? '';
    if (!isset($names[$slug])) return buildAudienceSeoPhrase($category, $type, $subject);
    $suffix = $slug === 'pedagogi' ? buildAudienceSeoPhrase(null, $type, $subject)
        : trim(($subject ? 'направление «' . $subject['name'] . '» ' : '') . ($type['seo_phrase'] ?? $type['name'] ?? ''));
    return trim('для ' . $names[$slug] . ' ' . $suffix);
}

function competitionCatalogIntro(string $role): string {
    if ($role === 'pedagogi') return 'Здесь выбирают конкурс для собственной профессиональной работы педагога. Если вы оформляете работу ребенка, выбирайте раздел по возрасту участника. Номинации, стоимость и условия оформления указаны в карточках.';
    if (in_array($role, ['doshkolnikam','shkolnikam'], true)) return 'Участник конкурса — ребенок, автор работы. Взрослый оформляет заявку и указывает свои данные отдельно. Выбирайте конкурс по возрасту участника, номинации и виду работы; проверьте условия в карточке.';
    if ($role === 'studentam-spo') return 'Подборка для работ студентов СПО. Преподаватель со своей профессиональной разработкой выбирает конкурсы для педагогов. Допустимый состав участников и порядок оформления указаны в карточке.';
    return 'Выберите конкурс по автору работы: педагог, ребенок или студент. Человек, оформляющий заявку, может быть заявителем, а участником будет автор работы. Условия, номинации и стоимость указаны в карточках.';
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
        ['q' => 'Кто участвует и кто оформляет заявку?', 'a' => 'Участники: ' . $competition['target_participants'] . '. ' . competitionApplicantText($roles)],
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
