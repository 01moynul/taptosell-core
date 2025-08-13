<?php
// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Shortcode to display the product catalog for dropshippers.
 */
function taptosell_product_catalog_shortcode() {
    if ( ! is_user_logged_in() ) { return '<p>You do not have permission to view this catalog. Please log in.</p>'; }
    $user = wp_get_current_user();
    $roles = (array) $user->roles;
    $is_dropshipper = in_array('dropshipper', $roles);
    $is_admin_level = current_user_can('manage_options');
    if ( ! $is_dropshipper && ! $is_admin_level ) { return '<p>This catalog is for Dropshippers only.</p>'; }

    ob_start();

    // Notices for SRP saving
    if (isset($_GET['srp_saved']) && $_GET['srp_saved'] === 'true') { echo '<div style="background-color: #d4edda; color: #155724; padding: 15px; margin-bottom: 20px;">Your price has been saved successfully!</div>'; }
    // NEW: Notice for SRP error
    if (isset($_GET['srp_error']) && $_GET['srp_error'] === 'true') { echo '<div style="background-color: #f8d7da; color: #721c24; padding: 15px; margin-bottom: 20px;">Error: Price cannot be lower than SRP.</div>'; }
    
    // Notices for ordering (unchanged)
    if (isset($_GET['order_status'])) { /* ... code remains the same ... */ }


    $args = ['post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => -1];
    $product_query = new WP_Query($args);

    if ( $product_query->have_posts() ) {
        echo '<div class="product-catalog-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 20px;">';
        while ( $product_query->have_posts() ) {
            $product_query->the_post();
            $product_id = get_the_ID();
            $supplier_price = (float) get_post_meta($product_id, '_price', true);
            $taptosell_price = $supplier_price * taptosell_get_commission_multiplier();
            $official_srp = $taptosell_price * 1.30; // SRP is defined by TapToSell (usually Supplier Price + 30%) [cite: 7]
            
            global $wpdb;
            $dropshipper_id = get_current_user_id();
            $table_name = $wpdb->prefix . 'taptosell_dropshipper_products';
            $saved_srp = $wpdb->get_var( $wpdb->prepare("SELECT srp FROM $table_name WHERE dropshipper_id = %d AND product_id = %d", $dropshipper_id, $product_id) );
            $display_srp = ($saved_srp !== null) ? $saved_srp : $official_srp;
            
            echo '<div class="product-card" style="border: 1px solid #eee; padding: 15px; text-align: center; display: flex; flex-direction: column; justify-content: space-between;">';
            echo '<div>';
            if ( has_post_thumbnail() ) { the_post_thumbnail('medium'); }
            echo '<h3>' . get_the_title() . '</h3>';
            echo '<p><strong>Your Cost:</strong> RM ' . number_format($taptosell_price, 2) . '</p>'; // TapToSell Price (Not editable) [cite: 12]
            echo '<p><strong>SRP:</strong> RM ' . number_format($official_srp, 2) . '</p>'; // SRP (For reference only) [cite: 13]
            
            if ($is_dropshipper) {
                echo '<form method="post" action="" style="margin-top: 10px;">';
                wp_nonce_field('taptosell_save_srp_action', 'taptosell_srp_nonce');
                echo '<input type="hidden" name="product_id" value="' . esc_attr($product_id) . '">';
                echo '<label for="srp-' . esc_attr($product_id) . '">My Selling Price:</label>'; // 'My Selling Price' - Editable field [cite: 14]
                echo '<input type="number" step="0.01" name="srp" id="srp-' . esc_attr($product_id) . '" value="' . esc_attr(number_format($display_srp, 2, '.', '')) . '" style="width: 100px; text-align: center; margin: 5px 0 10px 0;">';
                echo '<button type="submit" name="taptosell_action" value="save_srp">Save My Price</button>';
                echo '</form>';
            }
            echo '</div>';

            if ($is_dropshipper) { /* ... Place Order button remains the same ... */ }
            echo '</div>';
        }
        echo '</div>';
    } else { echo '<p>No products are available in the catalog yet.</p>'; }
    wp_reset_postdata();

    return ob_get_clean();
}
add_shortcode('taptosell_product_catalog', 'taptosell_product_catalog_shortcode');

/**
 * Handles saving the SRP value from the product catalog form.
 */
function taptosell_handle_srp_save() {
    if ( !isset($_POST['taptosell_action']) || $_POST['taptosell_action'] !== 'save_srp' || !current_user_can('dropshipper') ) { return; }
    if ( !isset($_POST['taptosell_srp_nonce']) || !wp_verify_nonce($_POST['taptosell_srp_nonce'], 'taptosell_save_srp_action') ) { wp_die('Security check failed!'); }

    $product_id = (int)$_POST['product_id'];
    $my_selling_price = (float)$_POST['srp'];
    $dropshipper_id = get_current_user_id();

    if ( $product_id <= 0 || $my_selling_price <= 0 || $dropshipper_id <= 0 ) { return; }
    
    // --- VALIDATION LOGIC ---
    $supplier_price = (float) get_post_meta($product_id, '_price', true);
    $taptosell_price = $supplier_price * taptosell_get_commission_multiplier();
    $official_srp = $taptosell_price * 1.30; 

    if ($my_selling_price < $official_srp) {
        $redirect_url = add_query_arg('srp_error', 'true', wp_get_referer());
        wp_redirect($redirect_url);
        exit;
    }
    
    // --- SAVE LOGIC (if validation passes) ---
    global $wpdb; // The semicolon was missing from this line in the previous code.
    $table_name = $wpdb->prefix . 'taptosell_dropshipper_products';
    $wpdb->query($wpdb->prepare("INSERT INTO $table_name (dropshipper_id, product_id, srp, date_added) VALUES (%d, %d, %f, %s) ON DUPLICATE KEY UPDATE srp = %f", $dropshipper_id, $product_id, $my_selling_price, current_time('mysql'), $my_selling_price));

    // CORRECTED REDIRECT: First, remove any old error messages from the URL.
    $redirect_url = remove_query_arg('srp_error', wp_get_referer());
    // Then, add the success message.
    $redirect_url = add_query_arg('srp_saved', 'true', $redirect_url);
    
    wp_redirect($redirect_url);
    exit;
}
add_action('init', 'taptosell_handle_srp_save');


/**
 * Access control for the Dropshipper Dashboard page.
 */
function taptosell_dropshipper_dashboard_access() {
    $dashboard_page = get_page_by_title('Dropshipper Dashboard');
    if ( $dashboard_page && is_page( $dashboard_page->ID ) ) {
        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            $roles = (array) $user->roles;
            $is_dropshipper = in_array('dropshipper', $roles);
            $is_admin_level = current_user_can('manage_options');
            if ( ! $is_dropshipper && ! $is_admin_level ) {
                wp_redirect( home_url() );
                exit;
            }
        } else {
            wp_redirect( home_url() );
            exit;
        }
    }
}
add_action( 'template_redirect', 'taptosell_dropshipper_dashboard_access' );


/**
 * Shortcode to display a dropshipper's list of products with their custom SRP.
 */
function taptosell_my_products_shortcode() {
    if ( ! is_user_logged_in() ) { return ''; }
    $user = wp_get_current_user();
    $roles = (array) $user->roles;
    $is_dropshipper = in_array('dropshipper', $roles);
    $is_admin_level = current_user_can('manage_options');
    if ( ! $is_dropshipper && ! $is_admin_level ) { return ''; }

    ob_start();
    if ( $is_dropshipper ) {
        global $wpdb;
        $dropshipper_id = get_current_user_id();
        $table_name = $wpdb->prefix . 'taptosell_dropshipper_products';
        $my_products_data = $wpdb->get_results( $wpdb->prepare("SELECT product_id, srp FROM $table_name WHERE dropshipper_id = %d ORDER BY date_added DESC", $dropshipper_id), OBJECT_K );

        if ( empty($my_products_data) ) {
            echo '<p>You have not yet set a price for any products. Please browse the <a href="/product-catalog">Product Catalog</a> to get started.</p>';
            return ob_get_clean();
        }
        $product_ids = array_keys($my_products_data);
        $args = ['post_type' => 'product', 'post__in' => $product_ids, 'posts_per_page' => -1, 'orderby' => 'post__in'];
        $my_products_query = new WP_Query($args);

        if ( $my_products_query->have_posts() ) {
            echo '<h2>My Products</h2>';
            echo '<div class="my-products-list" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 20px;">';
            while ( $my_products_query->have_posts() ) {
                $my_products_query->the_post();
                $product_id = get_the_ID();
                $my_srp = $my_products_data[$product_id]->srp;
                echo '<div class="product-card" style="border: 1px solid #eee; padding: 15px; text-align: center;">';
                if ( has_post_thumbnail() ) { the_post_thumbnail('medium', ['style' => 'max-width: 100%; height: auto;']); }
                echo '<h3 style="font-size: 1.2em; margin: 10px 0;">' . get_the_title() . '</h3>';
                echo '<p><strong>My Selling Price:</strong> RM ' . number_format($my_srp, 2) . '</p>';
                echo '</div>';
            }
            echo '</div>';
        }
        wp_reset_postdata();
    } elseif ($is_admin_level) {
        echo '<h3>Admin View: My Products List</h3>';
        echo '<p>This container shows the products for the logged-in dropshipper. As an admin, you see this message because you do not have a saved products list.</p>';
    }
    return ob_get_clean();
}
add_shortcode('my_products_list', 'taptosell_my_products_shortcode');


/**
 * Handles the manual order placement from the catalog.
 */
function taptosell_handle_manual_order() {
    if ( !isset($_POST['taptosell_action']) || $_POST['taptosell_action'] !== 'place_order' ) { return; }
    if ( !isset($_POST['taptosell_order_nonce']) || !wp_verify_nonce($_POST['taptosell_order_nonce'], 'taptosell_place_order_action') ) { wp_die('Security check failed!'); }
    if ( !current_user_can('dropshipper') ) { return; }

    $product_id = (int)$_POST['product_id'];
    $dropshipper_id = get_current_user_id();

    $supplier_price = (float) get_post_meta($product_id, '_price', true);
    $order_cost = $supplier_price * taptosell_get_commission_multiplier();

    // NEW: Check if the calculated cost is zero.
    if ($order_cost <= 0) {
        // If cost is zero, there's a data error. Redirect with a specific error message.
        $redirect_url = add_query_arg('order_status', 'price_error', wp_get_referer());
        wp_redirect($redirect_url);
        exit;
    }

    $balance = taptosell_get_user_wallet_balance($dropshipper_id);
    $order_data = [
        'post_title'  => 'Order – ' . current_time('mysql') . ' – ' . get_the_title($product_id),
        'post_type'   => 'taptosell_order',
        'post_author' => $dropshipper_id,
    ];

    if ($balance >= $order_cost) {
        $order_data['post_status'] = 'wc-processing';
        $new_order_id = wp_insert_post($order_data);
        if ($new_order_id) {
            update_post_meta($new_order_id, '_product_id', $product_id);
            update_post_meta($new_order_id, '_order_cost', $order_cost);
            taptosell_add_wallet_transaction($dropshipper_id, -$order_cost, 'order_payment', 'Payment for Order #' . $new_order_id);
        }
        $redirect_url = add_query_arg('order_status', 'success', wp_get_referer());
    } else {
        $order_data['post_status'] = 'wc-on-hold';
        $new_order_id = wp_insert_post($order_data);
        if ($new_order_id) { 
            update_post_meta($new_order_id, '_product_id', $product_id);
            update_post_meta($new_order_id, '_order_cost', $order_cost);
        }
        $redirect_url = add_query_arg('order_status', 'on-hold', wp_get_referer());
    }
    
    wp_redirect($redirect_url);
    exit;
}
add_action('init', 'taptosell_handle_manual_order');

/**
 * Shortcode to display a dropshipper's list of their own orders.
 * Usage: [dropshipper_my_orders]
 */
function taptosell_dropshipper_my_orders_shortcode() {
    if ( !is_user_logged_in() || !current_user_can('dropshipper') ) { return ''; }

    ob_start();
    
    // --- NEW: Display payment status notifications ---
    if (isset($_GET['payment_status'])) {
        if ($_GET['payment_status'] === 'success') {
            echo '<div style="background-color: #d4edda; color: #155724; padding: 15px; margin-bottom: 20px;">Payment successful! The order is now processing.</div>';
        } elseif ($_GET['payment_status'] === 'failed') {
            $wallet_page = get_page_by_title('My Wallet');
            $wallet_url = $wallet_page ? get_permalink($wallet_page->ID) : '#';
            echo '<div style="background-color: #f8d7da; color: #721c24; padding: 15px; margin-bottom: 20px;"><strong>Payment Failed:</strong> Insufficient funds. Please <a href="' . esc_url($wallet_url) . '">top up your wallet</a> and try again.</div>';
        }
    }

    echo '<h2>My Orders</h2>';
    
    $args = [
        'post_type' => 'taptosell_order',
        'author' => get_current_user_id(),
        'posts_per_page' => -1,
        'post_status' => ['wc-on-hold', 'wc-processing', 'wc-shipped', 'wc-completed'],
    ];
    $order_query = new WP_Query($args);

    if ( $order_query->have_posts() ) {
        echo '<table style="width: 100%; border-collapse: collapse;"><thead><tr><th>Order#</th><th>Product</th><th>Cost</th><th>Date</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
        while ( $order_query->have_posts() ) {
            $order_query->the_post();
            $order_id = get_the_ID();
            $product_id = get_post_meta($order_id, '_product_id', true);
            $order_cost = get_post_meta($order_id, '_order_cost', true);
            $status = get_post_status_object(get_post_status());

            if ( !is_numeric($order_cost) ) { $order_cost = 0; }

            echo '<tr><td>#' . $order_id . '</td>';
            echo '<td>' . esc_html(get_the_title($product_id)) . '</td>';
            echo '<td>RM ' . number_format($order_cost, 2) . '</td>';
            echo '<td>' . get_the_date('Y-m-d H:i') . '</td>';
            echo '<td>' . esc_html($status->label) . '</td>';
            echo '<td>';
            if (get_post_status() === 'wc-on-hold') {
                echo '<form method="post" action="">';
                wp_nonce_field('taptosell_pay_onhold_action', 'taptosell_onhold_nonce');
                echo '<input type="hidden" name="order_id" value="' . esc_attr($order_id) . '">';
                echo '<button type="submit" name="taptosell_action" value="pay_on_hold">Pay Now</button>';
                echo '</form>';
            } else { echo '—'; }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p>You have not placed any orders yet.</p>';
    }
    wp_reset_postdata();

    return ob_get_clean();
}
add_shortcode('dropshipper_my_orders', 'taptosell_dropshipper_my_orders_shortcode');

/**
 * Handles the "Pay Now" action for "On Hold" orders.
 */
function taptosell_handle_onhold_payment() {
    if ( !isset($_POST['taptosell_action']) || $_POST['taptosell_action'] !== 'pay_on_hold' ) { return; }
    if ( !isset($_POST['taptosell_onhold_nonce']) || !wp_verify_nonce($_POST['taptosell_onhold_nonce'], 'taptosell_pay_onhold_action') ) { wp_die('Security check failed!'); }
    if ( !current_user_can('dropshipper') ) { return; }

    $order_id = (int)$_POST['order_id'];
    $dropshipper_id = get_current_user_id();

    if ($dropshipper_id != get_post_field('post_author', $order_id)) { wp_die('Permission denied.'); }

    $order_cost = (float) get_post_meta($order_id, '_order_cost', true);
    if ( empty($order_cost) ) {
        $product_id = get_post_meta($order_id, '_product_id', true);
        $supplier_price = (float) get_post_meta($product_id, '_price', true);
        $taptosell_price = $supplier_price * taptosell_get_commission_multiplier();
    }

    $balance = taptosell_get_user_wallet_balance($dropshipper_id);
    $dashboard_page = get_page_by_title('Dropshipper Dashboard');
    $redirect_url = $dashboard_page ? get_permalink($dashboard_page->ID) : home_url();

    if ($balance >= $order_cost) {
        wp_update_post(['ID' => $order_id, 'post_status' => 'wc-processing']);
        taptosell_add_wallet_transaction($dropshipper_id, -$order_cost, 'order_payment', 'Payment for Order #' . $order_id);
        // Add a "success" message to the URL
        $redirect_url = add_query_arg('payment_status', 'success', $redirect_url);
    } else {
        // Add a "failed" message to the URL
        $redirect_url = add_query_arg('payment_status', 'failed', $redirect_url);
    }
    
    wp_redirect($redirect_url);
    exit;
}
add_action('init', 'taptosell_handle_onhold_payment');

/**
 * Shortcode to display the marketplace integration options.
 */
function taptosell_my_shops_shortcode() {
    if ( !is_user_logged_in() ) { return '<p>You must be logged in to view this page.</p>'; }
    $user = wp_get_current_user();
    if ( !in_array('dropshipper', (array)$user->roles) && !current_user_can('manage_options') ) { return '<p>This feature is for Dropshippers only.</p>'; }

    $dropshipper_id = get_current_user_id();

    ob_start();
    ?>
    <h2>My Shops Integration</h2>
    <p>Connect your marketplace shops to automate product syncing and order processing.</p>

    <?php
    // Display status messages from the callback or sync
    if (isset($_GET['shopee_status'])) { /* ... existing message code ... */ }
    if (isset($_GET['sync_status']) && $_GET['sync_status'] === 'failed') { echo '<div style="background-color: #f8d7da; color: #721c24; padding: 15px; margin-bottom: 20px;">Product sync failed. Please try again.</div>'; }
    ?>
    
    <div class="shops-container" style="margin-top: 20px;">
        <div class="shop-card" style="border: 1px solid #ddd; padding: 20px; margin-bottom: 15px; border-radius: 5px;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0;">Shopee</h3>
                <?php 
                $shopee_shop_id = get_user_meta($dropshipper_id, 'shopee_shop_id', true);
                if ($shopee_shop_id) : ?>
                    <div style="text-align: right;">
                        <p style="margin: 0; color: green;"><strong>Connected</strong></p>
                        <small>Shop ID: <?php echo esc_html($shopee_shop_id); ?></small>
                    </div>
                <?php else : 
                    $connect_shopee_link = add_query_arg('action', 'taptosell_connect_shopee', home_url());
                    ?>
                    <a href="<?php echo esc_url($connect_shopee_link); ?>" style="background-color: #EE4D2D; color: white; padding: 10px 20px; text-decoration: none;">Connect</a>
                <?php endif; ?>
            </div>

            <?php // NEW: If connected, show the Sync Products button
            if ($shopee_shop_id) : ?>
                <div style="margin-top: 20px; border-top: 1px solid #eee; padding-top: 20px;">
                    <form method="post" action="">
                        <?php wp_nonce_field('taptosell_sync_shopee_action', 'taptosell_sync_shopee_nonce'); ?>
                        <button type="submit" name="taptosell_action" value="sync_shopee_products">Sync Products</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <div class="shop-card" style="border: 1px solid #ddd; padding: 20px; margin-bottom: 15px;">
             <h3 style="margin: 0;">TikTok Shop</h3>
             <span style="color: #888;">Coming Soon</span>
        </div>

        <div class="shop-card" style="border: 1px solid #ddd; padding: 20px; margin-bottom: 15px;">
             <h3 style="margin: 0;">Lazada</h3>
             <span style="color: #888;">Coming Soon</span>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('my_shops_integration', 'taptosell_my_shops_shortcode');

/**
 * Handles the initial step of the Shopee OAuth flow.
 */
function taptosell_handle_shopee_connect() {
    // Check if our action has been triggered and the user is a dropshipper
    if ( isset($_GET['action']) && $_GET['action'] === 'taptosell_connect_shopee' && current_user_can('dropshipper') ) {
        
        // --- IMPORTANT: You will need to get these from the Shopee Open Platform ---
        $partner_id = 'YOUR_SHOPEE_PARTNER_ID'; // Replace with your actual Partner ID from Shopee
        $redirect_uri = home_url('/shopee-callback'); // This is a URL on our site we will build next
        
        // This is the main authorization URL for Shopee
        $shopee_auth_url = 'https://partner.shopeemobile.com/api/v2/shop/auth_partner';

        // Redirect the user to Shopee
        wp_redirect($shopee_auth_url . '?partner_id=' . $partner_id . '&redirect=' . urlencode($redirect_uri));
        exit;
    }
}
add_action('init', 'taptosell_handle_shopee_connect');

/**
 * --- NEW: Shortcode to display a sales summary chart. ---
 * Usage: [dropshipper_sales_summary]
 */
function taptosell_sales_summary_shortcode() {
    // Security check for logged-in dropshipper
    if ( !is_user_logged_in() || !current_user_can('dropshipper') ) {
        return '';
    }

    $user_id = get_current_user_id();
    $sales_data = [];
    $date_labels = [];

    // Loop through the last 7 days, including today
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $date_labels[] = date('M d', strtotime($date)); // Format for display, e.g., "Aug 13"

        // Query to get all completed orders for the current user on this specific day
        $daily_orders_query = new WP_Query([
            'post_type'      => 'taptosell_order',
            'author'         => $user_id,
            'post_status'    => 'wc-completed',
            'posts_per_page' => -1,
            'date_query'     => [
                ['year' => date('Y', strtotime($date)), 'month' => date('m', strtotime($date)), 'day' => date('d', strtotime($date))],
            ],
            'meta_key'       => '_order_cost', // Ensure we only get orders that have a cost associated
        ]);

        $daily_total = 0;
        if ($daily_orders_query->have_posts()) {
            while ($daily_orders_query->have_posts()) {
                $daily_orders_query->the_post();
                // For a sales chart, we should sum the SRP, not the cost.
                // We'll need to look up the SRP the dropshipper set for this product.
                $product_id = get_post_meta(get_the_ID(), '_product_id', true);

                global $wpdb;
                $srp_table_name = $wpdb->prefix . 'taptosell_dropshipper_products';
                $srp = $wpdb->get_var($wpdb->prepare(
                    "SELECT srp FROM $srp_table_name WHERE dropshipper_id = %d AND taptosell_product_id = %d",
                    $user_id, $product_id
                ));
                
                if (is_numeric($srp)) {
                    $daily_total += (float)$srp;
                }
            }
        }
        wp_reset_postdata();

        $sales_data[] = $daily_total;
    }

    ob_start();
    ?>
    <div class="sales-summary-container" style="max-width: 800px; margin-top: 20px;">
        <h3>Last 7 Days Sales Summary</h3>
        <canvas id="salesSummaryChart"></canvas>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('salesSummaryChart').getContext('2d');
            const salesChart = new Chart(ctx, {
                type: 'line', // Type of chart
                data: {
                    labels: <?php echo json_encode($date_labels); ?>, // Dates for the X-axis
                    datasets: [{
                        label: 'Total Sales (RM)',
                        data: <?php echo json_encode($sales_data); ?>, // Sales data for the Y-axis
                        backgroundColor: 'rgba(35, 181, 116, 0.2)',
                        borderColor: 'rgba(35, 181, 116, 1)',
                        borderWidth: 2,
                        tension: 0.1
                    }]
                },
                options: {
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('dropshipper_sales_summary', 'taptosell_sales_summary_shortcode');