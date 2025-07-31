<?php
// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Redirects users based on their role upon login.
 * This single function handles all custom role redirects.
 *
 * @param string $redirect_to The redirect destination URL.
 * @param object $request The object containing the original request.
 * @param object $user The user object for the user who just logged in.
 * @return string The modified redirect destination URL.
 */
function taptosell_role_based_login_redirect( $redirect_to, $request, $user ) {
    // Check if the user object exists and has roles.
    if ( isset( $user->roles ) && is_array( $user->roles ) ) {
        
        // Check for Dropshipper role
        if ( in_array( 'dropshipper', $user->roles ) ) {
            $dashboard_page = get_page_by_title('Dropshipper Dashboard');
            if ( $dashboard_page ) {
                return get_permalink($dashboard_page->ID);
            }
        }
        
        // Check for Supplier role
        if ( in_array( 'supplier', $user->roles ) ) {
            $dashboard_page = get_page_by_title('Supplier Dashboard');
            if ( $dashboard_page ) {
                return get_permalink($dashboard_page->ID);
            }
        }
    }
    
    // For all other users (Admin, etc.), return the default redirect URL.
    return $redirect_to;
}
add_filter( 'login_redirect', 'taptosell_role_based_login_redirect', 10, 3 );