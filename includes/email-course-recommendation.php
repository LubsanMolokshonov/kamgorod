<?php
/**
 * Подбор курсов для рекомендации в письмах триггерных цепочек мероприятий.
 *
 * Основная цель мероприятий (конкурсы/олимпиады/вебинары) — пополнять базу,
 * чтобы продавать курсы. Первое письмо каждой цепочки показывает участнику
 * курс переподготовки (ПП) и курс повышения квалификации (КПК), подобранные
 * по аудитории педагога.
 *
 * Порядок в письме всегда: сначала ПП, потом КПК.
 *
 * @param PDO      $pdo
 * @param int|null $userId   ID педагога-получателя (берём его профиль, а не
 *                           «детскую» аудиторию мероприятия). NULL → гость.
 * @param string   $campaign Метка кампании для UTM (например 'olympiad', 'webinar').
 * @param int[]    $excludeCourseIds ID курсов, которые НЕ предлагать (например уже
 *                           купленные в этом же заказе) — чтобы не рекомендовать
 *                           человеку то, что он только что приобрёл.
 * @return array{pp: array|null, kpk: array|null}
 *         Каждый курс: ['title','slug','price','hours','url'] либо null.
 */
function getCourseRecommendationsForEmail($pdo, ?int $userId, string $campaign = 'event', array $excludeCourseIds = []): array
{
    require_once __DIR__ . '/../classes/Database.php';
    require_once __DIR__ . '/../classes/Course.php';

    try {
        $course = new Course($pdo);

        // 1. Аудитория по профилю педагога (если он залогинен и заполнил профиль).
        $audienceSlug = null;
        if ($userId) {
            $stmt = $pdo->prepare(
                "SELECT at.slug
                 FROM users u
                 JOIN audience_types at ON u.institution_type_id = at.id
                 WHERE u.id = ? AND at.is_active = 1
                 LIMIT 1"
            );
            $stmt->execute([$userId]);
            $audienceSlug = $stmt->fetchColumn() ?: null;
        }

        $exclude = array_map('intval', $excludeCourseIds);
        $pp  = pickCourseForEmail($course, $audienceSlug, 'pp', $campaign, $exclude);
        $kpk = pickCourseForEmail($course, $audienceSlug, 'kpk', $campaign, $exclude);

        return ['pp' => $pp, 'kpk' => $kpk];
    } catch (\Throwable $e) {
        // Рекомендация — не критичный элемент письма: при сбое просто не показываем блок.
        error_log('getCourseRecommendationsForEmail error: ' . $e->getMessage());
        return ['pp' => null, 'kpk' => null];
    }
}

/**
 * Один курс заданного типа: сначала по аудитории педагога, иначе ТОП-курс
 * по display_order (универсальный fallback для гостей / аудиторий без курсов
 * этого типа, например КПК для СПО и доп. образования).
 */
function pickCourseForEmail(Course $course, ?string $audienceSlug, string $programType, string $campaign, array $excludeIds = []): ?array
{
    // Первый курс списка, не входящий в исключения (уже купленные в этом заказе).
    $firstAllowed = static function (array $rows) use ($excludeIds): ?array {
        foreach ($rows as $r) {
            if (!in_array((int)($r['id'] ?? 0), $excludeIds, true)) {
                return $r;
            }
        }
        return null;
    };

    $row = null;

    if ($audienceSlug) {
        $byAudience = $course->getFilteredCourses([
            'audience_type' => $audienceSlug,
            'program_type'  => $programType,
        ]);
        $row = $firstAllowed($byAudience);
    }

    if (!$row) {
        // Универсальный ТОП-курс (первый по display_order).
        $top = $course->getFilteredCourses(['program_type' => $programType]);
        $row = $firstAllowed($top);
    }

    if (!$row) {
        return null;
    }

    return [
        'title' => $row['title'],
        'slug'  => $row['slug'],
        'price' => $row['price'],
        'hours' => $row['hours'],
        'url'   => SITE_URL . '/kursy/' . $row['slug'] . '/'
            . '?utm_source=email&utm_medium=chain&utm_campaign=' . $campaign . '-course-reco'
            . '&utm_content=' . $programType,
    ];
}
