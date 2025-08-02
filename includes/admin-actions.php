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
 * Displays the content for the custom "Actions" column.
 */
function taptosell_display_product_actions($column, $post_id) {
    // Only add links to our custom column.
    if ($column === 'approval_actions') {
        $post_status = get_post_status($post_id);
        
        // Only show actions for products that are drafts.
        if ($post_status === 'draft') {
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