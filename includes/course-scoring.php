<?php
/**
 * Подбор курсов по аудиторным сигналам контента.
 *
 * Общий скоринг для Publication::getRecommendedCourses() (сигналы — из тегов
 * через PUBLICATION_TAG_AUDIENCE_MAP) и Material::getRecommendedCourses()
 * (сигналы — из material_specializations / material_audience_types).
 */

/**
 * Курсы, релевантные набору аудиторных сигналов.
 * Скоринг: специализация +3, тип аудитории +2, совпадение course_group +1.
 * При нехватке — дозаполняет популярными активными курсами, чтобы блок не пустовал.
 *
 * @param Database $db
 * @param int[]    $specIds ID специализаций
 * @param int[]    $typeIds ID типов аудитории
 * @param string[] $groups  Значения course_group
 * @param int      $limit
 * @return array Курсы (id, slug, title, course_group, hours, price, program_type)
 */
function scoreCoursesByAudience(Database $db, array $specIds, array $typeIds, array $groups, int $limit): array
{
    $limit = max(1, $limit);

    $specIds = array_values(array_unique(array_map('intval', $specIds)));
    $typeIds = array_values(array_unique(array_map('intval', $typeIds)));
    $groups  = array_values(array_unique($groups));

    $courses    = [];
    $excludeIds = [];

    if ($specIds || $typeIds || $groups) {
        $scoreParts = [];
        $params     = [];

        if ($specIds) {
            $ph = implode(',', array_fill(0, count($specIds), '?'));
            $scoreParts[] = "(EXISTS (SELECT 1 FROM course_specializations cs WHERE cs.course_id = c.id AND cs.specialization_id IN ($ph)) * 3)";
            $params = array_merge($params, $specIds);
        }
        if ($typeIds) {
            $ph = implode(',', array_fill(0, count($typeIds), '?'));
            $scoreParts[] = "(EXISTS (SELECT 1 FROM course_audience_types cat WHERE cat.course_id = c.id AND cat.audience_type_id IN ($ph)) * 2)";
            $params = array_merge($params, $typeIds);
        }
        if ($groups) {
            $ph = implode(',', array_fill(0, count($groups), '?'));
            $scoreParts[] = "(c.course_group IN ($ph)) * 1";
            $params = array_merge($params, $groups);
        }

        $sql = "SELECT c.id, c.slug, c.title, c.course_group, c.hours, c.price, c.program_type, ("
            . implode(' + ', $scoreParts) . ") AS score
             FROM courses c
             WHERE c.is_active = 1
             HAVING score > 0
             ORDER BY score DESC, c.display_order ASC, c.created_at DESC
             LIMIT ?";
        $params[] = $limit;

        $courses    = $db->query($sql, $params);
        $excludeIds = array_column($courses, 'id');
    }

    if (count($courses) < $limit) {
        $need       = $limit - count($courses);
        $fbParams   = [];
        $excludeSql = '';
        if ($excludeIds) {
            $ph = implode(',', array_fill(0, count($excludeIds), '?'));
            $excludeSql = " AND c.id NOT IN ($ph)";
            $fbParams = array_map('intval', $excludeIds);
        }
        $fbParams[] = $need;
        $fallback = $db->query(
            "SELECT c.id, c.slug, c.title, c.course_group, c.hours, c.price, c.program_type
             FROM courses c
             WHERE c.is_active = 1{$excludeSql}
             ORDER BY c.display_order ASC, c.created_at DESC
             LIMIT ?",
            $fbParams
        );
        $courses = array_merge($courses, $fallback);
    }

    return $courses;
}
