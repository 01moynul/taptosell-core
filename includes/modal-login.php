<?php
// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Generates the HTML for the login modal.
 * This is hooked into wp_footer so it's available on all pages.
 */
function taptosell_render_login_modal() {
    // Don't show the modal if the user is already logged in
    if ( is_user_logged_in() ) {
        return;
    }
    ?>
    <div id="taptosell-login-modal" class="taptosell-modal-overlay" style="display: none;">
        <div class="taptosell-modal-content">
            <a href="#" id="taptosell-close-login-modal" class="taptosell-modal-close">&times;</a>
            <h3 class="taptosell-modal-title">Welcome Back</h3>
            
            <?php
            // Define arguments for the login form
            $args = [
                'echo'           => true,
                'remember'       => true,
                'redirect'       => ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], // Redirect back to the current page
                'form_id'        => 'taptosell-login-form',
                'id_username'    => 'taptosell-user-login',
                'id_password'    => 'taptosell-user-pass',
                'id_remember'    => 'taptosell-rememberme',
                'id_submit'      => 'taptosell-wp-submit',
                'label_username' => __( 'Username or Email Address' ),
                'label_password' => __( 'Password' ),
                'label_remember' => __( 'Remember Me' ),
                'label_log_in'   => __( 'Log In' ),
                'value_username' => '',
                'value_remember' => false,
            ];
            
            // Display the WordPress login form
            wp_login_form( $args );
            ?>
            <div class="taptosell-modal-links">
                <a href="<?php echo esc_url( wp_lostpassword_url() ); ?>">Lost your password?</a>
                <?php if ( get_option( 'users_can_register' ) ) : ?>
                    <a href="<?php echo esc_url( wp_registration_url() ); ?>">Register</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}
add_action( 'wp_footer', 'taptosell_render_login_modal' );