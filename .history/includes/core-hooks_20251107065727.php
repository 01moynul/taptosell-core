<?php
// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * --- UPDATED: Create/update all custom roles and capabilities on plugin activation. ---
 * Now grants full product management capabilities to the Operational Admin.
 */
/*function taptosell_add_custom_roles() {
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
    
    // --- NEW: Add capabilities for managing our 'product' CPT ---
    $op_admin_caps['publish_products'] = true;
    $op_admin_caps['edit_products'] = true;
    $op_admin_caps['edit_others_products'] = true;
    $op_admin_caps['read_private_products'] = true;
    $op_admin_caps['delete_products'] = true;
    $op_admin_caps['delete_others_products'] = true;
    // --- End of new capabilities ---

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
    $sql_srp = "CREATE TABLE $srp_table_name ( id mediumint(9) NOT NULL AUTO_INCREMENT, dropshipper_id bigint(20) UNSIGNED NOT NULL, taptosell_product_id bigint(20) UNSIGNED NOT NULL, marketplace_product_id bigint(20) UNSIGNED, marketplace varchar(50), srp decimal(10,2), date_added datetime DEFAULT '0000-00-00 00:00:00' NOT NULL, PRIMARY KEY  (id), UNIQUE KEY dropshipper_product (dropshipper_id, taptosell_product_id) ) $charset_collate;";
    dbDelta($sql_srp);

    // Wallet Transactions Table
    $wallet_table_name = $wpdb->prefix . 'taptosell_wallet_transactions';
    $sql_wallet = "CREATE TABLE $wallet_table_name ( transaction_id bigint(20) NOT NULL AUTO_INCREMENT, user_id bigint(20) UNSIGNED NOT NULL, amount decimal(10,2) NOT NULL, type varchar(50) NOT NULL, details text, transaction_date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL, PRIMARY KEY  (transaction_id), KEY user_id (user_id) ) $charset_collate;";
    dbDelta($sql_wallet);

    // Table for Price Change Requests
    $price_change_table_name = $wpdb->prefix . 'taptosell_price_changes';
    $sql_price_changes = "CREATE TABLE $price_change_table_name ( id mediumint(9) NOT NULL AUTO_INCREMENT, product_id bigint(20) UNSIGNED NOT NULL, supplier_id bigint(20) UNSIGNED NOT NULL, old_price decimal(10,2) NOT NULL, new_price decimal(10,2) NOT NULL, request_date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL, status varchar(20) DEFAULT 'pending' NOT NULL, PRIMARY KEY  (id), KEY product_id (product_id) ) $charset_collate;";
    dbDelta($sql_price_changes);

    // Table for Notifications
    $notifications_table_name = $wpdb->prefix . 'taptosell_notifications';
    $sql_notifications = "CREATE TABLE $notifications_table_name ( id bigint(20) NOT NULL AUTO_INCREMENT, user_id bigint(20) UNSIGNED NOT NULL, message text NOT NULL, link varchar(255) DEFAULT '' NOT NULL, is_read tinyint(1) DEFAULT 0 NOT NULL, created_date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL, PRIMARY KEY  (id), KEY user_id (user_id) ) $charset_collate;";
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

    // Enqueue the new frontend script for modal, etc.
    wp_enqueue_script(
        'taptosell-frontend-scripts',
        TAPTOSELL_CORE_URL . 'assets/js/frontend-scripts.js',
        array('jquery'), // Make sure jQuery is loaded first
        TAPTOSELL_CORE_VERSION,
        true // Load in the footer
    );
    //--NEW: Force Dashicons to load for logged-in users ---
    // Hiding the admin bar can prevent this, so we load it manually.
    if ( is_user_logged_in() ) {
        wp_enqueue_style( 'dashicons' );
    }

    // --- START: React App Integration ---
    // This code block loads our built React app on the "Add New Product" page.
    
    // 1. Only load these scripts on the 'Add New Product' page.
    if ( is_page('add-new-product') ) {

        // 2. Define a unique name (handle) for our main React script.
        $react_app_handle = 'taptosell-react-app';

        // 3. Register the main React CSS file.
        wp_enqueue_style(
            $react_app_handle . '-css', // Handle: 'taptosell-react-app-css'
            TAPTOSELL_CORE_URL . 'assets/css/react-app.css',
            array(),
            TAPTOSELL_CORE_VERSION
        );
        
        // 4. Register the main React JS file.
        wp_register_script(
            $react_app_handle, // Handle: 'taptosell-react-app'
            TAPTOSELL_CORE_URL . 'assets/js/react-app.js',
            array(), // This script has no dependencies like jQuery
            TAPTOSELL_CORE_VERSION,
            true // Load in the footer
        );

        // 5. Pass data from PHP to React (API URL and Security Nonce).
        // This is how we replace Basic Auth.
        wp_localize_script(
            $react_app_handle, // Target our main script
            'taptosell_react_data', // The JavaScript object name React will look for
            array(
                // Provides the correct base URL for the API
                'api_url' => esc_url_raw( rest_url( 'taptosell/v1/' ) ),
                // Creates a secure, one-time-use token for authentication
                'nonce'   => wp_create_nonce( 'wp_rest' ) 
            )
        );

        // 6. Finally, enqueue the main React script to be loaded.
        wp_enqueue_script( $react_app_handle );

        // 7. Enqueue the 'chunk' file. We MUST hardcode the name here.
        // If you run 'npm run build' again, this name will change!
        wp_enqueue_script(
            $react_app_handle . '-chunk', // Handle: 'taptosell-react-app-chunk'
            TAPTOSELL_CORE_URL . 'assets/js/453.3f680a5e.chunk.js',
            array( $react_app_handle ), // This chunk DEPENDS on the main app script
            TAPTOSELL_CORE_VERSION,
            true // Load in the footer
        );
    }
    // --- END: React App Integration ---

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

/**
 * --- UPDATED (Phase 10): Enqueues the script for the product variations form. ---
 * Now loads on both "Add New Product" and "Edit Product" pages.
 * Passes saved variation data to the script on the edit page.
 */
/*function taptosell_enqueue_variations_script() {
    // We need this script on both the 'Add New Product' and 'Edit Product' pages.
    if ( is_page('add-new-product') || is_page('edit-product') ) {
        
        wp_enqueue_script(
            'taptosell-variations',
            TAPTOSELL_CORE_URL . 'assets/js/product-variations.js',
            ['jquery'], // This script depends on jQuery
            TAPTOSELL_CORE_VERSION,
            true // Load in the footer
        );

        // --- If we are on the EDIT page, pass the product's variation data to the script ---
        if ( is_page('edit-product') && isset($_GET['product_id']) ) {
            $product_id = (int)$_GET['product_id'];
            
            // Get the saved variation data from post meta
            $variation_attributes = get_post_meta($product_id, '_variation_attributes', true);
            $variations_data = get_post_meta($product_id, '_variations', true);

            // Create an array to hold the data
            $data_to_pass = [
                'attributes' => !empty($variation_attributes) ? $variation_attributes : [],
                'variations' => !empty($variations_data) ? $variations_data : [],
            ];
            
            // Use wp_localize_script to make this PHP data available in our JS file
            wp_localize_script('taptosell-variations', 'taptosell_edit_data', $data_to_pass);
        }
    }
}
add_action('wp_enqueue_scripts', 'taptosell_enqueue_variations_script');

/**
 * --- FINAL VERSION: Enqueues scripts for the OA Dashboard. ---
 * Consolidates all JavaScript variables (AJAX URL and nonces) into a single
 * localization object to prevent conflicts and ensure all data is available.
 */
/*function taptosell_enqueue_oa_dashboard_scripts() {
    // Only load these scripts on our OA Dashboard page
    if ( is_page('operational-admin-dashboard') ) {
        wp_enqueue_script(
            'taptosell-oa-dashboard-scripts', // Script handle
            TAPTOSELL_CORE_URL . 'assets/js/oa-dashboard.js',
            ['jquery'],
            '1.2.1', // --- Bumping the version number to help clear cache ---
            true 
        );

        // --- CORRECTED & EXPANDED: This passes all necessary data to our JavaScript file ---
        wp_localize_script(
            'taptosell-oa-dashboard-scripts', 
            'taptosell_ajax_object', // This is the correct object name our new JS file is looking for
            [
                'ajax_url'              => admin_url('admin-ajax.php'),
                'user_actions_nonce'    => wp_create_nonce('taptosell_oa_user_actions_nonce'),
                'product_actions_nonce' => wp_create_nonce('taptosell_oa_product_actions_nonce'),
            ]
        );
    }
}
// The add_action hook remains unchanged.
add_action('wp_enqueue_scripts', 'taptosell_enqueue_oa_dashboard_scripts');
/**
 * --- REVISED: Dynamically Filter Menu Items Based on User Role ---
 * This function uses a "whitelist" approach to show only the menu items
 * appropriate for the current user's role and login status.
 *
 * @param array $sorted_menu_items The menu items, sorted by appearance order.
 * @return array The filtered menu items.
 */
/*function taptosell_filter_nav_menu_items($sorted_menu_items) {
    // Get the current user object and their roles
    $user = wp_get_current_user();
    $is_logged_in = $user->exists();
    $user_roles = (array) $user->roles;

    // --- Rule for Administrators: Show everything and stop here. ---
    if ( in_array('administrator', $user_roles) ) {
        return $sorted_menu_items;
    }

    // --- Define Page Groups (Titles must EXACTLY match your WordPress Menu items) ---
    $public_pages           = ['Home', 'coming soon'];
    $logged_out_only_pages  = ['Login', 'Register'];
    $supplier_pages         = ['Supplier Dashboard','My Wallet'];
    $dropshipper_pages      = ['Dropshipper Dashboard', 'Product Catalog', 'My Store', 'My Wallet', 'My Shops', 'My Subscription', 'Product Matching'];
    $oa_pages               = ['Operational Admin Dashboard'];
    $authenticated_shared   = ['Logout', 'Notifications',]; // Pages for any logged-in user

    // --- Build the final list of allowed page titles for the current user ---
    $allowed_pages = $public_pages;

    if ($is_logged_in) {
        $allowed_pages = array_merge($allowed_pages, $authenticated_shared);

        // Add pages specific to the Operational Admin role
        if (in_array('operational_admin', $user_roles)) {
            $allowed_pages = array_merge($allowed_pages, $oa_pages);
        
        // Add pages specific to the Supplier role
        } elseif (in_array('supplier', $user_roles)) {
            $allowed_pages = array_merge($allowed_pages, $supplier_pages);
            $allowed_pages[] = 'My Wallet'; // Suppliers also need the Wallet
        
        // Add pages specific to the Dropshipper role
        } elseif (in_array('dropshipper', $user_roles)) {
            $allowed_pages = array_merge($allowed_pages, $dropshipper_pages);
        }

    } else {
        // User is not logged in, so only show public and logged-out pages
        $allowed_pages = array_merge($allowed_pages, $logged_out_only_pages);
    }

    // --- Filter the menu items (Using the original, reliable comparison method) ---
    foreach ($sorted_menu_items as $key => $menu_item) {
        // If the current menu item's title is NOT in our allowed list, remove it.
        if (!in_array($menu_item->title, $allowed_pages)) {
            unset($sorted_menu_items[$key]);
        }
    }
    
    return $sorted_menu_items;
}
add_filter('wp_nav_menu_objects', 'taptosell_filter_nav_menu_items', 10, 1);

/**
 * --- REVISED CORS HANDLING (For React Dev Server) ---
 * Uses the 'rest_pre_serve_request' filter to add CORS headers.
 * This should reliably allow credentials (cookies) from localhost:3000.
 *
 * @param bool $served Whether the request has already been served.
 * @param WP_REST_Response $result The result object.
 * @param WP_REST_Request $request The request object.
 * @param WP_REST_Server $server The server object.
 * @return bool True if served, false otherwise.
 */
/*function taptosell_add_cors_headers_for_dev( $served, $result, $request, $server ) {
    // Check if the request is coming from our React dev server
    if ( isset( $_SERVER['HTTP_ORIGIN'] ) && $_SERVER['HTTP_ORIGIN'] === 'http://localhost:3000' ) {
        
        // Add the necessary CORS headers directly to the response
        $server->send_header( 'Access-Control-Allow-Origin', 'http://localhost:3000' );
        $server->send_header( 'Access-Control-Allow-Credentials', 'true' );
        $server->send_header( 'Access-Control-Allow-Methods', 'GET, POST, OPTIONS' );
        // Allow specific headers (like the X-WP-Nonce we will need later AND Authorization for Basic Auth)
        $server->send_header( 'Access-Control-Allow-Headers', 'Authorization, X-WP-Nonce, Content-Type, X-Requested-With' );

        // Handle pre-flight OPTIONS requests (important for browsers)
        if ( 'OPTIONS' === $request->get_method() ) {
            // Send a simple 200 OK response for OPTIONS requests and stop processing.
            // Setting $served to true tells the REST server not to continue.
            //status_header( 200 ); // No need for this, $served=true handles it.
            return true; 
        }
    }
    
    // Allow the request to continue processing normally if it's not from our dev server or not an OPTIONS request.
    return $served; 
}
// Hook into the filter with priority 15 (runs after authentication checks)
add_filter( 'rest_pre_serve_request', 'taptosell_add_cors_headers_for_dev', 15, 4 );

/**
 * --- DEVELOPMENT ONLY: Force enable Application Passwords ---
 * Overrides potential theme/plugin conflicts when WP_ENVIRONMENT_TYPE is 'development'.
 */
function taptosell_force_enable_app_passwords_for_dev( $available ) {
    // Only force enable if the environment is explicitly set to development
    if ( defined('WP_ENVIRONMENT_TYPE') && WP_ENVIRONMENT_TYPE === 'development' ) {
        return true; // Force enable
    }
    return $available; // Otherwise, respect the default WordPress behavior
}
add_filter( 'wp_is_application_passwords_available', 'taptosell_force_enable_app_passwords_for_dev', 99 ); // High priority