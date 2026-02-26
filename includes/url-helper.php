<?php
/**
 * URL Helper Functions
 * Smart URL generation for competitions based on audience context
 */

/**
 * Generate competition URL based on audience types and context
 *
 * @param string $slug Competition slug
 * @param array $audienceTypes Array of audience type objects with 'slug' key
 * @param string|null $contextAudience Current audience filter slug (e.g., 'dou', 'nachalnaya-shkola')
 * @return string Clean URL for the competition
 */
function getCompetitionUrl($slug, $audienceTypes = [], $contextAudience = null) {
    return '/konkursy/' . urlencode($slug);
}

/**
 * Get current audience context from request
 *
 * @return string|null Audience slug or null if not in audience context
 */
function getCurrentAudienceContext() {
    // Check if we're filtering by audience
    $audienceFilter = $_GET['audience'] ?? null;
    if ($audienceFilter) {
        return $audienceFilter;
    }

    return null;
}
