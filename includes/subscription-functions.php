<?php
// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Adds custom subscription fields to the user profile screen (for admins).
 */
function taptosell_add_subscription_fields_to_profile($user) {
    // Only show these fields if the current user can edit this profile
    // AND the user being edited is a dropshipper.
    if (!current_user_can('edit_user', $user->ID) || !in_array('dropshipper', (array)$user->roles)) {
        return;
    }

    // Get existing values
    $status = get_user_meta($user->ID, '_subscription_status', true);
    $expiry_date = get_user_meta($user->ID, '_subscription_expiry_date', true);
    ?>
    <h3>TapToSell Subscription</h3>
    <table class="form-table">
        <tr>
            <th><label for="subscription_status">Subscription Status</label></th>
            <td>
                <select name="subscription_status" id="subscription_status">
                    <option value="inactive" <?php selected($status, 'inactive'); ?>>Inactive</option>
                    <option value="active" <?php selected($status, 'active'); ?>>Active</option>
                </select>
                <p class="description">Set the user's subscription status.</p>
            </td>
        </tr>
        <tr>
            <th><label for="subscription_expiry_date">Expiry Date</label></th>
            <td>
                <input type="date" name="subscription_expiry_date" id="subscription_expiry_date" value="<?php echo esc_attr($expiry_date); ?>" class="regular-text">
                <p class="description">Set the subscription expiry date. The system will check against this date.</p>
            </td>
        </tr>
    </table>
    <?php
}
// Add these fields to the user edit screen
add_action('show_user_profile', 'taptosell_add_subscription_fields_to_profile');
add_action('edit_user_profile', 'taptosell_add_subscription_fields_to_profile');


/**
 * Saves the custom subscription fields when a user profile is updated.
 */
function taptosell_save_subscription_fields($user_id) {
    // Check if the current user has permission to edit this profile
    if (!current_user_can('edit_user', $user_id)) {
        return false;
    }

    // Save the subscription status
    if (isset($_POST['subscription_status'])) {
        update_user_meta($user_id, '_subscription_status', sanitize_text_field($_POST['subscription_status']));
    }

    // Save the subscription expiry date
    if (isset($_POST['subscription_expiry_date'])) {
        update_user_meta($user_id, '_subscription_expiry_date', sanitize_text_field($_POST['subscription_expiry_date']));
    }
}
// Save the data when the profile is updated
add_action('personal_options_update', 'taptosell_save_subscription_fields');
add_action('edit_user_profile_update', 'taptosell_save_subscription_fields');

/**
 * Checks if a dropshipper can link a new product based on their subscription status.
 *
 * @param int $user_id The ID of the dropshipper.
 * @return bool True if they can link, false if they have reached their limit.
 */
function taptosell_can_user_link_product($user_id) {
    // Get the user's subscription details
    $status = get_user_meta($user_id, '_subscription_status', true);
    $expiry_date = get_user_meta($user_id, '_subscription_expiry_date', true);

    // Check for an active subscription
    if ($status === 'active' && !empty($expiry_date) && strtotime($expiry_date) > time()) {
        return true; // Active subscribers have no limits
    }

    // If not subscribed, check their free limit.
    // For now, we'll hard-code the limit at 5. We can make this an admin setting later.
    $free_limit = 5;

    global $wpdb;
    $table_name = $wpdb->prefix . 'taptosell_dropshipper_products';
    
    // Count how many products this user has already linked
    $linked_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(id) FROM $table_name WHERE dropshipper_id = %d AND marketplace_product_id IS NOT NULL",
        $user_id
    ));

    // Allow them to link if they are still under the free limit
    if ($linked_count < $free_limit) {
        return true;
    }

    // If they are not subscribed and have reached their limit, block them.
    return false;
}