<?php
/**
 * Unified Audience Filter Component
 * 3-level cascading filter: Category → Type → Specialization
 *
 * Required variables (set before include):
 *   $audienceCategories - array from AudienceCategory::getAll()
 *   $audienceTypes - array of types for selected category (or empty)
 *   $audienceSpecializations - array of specializations for selected type (or empty)
 *   $selectedCategory - selected category slug (or '')
 *   $selectedType - selected type slug (or '')
 *   $selectedSpec - selected specialization slug (or '')
 *   $audienceFilterBaseUrl - base URL for the page (e.g., '/konkursy')
 *   $extraPathPrefix - path segment before audience (e.g., 'metodika' or 'predstoyashchie'), or ''
 *   $extraQueryParams - additional query string to append (e.g., 'tag=xxx&type=yyy'), or ''
 */

if (!isset($extraPathPrefix)) $extraPathPrefix = '';
if (!isset($extraQueryParams)) $extraQueryParams = '';

// Build SEO-friendly URL helper
$afBuildUrl = function($ac = '', $at = '', $as = '') use ($audienceFilterBaseUrl, $extraPathPrefix, $extraQueryParams) {
    $url = $audienceFilterBaseUrl;
    if ($extraPathPrefix) $url .= '/' . $extraPathPrefix;
    if ($ac) $url .= '/' . rawurlencode($ac);
    if ($at) $url .= '/' . rawurlencode($at);
    if ($as) $url .= '/' . rawurlencode($as);
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
</div>
