<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

define('PUBLIC_SITE_URL', 'https://fgos.pro');
require_once __DIR__ . '/../classes/MaxCourseRecommendationChain.php';
require_once __DIR__ . '/../ai-consultant/src/MaxInboundProcessor.php';

function assertRecommendation(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "OK: {$message}\n";
}

$message = MaxCourseRecommendationChain::buildMessage([
    'title' => 'Современная педагогика',
    'slug' => 'sovremennaya-pedagogika',
    'program_type' => 'kpk',
    'hours' => 72,
    'price' => 4900,
], 'Олимпиада по педагогике', 123);

assertRecommendation(str_contains($message, 'Олимпиада по педагогике'), 'в тексте есть контекст покупки');
assertRecommendation(str_contains($message, 'Повышение квалификации, 72 ак. ч.'), 'тип и часы курса указаны');
assertRecommendation(str_contains($message, '4 900 ₽'), 'цена отформатирована');
assertRecommendation(str_contains($message, 'utm_campaign=post_purchase_course_recommendation'), 'UTM-атрибуция добавлена');
assertRecommendation(str_contains($message, 'utm_content=order_123'), 'заказ попадает в UTM');
assertRecommendation(str_contains($message, 'ответьте «Стоп»'), 'в сообщении есть отписка');
assertRecommendation(MaxInboundProcessor::isMarketingOptOutText('  СТОП! '), 'команда «Стоп» распознана');
assertRecommendation(!MaxInboundProcessor::isMarketingOptOutText('Как остановить рассылку?'), 'обычный вопрос не считается отпиской');

echo "Все тесты пройдены.\n";
