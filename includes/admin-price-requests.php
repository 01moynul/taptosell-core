<?php
// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Displays the HTML content for the Price Change Requests admin page.
 */
function taptosell_price_requests_page_html() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'taptosell_price_changes';

    // Check for any success or error messages from URL parameters
    if ( isset($_GET['message']) ) {
        if ($_GET['message'] === 'approved') {
            echo '<div class="notice notice-success is-dismissible"><p>Price change request approved successfully.</p></div>';
        } elseif ($_GET['message'] === 'rejected') {
            echo '<div class="notice notice-success is-dismissible"><p>Price change request rejected.</p></div>';
        }
    }

    // Fetch all pending requests
    $pending_requests = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM $table_name WHERE status = %s ORDER BY request_date ASC", 'pending')
    );
    ?>
    <div class="wrap">
        <h1>Price Change Requests</h1>
        <p>Review and approve or reject price change requests submitted by suppliers.</p>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:20%;">Product</th>
                    <th style="width:15%;">Supplier</th>
                    <th style="width:10%;">Old Price</th>
                    <th style="width:10%;">New Price</th>
                    <th style="width:15%;">Date Requested</th>
                    <th style="width:20%;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( ! empty( $pending_requests ) ) : ?>
                    <?php foreach ( $pending_requests as $request ) : ?>
                        <?php
                        // --- FIX: Use standard WordPress functions instead of WooCommerce functions ---
                        $product = get_post($request->product_id);
                        $supplier = get_user_by('id', $request->supplier_id);

                        $approve_nonce = wp_create_nonce('taptosell_approve_price_' . $request->id);
                        $reject_nonce = wp_create_nonce('taptosell_reject_price_' . $request->id);
                        
                        $approve_link = admin_url('admin.php?action=taptosell_approve_price&request_id=' . $request->id . '&_wpnonce=' . $approve_nonce);
                        $reject_link = admin_url('admin.php?action=taptosell_reject_price&request_id=' . $request->id . '&_wpnonce=' . $reject_nonce);
                        ?>
                        <tr>
                            <td>
                                <?php if ($product) : ?>
                                    <a href="<?php echo get_edit_post_link($request->product_id); ?>">
                                        <?php echo esc_html($product->post_title); // FIX: Use the standard 'post_title' property ?>
                                    </a>
                                <?php else : ?>
                                    Product #<?php echo esc_html($request->product_id); ?> (Not Found)
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($supplier->display_name); ?></td>
                            <td>RM <?php echo number_format($request->old_price, 2); ?></td>
                            <td style="font-weight: bold;">RM <?php echo number_format($request->new_price, 2); ?></td>
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
    </div>
    <?php
}