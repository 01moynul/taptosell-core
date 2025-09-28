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