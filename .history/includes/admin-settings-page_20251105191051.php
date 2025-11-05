<?php
// In: includes/admin-settings-page.php (Replace all content)

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Add the TapToSell Admin Settings menu.
 */
function taptosell_add_admin_menu() {
    add_menu_page('TapToSell', 'TapToSell', 'manage_taptosell_settings', 'taptosell_settings', 'taptosell_settings_page_html', 'dashicons-store', 25);
    add_submenu_page('taptosell_settings', 'Settings', 'Settings', 'manage_taptosell_settings', 'taptosell_settings', 'taptosell_settings_page_html');
    add_submenu_page('taptosell_settings', 'Price Requests', 'Price Requests', 'manage_taptosell_settings', 'taptosell_price_requests', 'taptosell_price_requests_page_html');
}
add_action('admin_menu', 'taptosell_add_admin_menu');

/**
 * --- NEW: Custom handler for our settings page form. ---
 * This gives us more control over the "Save" and "Regenerate" buttons.
 */
function taptosell_handle_settings_form() {
    // Check if a form was submitted from our settings page and the nonce is valid.
    if ( !isset($_POST['taptosell_settings_nonce']) || !wp_verify_nonce($_POST['taptosell_settings_nonce'], 'taptosell_settings_actions') ) {
        return;
    }
    // Ensure the user has the correct permissions.
    if (!current_user_can('manage_taptosell_settings')) {
        wp_die('You do not have permission to save these settings.');
    }

    // Check if the "Regenerate Key" button was clicked.
    if (isset($_POST['regenerate_reg_key'])) {
        $new_key = wp_generate_password(16, false);
        update_option('taptosell_supplier_reg_key', $new_key);
        add_settings_error('taptosell_settings', 'key_regenerated', 'New supplier registration key has been generated.', 'updated');

    } elseif (isset($_POST['submit'])) { // Check if the main "Save Settings" button was clicked.
        // Only save the platform commission here.
        if (isset($_POST['taptosell_platform_commission'])) {
            update_option('taptosell_platform_commission', sanitize_text_field($_POST['taptosell_platform_commission']));
        }
        // We do NOT save the key here. This prevents it from being overwritten with a blank value.
        add_settings_error('taptosell_settings', 'settings_saved', 'Settings saved.', 'updated');
    }

    // Store the admin notices so they can be displayed after the redirect.
    set_transient('settings_errors', get_settings_errors(), 30);
    
    // Redirect back to the settings page to prevent form resubmission.
    wp_safe_redirect(admin_url('admin.php?page=taptosell_settings'));
    exit;
}
add_action('admin_post_taptosell_save_settings', 'taptosell_handle_settings_form');

/**
 * --- UPDATED: Display the HTML for the settings page. ---
 * The form now posts to our custom handler.
 */
function taptosell_settings_page_html() {
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <?php settings_errors(); // This function displays our admin notices. ?>
        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
            <input type="hidden" name="action" value="taptosell_save_settings">
            <?php wp_nonce_field('taptosell_settings_actions', 'taptosell_settings_nonce'); ?>
            <?php do_settings_sections('taptosell_settings'); // Renders our fields ?>
            <?php submit_button('Save Settings'); ?>
        </form>
    </div>
    <?php
}

/**
 * --- REFACTORED: We still use this to register the fields for display. ---
 */
function taptosell_register_settings() {
    add_settings_section('taptosell_main_settings_section', 'General Settings', null, 'taptosell_settings');
    add_settings_field('taptosell_platform_commission', 'Platform Commission (%)', 'taptosell_commission_field_html', 'taptosell_settings', 'taptosell_main_settings_section');
    add_settings_field('taptosell_supplier_reg_key', 'Supplier Registration Key', 'taptosell_supplier_reg_key_field_html', 'taptosell_settings', 'taptosell_main_settings_section');
}
add_action('admin_init', 'taptosell_register_settings');

/**
 * Display the HTML for the commission input field. (No changes here)
 */
function taptosell_commission_field_html() {
    $commission = get_option('taptosell_platform_commission', 5);
    ?>
    <input type="number" name="taptosell_platform_commission" value="<?php echo esc_attr($commission); ?>" min="0" step="0.1" />
    <p class="description">Enter the commission percentage the platform takes.</p>
    <?php
}

/**
 * --- UPDATED: Display the HTML for the supplier registration key field. ---
 * The regenerate button is now a proper submit button within the main form.
 */
function taptosell_supplier_reg_key_field_html() {
    $reg_key = get_option('taptosell_supplier_reg_key');
    if (empty($reg_key)) {
        $reg_key = wp_generate_password(16, false);
        update_option('taptosell_supplier_reg_key', $reg_key);
    }
    
    $supplier_reg_page = taptosell_get_page_by_title('Supplier Registration');
    $reg_url = $supplier_reg_page ? get_permalink($supplier_reg_page->ID) : home_url('/supplier-registration/');
    ?>
    <input type="text" value="<?php echo esc_attr($reg_key); ?>" class="regular-text" readonly />
    <p class="description">
        This is the private key for supplier registration.
        <br><strong>Current Registration Link:</strong><br>
        <code><?php echo esc_url(add_query_arg('reg_key', $reg_key, $reg_url)); ?></code>
    </p>
    <p style="margin-top: 15px;">
        <input type="submit" name="regenerate_reg_key" class="button button-secondary" value="Regenerate Key">
        <br><em><span style="color:red;">Warning:</span> This will immediately invalidate the current link.</em>
    </p>
    <?php
}