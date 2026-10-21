<?php
if (php_sapi_name() !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(403); die('CLI only'); }
/**
 * Положение о конкурсе — единый источник текста.
 *
 * Используется двумя точками входа:
 *   ajax/get-regulations.php      — HTML для модального окна на странице конкурса;
 *   ajax/download-regulations.php — тот же текст, свёрстанный в PDF для скачивания.
 *
 * Повод вынести: пользователи просят «скачать положение» (support_alerts 189, 191, 192),
 * а модалка отдавала текст только на просмотр.
 */

require_once __DIR__ . '/../classes/Competition.php';

/**
 * Тело положения (без заголовка и реквизитов шапки).
 *
 * @param array $competition Строка из competitions
 * @return string HTML
 */
require_once __DIR__ . '/competition-presentation.php';

function generateRegulationsContent($competition, ?array $content = null) {
    global $db;
    $facts = isset($db) && $db instanceof PDO ? competitionFacts($db, (int)$competition['id']) : [];
    if ($content === null) $content = $facts['content_json'] ?? [];
    $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $title = $e($competition['title']);
    $html = '<p>Редакция условий оформления: <time datetime="2026-10-21">21.10.2026</time>.</p>';
    $html .= '<h3>Участник и заявитель</h3><p>«' . $title . '». Участники: ' . $e($competition['target_participants']) . '. Заявку на ребенка оформляет взрослый; участник и руководитель указываются отдельно.</p>';
    $nominations = json_decode($competition['nomination_options'] ?? '', true);
    if (!is_array($nominations)) $nominations = preg_split('/\r?\n/', $competition['nomination_options'] ?? '');
    $html .= '<h3>Номинации</h3><ul>';
    foreach ($nominations as $nomination) if (is_string($nomination) && trim($nomination) !== '') $html .= '<li>' . $e($nomination) . '</li>';
    $html .= '</ul>';
    $html .= '<h3>Порядок оформления</h3><p>' . $e(competitionProcessText()) . '</p>';
    $html .= '<h3>Стоимость и документы</h3><p>Базовая стоимость оформления: ' . $e(number_format((float)$competition['price'], 0, ',', ' ')) . ' ₽. Действующие условия подписки, скидки и дополнительные документы учитываются в форме и корзине до оплаты. Электронный диплом доступен в личном кабинете после подтверждения оплаты или оформления по подписке.</p>';
    $html .= '<p>' . $e(competitionPortfolioText($facts['participant_roles'] ?? [])) . '</p>';
    $html .= '<h3>Акции и групповое оформление</h3><p>' . $e(competitionDiscountText()) . '</p>';
    foreach (['submission_requirements'=>'Требования к работе', 'assessment'=>'Порядок определения результата', 'rules'=>'Положение конкурса'] as $field=>$label) {
        if (!empty($content[$field])) $html .= '<h3>' . $label . '</h3><p>' . nl2br($e($content[$field])) . '</p>';
    }
    if (!empty($content['rules_version'])) $html .= '<p>Версия положения: ' . $e($content['rules_version']) . '</p>';
    $deadline = competitionDeadline($content);
    if ($deadline !== '') $html .= '<p>' . $e($deadline) . '</p>';
    $html .= '<p>Организатор: ООО «Едурегионлаб», Эл СМИ «ФГОС-Практикум». <a href="/svedeniya/dokumenty/">Реквизиты и документы</a>. <a href="/oferta-meropriyatiya/">Оферта и условия возврата</a>. Поддержка: info@fgos.pro.</p>';
    return $html;
}

/**
 * Колонтитул для mPDF. Отдельно от тела — в потоке документа он выдавливал
 * лишнюю почти пустую страницу.
 *
 * @return string HTML
 */
function regulationsPdfFooterHtml() {
    return '<div style="border-top:1px solid #E5E7EB;padding-top:6px;font-size:8pt;color:#6B7280;">'
         . '<table width="100%"><tr>'
         . '<td style="text-align:left;">Документ сформирован на fgos.pro ' . date('d.m.Y') . '</td>'
         . '<td style="text-align:right;">стр. {PAGENO} из {nbpg}</td>'
         . '</tr></table></div>';
}

/**
 * Полная HTML-страница положения для mPDF: шапка с названием конкурса + тело.
 *
 * @param array $competition Строка из competitions
 * @return string HTML-документ
 */
function buildRegulationsPdfHtml($competition) {
    $title = htmlspecialchars($competition['title'], ENT_QUOTES, 'UTF-8');
    $body  = generateRegulationsContent($competition);

    return <<<HTML
<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><style>
body { font-family: dejavusans, sans-serif; font-size: 10.5pt; line-height: 1.55; color: #1F2937; }
.doc-head { text-align: center; border-bottom: 2px solid #667eea; padding-bottom: 12px; margin-bottom: 20px; }
.doc-head .org { font-size: 9.5pt; color: #6B7280; margin: 0 0 6px; }
.doc-head h1 { font-size: 15pt; margin: 0 0 6px; color: #111827; }
.doc-head .sub { font-size: 11pt; color: #374151; margin: 0; }
h3 { font-size: 11.5pt; color: #4C51BF; margin: 16px 0 6px; }
p { margin: 0 0 8px; text-align: justify; }
ul { margin: 0 0 8px 0; padding-left: 18px; }
li { margin-bottom: 4px; }
</style></head><body>
<div class="doc-head">
  <p class="org">Эл СМИ «ФГОС-Практикум» · ООО «Едурегионлаб» · fgos.pro</p>
  <h1>УСЛОВИЯ ОФОРМЛЕНИЯ И ПОЛОЖЕНИЕ</h1>
  <p class="sub">конкурса «{$title}»</p>
</div>
{$body}
</body></html>
HTML;
}
