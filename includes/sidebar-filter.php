<?php
/**
 * Sidebar Filter Component (vertical, for desktop)
 * Renders the same audience filters as audience-filter.php but in vertical layout.
 *
 * Required variables (same as audience-filter.php):
 *   $audienceCategories, $audienceTypes, $audienceSpecializations
 *   $selectedCategory, $selectedType, $selectedSpec
 *   $afBuildUrl - closure for building audience URLs
 *
 * Optional:
 *   $sidebarExtraFilters - array with keys: title, links[] (each: label, url, active), allLabel, allUrl, allActive
 *   $audienceCategoryCounts - array slug=>count (optional, for showing counts)
 *   $audienceTypeCounts - array slug=>count (optional)
 *   $audienceSpecCounts - array slug=>count (optional)
 */
if (!isset($audienceCategoryCounts)) $audienceCategoryCounts = [];
if (!isset($audienceTypeCounts)) $audienceTypeCounts = [];
if (!isset($audienceSpecCounts)) $audienceSpecCounts = [];
?>

<?php if (!empty($sidebarExtraFilters) && !empty($sidebarExtraFilters['links'])): ?>
<div class="sidebar-section">
    <h4><?php echo htmlspecialchars($sidebarExtraFilters['title']); ?></h4>
    <div class="filter-links">
        <a href="<?php echo $sidebarExtraFilters['allUrl']; ?>#catalog"
           class="filter-link<?php echo !empty($sidebarExtraFilters['allActive']) ? ' active' : ''; ?>">
            <?php echo htmlspecialchars($sidebarExtraFilters['allLabel']); ?>
        </a>
        <?php foreach ($sidebarExtraFilters['links'] as $link): ?>
        <a href="<?php echo $link['url']; ?>#catalog"
           class="filter-link<?php echo !empty($link['active']) ? ' active' : ''; ?>">
            <?php echo htmlspecialchars($link['label']); ?>
            <?php if (isset($link['count'])): ?>
                <span class="filter-link-count">(<?php echo $link['count']; ?>)</span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($audienceCategories)): ?>
<!-- Категория аудитории -->
<div class="sidebar-section">
    <h4>Аудитория</h4>
    <div class="filter-links">
        <a href="<?php echo $afBuildUrl(); ?>#catalog"
           class="filter-link<?php echo empty($selectedCategory) ? ' active' : ''; ?>">Все</a>
        <?php foreach ($audienceCategories as $cat): ?>
        <a href="<?php echo $afBuildUrl($cat['slug']); ?>#catalog"
           class="filter-link<?php echo ($selectedCategory === $cat['slug']) ? ' active' : ''; ?>">
            <?php echo htmlspecialchars($cat['name']); ?>
            <?php if (isset($audienceCategoryCounts[$cat['slug']])): ?>
                <span class="filter-link-count">(<?php echo $audienceCategoryCounts[$cat['slug']]; ?>)</span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($selectedCategory) && !empty($audienceTypes)): ?>
<!-- Тип аудитории -->
<div class="sidebar-section">
    <h4>Тип</h4>
    <div class="filter-links">
        <a href="<?php echo $afBuildUrl($selectedCategory); ?>#catalog"
           class="filter-link<?php echo empty($selectedType) ? ' active' : ''; ?>">Все</a>
        <?php foreach ($audienceTypes as $type): ?>
        <a href="<?php echo $afBuildUrl($selectedCategory, $type['slug']); ?>#catalog"
           class="filter-link<?php echo ($selectedType === $type['slug']) ? ' active' : ''; ?>">
            <?php echo htmlspecialchars($type['name']); ?>
            <?php if (isset($audienceTypeCounts[$type['slug']])): ?>
                <span class="filter-link-count">(<?php echo $audienceTypeCounts[$type['slug']]; ?>)</span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($selectedType) && !empty($audienceSpecializations)): ?>
<!-- Специализация -->
<div class="sidebar-section">
    <h4>Специализация</h4>
    <div class="filter-links">
        <a href="<?php echo $afBuildUrl($selectedCategory, $selectedType); ?>#catalog"
           class="filter-link<?php echo empty($selectedSpec) ? ' active' : ''; ?>">Все</a>
        <?php foreach ($audienceSpecializations as $spec): ?>
        <a href="<?php echo $afBuildUrl($selectedCategory, $selectedType, $spec['slug']); ?>#catalog"
           class="filter-link<?php echo ($selectedSpec === $spec['slug']) ? ' active' : ''; ?>">
            <?php echo htmlspecialchars($spec['name']); ?>
            <?php if (isset($audienceSpecCounts[$spec['slug']])): ?>
                <span class="filter-link-count">(<?php echo $audienceSpecCounts[$spec['slug']]; ?>)</span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
