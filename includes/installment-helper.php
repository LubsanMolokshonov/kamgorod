<?php
/**
 * Хелпер расчёта рассрочки 0% по курсам.
 *
 * Принимает УЖЕ финальную цену курса в ₽ (после CoursePriceAB::getAdjustedPrice
 * и любых других применённых скидок). Сам никаких скидок не считает.
 */

/**
 * @param float $finalPrice Итоговая цена курса в рублях
 * @return array{available:bool, monthly:int, months:int, total:int}
 */
function calculateInstallment(float $finalPrice): array {
    $months   = defined('COURSE_INSTALLMENT_MONTHS')   ? (int)COURSE_INSTALLMENT_MONTHS   : 12;
    $minPrice = defined('COURSE_INSTALLMENT_MIN_PRICE') ? (int)COURSE_INSTALLMENT_MIN_PRICE : 10000;

    $finalPrice = max(0.0, $finalPrice);
    $available  = $finalPrice >= $minPrice && $months > 0;

    return [
        'available' => $available,
        'monthly'   => $available ? (int)ceil($finalPrice / $months) : 0,
        'months'    => $months,
        'total'     => (int)round($finalPrice),
    ];
}

/** Предложение считается по варианту записи и времени её создания на сервере. */
function courseInstallmentQuote(array $enrollment, ?int $now = null): array {
    if (($enrollment['installment_total'] ?? null) !== null && (int)($enrollment['installment_months'] ?? 0) > 0) {
        $amount = (float)$enrollment['installment_total'];
        return ['available' => true, 'monthly' => (int)$enrollment['installment_monthly_amount'],
                'months' => (int)$enrollment['installment_months'], 'total' => (int)round($amount),
                'amount_kopecks' => (int)round($amount * 100), 'deadline' => null];
    }
    $variant = ($enrollment['ab_variant'] ?? null) ?: CoursePriceAB::getVariant();
    $base = CoursePriceAB::getAdjustedPrice((float)$enrollment['price'], $variant, $enrollment['program_type'] ?? null);
    $created = new DateTimeImmutable($enrollment['enrolled_at'], new DateTimeZone('UTC'));
    $deadline = $created->getTimestamp() + 600;
    $discounted = ($now ?? time()) < $deadline;
    $price = $discounted ? round($base * 0.9, 2) : $base;
    return calculateInstallment($price) + ['amount_kopecks' => (int)round($price * 100), 'deadline' => $deadline];
}

/** Цена первичного создания сделки учитывает условия уже принятой рассрочки. */
function courseDealTerms(PDO $pdo, array $enrollment, array $course): array {
    if ($enrollment['status'] === 'installment_requested') {
        $q = $pdo->prepare('SELECT total_amount, months FROM course_installment_quotes WHERE enrollment_id=?');
        $q->execute([$enrollment['id']]);
        if ($saved = $q->fetch(PDO::FETCH_ASSOC)) {
            return ['amount' => (float)$saved['total_amount'], 'payment' => [
                'payment_method' => 'installment', 'installment_months' => (int)$saved['months'],
                'installment_monthly' => (int)$enrollment['installment_monthly_amount'],
            ]];
        }
    }
    return ['amount' => CoursePriceAB::getAdjustedPrice((float)$course['price'], $enrollment['ab_variant'] ?? 'A',
                                                       $course['program_type'] ?? null), 'payment' => []];
}

/** Отправить в исходную сделку сохранённую цену. При сбое запись подберёт cron. */
function syncInstallmentQuote(PDO $pdo, Bitrix24Integration $bitrix, int $enrollmentId): void {
    if (!$bitrix->isConfigured()) return;
    $stmt = $pdo->prepare("SELECT ce.bitrix_lead_id, COALESCE(NULLIF(ce.course_title_snapshot,''), c.title) AS title,
                                  q.total_amount
                           FROM course_installment_quotes q JOIN course_enrollments ce ON ce.id=q.enrollment_id
                           JOIN courses c ON c.id=ce.course_id
                           WHERE q.enrollment_id=? AND q.crm_synced_at IS NULL AND ce.bitrix_lead_id > 0
                             AND ce.status='installment_requested'");
    $stmt->execute([$enrollmentId]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$quote) return;
    if ($bitrix->updateDeal((string)$quote['bitrix_lead_id'], [
        'TITLE' => mb_substr('РАССРОЧКА ' . $quote['title'], 0, 100),
        'OPPORTUNITY' => (float)$quote['total_amount'], 'CURRENCY_ID' => 'RUB',
    ])) {
        $pdo->prepare('UPDATE course_installment_quotes SET crm_synced_at=CURRENT_TIMESTAMP WHERE enrollment_id=?')->execute([$enrollmentId]);
    }
}

/** Форматирует целую сумму как «3 159 ₽» */
if (!function_exists('formatRub')) {
    function formatRub(float $amount): string {
        return number_format($amount, fmod($amount, 1.0) == 0.0 ? 0 : 2, ',', ' ') . ' ₽';
    }
}
