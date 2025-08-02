<?php
// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Handles the registration form submission.
 * Marks new users as 'pending' and creates an admin notification.
 */
function taptosell_handle_registration() {
    if ( isset( $_POST['taptosell_register_submit'] ) ) {
        if ( ! isset( $_POST['taptosell_nonce_field'] ) || ! wp_verify_nonce( $_POST['taptosell_nonce_field'], 'taptosell_nonce_action' ) ) {
            wp_die( 'Security check failed!' );
        }
        
        $username = sanitize_user( $_POST['taptosell_username'] );
        $email    = sanitize_email( $_POST['taptosell_email'] );
        $password = $_POST['taptosell_password'];
        $role     = sanitize_text_field( $_POST['taptosell_role'] );

        if ( empty($username) || empty($email) || empty($password) || empty($role) || !is_email($email) || username_exists($username) || email_exists($email) || !in_array($role, ['supplier', 'dropshipper']) ) {
            return;
        }

        $user_id = wp_insert_user([
            'user_login' => $username,
            'user_email' => $email,
            'user_pass'  => $password,
            'role'       => $role
        ]);

        if ( ! is_wp_error( $user_id ) ) {
            update_user_meta($user_id, '_account_status', 'pending');
            
            // --- NEW: Generate a notification for all Operational Admins ---
            $op_admins = get_users(['role' => 'operational_admin', 'fields' => 'ID']);
            if (!empty($op_admins)) {
                $message = 'New user "' . esc_html($username) . '" has registered and is pending approval.';
                $link = admin_url('users.php');
                foreach ($op_admins as $admin_id) {
                    taptosell_add_notification($admin_id, $message, $link);
                }
            }
            
            $login_url = wp_login_url() . '?registration=pending';
            wp_redirect( $login_url );
            exit;
        }
    }
}
add_action( 'init', 'taptosell_handle_registration' );


function taptosell_registration_form_shortcode() {
    ob_start();
    ?>
    <form action="" method="post" class="taptosell-form">
        <?php wp_nonce_field( 'taptosell_nonce_action', 'taptosell_nonce_field' ); ?>
        <p>
            <label for="taptosell-username">Username</label>
            <input type="text" name="taptosell_username" id="taptosell-username" required>
        </p>
        <p>
            <label for="taptosell-email">Email</label>
            <input type="email" name="taptosell_email" id="taptosell-email" required>
        </p>
        <p>
            <label for="taptosell-password">Password</label>
            <input type="password" name="taptosell_password" id="taptosell-password" required>
        </p>
        <p>
            <label for="taptosell-role">I am a...</label>
            <select name="taptosell_role" id="taptosell-role" required>
                <option value="">Select Role</option>
                <option value="supplier">Supplier</option>
                <option value="dropshipper">Dropshipper</option>
            </select>
        </p>
        <p>
            <input type="submit" name="taptosell_register_submit" value="Register">
        </p>
    </form>
    <?php
    return ob_get_clean();
}
add_shortcode( 'taptosell_registration_form', 'taptosell_registration_form_shortcode' );

/**
 * Displays a custom message on the login screen for pending accounts.
 */
function taptosell_custom_login_message( $message ) {
    if ( isset($_GET['registration']) && $_GET['registration'] === 'pending' ) {
        $message = '<p class="message" style="border-left-color: #ffb900;">Thank you for registering! Your account is currently pending approval by an administrator.</p>';
    }
    return $message;
}
add_filter( 'login_message', 'taptosell_custom_login_message' );

/**
 * Prevents users with a 'pending' account status from logging in.
 */
function taptosell_prevent_pending_user_login($user, $password) {
    // Get the user's account status meta
    $account_status = get_user_meta($user->ID, '_account_status', true);

    // If the status is 'pending', return a WP_Error to block the login
    if ($account_status === 'pending') {
        return new WP_Error(
            'pending_approval',
            __('<strong>Error:</strong> Your account is still pending approval.')
        );
    }

    // If status is not 'pending', return the user object to allow the login
    return $user;
}
// Hook into the authentication process. It runs after username/password are validated.
add_filter('wp_authenticate_user', 'taptosell_prevent_pending_user_login', 10, 2);