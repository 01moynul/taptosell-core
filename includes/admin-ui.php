<?php
// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Customize the admin menu based on user role.
 */
function taptosell_customize_admin_menu() {
    // Get the current user.
    $user = wp_get_current_user();

    // Check if the user has the 'supplier' role.
    if ( in_array('supplier', (array) $user->roles) ) {
        
        // Remove menu items that are not relevant for suppliers.
        remove_menu_page('edit.php'); // Posts
        remove_menu_page('edit-comments.php'); // Comments
        remove_menu_page('edit.php?post_type=page'); // Pages
        remove_menu_page('upload.php'); // Media Library link
        remove_menu_page('tools.php'); // Tools
        remove_menu_page('edit.php?post_type=roadmap_item'); // Roadmap Items
        remove_menu_page('edit.php?post_type=project'); // NEW: Removes 'Projects'
    }
}
// Hook into the 'admin_menu' action with a late priority to ensure it runs after the menu is built.
add_action('admin_menu', 'taptosell_customize_admin_menu', 999);

/**
 * Customize the '+ New' menu in the top admin bar based on user role.
 */
function taptosell_customize_admin_bar($wp_admin_bar) {
    // Get the current user.
    $user = wp_get_current_user();

    // Check if the user has the 'supplier' role.
    if ( in_array('supplier', (array) $user->roles) ) {
        
        // Remove nodes from the '+ New' menu that are not relevant for suppliers.
        $wp_admin_bar->remove_node('new-post'); // Removes 'New Post'
        $wp_admin_bar->remove_node('new-page'); // Removes 'New Page'
        $wp_admin_bar->remove_node('new-media'); // Removes 'New Media'
        
        // Below are examples for other CPTs, assuming their slugs are 'project' and 'roadmap_item'
        $wp_admin_bar->remove_node('new-project');
        $wp_admin_bar->remove_node('new-roadmap_item');
        
        // This will leave only the 'New Product' link, which is what we want.
    }
}
// Hook into the 'admin_bar_menu' action with a late priority.
add_action('admin_bar_menu', 'taptosell_customize_admin_bar', 999);
/**
 * =================================================================
 * USER APPROVAL UI (ADMIN-FACING)
 * =================================================================
 */

// Add a new "Status" column to the Users list table
function taptosell_add_user_status_column($columns) {
    $columns['account_status'] = 'Status';
    return $columns;
}
add_filter('manage_users_columns', 'taptosell_add_user_status_column');

// Render the content for our custom "Status" column
function taptosell_display_user_status_column($value, $column_name, $user_id) {
    if ('account_status' === $column_name) {
        $status = get_user_meta($user_id, '_account_status', true);
        
        if ($status === 'pending') {
            // Create links for approve and reject actions
            $approve_nonce = wp_create_nonce('taptosell_approve_user_' . $user_id);
            $reject_nonce = wp_create_nonce('taptosell_reject_user_' . $user_id);
            $approve_link = admin_url('users.php?action=taptosell_approve_user&user_id=' . $user_id . '&_wpnonce=' . $approve_nonce);
            $reject_link = admin_url('users.php?action=taptosell_reject_user&user_id=' . $user_id . '&_wpnonce=' . $reject_nonce);
            
            // Add a javascript confirmation before deleting the user
            $reject_onclick = "return confirm('Are you sure you want to reject and delete this user? This action cannot be undone.');";

            return '<strong>Pending</strong> <br> 
                    <a href="' . esc_url($approve_link) . '">Approve</a> | 
                    <a href="' . esc_url($reject_link) . '" style="color: #a00;" onclick="' . esc_js($reject_onclick) . '">Reject</a>';
        } else {
            return '<span style="color: green;">Approved</span>';
        }
    }
    return $value;
}
add_filter('manage_users_custom_column', 'taptosell_display_user_status_column', 10, 3);

/**
 * =================================================================
 * ADMIN DASHBOARD WIDGETS
 * =================================================================
 */

/**
 * Add a custom widget to the main WordPress dashboard.
 */
function taptosell_add_dashboard_widgets() {
    // Check if the current user can see the widget
    if (current_user_can('edit_others_posts')) {
        wp_add_dashboard_widget(
            'taptosell_pending_tasks_widget',       // Widget slug.
            'TapToSell Pending Tasks',              // Title.
            'taptosell_pending_tasks_widget_display' // Display function.
        );
    }
}
add_action('wp_dashboard_setup', 'taptosell_add_dashboard_widgets');

/**
 * --- CORRECTED: The display function for the custom dashboard widget. ---
 * Now counts 'pending' products, not 'draft'.
 */
function taptosell_pending_tasks_widget_display() {
    // 1. Get the count of pending products
    $pending_products_count = 0;
    $product_counts = wp_count_posts('product');
    // --- CORRECTED LOGIC: Count 'pending' status. ---
    if (isset($product_counts->pending)) {
        $pending_products_count = $product_counts->pending;
    }
    // Create the link to the product approval page
    $products_link = admin_url('edit.php?post_status=pending&post_type=product');

    // 2. Get the count of pending users
    $user_query = new WP_User_Query([
        'meta_key' => '_account_status',
        'meta_value' => 'pending'
    ]);
    $pending_users_count = $user_query->get_total();
    // Create the link to the user management page
    $users_link = admin_url('users.php');

    // 3. Display the information
    echo '<ul>';
    
    echo '<li>';
    echo sprintf(
        _n(
            'There is <a href="%s"><strong>%d new product</strong></a> pending approval.',
            'There are <a href="%s"><strong>%d new products</strong></a> pending approval.',
            $pending_products_count
        ),
        esc_url($products_link),
        $pending_products_count
    );
    echo '</li>';

    echo '<li>';
    echo sprintf(
        _n(
            'There is <a href="%s"><strong>%d new user</strong></a> pending approval.',
            'There are <a href="%s"><strong>%d new users</strong></a> pending approval.',
            $pending_users_count
        ),
        esc_url($users_link),
        $pending_users_count
    );
    echo '</li>';

    echo '</ul>';
}

// In: includes/admin-ui.php

/**
 * Enqueues a script to warn admins if they log out with pending tasks.
 */
function taptosell_enqueue_logout_warning_script() {
    // Only run this for users who can edit other's posts (Admins and OAs)
    if (!current_user_can('edit_others_posts')) {
        return;
    }

    // Get pending product count
    $product_counts = wp_count_posts('product');
    $pending_products_count = isset($product_counts->draft) ? $product_counts->draft : 0;

    // Get pending user count
    $user_query = new WP_User_Query(['meta_key' => '_account_status', 'meta_value' => 'pending']);
    $pending_users_count = $user_query->get_total();
    
    $total_pending = $pending_products_count + $pending_users_count;

    // If there are no pending tasks, do nothing.
    if ($total_pending == 0) {
        return;
    }
    
    // If there ARE pending tasks, load our script.
    $script_url = plugin_dir_url( dirname( __FILE__ ) ) . 'assets/js/logout-warning.js';
    wp_enqueue_script('taptosell-logout-warning', $script_url, ['jquery'], '1.1', true); // Version bumped

    // --- NEW: Pass data from PHP to our JavaScript file ---
    $current_user = wp_get_current_user();
    $user_role = !empty($current_user->roles) ? $current_user->roles[0] : '';
    
    wp_localize_script(
        'taptosell-logout-warning',
        'taptosellLogoutData', // This is the name of our JavaScript object
        [
            'pendingTasks' => $total_pending,
            'currentUserRole' => $user_role,
        ]
    );
}
add_action('admin_enqueue_scripts', 'taptosell_enqueue_logout_warning_script');

/**
 * =================================================================
 * REJECTION REASON UI (ADMIN-FACING)
 * =================================================================
 */

// --- NEW: Add metabox for rejection reason ---
function taptosell_add_rejection_reason_metabox() {
    add_meta_box(
        'taptosell_rejection_reason_metabox', // ID
        'Product Rejection Reason',           // Title
        'taptosell_rejection_reason_metabox_html', // Callback function to render the HTML
        'product',                            // The post type where it will appear
        'side',                               // Context (side, normal, advanced)
        'high'                                // Priority
    );
}
add_action('add_meta_boxes_product', 'taptosell_add_rejection_reason_metabox');

/**
 * Renders the HTML for the rejection reason metabox.
 */
function taptosell_rejection_reason_metabox_html($post) {
    // Add a nonce for security
    wp_nonce_field('taptosell_rejection_nonce_action', 'taptosell_rejection_nonce');

    // Get the existing reason if it has been saved before
    $reason = get_post_meta($post->ID, '_rejection_reason', true);

    echo '<p>If rejecting, enter the reason here. This will be visible to the supplier.</p>';
    echo '<textarea name="rejection_reason" style="width:100%; height: 100px;">' . esc_textarea($reason) . '</textarea>';
    echo '<p class="description">After entering the reason, use the "Reject" button in the "Actions" column on the main Products list, or update the status manually.</p>';
}

// In: includes/admin-ui.php

/**
 * --- UPDATED: Displays role-specific custom fields on the user's profile page. ---
 */
function taptosell_display_custom_user_profile_fields($user) {
    $user_roles = (array)$user->roles;

    // --- A) Display fields for DROPSHIPPERS ---
    if ( in_array('dropshipper', $user_roles) ) {
        ?>
        <h3>TapToSell Dropshipper Information</h3>
        <table class="form-table">
            <tr>
                <th><label for="full_name">Full Name (As Per IC)</label></th>
                <td><input type="text" name="full_name" value="<?php echo esc_attr(get_user_meta($user->ID, 'full_name', true)); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="company_name">Company Name</label></th>
                <td><input type="text" name="company_name" value="<?php echo esc_attr(get_user_meta($user->ID, 'company_name', true)); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="ic_number">IC Number</label></th>
                <td><input type="text" name="ic_number" value="<?php echo esc_attr(get_user_meta($user->ID, 'ic_number', true)); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="mobile_number">Mobile Number</label></th>
                <td><input type="text" name="mobile_number" value="<?php echo esc_attr(get_user_meta($user->ID, 'mobile_number', true)); ?>" class="regular-text" /></td>
            </tr>
        </table>
        <?php
    }

    // --- B) Display fields for SUPPLIERS ---
    if ( in_array('supplier', $user_roles) ) {
        $ssm_url = get_user_meta($user->ID, 'ssm_document_url', true);
        $bank_statement_url = get_user_meta($user->ID, 'bank_statement_url', true);
        ?>
        <h3>TapToSell Supplier Information</h3>
        <table class="form-table">
            <tr>
                <th><label for="company_name">Company Name (As per SSM)</label></th>
                <td><input type="text" name="company_name" value="<?php echo esc_attr(get_user_meta($user->ID, 'company_name', true)); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="pic_name">Person in Charge (PIC)</label></th>
                <td><input type="text" name="pic_name" value="<?php echo esc_attr(get_user_meta($user->ID, 'pic_name', true)); ?>" class="regular-text" /></td>
            </tr>
             <tr>
                <th><label for="mobile_number">Mobile Number</label></th>
                <td><input type="text" name="mobile_number" value="<?php echo esc_attr(get_user_meta($user->ID, 'mobile_number', true)); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="billing_address_1">Address</label></th>
                <td><textarea name="billing_address_1" rows="3" class="regular-text"><?php echo esc_textarea(get_user_meta($user->ID, 'billing_address_1', true)); ?></textarea></td>
            </tr>
             <tr>
                <th><label for="billing_postcode">Postcode</label></th>
                <td><input type="text" name="billing_postcode" value="<?php echo esc_attr(get_user_meta($user->ID, 'billing_postcode', true)); ?>" class="regular-text" /></td>
            </tr>

            <tr class="document-links">
                <th><label>SSM Document</label></th>
                <td>
                    <?php if ($ssm_url): ?>
                        <a href="<?php echo esc_url($ssm_url); ?>" target="_blank">View Uploaded Document</a>
                    <?php else: ?>
                        <span>No document uploaded.</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr class="document-links">
                <th><label>Bank Statement</label></th>
                <td>
                    <?php if ($bank_statement_url): ?>
                        <a href="<?php echo esc_url($bank_statement_url); ?>" target="_blank">View Uploaded Document</a>
                    <?php else: ?>
                        <span>No document uploaded.</span>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        <?php
    }
}
add_action('show_user_profile', 'taptosell_display_custom_user_profile_fields');
add_action('edit_user_profile', 'taptosell_display_custom_user_profile_fields');


/**
 * --- UPDATED: Saves the role-specific custom fields from the user profile screen. ---
 */
function taptosell_save_custom_user_profile_fields($user_id) {
    if (!current_user_can('edit_user', $user_id)) {
        return false;
    }
    
    // Define all possible text-based fields from both forms
    $all_fields = [
        'full_name', 'ic_number', 'gender', // Dropshipper
        'pic_name',                         // Supplier
        'company_name', 'mobile_number',    // Shared
        'billing_address_1', 'billing_postcode', 'billing_city', 'billing_state', // Shared Address
    ];

    // Loop through and save any of the fields that were submitted
    foreach ($all_fields as $field) {
        if (isset($_POST[$field])) {
            // Use sanitize_textarea_field for address, and sanitize_text_field for all others
            $value = ($field === 'billing_address_1') ? sanitize_textarea_field($_POST[$field]) : sanitize_text_field($_POST[$field]);
            update_user_meta($user_id, $field, $value);
        }
    }
    
    // Note: We do not handle file re-uploads here. This function is for editing the text data.
}
add_action('personal_options_update', 'taptosell_save_custom_user_profile_fields');
add_action('edit_user_profile_update', 'taptosell_save_custom_user_profile_fields');