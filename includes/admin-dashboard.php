<?php
// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * --- Helper function to get the number of users pending approval. ---
 */
function taptosell_get_pending_user_count() {
    $user_query = new WP_User_Query([
        'meta_key' => '_account_status',
        'meta_value' => 'pending'
    ]);
    return $user_query->get_total();
}

/**
 * --- Helper function to get the number of products pending approval. ---
 */
function taptosell_get_pending_product_count() {
    $product_counts = wp_count_posts('product');
    return isset($product_counts->pending) ? $product_counts->pending : 0;
}

/**
 * --- Helper function to get the number of pending withdrawal requests. ---
 */
function taptosell_get_pending_withdrawal_count() {
    $withdrawal_counts = wp_count_posts('withdrawal_request');
    return isset($withdrawal_counts->{'wd-pending'}) ? $withdrawal_counts->{'wd-pending'} : 0;
}

/**
 * --- NEW (Phase 11): Renders the "At a Glance" widgets for the main dashboard view. ---
 */
function taptosell_render_oa_dashboard_hub() {
    // Get the live counts for our widgets
    $pending_users = taptosell_get_pending_user_count();
    $pending_products = taptosell_get_pending_product_count();
    $pending_withdrawals = taptosell_get_pending_withdrawal_count();
    
    // Get the base URL for the dashboard page
    $dashboard_url = get_permalink(get_the_ID());
    ?>
    <div class="oa-at-a-glance">
        <div class="glance-card">
            <h4>Pending Users</h4>
            <span class="glance-count"><?php echo esc_html($pending_users); ?></span>
            <a href="<?php echo esc_url(add_query_arg('view', 'users', $dashboard_url)); ?>" class="glance-link">Manage Users &rarr;</a>
        </div>

        <div class="glance-card">
            <h4>Pending Products</h4>
            <span class="glance-count"><?php echo esc_html($pending_products); ?></span>
            <a href="<?php echo esc_url(add_query_arg('view', 'products', $dashboard_url)); ?>" class="glance-link">Manage Products &rarr;</a>
        </div>

        <div class="glance-card">
            <h4>Pending Withdrawals</h4>
            <span class="glance-count"><?php echo esc_html($pending_withdrawals); ?></span>
            <a href="<?php echo esc_url(add_query_arg('view', 'withdrawals', $dashboard_url)); ?>" class="glance-link">Manage Withdrawals &rarr;</a>
        </div>
    </div>
    <?php
}

/**
 * --- UPGRADED (Phase 11): Renders the User Management view with search and filtering. ---
 * Allows searching all users (excluding admins) and filtering by account status.
 */
function taptosell_render_oa_users_view() {
    // Get the search and filter values from the URL, if they exist
    $search_query = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    $status_filter = isset($_GET['status']) ? sanitize_key($_GET['status']) : 'pending'; // Default to 'pending'

    $dashboard_url = get_permalink(get_the_ID());
    ?>
    <h3>User Management</h3>

    <?php
    // --- Display any success/error messages from user actions ---
    if (isset($_GET['message'])) {
        $message_type = sanitize_key($_GET['message']);
        $notice_text = '';
        if ($message_type === 'user_approved') {
            $notice_text = 'User approved successfully.';
        } elseif ($message_type === 'user_rejected') {
            $notice_text = 'User rejected and deleted successfully.';
        }
        if ($notice_text) {
            echo '<div class="taptosell-notice success"><p>' . esc_html($notice_text) . '</p></div>';
        }
    }
    ?>

    <form method="get" action="<?php echo esc_url($dashboard_url); ?>">
        <input type="hidden" name="view" value="users">
        <p class="search-box">
            <label class="screen-reader-text" for="user-search-input">Search Users:</label>
            <input type="search" id="user-search-input" name="s" value="<?php echo esc_attr($search_query); ?>" placeholder="Search by username, email...">
            
            <select name="status" id="status-filter">
                <option value="all" <?php selected($status_filter, 'all'); ?>>All Statuses</option>
                <option value="pending" <?php selected($status_filter, 'pending'); ?>>Pending Approval</option>
                <option value="approved" <?php selected($status_filter, 'approved'); ?>>Approved</option>
            </select>

            <input type="submit" id="search-submit" class="button" value="Filter Users">
        </p>
    </form>
    
    <?php
    // --- Build the user query arguments ---
    $query_args = [
        'orderby'      => 'user_registered',
        'order'        => 'DESC',
        'role__not_in' => ['administrator', 'operational_admin'] // IMPORTANT: Exclude admins
    ];

    // Add search parameter if a search query exists
    if (!empty($search_query)) {
        $query_args['search'] = '*' . esc_attr($search_query) . '*';
        $query_args['search_columns'] = ['user_login', 'user_email', 'display_name'];
    }

    // Add status filter if a specific status is selected
    if (!empty($status_filter) && $status_filter !== 'all') {
        $query_args['meta_query'] = [
            [
                'key'     => '_account_status',
                'value'   => $status_filter,
                'compare' => '='
            ]
        ];
    }
    
    $users_query = new WP_User_Query($query_args);
    $found_users = $users_query->get_results();

    if (!empty($found_users)) {
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Registered</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($found_users as $user) : 
                    $account_status = get_user_meta($user->ID, '_account_status', true);
                    
                    $approve_nonce = wp_create_nonce('oa_approve_user_' . $user->ID);
                    $reject_nonce = wp_create_nonce('oa_reject_user_' . $user->ID);
                    
                    // Build the action links, including the current search/filter state
                    $approve_link = add_query_arg(['view' => 'users', 's' => $search_query, 'status' => $status_filter, 'action' => 'approve_user', 'user_id' => $user->ID, '_wpnonce' => $approve_nonce], $dashboard_url);
                    $reject_link = add_query_arg(['view' => 'users', 's' => $search_query, 'status' => $status_filter, 'action' => 'reject_user', 'user_id' => $user->ID, '_wpnonce' => $reject_nonce], $dashboard_url);
                ?>
                    <tr>
                        <td><strong><?php echo esc_html($user->user_login); ?></strong></td>
                        <td><?php echo esc_html($user->user_email); ?></td>
                        <td><?php echo esc_html(ucfirst(implode(', ', $user->roles))); ?></td>
                        <td><?php echo date('F j, Y', strtotime($user->user_registered)); ?></td>
                        <td>
                            <?php if ($account_status === 'pending') : ?>
                                <span style="color: #ffb900; font-weight: bold;">Pending</span>
                            <?php elseif ($account_status === 'approved') : ?>
                                <span style="color: green; font-weight: bold;">Approved</span>
                            <?php else: ?>
                                <span style="color: #a00; font-weight: bold;">Unverified</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="#" class="button button-secondary oa-user-details-btn" data-userid="<?php echo esc_attr($user->ID); ?>">Details</a>
                            <?php if ($account_status === 'pending') : ?>
                                | <a href="<?php echo esc_url($approve_link); ?>" class="button button-primary">Approve</a>
                                | <a href="#" class="button button-secondary oa-reject-user-btn" data-reject-url="<?php echo esc_url($reject_link); ?>">Reject</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    } else { 
        echo '<p>No users found matching your criteria.</p>';
    }
    ?>

    <div id="tts-rejection-modal" class="taptosell-modal-overlay" style="display: none;">
        <div class="taptosell-modal-content">
            <span class="tts-modal-close taptosell-modal-close">&times;</span>
            <h3 class="taptosell-modal-title">Rejection Reason</h3>
            <p>Please provide a reason for rejecting this user. This will be sent to them in an email.</p>
            <textarea id="tts-rejection-reason-text" style="width: 100%; height: 100px;" placeholder="e.g., Incomplete information provided..."></textarea>
            <div style="margin-top: 20px; text-align: right;">
                <button type="button" id="tts-rejection-cancel" class="button button-secondary">Cancel</button>
                <button type="button" id="tts-rejection-confirm" class="button button-primary" style="margin-left: 10px;">Confirm Rejection</button>
            </div>
        </div>
    </div>

    <div id="tts-details-modal" class="taptosell-modal-overlay" style="display: none;">
        <div class="taptosell-modal-content">
            <span class="tts-modal-close taptosell-modal-close">&times;</span>
            <h3 class="taptosell-modal-title">User Registration Details</h3>
            <div id="tts-details-modal-content" class="taptosell-modal-body">
                <p>Loading details...</p>
            </div>
        </div>
    </div>
    <?php
}

/**
 * --- UPDATED (Phase 11): Main shortcode for the Operational Admin Dashboard ---
 * This function now acts as a router, displaying different content based on the 'view' URL parameter.
 */
function taptosell_oa_dashboard_shortcode() {
    ob_start();
    // ... the rest of the function continues below
    // Determine which view to show. Default to 'dashboard' (the hub).
    $current_view = isset($_GET['view']) ? sanitize_key($_GET['view']) : 'dashboard';
    
    // Get the base URL of the current page to build our nav links
    $dashboard_url = get_permalink(get_the_ID());
    ?>

    <div class="taptosell-dashboard-wrapper taptosell-oa-dashboard">
        <div class="dashboard-header">
            <h1>Operational Admin Dashboard</h1>
        </div>

        <div class="dashboard-nav">
            <ul>
                <li class="<?php echo ($current_view === 'dashboard') ? 'active' : ''; ?>">
                    <a href="<?php echo esc_url($dashboard_url); ?>">Dashboard</a>
                </li>
                <li class="<?php echo ($current_view === 'users') ? 'active' : ''; ?>">
                    <a href="<?php echo esc_url(add_query_arg('view', 'users', $dashboard_url)); ?>">Users</a>
                </li>
                <li class="<?php echo ($current_view === 'products') ? 'active' : ''; ?>">
                    <a href="<?php echo esc_url(add_query_arg('view', 'products', $dashboard_url)); ?>">Products</a>
                </li>
                <li class="<?php echo ($current_view === 'withdrawals') ? 'active' : ''; ?>">
                    <a href="<?php echo esc_url(add_query_arg('view', 'withdrawals', $dashboard_url)); ?>">Withdrawals</a>
                </li>
                <li class="<?php echo ($current_view === 'price-requests') ? 'active' : ''; ?>">
                    <a href="<?php echo esc_url(add_query_arg('view', 'price-requests', $dashboard_url)); ?>">Price Requests</a>
                </li>
                <li class="<?php echo ($current_view === 'settings') ? 'active' : ''; ?>">
                    <a href="<?php echo esc_url(add_query_arg('view', 'settings', $dashboard_url)); ?>">Settings</a>
                </li>
            </ul>
        </div>

        <div class="dashboard-content">
            <?php
            // Load the content for the current view
            switch ($current_view) {
                case 'users':
                    taptosell_render_oa_users_view();
                    break;
                case 'products':
                    taptosell_render_oa_products_view();
                    break;
                case 'withdrawals':
                    taptosell_render_oa_withdrawals_view();
                    break;
                 case 'price-requests':
                    taptosell_render_oa_price_requests_view();
                    break; 
                case 'settings':
                    taptosell_render_oa_settings_view();
                    break;      
                case 'dashboard':
                default:
                    taptosell_render_oa_dashboard_hub();
                    break;
            }
            ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('oa_dashboard', 'taptosell_oa_dashboard_shortcode');

/**
 * --- REVISED (Phase 11 & 12): Renders the Product Management view for the OA. ---
 * Displays a list of pending products with Approve, Reject, and now Details actions.
 * Also includes the HTML structure for the commission editing modal.
 */
function taptosell_render_oa_products_view() {
    ?>
    <h3>Manage Pending Products</h3>
    <?php
    // --- Display any success/error messages ---
    if (isset($_GET['message'])) {
        if ($_GET['message'] === 'product_approved') {
            echo '<div class="taptosell-notice success"><p>Product has been approved and the supplier has been notified.</p></div>';
        }
        if ($_GET['message'] === 'product_rejected') {
            echo '<div class="taptosell-notice"><p>Product has been rejected and the supplier has been notified.</p></div>';
        }
        // --- NEW (Phase 12): Add message for commission updates ---
        if ($_GET['message'] === 'commission_updated') {
            echo '<div class="taptosell-notice success"><p>Product commission has been updated successfully.</p></div>';
        }
    }
    ?>
    <p>The following products have been submitted by suppliers and are awaiting your review.</p>
    <?php

    $args = [
        'post_type'      => 'product',
        'post_status'    => 'pending',
        'posts_per_page' => 20,
    ];
    $pending_products_query = new WP_Query($args);

    if ($pending_products_query->have_posts()) {
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Product Name</th>
                    <th>Supplier</th>
                    <th>Date Submitted</th>
                    <th style="width: 250px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($pending_products_query->have_posts()) : $pending_products_query->the_post(); 
                    $product_id = get_the_ID();
                    $supplier = get_user_by('id', get_the_author_meta('ID'));
                    
                    // Get commission to pass to the modal
                    $current_commission = get_post_meta($product_id, '_taptosell_commission_rate', true);
                    $global_commission = get_option('taptosell_platform_commission', 5);

                    // Create secure URLs for approve/reject actions
                    $approve_nonce = wp_create_nonce('oa_approve_product_' . $product_id);
                    $approve_link = admin_url('admin-post.php?action=taptosell_oa_approve_product&product_id=' . $product_id . '&_wpnonce=' . $approve_nonce);

                    $reject_nonce = wp_create_nonce('oa_reject_product_' . $product_id);
                    $reject_link = admin_url('admin-post.php?action=taptosell_oa_reject_product&product_id=' . $product_id . '&_wpnonce=' . $reject_nonce);
                ?>
                    <tr>
                        <td><strong><?php the_title(); ?></strong></td>
                        <td><?php echo esc_html($supplier->display_name); ?></td>
                        <td><?php echo get_the_date(); ?></td>
                        <td>
                            <a href="<?php echo esc_url($approve_link); ?>" class="button button-primary">Approve</a>
                            <a href="<?php echo esc_url($reject_link); ?>" class="button button-secondary">Reject</a>
                            <button 
                                class="button button-secondary taptosell-oa-product-details-btn"
                                data-product-id="<?php echo esc_attr($product_id); ?>"
                                data-product-name="<?php echo esc_attr(get_the_title()); ?>"
                                data-current-commission="<?php echo esc_attr($current_commission); ?>"
                                data-global-commission="<?php echo esc_attr($global_commission); ?>"
                            >Details</button>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        <?php
        wp_reset_postdata();
    } else {
        echo '<p>There are no products pending review at this time.</p>';
    }
    
    // --- NEW (Phase 12): HTML structure for the product details modal ---
    ?>
    <div id="taptosell-product-details-modal" class="taptosell-modal" style="display: none;">
        <div class="taptosell-modal-content">
            <span class="taptosell-modal-close">&times;</span>
            <h3>Product Details</h3>
            <p><strong>Product:</strong> <span id="modal-product-name"></span></p>
            <form id="taptosell-commission-form" method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="taptosell_oa_update_commission">
                <input type="hidden" name="product_id" id="modal-product-id" value="">
                <?php wp_nonce_field('oa_update_commission_action', 'oa_update_commission_nonce'); ?>
                
                <div class="form-row">
                    <label for="modal-commission-rate">Per-Product Commission Rate (%)</label>
                    <input type="number" step="0.1" id="modal-commission-rate" name="taptosell_commission_rate" placeholder="Global Rate" style="width: 100%;" />
                    <p class="description">
                        Set a specific commission for this product. Leave blank to use the global rate.
                    </p>
                </div>
                
                <div class="form-row">
                    <button type="submit" class="button button-primary">Save Commission</button>
                </div>
            </form>
        </div>
    </div>
    <?php
}
/**
 * --- NEW (Phase 11): Renders the Withdrawal Management view. ---
 * Displays a list of pending withdrawal requests for the OA to process.
 */
function taptosell_render_oa_withdrawals_view() {
    ?>
    <h3>Manage Pending Withdrawals</h3>
    <?php
    // --- Display any success/error messages ---
    if (isset($_GET['message']) && $_GET['message'] === 'withdrawal_processed') {
        echo '<div class="taptosell-notice success"><p>Withdrawal request has been marked as processed.</p></div>';
    }
    ?>
    <p>The following withdrawal requests have been submitted by suppliers and are awaiting processing.</p>
    <?php

    $args = [
        'post_type'      => 'withdrawal_request',
        'post_status'    => 'wd-pending',
        'posts_per_page' => 20,
        'orderby'        => 'date',
        'order'          => 'ASC', // Show oldest first
    ];
    $pending_withdrawals_query = new WP_Query($args);

    if ($pending_withdrawals_query->have_posts()) {
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Request ID</th>
                    <th>Supplier</th>
                    <th>Amount</th>
                    <th>Date Requested</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($pending_withdrawals_query->have_posts()) : $pending_withdrawals_query->the_post(); 
                    $request_id = get_the_ID();
                    $supplier = get_user_by('id', get_the_author_meta('ID'));
                    $amount = get_post_meta($request_id, '_withdrawal_amount', true);

                    // Create a secure URL for the "process" action
                    $process_nonce = wp_create_nonce('oa_process_withdrawal_' . $request_id);
                    $process_link = admin_url('admin-post.php?action=taptosell_oa_process_withdrawal&request_id=' . $request_id . '&_wpnonce=' . $process_nonce);
                ?>
                    <tr>
                        <td><strong>#<?php echo esc_html($request_id); ?></strong></td>
                        <td><?php echo esc_html($supplier->display_name); ?></td>
                        <td><strong>RM <?php echo number_format((float)$amount, 2); ?></strong></td>
                        <td><?php echo get_the_date(); ?></td>
                        <td>
                            <a href="<?php echo esc_url($process_link); ?>" class="button button-primary" onclick="return confirm('Are you sure you have processed this payment? This action cannot be undone.');">Mark as Processed</a>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        <?php
        wp_reset_postdata();
    } else {
        echo '<p>There are no pending withdrawal requests at this time.</p>';
    }
}
/**
 * --- NEW (Phase 12): Renders the Price Change Requests view for the OA. ---
 */
function taptosell_render_oa_price_requests_view() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'taptosell_price_changes';

    // --- Display any success/error messages ---
    if (isset($_GET['message'])) {
        if ($_GET['message'] === 'price_approved') {
            echo '<div class="taptosell-notice success"><p>Price change request approved and product updated.</p></div>';
        } elseif ($_GET['message'] === 'price_rejected') {
            echo '<div class="taptosell-notice success"><p>Price change request has been rejected.</p></div>';
        }
    }

    $pending_requests = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name WHERE status = %s ORDER BY request_date ASC", 'pending'));
    ?>
    <h3>Manage Price Change Requests</h3>
    <p>Review and approve or reject price change requests submitted by suppliers for their published products.</p>
    
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th>Product</th>
                <th>Supplier</th>
                <th>Old Price</th>
                <th>New Price</th>
                <th>Date Requested</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($pending_requests)) : ?>
                <?php foreach ($pending_requests as $request) :
                    $product = get_post($request->product_id);
                    $supplier = get_user_by('id', $request->supplier_id);

                    // Create secure action links
                    $approve_nonce = wp_create_nonce('oa_approve_price_' . $request->id);
                    $reject_nonce = wp_create_nonce('oa_reject_price_' . $request->id);
                    $approve_link = admin_url('admin-post.php?action=taptosell_oa_approve_price&request_id=' . $request->id . '&_wpnonce=' . $approve_nonce);
                    $reject_link = admin_url('admin-post.php?action=taptosell_oa_reject_price&request_id=' . $request->id . '&_wpnonce=' . $reject_nonce);
                ?>
                    <tr>
                        <td>
                            <?php if ($product) : ?>
                                <a href="<?php echo get_edit_post_link($request->product_id); ?>" target="_blank">
                                    <?php echo esc_html($product->post_title); ?>
                                </a>
                            <?php else : ?>
                                Product #<?php echo esc_html($request->product_id); ?> (Not Found)
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($supplier->display_name); ?></td>
                        <td>RM <?php echo number_format($request->old_price, 2); ?></td>
                        <td style="font-weight: bold; color: #d9534f;">RM <?php echo number_format($request->new_price, 2); ?></td>
                        <td><?php echo date('Y-m-d H:i', strtotime($request->request_date)); ?></td>
                        <td>
                            <a href="<?php echo esc_url($approve_link); ?>" class="button button-primary">Approve</a>
                            <a href="<?php echo esc_url($reject_link); ?>" class="button button-secondary">Reject</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr>
                    <td colspan="6">No pending price change requests.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    <?php
}
/**
 * --- NEW (Phase 12): Renders the platform settings view for the OA. ---
 */
function taptosell_render_oa_settings_view() {
    // Check for any success messages from a form submission
    if (isset($_GET['message'])) {
        if ($_GET['message'] === 'settings_saved') {
            echo '<div class="taptosell-notice success"><p>Settings have been saved successfully.</p></div>';
        } elseif ($_GET['message'] === 'key_regenerated') {
            echo '<div class="taptosell-notice success"><p>New supplier registration key has been generated.</p></div>';
        }
    }

    // Get current settings values from the database
    $commission = get_option('taptosell_platform_commission', 5);
    $reg_key = get_option('taptosell_supplier_reg_key');
    if (empty($reg_key)) {
        $reg_key = wp_generate_password(16, false);
        update_option('taptosell_supplier_reg_key', $reg_key);
    }
    $supplier_reg_page = get_page_by_title('Supplier Registration');
    $reg_url = $supplier_reg_page ? get_permalink($supplier_reg_page->ID) : home_url('/supplier-registration/');
    $full_reg_link = esc_url(add_query_arg('reg_key', $reg_key, $reg_url));
    ?>
    <h3>Platform Settings</h3>
    <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
        <input type="hidden" name="action" value="taptosell_oa_save_settings">
        <?php wp_nonce_field('taptosell_oa_settings_actions', 'taptosell_oa_settings_nonce'); ?>

        <table class="form-table">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="taptosell_platform_commission">Platform Commission (%)</label>
                    </th>
                    <td>
                        <input type="number" name="taptosell_platform_commission" id="taptosell_platform_commission" value="<?php echo esc_attr($commission); ?>" min="0" step="0.1" class="regular-text" />
                        <p class="description">Enter the global commission percentage the platform takes from supplier prices.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="taptosell_supplier_reg_key">Supplier Registration Key</label>
                    </th>
                    <td>
                        <input type="text" id="taptosell_supplier_reg_key" value="<?php echo esc_attr($reg_key); ?>" class="regular-text" readonly />
                        <p class="description">
                            This is the private key for supplier registration.
                            <br><strong>Current Registration Link:</strong><br>
                            <code><?php echo $full_reg_link; ?></code>
                        </p>
                        <p style="margin-top: 15px;">
                            <input type="submit" name="regenerate_reg_key" class="button button-secondary" value="Regenerate Key">
                            <br><em><span style="color:red;">Warning:</span> This will immediately invalidate the current link.</em>
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>
        
        <p class="submit">
            <input type="submit" name="submit" class="button button-primary" value="Save Settings">
        </p>
    </form>
    <?php
}