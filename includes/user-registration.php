<?php
// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * --- REBUILT: Handles the new, detailed Dropshipper registration form. ---
 */
function taptosell_handle_registration_form() {
    // Check if our registration form has been submitted
    if (isset($_POST['taptosell_register_nonce']) && wp_verify_nonce($_POST['taptosell_register_nonce'], 'taptosell_register')) {

        // --- Step 1: Sanitize and retrieve all the new form fields ---
        $email          = sanitize_email($_POST['email']);
        $password       = $_POST['password'];
        $confirm_pass   = $_POST['confirm_password'];
        $role           = 'dropshipper'; // Role is now hardcoded
        
        $company_name   = sanitize_text_field($_POST['company_name']);
        $full_name      = sanitize_text_field($_POST['full_name']);
        $ic_number      = sanitize_text_field($_POST['ic_number']);
        $gender         = sanitize_text_field($_POST['gender']);
        $mobile         = sanitize_text_field($_POST['mobile']);
        $address        = sanitize_textarea_field($_POST['address']);
        $postcode       = sanitize_text_field($_POST['postcode']);
        $city           = sanitize_text_field($_POST['city']);
        $state          = sanitize_text_field($_POST['state']);

        // --- Step 2: Automatically create a username from the email address ---
        // e.g., "test@example.com" becomes "test". We add numbers if it's already taken.
        $username = sanitize_user(explode('@', $email)[0]);
        $original_username = $username;
        $i = 1;
        while(username_exists($username)) {
            $username = $original_username . $i;
            $i++;
        }
        
        // --- Step 3: Run more validation checks ---
        if (empty($email) || empty($password) || empty($full_name) || empty($ic_number) || empty($mobile)) {
            wp_redirect(add_query_arg('registration_error', 'empty_fields', wp_get_referer()));
            exit;
        }
        if ($password !== $confirm_pass) {
            wp_redirect(add_query_arg('registration_error', 'password_mismatch', wp_get_referer()));
            exit;
        }
        if (email_exists($email)) {
            wp_redirect(add_query_arg('registration_error', 'email_exists', wp_get_referer()));
            exit;
        }

        // --- Step 4: Create the user with basic WordPress info ---
        $user_data = [
            'user_login' => $username,
            'user_email' => $email,
            'user_pass'  => $password,
            'role'       => $role,
            'display_name' => $full_name // Use their full name as the display name
        ];
        $user_id = wp_insert_user($user_data);

        // --- Step 5: If user was created, save all extra data and start 2FA ---
        if (!is_wp_error($user_id)) {
            
            // Save all the extra details to the user's profile meta fields
            update_user_meta($user_id, 'company_name', $company_name);
            update_user_meta($user_id, 'full_name', $full_name);
            update_user_meta($user_id, 'ic_number', $ic_number);
            update_user_meta($user_id, 'gender', $gender);
            update_user_meta($user_id, 'mobile_number', $mobile); // Use a more specific meta key
            update_user_meta($user_id, 'billing_address_1', $address);
            update_user_meta($user_id, 'billing_postcode', $postcode);
            update_user_meta($user_id, 'billing_city', $city);
            update_user_meta($user_id, 'billing_state', $state);
            update_user_meta($user_id, 'billing_country', 'MY'); // Hardcode country to Malaysia
            
            // The 2FA workflow remains exactly the same
            update_user_meta($user_id, '_account_status', 'unverified');
            taptosell_send_2fa_code($user_id, $email);
            wp_set_current_user($user_id);
            wp_set_auth_cookie($user_id, true);
            $redirect_url = home_url('/verify-account/'); 
            wp_redirect($redirect_url);
            exit;

        } else {
            // If wp_insert_user failed for some reason
            wp_redirect(add_query_arg('registration_error', 'user_creation_failed', wp_get_referer()));
            exit;
        }
    }
}
add_action('init', 'taptosell_handle_registration_form');

/**
 * --- REBUILT: Shortcode to display the new, detailed Dropshipper registration form. ---
 * This form is now hardcoded for the 'dropshipper' role.
 */
function taptosell_registration_form_shortcode() {
    ob_start();
    // --- NEW: Display detailed error messages ---
    if (isset($_GET['registration_error'])) {
        $error_code = $_GET['registration_error'];
        $error_message = 'An unknown error occurred. Please try again.'; // Default message
        
        if ($error_code === 'empty_fields') {
            $error_message = 'Please fill in all required fields.';
        } elseif ($error_code === 'password_mismatch') {
            $error_message = 'The passwords you entered do not match. Please try again.';
        } elseif ($error_code === 'email_exists') {
            $error_message = 'This email address is already registered. Please log in or use a different email.';
        } elseif ($error_code === 'user_creation_failed') {
            $error_message = 'Could not create user. Please contact support.';
        }
        
        echo '<div class="tts-notice tts-notice-error" style="background-color: #f8d7da; color: #721c24; padding: 15px; margin-bottom: 20px;">' . esc_html($error_message) . '</div>';
    }
    // --- END of new block ---
    
    ?>
    <style>
        /* Simple two-column layout for the new form */
        .tts-reg-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        @media (max-width: 768px) { .tts-reg-form-grid { grid-template-columns: 1fr; } }
        .tts-reg-form-grid .form-column { display: flex; flex-direction: column; gap: 15px; }
    </style>

    <form action="" method="post" class="taptosell-form">
        
        <?php wp_nonce_field( 'taptosell_register', 'taptosell_register_nonce' ); ?>
        
        <?php // This hidden input tells our handler that a dropshipper is registering. ?>
        <input type="hidden" name="role" value="dropshipper">

        <div class="tts-reg-form-grid">

            <div class="form-column">
                <p><label for="email">Email</label>
                   <input type="email" name="email" id="email" required></p>
                
                <p><label for="password">Password</label>
                   <input type="password" name="password" id="password" required></p>

                <p><label for="confirm_password">Confirm Password</label>
                   <input type="password" name="confirm_password" id="confirm_password" required></p>

                <p><label for="company_name">Company Name (If Any)</label>
                   <input type="text" name="company_name" id="company_name"></p>
                
                <p><label for="full_name">Full Name (As Per IC)</label>
                   <input type="text" name="full_name" id="full_name" required></p>

                <p><label for="ic_number">IC Number (e.g., 8901221055...)</label>
                   <input type="text" name="ic_number" id="ic_number" required></p>
                
                <p><label for="gender">Gender</label>
                   <select name="gender" id="gender">
                        <option value="">Select Gender</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                   </select></p>
            </div>

            <div class="form-column">
                <p><label for="mobile">Mobile</label>
                   <input type="text" name="mobile" id="mobile" required></p>

                <p><label for="address">Address</label>
                   <textarea name="address" id="address" rows="3"></textarea></p>

                <p><label for="postcode">Postcode</label>
                   <input type="text" name="postcode" id="postcode"></p>

                <p><label for="city">City</label>
                   <input type="text" name="city" id="city"></p>

                <p><label for="state">State</label>
                   <select name="state" id="state">
                        <option value="">Select State</option>
                        <option value="Johor">Johor</option>
                        <option value="Kedah">Kedah</option>
                        <option value="Kelantan">Kelantan</option>
                        <option value="Kuala Lumpur">Kuala Lumpur</option>
                        <option value="Labuan">Labuan</option>
                        <option value="Melaka">Melaka</option>
                        <option value="Negeri Sembilan">Negeri Sembilan</option>
                        <option value="Pahang">Pahang</option>
                        <option value="Penang">Penang</option>
                        <option value="Perak">Perak</option>
                        <option value="Perlis">Perlis</option>
                        <option value="Putrajaya">Putrajaya</option>
                        <option value="Sabah">Sabah</option>
                        <option value="Sarawak">Sarawak</option>
                        <option value="Selangor">Selangor</option>
                        <option value="Terengganu">Terengganu</option>
                   </select></p>
                
                <p><label for="country">Country</label>
                   <input type="text" name="country" id="country" value="Malaysia" readonly></p>
            </div>

        </div>

        <p style="margin-top: 20px;">
            <?php // Note: We will add reCAPTCHA functionality in a later step. ?>
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