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
            $dashboard_page = taptosell_get_page_by_title('Dropshipper Dashboard');
            if ( $dashboard_page ) {
                return get_permalink($dashboard_page->ID);
            }
        }
        
        // Check for Supplier role
        if ( in_array( 'supplier', $user->roles ) ) {
            $dashboard_page = taptosell_get_page_by_title('Supplier Dashboard');
            if ( $dashboard_page ) {
                return get_permalink($dashboard_page->ID);
            }
        }
    }
    
    // For all other users (Admin, etc.), return the default redirect URL.
    return $redirect_to;
}
add_filter( 'login_redirect', 'taptosell_role_based_login_redirect', 10, 3 );

// ... (at the end of the file, after the taptosell_login_redirect function)

/**
 * Conditionally adds Login or Logout links to the primary menu.
 * This filter checks if the user is logged in and appends the correct link.
 *
 * @param string $items The HTML list of menu items.
 * @param stdClass $args An object containing menu arguments.
 * @return string The modified HTML list of menu items.
 */
function taptosell_add_login_logout_links( $items, $args ) {
    
    // Check if this is the 'primary-menu' (which Divi uses)
    if ( isset($args->theme_location) && $args->theme_location == 'primary-menu' ) {

        if ( is_user_logged_in() ) {
            // User is logged in: Add a "Logout" link
            $logout_url = wp_logout_url( home_url( '/' ) ); // Redirect to homepage on logout
            $items .= '<li class="menu-item tts-logout-link"><a href="' . esc_url( $logout_url ) . '" class="tts-logout-button">Logout</a></li>';
        
        } else {
            // User is logged out: Add our "Login" modal trigger
            $items .= '<li class="menu-item tts-login-link"><a href="#" class="tts-login-trigger">Login</a></li>';
        }
    }
    
    return $items;
}
add_filter( 'wp_nav_menu_items', 'taptosell_add_login_logout_links', 10, 2 );

?>