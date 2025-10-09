<?php
// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Adds a custom "Actions" column to the Products list in the admin panel.
 */
function taptosell_add_product_actions_column($columns) {
    // Add the new column after the 'title' column.
    $new_columns = [];
    foreach ($columns as $key => $title) {
        $new_columns[$key] = $title;
        if ($key === 'title') {
            $new_columns['approval_actions'] = 'Actions';
        }
    }
    return $new_columns;
}
add_filter('manage_product_posts_columns', 'taptosell_add_product_actions_column');


/**
 * --- CORRECTED: Displays the content for the custom "Actions" column. ---
 * Now shows actions for 'pending' products, not 'draft'.
 */
function taptosell_display_product_actions($column, $post_id) {
    // Only add links to our custom column.
    if ($column === 'approval_actions') {
        $post_status = get_post_status($post_id);
        
        // --- CORRECTED LOGIC: Only show actions for products that are pending review. ---
        if ($post_status === 'pending') {
            // Create a security nonce.
            $nonce = wp_create_nonce('taptosell_product_action_' . $post_id);
            
            // Build the "Approve" and "Reject" links with the necessary parameters.
            $approve_link = admin_url('admin.php?action=taptosell_approve_product&product_id=' . $post_id . '&_wpnonce=' . $nonce);
            $reject_link = admin_url('admin.php?action=taptosell_reject_product&product_id=' . $post_id . '&_wpnonce=' . $nonce);
            
            // Echo the links.
            printf('<a href="%s" style="color: #008000;">Approve</a> | <a href="%s" style="color: #a00;">Reject</a>', esc_url($approve_link), esc_url($reject_link));
        }
    }
}
add_action('manage_product_posts_custom_column', 'taptosell_display_product_actions', 10, 2);


/**
 * Handles the logic when an "Approve" or "Reject" link is clicked.
 */
function taptosell_handle_product_approval_actions() {
    // Check if the current user has permission to edit other's posts.
    if (!current_user_can('edit_others_posts')) {
        return;
    }

    // --- Handle Approve Action ---
    if (isset($_GET['action']) && $_GET['action'] === 'taptosell_approve_product') {
        $post_id = (int)$_GET['product_id'];
        check_admin_referer('taptosell_product_action_' . $post_id);

        wp_update_post([
            'ID' => $post_id,
            'post_status' => 'publish'
        ]);
        
        // --- NEW: Notify the supplier that their product was approved ---
        $supplier_id = get_post_field('post_author', $post_id);
        $product_title = get_the_title($post_id);
        $dashboard_page = get_page_by_title('Supplier Dashboard');
        $dashboard_link = $dashboard_page ? get_permalink($dashboard_page->ID) : '';
        $message = 'Congratulations! Your product "' . esc_html($product_title) . '" has been approved.';
        taptosell_add_notification($supplier_id, $message, $dashboard_link);

        wp_redirect(admin_url('edit.php?post_type=product'));
        exit();
    }

    // --- Handle Reject Action ---
    if (isset($_GET['action']) && $_GET['action'] === 'taptosell_reject_product') {
        $post_id = (int)$_GET['product_id'];
        check_admin_referer('taptosell_product_action_' . $post_id);

        wp_update_post([
            'ID' => $post_id,
            'post_status' => 'rejected'
        ]);
        
        // --- NEW: Notify the supplier that their product was rejected ---
        $supplier_id = get_post_field('post_author', $post_id);
        $product_title = get_the_title($post_id);
        $dashboard_page = get_page_by_title('Supplier Dashboard');
        $dashboard_link = $dashboard_page ? get_permalink($dashboard_page->ID) : '';
        $rejection_reason = get_post_meta($post_id, '_rejection_reason', true);
        $message = 'Your product "' . esc_html($product_title) . '" has been rejected.';
        if (!empty($rejection_reason)) {
            $message .= ' Reason: ' . esc_html($rejection_reason);
        }
        taptosell_add_notification($supplier_id, $message, $dashboard_link);
        
        wp_redirect(admin_url('edit.php?post_type=product'));
        exit();
    }
}
add_action('admin_init', 'taptosell_handle_product_approval_actions');
/**
 * =================================================================
 * ORDER ACTIONS (ADMIN-FACING - PAYMENT RELEASE)
 * =================================================================
 */

// Add a new "Actions" column to the Orders list table
function taptosell_add_order_actions_column($columns) {
    $columns['order_actions'] = 'Actions';
    return $columns;
}
add_filter('manage_taptosell_order_posts_columns', 'taptosell_add_order_actions_column');

// Render the content for the "Actions" column in the Orders list
function taptosell_display_order_actions_column($column, $post_id) {
    if ($column === 'order_actions') {
        $status = get_post_status($post_id);

        // Only show the action for orders that are "Shipped"
        if ($status === 'wc-shipped') {
            $nonce = wp_create_nonce('taptosell_complete_order_' . $post_id);
            $action_link = admin_url('admin.php?action=taptosell_complete_order&order_id=' . $post_id . '&_wpnonce=' . $nonce);
            
            printf('<a href="%s">Mark as Completed</a>', esc_url($action_link));
        }
    }
}
add_action('manage_taptosell_order_posts_custom_column', 'taptosell_display_order_actions_column', 10, 2);


/**
 * Handles the "Mark as Completed" action and releases payment to the supplier.
 */
function taptosell_handle_payment_release_action() {
    // Check if our action was triggered
    if (isset($_GET['action']) && $_GET['action'] === 'taptosell_complete_order') {
        
        // Permission check: Ensures only Admin or Operational Admin can run this.
        if (!current_user_can('edit_others_posts')) {
            wp_die('You do not have permission to perform this action.');
        }

        $order_id = (int)$_GET['order_id'];
        
        // Verify the security token (nonce)
        check_admin_referer('taptosell_complete_order_' . $order_id);

        // --- Start Transaction Logic ---

        // 1. Get all the necessary info
        $product_id = get_post_meta($order_id, '_product_id', true);
        $supplier_id = get_post_field('post_author', $product_id);
        $supplier_earning = (float) get_post_meta($product_id, '_price', true);

        // 2. Add the earning to the supplier's wallet if the data is valid
        if ($supplier_id && $supplier_earning > 0) {
            taptosell_add_wallet_transaction(
                $supplier_id,
                $supplier_earning,
                'payout', // Transaction type
                'Payout for Order #' . $order_id
            );
        }

        // 3. Update the order status to "Completed"
        wp_update_post([
            'ID' => $order_id,
            'post_status' => 'wc-completed'
        ]);
        
        // Redirect back to the orders list to show the result
        wp_redirect(admin_url('edit.php?post_type=taptosell_order'));
        exit();
    }
}
add_action('admin_init', 'taptosell_handle_payment_release_action');
/**
 * Handles the user approval and rejection actions.
 */
function taptosell_handle_user_approval() {
    // Check if current user can edit users before proceeding
    if (!current_user_can('edit_users')) {
        return;
    }

    // --- Handle Approve Action ---
    if (isset($_GET['action']) && $_GET['action'] === 'taptosell_approve_user') {
        $user_id = (int)$_GET['user_id'];
        check_admin_referer('taptosell_approve_user_' . $user_id);
        update_user_meta($user_id, '_account_status', 'approved');
        wp_redirect(admin_url('users.php'));
        exit();
    }

    // --- Handle Reject Action ---
    if (isset($_GET['action']) && $_GET['action'] === 'taptosell_reject_user') {
        $user_id = (int)$_GET['user_id'];
        check_admin_referer('taptosell_reject_user_' . $user_id);
        
        // This file is required to use the wp_delete_user() function
        require_once(ABSPATH.'wp-admin/includes/user.php');
        
        // Delete the user
        wp_delete_user($user_id);

        wp_redirect(admin_url('users.php'));
        exit();
    }
}
add_action('admin_init', 'taptosell_handle_user_approval');

/**
 * =================================================================
 * WITHDRAWAL ACTIONS (ADMIN-FACING)
 * =================================================================
 */

// Add a new "Actions" column to the Withdrawals list table
function taptosell_add_withdrawal_actions_column($columns) {
    $columns['withdrawal_actions'] = 'Actions';
    return $columns;
}
add_filter('manage_withdrawal_request_posts_columns', 'taptosell_add_withdrawal_actions_column');

// Render the content for the "Actions" column in the Withdrawals list
function taptosell_display_withdrawal_actions_column($column, $post_id) {
    if ($column === 'withdrawal_actions') {
        $status = get_post_status($post_id);

        // Only show the action for requests that are "Pending"
        if ($status === 'wd-pending') {
            $nonce = wp_create_nonce('taptosell_process_withdrawal_' . $post_id);
            $action_link = admin_url('admin.php?action=taptosell_process_withdrawal&withdrawal_id=' . $post_id . '&_wpnonce=' . $nonce);
            
            printf('<a href="%s">Mark as Processed</a>', esc_url($action_link));
        }
    }
}
add_action('manage_withdrawal_request_posts_custom_column', 'taptosell_display_withdrawal_actions_column', 10, 2);


/**
 * Handles the "Mark as Processed" action for withdrawal requests.
 */
function taptosell_handle_withdrawal_processing() {
    // Check if our action was triggered
    if (isset($_GET['action']) && $_GET['action'] === 'taptosell_process_withdrawal') {
        
        // Permission check: Ensures only Admin or Operational Admin can run this.
        if (!current_user_can('edit_others_posts')) {
            wp_die('You do not have permission to perform this action.');
        }

        $withdrawal_id = (int)$_GET['withdrawal_id'];
        
        // Verify nonce for security
        check_admin_referer('taptosell_process_withdrawal_' . $withdrawal_id);

        // Update the withdrawal request status to "Processed"
        wp_update_post([
            'ID' => $withdrawal_id,
            'post_status' => 'wd-processed'
        ]);
        
        // Redirect back to the withdrawals list
        wp_redirect(admin_url('edit.php?post_type=withdrawal_request'));
        exit();
    }
}
add_action('admin_init', 'taptosell_handle_withdrawal_processing');

/**
 * Saves the rejection reason when a product is updated in the admin panel.
 */
function taptosell_save_rejection_reason($post_id) {
    // Check if our nonce is set and verified.
    if ( !isset($_POST['taptosell_rejection_nonce']) || !wp_verify_nonce($_POST['taptosell_rejection_nonce'], 'taptosell_rejection_nonce_action') ) {
        return;
    }

    // Check if the current user has permission to edit the post.
    if ( !current_user_can('edit_post', $post_id) ) {
        return;
    }

    // Check if our rejection reason field is set in the form submission.
    if ( isset($_POST['rejection_reason']) ) {
        // Sanitize the input and update the post meta field.
        update_post_meta(
            $post_id,
            '_rejection_reason',
            sanitize_textarea_field($_POST['rejection_reason'])
        );
    }
}
// Hook into the save_post action for the 'product' post type.
add_action('save_post_product', 'taptosell_save_rejection_reason');

/**
 * --- NEW: Handles the Approve/Reject actions for price change requests. ---
 */
function taptosell_handle_price_request_actions() {
    // Check if the current user has permission to manage settings.
    if (!current_user_can('manage_taptosell_settings')) {
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'taptosell_price_changes';
    $redirect_url = admin_url('admin.php?page=taptosell_price_requests');

    // --- Handle Approve Action ---
    if (isset($_GET['action']) && $_GET['action'] === 'taptosell_approve_price') {
        $request_id = (int)$_GET['request_id'];
        check_admin_referer('taptosell_approve_price_' . $request_id);

        // Get the request details from the database
        $request = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $request_id));

        if ($request) {
            // 1. Update the product's price (post meta)
            update_post_meta($request->product_id, '_price', $request->new_price);

            // 2. Update the status of the request in our custom table
            $wpdb->update(
                $table_name,
                ['status' => 'approved'], // Data
                ['id' => $request_id]     // Where
            );
        }
        
        // Redirect back with a success message
        wp_redirect(add_query_arg('message', 'approved', $redirect_url));
        exit();
    }

    // --- Handle Reject Action ---
    if (isset($_GET['action']) && $_GET['action'] === 'taptosell_reject_price') {
        $request_id = (int)$_GET['request_id'];
        check_admin_referer('taptosell_reject_price_' . $request_id);

        // Update the status of the request in our custom table
        $wpdb->update(
            $table_name,
            ['status' => 'rejected'], // Data
            ['id' => $request_id]     // Where
        );
        
        // Redirect back with a success message
        wp_redirect(add_query_arg('message', 'rejected', $redirect_url));
        exit();
    }
}
add_action('admin_init', 'taptosell_handle_price_request_actions');

/**
 * --- UPDATED (Phase 11): Handles user approval/rejection from the front-end OA Dashboard. ---
 * Now includes the rejection reason in the notification email.
 */
function taptosell_handle_oa_user_actions() {
    if ( !isset($_GET['action']) || !is_page('operational-admin-dashboard') ) { return; }
    if ( !current_user_can('edit_users') ) { return; }

    $action = sanitize_key($_GET['action']);
    $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
    
    $redirect_url = add_query_arg('view', 'users', get_permalink(get_the_ID()));

    // --- Handle Approve Action ---
    if ($action === 'approve_user' && $user_id > 0) {
        check_admin_referer('oa_approve_user_' . $user_id);
        update_user_meta($user_id, '_account_status', 'approved');
        $user_info = get_userdata($user_id);
        wp_mail($user_info->user_email, 'Your Account has been Approved', 'Congratulations! Your TapToSell account has been approved. You can now log in and start using the platform.');
        wp_redirect(add_query_arg('message', 'user_approved', $redirect_url));
        exit;
    }

    // --- Handle Reject Action ---
    if ($action === 'reject_user' && $user_id > 0) {
        check_admin_referer('oa_reject_user_' . $user_id);
        require_once(ABSPATH.'wp-admin/includes/user.php');
        
        $user_info = get_userdata($user_id);
        $user_email = $user_info->user_email;
        
        // --- NEW: Get the rejection reason from the URL ---
        $rejection_reason = isset($_GET['reason']) ? sanitize_textarea_field(urldecode($_GET['reason'])) : '';
        $email_message = "We regret to inform you that your application for a TapToSell account has been rejected at this time.";
        if (!empty($rejection_reason)) {
            $email_message .= "\n\nReason: " . $rejection_reason;
        }

        wp_delete_user($user_id);
        wp_mail($user_email, 'Your Account Application', $email_message);
        wp_redirect(add_query_arg('message', 'user_rejected', $redirect_url));
        exit;
    }
}
add_action('template_redirect', 'taptosell_handle_oa_user_actions');
/**
 * --- NEW (Phase 11): AJAX handler to fetch and display user details for the OA dashboard. ---
 */
function taptosell_get_user_details_ajax_handler() {
    // Security checks: ensure a user is logged in, has the right permissions, and the request is valid.
    if ( !is_user_logged_in() || !current_user_can('edit_users') ) {
        wp_send_json_error(['message' => 'Permission denied.']);
    }
    check_ajax_referer('oa_view_user_details_nonce', 'security');

    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    if ($user_id <= 0) {
        wp_send_json_error(['message' => 'Invalid user ID.']);
    }

    $user_data = get_userdata($user_id);
    if (!$user_data) {
        wp_send_json_error(['message' => 'User not found.']);
    }

    // --- Start building the HTML to display in the modal ---
    ob_start();
    ?>
    <table class="form-table">
        <tr>
            <th>Username</th>
            <td><?php echo esc_html($user_data->user_login); ?></td>
        </tr>
        <tr>
            <th>Email</th>
            <td><?php echo esc_html($user_data->user_email); ?></td>
        </tr>
        <tr>
            <th>Role</th>
            <td><?php echo esc_html(ucfirst(implode(', ', $user_data->roles))); ?></td>
        </tr>
    </table>
    
    <hr>
    <h4>Registration Details</h4>
    <table class="form-table">
    <?php
    // Get all meta for this user
    $meta = get_user_meta($user_id);
    
    // Define the fields we want to show
    $fields_to_display = [
        'full_name' => 'Full Name',
        'pic_name' => 'Person in Charge',
        'company_name' => 'Company Name',
        'ic_number' => 'IC Number',
        'mobile_number' => 'Mobile Number',
        'billing_address_1' => 'Address',
        'billing_postcode' => 'Postcode',
        'ssm_document_url' => 'SSM Document',
        'bank_statement_url' => 'Bank Statement',
    ];

    foreach ($fields_to_display as $key => $label) {
        if (isset($meta[$key][0]) && !empty($meta[$key][0])) {
            $value = $meta[$key][0];
            // If the field is a URL, make it a clickable link
            if (strpos($key, '_url') !== false) {
                $value = '<a href="' . esc_url($value) . '" target="_blank">View Document</a>';
            } else {
                $value = esc_html($value);
            }
            echo '<tr><th>' . esc_html($label) . '</th><td>' . $value . '</td></tr>';
        }
    }
    ?>
    </table>
    <?php
    $html_output = ob_get_clean();
    
    // Send the HTML back to our JavaScript as a success response
    wp_send_json_success(['html' => $html_output]);
}
// Hook our function to WordPress's AJAX actions
add_action('wp_ajax_taptosell_get_user_details', 'taptosell_get_user_details_ajax_handler');

/**
 * --- CORRECTED (Phase 11): Handles product approval from the OA Dashboard. ---
 * Uses a more specific action name to prevent nonce conflicts.
 */
function taptosell_handle_oa_product_approval() {
    // Check if a product ID and a nonce were provided in the URL.
    if ( !isset($_GET['product_id']) || !isset($_GET['_wpnonce']) ) {
        wp_die('Missing required parameters.');
    }

    $product_id = intval($_GET['product_id']);
    
    // Verify the nonce. This is the crucial security check.
    if ( !wp_verify_nonce($_GET['_wpnonce'], 'oa_approve_product_' . $product_id) ) {
        wp_die('Security check failed. Please go back and try again.');
    }

    // Check if the current user has the authority to publish products.
    if ( !current_user_can('publish_products') ) {
        wp_die('You do not have sufficient permissions to approve products.');
    }

    if ( $product_id > 0 ) {
        // Update the product's status to 'publish'.
        wp_update_post([
            'ID'          => $product_id,
            'post_status' => 'publish',
        ]);
        
        // Notify the supplier that their product was approved.
        $supplier_id = get_post_field('post_author', $product_id);
        $product_title = get_the_title($product_id);
        $dashboard_page = get_page_by_title('Supplier Dashboard');
        $dashboard_link = $dashboard_page ? get_permalink($dashboard_page->ID) : '';
        $message = 'Congratulations! Your product "' . esc_html($product_title) . '" has been approved.';
        taptosell_add_notification($supplier_id, $message, $dashboard_link);
    }

    // Redirect back to the product management dashboard with a success message.
    $redirect_url = add_query_arg([
        'view' => 'products',
        'message' => 'product_approved'
    ], get_permalink(get_page_by_path('operational-admin-dashboard')));
    
    wp_redirect($redirect_url);
    exit;
}
// CORRECTED HOOK: The action name is now more specific.
add_action('admin_post_taptosell_oa_approve_product', 'taptosell_handle_oa_product_approval');

/**
 * --- CORRECTED (Phase 11): Handles product rejection from the OA Dashboard. ---
 * Now correctly sets the post status to 'rejected'.
 */
function taptosell_handle_oa_product_rejection() {
    // Security and permission checks
    if ( !isset($_GET['product_id']) || !isset($_GET['_wpnonce']) || !isset($_GET['reason']) ) {
        wp_die('Missing required parameters.');
    }
    if ( !current_user_can('edit_others_products') ) {
        wp_die('You do not have sufficient permissions to reject products.');
    }

    $product_id = intval($_GET['product_id']);
    
    // Verify the nonce security token
    if ( !wp_verify_nonce($_GET['_wpnonce'], 'oa_reject_product_' . $product_id) ) {
        wp_die('Security check failed. Please go back and try again.');
    }

    // Sanitize the rejection reason
    $rejection_reason = sanitize_textarea_field(urldecode($_GET['reason']));

    if ( $product_id > 0 ) {
        // --- CORRECTED LOGIC: Set the product status to 'rejected'. ---
        wp_update_post([
            'ID'          => $product_id,
            'post_status' => 'rejected',
        ]);
        
        // Save the rejection reason as post meta for the supplier to see.
        if (!empty($rejection_reason)) {
            update_post_meta($product_id, '_rejection_reason', $rejection_reason);
        }

        // Notify the supplier that their product was rejected, including the reason.
        $supplier_id = get_post_field('post_author', $product_id);
        $product_title = get_the_title($product_id);
        $dashboard_page = get_page_by_title('Supplier Dashboard');
        $dashboard_link = $dashboard_page ? get_permalink($dashboard_page->ID) : '';
        $message = 'Your product "' . esc_html($product_title) . '" was not approved.'; // Simplified message
        if (!empty($rejection_reason)) {
            $message .= ' Reason: ' . esc_html($rejection_reason);
        }
        taptosell_add_notification($supplier_id, $message, $dashboard_link);
    }

    // Redirect back to the product management dashboard with a success message.
    $redirect_url = add_query_arg([
        'view' => 'products',
        'message' => 'product_rejected'
    ], get_permalink(get_page_by_path('operational-admin-dashboard')));
    
    wp_redirect($redirect_url);
    exit;
}
// Hook the new handler to its action.
add_action('admin_post_taptosell_oa_reject_product', 'taptosell_handle_oa_product_rejection');

/**
 * --- NEW (Phase 11): Handles the processing of a withdrawal request. ---
 * Linked to the "Mark as Processed" button in the OA dashboard.
 * Verifies the nonce, checks user caps, and updates the post status.
 */
function taptosell_handle_oa_process_withdrawal() {
    // 1. Security Checks
    if (!isset($_GET['request_id']) || !isset($_GET['_wpnonce'])) {
        wp_die('Invalid request.');
    }

    $request_id = intval($_GET['request_id']);
    $nonce = sanitize_text_field($_GET['_wpnonce']);

    if (!wp_verify_nonce($nonce, 'oa_process_withdrawal_' . $request_id)) {
        wp_die('Security check failed.');
    }

    if (!current_user_can('operational_admin')) {
        wp_die('You do not have permission to perform this action.');
    }

    // 2. Update the Post Status
    $post_updated = wp_update_post([
        'ID'          => $request_id,
        'post_status' => 'wd-processed',
    ]);

    // 3. Redirect back to the dashboard
    $redirect_url = get_permalink(get_option('taptosell_oa_dashboard_page_id'));
    if (!$redirect_url) {
        // Fallback if the page ID isn't set for some reason
        $redirect_url = home_url('/');
    }

    if ($post_updated) {
        // Add a success message to the URL
        $redirect_url = add_query_arg('message', 'withdrawal_processed', $redirect_url . '#/withdrawals');
    } else {
        // Optional: Add an error message if the update fails
        $redirect_url = add_query_arg('error', 'update_failed', $redirect_url . '#/withdrawals');
    }

    wp_redirect($redirect_url);
    exit;
}