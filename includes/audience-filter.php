<?php
/**
 * Unified Audience Filter Component
 * 3-level cascading filter.
 *
 * Two ordering modes:
 *   - Default (журнал, курсы):       Category → Type → Specialization
 *   - Reordered (конкурсы/олимпиады/вебинары): Category → Specialization → Type
 *
 * Required variables (set before include):
 *   $audienceCategories - array from AudienceCategory::getAll()
 *   $audienceTypes - array of types for selected category (or empty)
 *   $audienceSpecializations - array of specializations available (or empty)
 *   $selectedCategory - selected category slug (or '')
 *   $selectedType - selected type slug (or '')
 *   $selectedSpec - selected specialization slug (or '')
 *   $audienceFilterBaseUrl - base URL for the page (e.g., '/konkursy')
 *   $extraPathPrefix - path segment before audience (e.g., 'metodika' or 'predstoyashchie'), or ''
 *   $extraQueryParams - additional query string to append (e.g., 'tag=xxx&type=yyy'), or ''
 *   $audienceFilterReorderedUrl - true → ac/as/at, false → ac/at/as (default false)
 */

if (!isset($extraPathPrefix)) $extraPathPrefix = '';
if (!isset($extraQueryParams)) $extraQueryParams = '';
if (!isset($audienceFilterReorderedUrl)) $audienceFilterReorderedUrl = false;

// Build SEO-friendly URL helper. Segments are passed by semantic name:
//   $ac = audience category, $at = audience type (уровень), $as = specialization (предмет)
// Внутренний порядок зависит от $audienceFilterReorderedUrl.
$afBuildUrl = function($ac = '', $at = '', $as = '') use ($audienceFilterBaseUrl, $extraPathPrefix, $extraQueryParams, $audienceFilterReorderedUrl) {
    $url = $audienceFilterBaseUrl;
    if ($extraPathPrefix) $url .= '/' . $extraPathPrefix;
    if ($ac) {
        $url .= '/' . rawurlencode($ac);
        if ($audienceFilterReorderedUrl) {
            // Новый порядок: ac/as/at
            if ($as) {
                $url .= '/' . rawurlencode($as);
                if ($at) $url .= '/' . rawurlencode($at);
            } elseif ($at) {
                // Уровень без специализации — 2-сегментный URL
                $url .= '/' . rawurlencode($at);
            }
        } else {
            // Старый порядок: ac/at/as
            if ($at) {
                $url .= '/' . rawurlencode($at);
                if ($as) $url .= '/' . rawurlencode($as);
            }
        }
    }
    $url .= '/';
    if ($extraQueryParams) $url .= '?' . ltrim($extraQueryParams, '&?');
    return $url;
};
?>
<div class="audience-filter" id="audienceFilter">
    <!-- Level 0: Категории аудитории -->
    <div class="af-categories">
        <a href="<?php echo $afBuildUrl(); ?>"
           class="af-tab<?php echo empty($selectedCategory) ? ' active' : ''; ?>">Все</a>
        <?php foreach ($audienceCategories as $cat): ?>
        <a href="<?php echo $afBuildUrl($cat['slug']); ?>"
           class="af-tab<?php echo ($selectedCategory === $cat['slug']) ? ' active' : ''; ?>">
            <?php echo htmlspecialchars($cat['name']); ?>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if ($audienceFilterReorderedUrl): ?>
        <?php /* НОВЫЙ ПОРЯДОК: Категория → Специализация → Уровень */ ?>

        <?php if (!empty($selectedCategory) && !empty($audienceSpecializations)): ?>
        <!-- Level 1: Специализации (предметы) -->
        <div class="af-specs">
            <a href="<?php echo $afBuildUrl($selectedCategory); ?>"
               class="af-chip<?php echo empty($selectedSpec) ? ' active' : ''; ?>">Все</a>
            <?php foreach ($audienceSpecializations as $spec): ?>
            <a href="<?php echo $afBuildUrl($selectedCategory, '', $spec['slug']); ?>"
               class="af-chip<?php echo ($selectedSpec === $spec['slug']) ? ' active' : ''; ?><?php echo (!empty($spec['specialization_type']) && $spec['specialization_type'] === 'role') ? ' af-chip--role' : ''; ?>">
                <?php echo htmlspecialchars($spec['name']); ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($selectedSpec) && !empty($audienceTypes)): ?>
        <!-- Level 2: Уровень (тип аудитории) -->
        <div class="af-types">
            <a href="<?php echo $afBuildUrl($selectedCategory, '', $selectedSpec); ?>"
               class="af-pill<?php echo empty($selectedType) ? ' active' : ''; ?>">Все уровни</a>
            <?php foreach ($audienceTypes as $type): ?>
            <a href="<?php echo $afBuildUrl($selectedCategory, $type['slug'], $selectedSpec); ?>"
               class="af-pill<?php echo ($selectedType === $type['slug']) ? ' active' : ''; ?>">
                <?php echo htmlspecialchars($type['name']); ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    <?php else: ?>
        <?php /* СТАРЫЙ ПОРЯДОК: Категория → Уровень → Специализация */ ?>

        <?php if (!empty($selectedCategory) && !empty($audienceTypes)): ?>
        <!-- Level 1: Типы аудитории -->
        <div class="af-types">
            <a href="<?php echo $afBuildUrl($selectedCategory); ?>"
               class="af-pill<?php echo empty($selectedType) ? ' active' : ''; ?>">Все</a>
            <?php foreach ($audienceTypes as $type): ?>
            <a href="<?php echo $afBuildUrl($selectedCategory, $type['slug']); ?>"
               class="af-pill<?php echo ($selectedType === $type['slug']) ? ' active' : ''; ?>">
                <?php echo htmlspecialchars($type['name']); ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($selectedType) && !empty($audienceSpecializations)): ?>
        <!-- Level 2: Специализации -->
        <div class="af-specs">
            <a href="<?php echo $afBuildUrl($selectedCategory, $selectedType); ?>"
               class="af-chip<?php echo empty($selectedSpec) ? ' active' : ''; ?>">Все</a>
            <?php foreach ($audienceSpecializations as $spec): ?>
            <a href="<?php echo $afBuildUrl($selectedCategory, $selectedType, $spec['slug']); ?>"
               class="af-chip<?php echo ($selectedSpec === $spec['slug']) ? ' active' : ''; ?><?php echo (!empty($spec['specialization_type']) && $spec['specialization_type'] === 'role') ? ' af-chip--role' : ''; ?>">
                <?php echo htmlspecialchars($spec['name']); ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    <?php endif; ?>
</div>
