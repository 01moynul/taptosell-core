<?php
// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Add the TapToSell Admin Settings page and its submenus.
 */
function taptosell_add_admin_menu() {
    // Add the top-level menu item
    add_menu_page(
        'TapToSell',                // Page Title (visible when on the page)
        'TapToSell',                // Menu Title (visible in the sidebar)
        'manage_taptosell_settings',// Capability required
        'taptosell_settings',       // Menu Slug (unique identifier)
        'taptosell_settings_page_html', // Function to display the page content
        'dashicons-store',          // Icon
        25                          // Position
    );

    // Explicitly add the "Settings" submenu page
    add_submenu_page(
        'taptosell_settings',       // Parent Slug
        'TapToSell Settings',       // Page Title
        'Settings',                 // Menu Title
        'manage_taptosell_settings',// Capability
        'taptosell_settings',       // Menu Slug (same as parent to make it the default)
        'taptosell_settings_page_html' // Function
    );

    // --- NEW: Add the "Price Requests" submenu page ---
    add_submenu_page(
        'taptosell_settings',           // Parent Slug
        'Price Change Requests',        // Page Title
        'Price Requests',               // Menu Title
        'manage_taptosell_settings',    // Capability
        'taptosell_price_requests',     // Menu Slug
        'taptosell_price_requests_page_html' // Display function (we will create this next)
    );
}
add_action('admin_menu', 'taptosell_add_admin_menu');

/**
 * Display the HTML for the settings page.
 */
function taptosell_settings_page_html() {
    // CORRECTED: Check for our custom capability
    if (!current_user_can('manage_taptosell_settings')) {
        return;
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <form action="options.php" method="post">
            <?php
            settings_fields('taptosell_settings_group');
            do_settings_sections('taptosell_settings');
            submit_button('Save Settings');
            ?>
        </form>
    </div>
    <?php
}

/**
 * Register the settings, sections, and fields.
 */
function taptosell_register_settings() {
    // Register the setting group
    register_setting('taptosell_settings_group', 'taptosell_platform_commission');

    // Add a section to the page
    add_settings_section(
        'taptosell_commission_section',
        'Pricing Settings',
        null,
        'taptosell_settings'
    );

    // Add the commission field to the section
    add_settings_field(
        'taptosell_platform_commission',
        'Platform Commission (%)',
        'taptosell_commission_field_html',
        'taptosell_settings',
        'taptosell_commission_section'
    );
}
add_action('admin_init', 'taptosell_register_settings');

/**
 * Display the HTML for the commission input field.
 */
function taptosell_commission_field_html() {
    $commission = get_option('taptosell_platform_commission', 5); // Default to 5%
    ?>
    <input type="number" name="taptosell_platform_commission" value="<?php echo esc_attr($commission); ?>" min="0" step="0.1" />
    <p class="description">Enter the commission percentage the platform takes. E.g., enter '5' for 5%.</p>
    <?php
}