<?php
// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Gets the platform commission and returns it as a multiplier.
 * e.g., 5% becomes 1.05
 * @return float The commission multiplier.
 */
function taptosell_get_commission_multiplier() {
    // Get the saved value, or default to 5 if it's not set.
    $commission_percentage = (float) get_option('taptosell_platform_commission', 5);
    // Convert the percentage to a multiplier for calculations.
    return 1 + ($commission_percentage / 100);
}
/**
 * --- NEW (Phase 12 Fix): Modern replacement for the deprecated taptosell_get_page_by_title(). ---
 * Finds a single page by its exact title.
 *
 * @param string $title The title of the page to find.
 * @return WP_Post|null The post object if found, otherwise null.
 */
function taptosell_taptosell_get_page_by_title($title) {
    $pages = get_posts([
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'title'          => $title,
        'posts_per_page' => 1,
        'no_found_rows'  => true, // Performance improvement
    ]);

    if (!empty($pages)) {
        return $pages[0]; // Return the first (and only) post object
    }

    return null; // Return null if no page was found
}