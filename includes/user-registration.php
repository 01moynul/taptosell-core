<?php
// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// In: includes/user-registration.php

/**
 * --- UPDATED: Handles the custom registration form submission with 2FA integration. ---
 */
function taptosell_handle_registration_form() {
    // Check if our registration form has been submitted
    if (isset($_POST['taptosell_register_nonce']) && wp_verify_nonce($_POST['taptosell_register_nonce'], 'taptosell_register')) {

        // Sanitize and validate all form fields
        $username = sanitize_user($_POST['username']);
        $email = sanitize_email($_POST['email']);
        $password = $_POST['password'];
        $role = sanitize_text_field($_POST['role']);

        // Basic validation checks
        if (empty($username) || empty($email) || empty($password) || empty($role)) {
            wp_redirect(add_query_arg('registration_error', 'empty_fields', wp_get_referer()));
            exit;
        }
        if (!is_email($email)) {
            wp_redirect(add_query_arg('registration_error', 'invalid_email', wp_get_referer()));
            exit;
        }
        if (username_exists($username)) {
            wp_redirect(add_query_arg('registration_error', 'username_exists', wp_get_referer()));
            exit;
        }
        if (email_exists($email)) {
            wp_redirect(add_query_arg('registration_error', 'email_exists', wp_get_referer()));
            exit;
        }
        if (!in_array($role, ['supplier', 'dropshipper'])) {
            wp_redirect(add_query_arg('registration_error', 'invalid_role', wp_get_referer()));
            exit;
        }

        // All checks passed, create the new user
        $user_data = [
            'user_login' => $username,
            'user_email' => $email,
            'user_pass'  => $password,
            'role'       => $role
        ];
        $user_id = wp_insert_user($user_data);

        // Check if user was created successfully
        if (!is_wp_error($user_id)) {
            
            // --- THIS IS THE NEW 2FA WORKFLOW ---

            // 1. Set the initial account status to 'unverified'.
            // The user cannot access anything until they verify their email.
            update_user_meta($user_id, '_account_status', 'unverified');

            // 2. Send the 2FA verification code to their email using our new function.
            taptosell_send_2fa_code($user_id, $email);

            // 3. Automatically log the new user in so they can access the verification page.
            wp_set_current_user($user_id);
            wp_set_auth_cookie($user_id, true);

            // 4. Redirect them to our new verification page to complete the process.
            // (Ensure your page slug is 'verify-account')
            $redirect_url = home_url('/verify-account/'); 
            wp_redirect($redirect_url);
            exit;
            
            // --- END OF NEW WORKFLOW ---

        } else {
            // If there was an error creating the user, redirect with a generic error
            wp_redirect(add_query_arg('registration_error', 'user_creation_failed', wp_get_referer()));
            exit;
        }
    }
}
// The add_action line remains the same
add_action('init', 'taptosell_handle_registration_form');

/**
 * --- CORRECTED: Shortcode to display the registration form. ---
 * This version uses the correct input names to match our new handler function.
 */
function taptosell_registration_form_shortcode() {
    ob_start();
    ?>
    <form action="" method="post" class="taptosell-form">
        
        <?php // CORRECTED: The nonce name now matches the handler. ?>
        <?php wp_nonce_field( 'taptosell_register', 'taptosell_register_nonce' ); ?>
        
        <p>
            <label for="username">Username</label>
            <?php // CORRECTED: The input name is now 'username'. ?>
            <input type="text" name="username" id="username" required>
        </p>
        <p>
            <label for="email">Email</label>
            <?php // CORRECTED: The input name is now 'email'. ?>
            <input type="email" name="email" id="email" required>
        </p>
        <p>
            <label for="password">Password</label>
            <?php // CORRECTED: The input name is now 'password'. ?>
            <input type="password" name="password" id="password" required>
        </p>
        <p>
            <label for="role">I am a...</label>
            <?php // CORRECTED: The input name is now 'role'. ?>
            <select name="role" id="role" required>
                <option value="">Select Role</option>
                <option value="supplier">Supplier</option>
                <option value="dropshipper">Dropshipper</option>
            </select>
        </p>
        <p>
            <?php // The submit button no longer needs a 'name' attribute. ?>
            <input type="submit" value="Register">
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