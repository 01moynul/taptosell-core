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
 * The display function for the custom dashboard widget.
 */
function taptosell_pending_tasks_widget_display() {
    // 1. Get the count of pending products
    $pending_products_count = 0;
    $product_counts = wp_count_posts('product');
    if (isset($product_counts->draft)) {
        $pending_products_count = $product_counts->draft;
    }
    // Create the link to the product approval page
    $products_link = admin_url('edit.php?post_status=draft&post_type=product');

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
        // Use _n() to handle plural vs. singular text correctly
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

/**
 * Enqueues a script to warn admins if they log out with pending tasks.
 */
function taptosell_enqueue_logout_warning_script() {
    // Only run this for Admins and Operational Admins.
    if (!current_user_can('edit_others_posts')) {
        return;
    }

    // Get pending product count
    $product_counts = wp_count_posts('product');
    $pending_products_count = isset($product_counts->draft) ? $product_counts->draft : 0;

    // Get pending user count
    $user_query = new WP_User_Query(['meta_key' => '_account_status', 'meta_value' => 'pending']);
    $pending_users_count = $user_query->get_total();

    // If there are no pending tasks, do nothing.
    if ($pending_products_count == 0 && $pending_users_count == 0) {
        return;
    }
    
    // If there ARE pending tasks, load our script.
    $script_url = plugin_dir_url( dirname( __FILE__ ) ) . 'assets/js/logout-warning.js';
    wp_enqueue_script('taptosell-logout-warning', $script_url, ['jquery'], '1.0', true);
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

