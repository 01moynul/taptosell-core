<?php
// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * --- FINAL VERSION: Handles both Dropshipper and Supplier registration forms. ---
 * This function now checks the submitted 'role' and processes the appropriate fields,
 * including secure file uploads for suppliers.
 */
function taptosell_handle_registration_form() {
    // Check if our registration form has been submitted
    if (isset($_POST['taptosell_register_nonce']) && wp_verify_nonce($_POST['taptosell_register_nonce'], 'taptosell_register')) {

        // --- Step 1: Sanitize and validate common fields ---
        $email          = sanitize_email($_POST['email']);
        $password       = $_POST['password'];
        $confirm_pass   = $_POST['confirm_password'];
        $role           = sanitize_text_field($_POST['role']); // 'dropshipper' or 'supplier'

        // Basic validation for fields common to both forms
        if (empty($email) || empty($password) || !in_array($role, ['dropshipper', 'supplier'])) {
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

        // --- Step 2: Automatically create a unique username ---
        $username = sanitize_user(explode('@', $email)[0]);
        $original_username = $username;
        $i = 1;
        while(username_exists($username)) {
            $username = $original_username . $i;
            $i++;
        }
        
        // --- Step 3: Create the user (same for both roles) ---
        $user_data = [
            'user_login' => $username,
            'user_email' => $email,
            'user_pass'  => $password,
            'role'       => $role,
        ];
        $user_id = wp_insert_user($user_data);

        // --- Step 4: If user was created, save role-specific data ---
        if (!is_wp_error($user_id)) {
            
            // --- A) If they are a DROPSHIPPER, save their specific meta fields ---
            if ($role === 'dropshipper') {
                $full_name = sanitize_text_field($_POST['full_name']);
                wp_update_user(['ID' => $user_id, 'display_name' => $full_name]);

                update_user_meta($user_id, 'company_name', sanitize_text_field($_POST['company_name']));
                update_user_meta($user_id, 'full_name', $full_name);
                update_user_meta($user_id, 'ic_number', sanitize_text_field($_POST['ic_number']));
                update_user_meta($user_id, 'gender', sanitize_text_field($_POST['gender']));
                update_user_meta($user_id, 'mobile_number', sanitize_text_field($_POST['mobile']));
                update_user_meta($user_id, 'billing_address_1', sanitize_textarea_field($_POST['address']));
                update_user_meta($user_id, 'billing_postcode', sanitize_text_field($_POST['postcode']));
                update_user_meta($user_id, 'billing_city', sanitize_text_field($_POST['city']));
                update_user_meta($user_id, 'billing_state', sanitize_text_field($_POST['state']));
                update_user_meta($user_id, 'billing_country', 'MY');
            }

            // --- B) If they are a SUPPLIER, save their specific meta fields and handle file uploads ---
            if ($role === 'supplier') {
                $pic_name = sanitize_text_field($_POST['pic_name']);
                wp_update_user(['ID' => $user_id, 'display_name' => $pic_name]);
                
                update_user_meta($user_id, 'company_name', sanitize_text_field($_POST['company_name']));
                update_user_meta($user_id, 'pic_name', $pic_name);
                update_user_meta($user_id, 'mobile_number', sanitize_text_field($_POST['mobile']));
                update_user_meta($user_id, 'billing_address_1', sanitize_textarea_field($_POST['address']));
                update_user_meta($user_id, 'billing_postcode', sanitize_text_field($_POST['postcode']));

                // --- Handle File Uploads ---
                if (!function_exists('wp_handle_upload')) {
                    require_once(ABSPATH . 'wp-admin/includes/file.php');
                }

                // Handle SSM Document Upload
                if (isset($_FILES['ssm_document']) && $_FILES['ssm_document']['error'] == 0) {
                    $ssm_file = wp_handle_upload($_FILES['ssm_document'], ['test_form' => false]);
                    if ($ssm_file && !isset($ssm_file['error'])) {
                        // Save the URL of the uploaded file to user meta
                        update_user_meta($user_id, 'ssm_document_url', $ssm_file['url']);
                    }
                }

                // Handle Bank Statement Upload
                if (isset($_FILES['bank_statement']) && $_FILES['bank_statement']['error'] == 0) {
                    $bank_file = wp_handle_upload($_FILES['bank_statement'], ['test_form' => false]);
                    if ($bank_file && !isset($bank_file['error'])) {
                        // Save the URL of the uploaded file to user meta
                        update_user_meta($user_id, 'bank_statement_url', $bank_file['url']);
                    }
                }
            }

            // --- Step 5: The 2FA workflow runs for EVERYONE ---
            update_user_meta($user_id, '_account_status', 'unverified');
            taptosell_send_2fa_code($user_id, $email);
            wp_set_current_user($user_id);
            wp_set_auth_cookie($user_id, true);
            $redirect_url = home_url('/verify-account/'); 
            wp_redirect($redirect_url);
            exit;

        } else {
            // Handle error if user creation failed
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

/**
 * --- NEW: Shortcode to display the new, dedicated Supplier registration form. ---
 * This form includes file uploads for verification documents.
 * Usage: [taptosell_supplier_registration_form]
 */
function taptosell_supplier_registration_form_shortcode() {
    ob_start();

    // Display any error messages passed in the URL
    if (isset($_GET['registration_error'])) {
        $error_code = $_GET['registration_error'];
        $error_message = 'An unknown error occurred. Please try again.';
        if ($error_code === 'empty_fields') { $error_message = 'Please fill in all required fields.'; }
        if ($error_code === 'password_mismatch') { $error_message = 'The passwords you entered do not match.'; }
        if ($error_code === 'email_exists') { $error_message = 'This email address is already registered.'; }
        echo '<div class="tts-notice tts-notice-error">' . esc_html($error_message) . '</div>';
    }
    ?>

    <style>
        .tts-reg-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        @media (max-width: 768px) { .tts-reg-form-grid { grid-template-columns: 1fr; } }
        .tts-reg-form-grid .form-column { display: flex; flex-direction: column; gap: 15px; }
        .tts-form-section-header { font-size: 1.4em; font-weight: bold; margin-top: 30px; margin-bottom: 10px; grid-column: 1 / -1; border-bottom: 1px solid #ddd; padding-bottom: 5px; }
    </style>

    <form action="" method="post" class="taptosell-form" enctype="multipart/form-data">
        
        <?php wp_nonce_field( 'taptosell_register', 'taptosell_register_nonce' ); ?>
        
        <?php // This hidden input tells our handler that a supplier is registering. ?>
        <input type="hidden" name="role" value="supplier">

        <div class="tts-reg-form-grid">

            <div class="tts-form-section-header">Account Details</div>
            
            <div class="form-column">
                <p><label for="email">Email</label>
                   <input type="email" name="email" id="email" required></p>
            </div>
            <div class="form-column"></div> <?php // Empty column for alignment ?>

            <div class="form-column">
                <p><label for="password">Password</label>
                   <input type="password" name="password" id="password" required></p>
            </div>
            <div class="form-column">
                <p><label for="confirm_password">Confirm Password</label>
                   <input type="password" name="confirm_password" id="confirm_password" required></p>
            </div>

            <div class="tts-form-section-header">Company Information</div>

            <div class="form-column">
                <p><label for="company_name">Company Name (As per SSM)</label>
                   <input type="text" name="company_name" id="company_name" required></p>
                
                <p><label for="pic_name">Person in Charge (PIC) Name</label>
                   <input type="text" name="pic_name" id="pic_name" required></p>

                <p><label for="mobile">Mobile</label>
                   <input type="text" name="mobile" id="mobile" required></p>
            </div>
            <div class="form-column">
                <p><label for="address">Address</label>
                   <textarea name="address" id="address" rows="3" required></textarea></p>

                <p><label for="postcode">Postcode</label>
                   <input type="text" name="postcode" id="postcode" required></p>
            </div>

            <div class="tts-form-section-header">Document Uploads</div>
            <p style="grid-column: 1 / -1; margin-top: -10px; font-style: italic;">Please provide these documents for verification.</p>
            
            <div class="form-column">
                 <p><label for="ssm_document">SSM Document (PDF, JPG, PNG)</label>
                   <input type="file" name="ssm_document" id="ssm_document" accept=".pdf,.jpg,.jpeg,.png"></p>
            </div>
            <div class="form-column">
                 <p><label for="bank_statement">Bank Statement (PDF, JPG, PNG)</label>
                   <input type="file" name="bank_statement" id="bank_statement" accept=".pdf,.jpg,.jpeg,.png"></p>
            </div>

        </div>

        <p style="margin-top: 20px;">
            <input type="submit" value="Register as Supplier">
        </p>
    </form>
    <?php
    return ob_get_clean();
}
add_shortcode('taptosell_supplier_registration_form', 'taptosell_supplier_registration_form_shortcode');