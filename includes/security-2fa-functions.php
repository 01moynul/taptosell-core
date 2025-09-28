<?php
// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * --- 2FA: Generates and sends a 2FA verification code to a user's email. ---
 *
 * @param int $user_id The ID of the user to send the code to.
 * @param string $user_email The email address of the user.
 * @return bool True on success, false on failure.
 */
function taptosell_send_2fa_code($user_id, $user_email) {
    // Generate a secure 6-digit random code.
    $verification_code = rand(100000, 999999);

    // Save this code as temporary user meta. It will expire in 15 minutes.
    // The 'update_user_meta' function will create the meta field if it doesn't exist.
    update_user_meta($user_id, '_tts_2fa_code', $verification_code);
    update_user_meta($user_id, '_tts_2fa_code_timestamp', time()); // Store current time

    // Prepare and send the email
    $subject = 'Your Verification Code for TapToSell';
    $message = "Welcome to TapToSell!\n\n";
    $message .= "Your verification code is: " . $verification_code . "\n\n";
    $message .= "This code will expire in 15 minutes.\n\n";
    $message .= "If you did not request this, please ignore this email.\n";

    // Use WordPress's built-in mail function
    $sent = wp_mail($user_email, $subject, $message);

    return $sent;
}

/**
 * --- 2FA: Verifies the code submitted by the user. ---
 *
 * @param int $user_id The ID of the user being verified.
 * @param string $submitted_code The 6-digit code from the user's form submission.
 * @return string "valid", "invalid", or "expired".
 */
function taptosell_verify_2fa_code($user_id, $submitted_code) {
    // Retrieve the saved code and timestamp from the user's profile
    $saved_code = get_user_meta($user_id, '_tts_2fa_code', true);
    $timestamp = get_user_meta($user_id, '_tts_2fa_code_timestamp', true);

    // Check 1: Is there a saved code?
    if ( empty($saved_code) ) {
        return 'invalid';
    }

    // Check 2: Has the code expired? (15 minutes = 900 seconds)
    if ( (time() - $timestamp) > 900 ) {
        return 'expired';
    }

    // Check 3: Does the submitted code match the saved code?
    if ( $saved_code == $submitted_code ) {
        // Success! Clear the temporary codes from the database.
        delete_user_meta($user_id, '_tts_2fa_code');
        delete_user_meta($user_id, '_tts_2fa_code_timestamp');
        return 'valid';
    }

    // If none of the above, the code is simply incorrect.
    return 'invalid';
}

/**
 * --- 2FA: Deletes temporary 2FA codes from a user's profile. ---
 * This is a cleanup function used if the user needs a new code resent.
 * @param int $user_id The user ID.
 */
function taptosell_clear_2fa_codes($user_id) {
    delete_user_meta($user_id, '_tts_2fa_code');
    delete_user_meta($user_id, '_tts_2fa_code_timestamp');
}

// In: includes/security-2fa-functions.php

/**
 * --- NEW: Shortcode to display the 2FA verification form. ---
 *
 * This function handles both displaying the form and processing the submitted code.
 * Usage: [taptosell_2fa_verification_form]
 */
function taptosell_2fa_verification_form_shortcode() {
    // This page is only for logged-in users who need to be verified.
    if (!is_user_logged_in()) {
        // Redirect non-logged-in users away from this page.
        wp_redirect(wp_login_url());
        exit;
    }

    $user_id = get_current_user_id();
    $output = '';

    // --- Part 1: Handle Form Submission (when user clicks "Verify") ---
    if (isset($_POST['taptosell_2fa_submit'])) {
        // Security check
        if (!isset($_POST['taptosell_2fa_nonce']) || !wp_verify_nonce($_POST['taptosell_2fa_nonce'], 'taptosell_2fa_verify_action')) {
            wp_die('Security check failed!');
        }

        $submitted_code = sanitize_text_field($_POST['2fa_code']);
        $result = taptosell_verify_2fa_code($user_id, $submitted_code);

        if ($result === 'valid') {
            // If the code is valid, update the account status from 'unverified' to 'pending'.
            // This is the final step before the admin needs to approve the account.
            update_user_meta($user_id, '_account_status', 'pending');
            $output .= '<div class="tts-notice tts-notice-success">Verification successful! Your account is now pending admin approval. You will be notified by email once it is active.</div>';
            return $output; // We stop here and only show the success message.
        } elseif ($result === 'expired') {
            $output .= '<div class="tts-notice tts-notice-error">The verification code has expired. Please use the button below to request a new one.</div>';
        } else { // 'invalid'
            $output .= '<div class="tts-notice tts-notice-error">The code you entered is incorrect. Please check and try again.</div>';
        }
    }
    
    // --- Part 2: Handle "Resend Code" Request ---
    if (isset($_POST['taptosell_2fa_resend'])) {
        if (!isset($_POST['taptosell_2fa_nonce']) || !wp_verify_nonce($_POST['taptosell_2fa_nonce'], 'taptosell_2fa_verify_action')) {
            wp_die('Security check failed!');
        }
        
        $current_user = wp_get_current_user();
        taptosell_clear_2fa_codes($user_id); // Clear any old codes first
        taptosell_send_2fa_code($user_id, $current_user->user_email); // Send a new one
        $output .= '<div class="tts-notice tts-notice-success">A new verification code has been sent to your email address.</div>';
    }

    // --- Part 3: Display the Verification Form ---
    $output .= '<h2>Verify Your Account</h2>';
    $output .= '<p>We have sent a 6-digit verification code to your email address. Please enter it below to proceed. The code will expire in 15 minutes.</p>';
    
    $output .= '<form method="post" action="">';
    // Add a nonce field for security
    $output .= wp_nonce_field('taptosell_2fa_verify_action', 'taptosell_2fa_nonce', true, false);
    
    $output .= '<p><label for="2fa_code">Verification Code</label><br/>';
    $output .= '<input type="text" name="2fa_code" id="2fa_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required style="width: 150px; text-align: center; font-size: 1.2em;" /></p>';
    
    $output .= '<p>';
    $output .= '<button type="submit" name="taptosell_2fa_submit" class="button button-primary">Verify</button> ';
    $output .= '<button type="submit" name="taptosell_2fa_resend" class="button">Resend Code</button>';
    $output .= '</p>';
    
    $output .= '</form>';

    return $output;
}
add_shortcode('taptosell_2fa_verification_form', 'taptosell_2fa_verification_form_shortcode');