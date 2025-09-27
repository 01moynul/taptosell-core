<?php
// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Create/update all custom roles, capabilities, and database tables on plugin activation.
 */
function taptosell_add_custom_roles() {
    // --- Role and capability definitions ---
    $custom_caps = [
        'manage_taptosell_settings' => true, 'manage_product_category' => true, 'edit_product_category' => true,
        'delete_product_category'   => true, 'assign_product_category' => true, 'manage_brand' => true,
        'edit_brand' => true, 'delete_brand' => true, 'assign_brand' => true,
    ];
    $admin_role = get_role('administrator');
    if ($admin_role) { foreach ($custom_caps as $cap => $grant) { $admin_role->add_cap($cap); } }

    remove_role('operational_admin');
    $op_admin_caps = get_role('editor')->capabilities;
    $op_admin_caps['list_users'] = true; $op_admin_caps['edit_users'] = true; $op_admin_caps['remove_users'] = true;
    $op_admin_caps['create_users'] = true; $op_admin_caps['promote_users'] = true;
    $op_admin_caps = array_merge($op_admin_caps, $custom_caps);
    add_role('operational_admin', 'Operational Admin', $op_admin_caps);
    
    remove_role('supplier');
    add_role('supplier', 'Supplier', get_role('editor')->capabilities);

    remove_role('dropshipper');
    add_role('dropshipper', 'Dropshipper', ['read' => true]);


    // --- Create/Update Database Tables ---
    global $wpdb;
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    $charset_collate = $wpdb->get_charset_collate();

    // Table for Dropshipper SRPs and Product Links
    $srp_table_name = $wpdb->prefix . 'taptosell_dropshipper_products';
    $sql_srp = "CREATE TABLE $srp_table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        dropshipper_id bigint(20) UNSIGNED NOT NULL,
        taptosell_product_id bigint(20) UNSIGNED NOT NULL,
        marketplace_product_id bigint(20) UNSIGNED,
        marketplace varchar(50),
        srp decimal(10,2),
        date_added datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY dropshipper_product (dropshipper_id, taptosell_product_id)
    ) $charset_collate;";
    dbDelta($sql_srp);

    // Wallet Transactions Table
    $wallet_table_name = $wpdb->prefix . 'taptosell_wallet_transactions';
    $sql_wallet = "CREATE TABLE $wallet_table_name ( transaction_id bigint(20) NOT NULL AUTO_INCREMENT, user_id bigint(20) UNSIGNED NOT NULL, amount decimal(10,2) NOT NULL, type varchar(50) NOT NULL, details text, transaction_date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL, PRIMARY KEY  (transaction_id), KEY user_id (user_id) ) $charset_collate;";
    dbDelta($sql_wallet);

    // Table for Price Change Requests
    $price_change_table_name = $wpdb->prefix . 'taptosell_price_changes';
    $sql_price_changes = "CREATE TABLE $price_change_table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        product_id bigint(20) UNSIGNED NOT NULL,
        supplier_id bigint(20) UNSIGNED NOT NULL,
        old_price decimal(10,2) NOT NULL,
        new_price decimal(10,2) NOT NULL,
        request_date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        status varchar(20) DEFAULT 'pending' NOT NULL,
        PRIMARY KEY  (id),
        KEY product_id (product_id)
    ) $charset_collate;";
    dbDelta($sql_price_changes);

    // Table for Notifications
    $notifications_table_name = $wpdb->prefix . 'taptosell_notifications';
    $sql_notifications = "CREATE TABLE $notifications_table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) UNSIGNED NOT NULL,
        message text NOT NULL,
        link varchar(255) DEFAULT '' NOT NULL,
        is_read tinyint(1) DEFAULT 0 NOT NULL,
        created_date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        PRIMARY KEY  (id),
        KEY user_id (user_id)
    ) $charset_collate;";
    dbDelta($sql_notifications);
}
register_activation_hook( TAPTOSELL_CORE_PATH . 'taptosell-core.php', 'taptosell_add_custom_roles' );


/**
 * Remove custom user roles and all custom capabilities on plugin deactivation.
 */
function taptosell_remove_custom_roles() {
    // --- Define All Custom Capabilities ---
    $custom_caps = [
        'manage_taptosell_settings', 'manage_product_category', 'edit_product_category',
        'delete_product_category', 'assign_product_category', 'manage_brand', 'edit_brand',
        'delete_brand', 'assign_brand'
    ];
    
    // --- Remove capabilities from Administrator and Operational Admin ---
    $roles_to_update = ['administrator', 'operational_admin'];
    foreach ($roles_to_update as $role_name) {
        $role = get_role($role_name);
        if ($role) {
            foreach ($custom_caps as $cap) {
                $role->remove_cap($cap);
            }
        }
    }
    
    // --- Remove the custom roles ---
    remove_role('operational_admin');
    remove_role('supplier');
    remove_role('dropshipper');
}
register_deactivation_hook( TAPTOSELL_CORE_PATH . 'taptosell-core.php', 'taptosell_remove_custom_roles' );

/**
 * Enqueue frontend styles and scripts.
 */
function taptosell_enqueue_frontend_styles() {
    // Enqueue the main plugin stylesheet
    wp_enqueue_style(
        'taptosell-core-styles',
        TAPTOSELL_CORE_URL . 'assets/css/taptosell-styles.css',
        array(),
        TAPTOSELL_CORE_VERSION
    );

    // --- ADD THIS BLOCK ---
    // Enqueue the new frontend script for modal, etc.
    wp_enqueue_script(
        'taptosell-frontend-scripts',
        TAPTOSELL_CORE_URL . 'assets/js/frontend-scripts.js',
        array('jquery'), // Make sure jQuery is loaded first
        TAPTOSELL_CORE_VERSION,
        true // Load in the footer
    );
    // ---------------------
}
add_action('wp_enqueue_scripts', 'taptosell_enqueue_frontend_styles');

// In: includes/core-hooks.php

/**
 * --- NEW: Enqueues logout warning script on the FRONT-END for admins with pending tasks. ---
 */
function taptosell_enqueue_frontend_logout_warning() {
    // Only run this for logged-in users who can edit other's posts (Admins and OAs)
    if (!is_user_logged_in() || !current_user_can('edit_others_posts')) {
        return;
    }

    // Check for pending tasks (this logic is the same as in our admin-ui.php file)
    $product_counts = wp_count_posts('product');
    $pending_products_count = isset($product_counts->draft) ? $product_counts->draft : 0;
    $user_query = new WP_User_Query(['meta_key' => '_account_status', 'meta_value' => 'pending']);
    $pending_users_count = $user_query->get_total();
    $total_pending = $pending_products_count + $pending_users_count;

    // If there are no pending tasks, do nothing.
    if ($total_pending == 0) {
        return;
    }
    
    // If there ARE pending tasks, load our script and its data
    wp_enqueue_script(
        'taptosell-logout-warning', 
        TAPTOSELL_CORE_URL . 'assets/js/logout-warning.js', 
        ['jquery'], 
        '1.2', // Version bumped
        true
    );

    $current_user = wp_get_current_user();
    $user_role = !empty($current_user->roles) ? $current_user->roles[0] : '';
    
    wp_localize_script(
        'taptosell-logout-warning',
        'taptosellLogoutData',
        [
            'pendingTasks' => $total_pending,
            'currentUserRole' => $user_role,
        ]
    );
}
add_action('wp_enqueue_scripts', 'taptosell_enqueue_frontend_logout_warning');

// In: includes/core-hooks.php

/**
 * --- NEW (Replaces old wallet-specific function): Prevents caching on key dynamic pages. ---
 * Many live servers use aggressive page caching. This function checks if we are on a page
 * that needs to display dynamic data (like status messages) and tells caching systems
 * not to serve a stale version of the page.
 */
function taptosell_prevent_dynamic_page_caching() {
    // This is our master list of page titles that should NEVER be cached.
    $no_cache_pages = [
        'My Wallet',
        'Product Catalog',
        'My Store',
        'My Orders',
        'Dropshipper Dashboard',
        'Supplier Dashboard',
    ];

    // Check if the current request is for a single page.
    if ( is_page() ) {
        global $post;
        // Check if the current page's title is in our no-cache list.
        if ( isset($post->post_title) && in_array($post->post_title, $no_cache_pages) ) {
            // This is the standard WordPress constant to prevent caching.
            if ( ! defined('DONOTCACHEPAGE') ) {
                define('DONOTCACHEPAGE', true);
            }
        }
    }
}
add_action( 'template_redirect', 'taptosell_prevent_dynamic_page_caching' );

?>