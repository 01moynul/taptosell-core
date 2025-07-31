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