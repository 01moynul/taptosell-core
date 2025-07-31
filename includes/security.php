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