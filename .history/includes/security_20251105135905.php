<?php
// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function taptosell_remove_admin_from_role_list( $roles ) {
    if ( is_user_logged_in() && current_user_can('operational_admin') && !current_user_can('administrator') ) {
        unset( $roles['administrator'] );
    }
    return $roles;
}
add_filter( 'editable_roles', 'taptosell_remove_admin_from_role_list' );


function taptosell_prevent_admin_user_edit() {
    if ( ! current_user_can('operational_admin') || current_user_can('administrator') ) {
        return;
    }

    $user_ids_to_check = [];

    if ( isset($_REQUEST['user_id']) ) {
        $user_ids_to_check[] = (int) $_REQUEST['user_id'];
    }

    if ( isset($_REQUEST['users']) && is_array($_REQUEST['users']) ) {
        $user_ids_to_check = array_map('intval', $_REQUEST['users']);
    }

    if ( empty($user_ids_to_check) ) {
        return;
    }

    foreach ( $user_ids_to_check as $user_id ) {
        $user_to_edit = get_userdata( $user_id );
        
        if ( $user_to_edit && in_array('administrator', $user_to_edit->roles) ) {
            wp_die( 'Security check failed. You do not have permission to edit Administrator accounts.' );
        }
    }
}
add_action( 'admin_init', 'taptosell_prevent_admin_user_edit' );

// In: includes/security.php

/**
 * --- UPDATED: Protects the Supplier Registration page more securely. ---
 */
function taptosell_protect_supplier_registration_page() {
    $supplier_reg_page = get_page_by_title('Supplier Registration');

    // Only run our logic if the user is currently trying to view the Supplier Registration page.
    if ( $supplier_reg_page && is_page($supplier_reg_page->ID) ) {

        // --- NEW, TIGHTER SECURITY LOGIC ---

        // Condition 1: Allow access if the user is an admin AND is trying to edit the page.
        // This lets you use page builders like Divi/Elementor without being blocked.
        $is_admin_editing = (is_user_logged_in() && current_user_can('manage_options') && (is_admin() || isset($_GET['et_fb']) || isset($_GET['elementor-preview'])));
        if ($is_admin_editing) {
            return; // Stop here and allow access.
        }

        // Condition 2: Allow access if the correct registration key is in the URL.
        $correct_key = get_option('taptosell_supplier_reg_key');
        $provided_key = isset($_GET['reg_key']) ? sanitize_text_field($_GET['reg_key']) : '';

        if ( !empty($correct_key) && hash_equals($correct_key, $provided_key) ) {
             // hash_equals is a more secure way to compare strings
            return; // Stop here and allow access.
        }

        // --- If NEITHER of the above conditions are met, redirect the user away. ---
        wp_redirect( home_url() );
        exit;
    }
}
add_action('template_redirect', 'taptosell_protect_supplier_registration_page');

/**
 * --- NEW (Phase 11): Access Control for Operational Admin Dashboard ---
 * This function ensures that only users with the 'operational_admin' or 'administrator'
 * role can access the front-end Operational Admin Dashboard page.
 * It hooks into 'template_redirect' to check on every page load.
 */
function taptosell_oa_dashboard_access_control() {
    // Check if the current page is our "Operational Admin Dashboard"
    // This checks the page's slug (the part in the URL).
    if ( is_page('operational-admin-dashboard') ) {
        
        // If the user is NOT logged in OR does NOT have the correct role...
        if ( !is_user_logged_in() || (!current_user_can('administrator') && !current_user_can('operational_admin')) ) {
            
            // ...redirect them to the homepage immediately.
            wp_redirect( home_url() );
            exit;
        }
    }
}
add_action( 'template_redirect', 'taptosell_oa_dashboard_access_control' );
/**
 * --- NEW: Disables the WordPress Admin Bar for specific user roles. ---
 *
 * This function checks the current user's role and hides the top admin bar
 * for everyone except main Administrators, creating a cleaner frontend experience.
 *
 * @param bool $show Whether to show the admin bar.
 * @return bool
 */
function taptosell_hide_admin_bar_for_custom_roles($show) {
    // First, check if a user is logged in. If not, do nothing.
    if (!is_user_logged_in()) {
        return $show;
    }

    // Always show the admin bar for users who can 'manage_options' (Administrators).
    if (current_user_can('manage_options')) {
        return true;
    }
    
    // If the user is NOT an admin, hide the bar. This covers our Supplier,
    // Dropshipper, and Operational Admin roles.
    return false;
}
add_filter('show_admin_bar', 'taptosell_hide_admin_bar_for_custom_roles');


/**
 * --- NEW: Redirects non-admin users away from the WordPress backend. ---
 *
 * This function hooks into the admin area's initialization and checks the user's
 * role. If they are not an Administrator, it redirects them to their appropriate
 * frontend dashboard, preventing access to /wp-admin/.
 */
function taptosell_redirect_non_admins_from_backend() {
    // 1. Do not redirect for AJAX requests OR REST API requests.
    // We check for '/wp-json/' in the URL because 'admin_init'
    // runs before the REST_REQUEST constant is defined.
    if (wp_doing_ajax() || (strpos($_SERVER['REQUEST_URI'], '/wp-json/') !== false)) {
        return;
    }

    // 2. Do not redirect for users who can 'manage_options' (Administrators).
    if (current_user_can('manage_options')) {
        return;
    }

    // 3. If we're here, the user is a non-admin trying to access the backend.
    // Let's find their correct frontend dashboard and redirect them.
    $user = wp_get_current_user();
    $roles = (array) $user->roles;
    $redirect_url = home_url(); // A safe fallback to the homepage.

    if (in_array('operational_admin', $roles)) {
        $dashboard_page = taptosell_get_page_by_title('Operational Admin Dashboard');
        if ($dashboard_page) { $redirect_url = get_permalink($dashboard_page->ID); }
    } elseif (in_array('supplier', $roles)) {
        $dashboard_page = taptosell_get_page_by_title('Supplier Dashboard');
        if ($dashboard_page) { $redirect_url = get_permalink($dashboard_page->ID); }
    } elseif (in_array('dropshipper', $roles)) {
        $dashboard_page = taptosell_get_page_by_title('Dropshipper Dashboard');
        if ($dashboard_page) { $redirect_url = get_permalink($dashboard_page->ID); }
    }

    // 4. Perform the redirect.
    wp_redirect($redirect_url);
    exit;
}
add_action('admin_init', 'taptosell_redirect_non_admins_from_backend');

/**
 * --- NEW: Redirects logged-out users from the default wp-login.php page. ---
 *
 * This function prevents users from directly viewing the backend login form.
 * It redirects them to the homepage, encouraging the use of the frontend
 * login modal. It still allows essential actions like processing a login (POST),
 * logging out, or resetting a password.
 */
function taptosell_redirect_from_login_page() {
    // Get the action being performed on the login page, if any.
    $action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'login';

    // If data is being submitted (a login attempt) or the action is one we need
    // to allow (logout, lostpassword, etc.), then don't redirect.
    if ( !empty($_POST) || in_array($action, ['logout', 'lostpassword', 'rp', 'register', 'postpass']) ) {
        return;
    }

    // If none of the above conditions are met, the user is simply trying to view
    // the login page. Redirect them to the homepage.
    wp_redirect( home_url('/') );
    exit;
}
add_action('login_init', 'taptosell_redirect_from_login_page');