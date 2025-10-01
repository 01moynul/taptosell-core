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
 * --- UPDATED (Phase 11): Renders the User Management view. ---
 * This now fetches and displays a list of users pending approval.
 */
function taptosell_render_oa_users_view() {
    ?>
    <h3>Manage Pending Users</h3>
    <p>The following users have registered and are awaiting account approval.</p>

    <?php
    // Query the database for all users with the '_account_status' meta key set to 'pending'
    $pending_users_query = new WP_User_Query([
        'meta_key'   => '_account_status',
        'meta_value' => 'pending',
        'orderby'    => 'user_registered',
        'order'      => 'DESC',
    ]);

    $pending_users = $pending_users_query->get_results();

    // Check if any pending users were found
    if (!empty($pending_users)) {
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Registered</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pending_users as $user) : ?>
                    <tr>
                        <td><strong><?php echo esc_html($user->user_login); ?></strong></td>
                        <td><?php echo esc_html($user->user_email); ?></td>
                        <td><?php echo esc_html(ucfirst(implode(', ', $user->roles))); ?></td>
                        <td><?php echo date('F j, Y', strtotime($user->user_registered)); ?></td>
                        <td>
                            <a href="#" class="button button-primary">Approve</a>
                            <a href="#" class="button button-secondary">Reject</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    } else {
        // If no pending users are found, display a message.
        echo '<p>There are no users pending approval at this time.</p>';
    }
}
/**
 * --- UPDATED (Phase 11): Main shortcode for the Operational Admin Dashboard ---
 * This function now acts as a router, displaying different content based on the 'view' URL parameter.
 */
function taptosell_oa_dashboard_shortcode() {
    ob_start();

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
                    // Placeholder for now
                    echo '<h3>Manage Pending Products</h3><p>The product approval list will be here.</p>';
                    break;
                case 'withdrawals':
                     // Placeholder for now
                    echo '<h3>Manage Pending Withdrawals</h3><p>The withdrawal request list will be here.</p>';
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