<?php
// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles the logic for the Shopee OAuth callback.
 */
function taptosell_handle_shopee_callback() {
    // Check if this is the Shopee callback URL and the 'code' parameter exists.
    if ( isset( $_GET['code'] ) && isset( $_GET['shop_id'] ) ) {
        
        $code = sanitize_text_field($_GET['code']);
        $shop_id = (int)$_GET['shop_id'];
        $dropshipper_id = get_current_user_id();

        // If no user is logged in, we can't proceed.
        if (!$dropshipper_id) {
            wp_die('Error: You must be logged in to connect your shop.');
        }

        // --- IMPORTANT: You will need these from the Shopee Open Platform ---
        $partner_id = 'YOUR_SHOPEE_PARTNER_ID'; // Replace with your actual Partner ID
        $partner_key = 'YOUR_SHOPEE_PARTNER_KEY'; // Replace with your actual Partner Key

        // --- Exchange the Authorization Code for an Access Token ---
        $api_path = '/api/v2/auth/token/get';
        $timestamp = time();
        $base_string = sprintf("%s%s%s", $partner_id, $api_path, $timestamp);
        $sign = hash_hmac('sha256', $base_string, $partner_key);

        $api_url = 'https://partner.shopeemobile.com' . $api_path;

        $response = wp_remote_post($api_url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode([
                'code' => $code,
                'shop_id' => $shop_id,
                'partner_id' => (int)$partner_id,
            ]),
            'method'  => 'POST',
        ]);

        $redirect_url = get_permalink(taptosell_get_page_by_title('My Shops')->ID);

        if (is_wp_error($response)) {
            // Handle connection error
            wp_redirect(add_query_arg('shopee_status', 'error', $redirect_url));
            exit;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['access_token']) && isset($body['refresh_token'])) {
            // Success! Save the tokens to the user's profile.
            update_user_meta($dropshipper_id, 'shopee_shop_id', $shop_id);
            update_user_meta($dropshipper_id, 'shopee_access_token', $body['access_token']);
            update_user_meta($dropshipper_id, 'shopee_refresh_token', $body['refresh_token']);
            update_user_meta($dropshipper_id, 'shopee_token_expiry', time() + $body['expire_in']);

            wp_redirect(add_query_arg('shopee_status', 'success', $redirect_url));
        } else {
            // Handle error from Shopee API
            wp_redirect(add_query_arg('shopee_status', 'failed', $redirect_url));
        }
        exit;
    }
}
// We use 'template_redirect' to check the URL on every page load before the template is chosen.
add_action('template_redirect', 'taptosell_handle_shopee_callback');

/**
 * Handles the Shopee product sync action.
 */
function taptosell_handle_shopee_sync_products() {
    if ( !isset($_POST['taptosell_action']) || $_POST['taptosell_action'] !== 'sync_shopee_products' ) { return; }
    if ( !isset($_POST['taptosell_sync_shopee_nonce']) || !wp_verify_nonce($_POST['taptosell_sync_shopee_nonce'], 'taptosell_sync_shopee_action') ) { wp_die('Security check failed!'); }
    if ( !current_user_can('dropshipper') ) { return; }

    // Step 1: Get the list of products from the marketplace (using our dummy function for now).
    $shopee_products = taptosell_get_dummy_shopee_products();

    // Step 2: Run the matching engine.
    $matching_results = taptosell_match_shopee_products($shopee_products);

    // Step 3: Display the results for testing.
    echo '<pre>';
    echo '<h3>Matching Results</h3>';
    print_r($matching_results);
    echo '</pre>';
    wp_die("This is the result of the product matching engine. The next step is to build a UI for the user to review these matches.");
}
add_action('init', 'taptosell_handle_shopee_sync_products');

/**
 * Provides a sample list of products to simulate a Shopee API response.
 *
 * @return array A list of sample Shopee products.
 */
function taptosell_get_dummy_shopee_products() {
    return [
        [
            'item_id'   => 1001,
            'item_sku'  => 'TTS-PANT-01', // This SKU should match a product in your DB
            'item_name' => 'Premium Khaki Trousers',
        ],
        [
            'item_id'   => 1002,
            'item_sku'  => 'SHOPEE-TSHIRT-02',
            'item_name' => 'Hilfiger Graphic T-Shirt', // This title should partially match
        ],
        [
            'item_id'   => 1003,
            'item_sku'  => 'SHOPEE-UNIQUE-SKU-99',
            'item_name' => 'A completely random product',
        ],
        // --- NEW DUMMY PRODUCTS FOR TESTING ---
        [
            'item_id'   => 2001,
            'item_sku'  => 'u-y-rej', // SKU from your screenshot for "rejetd test"
            'item_name' => 'Supplier Rejetd Test Product',
        ],
        [
            'item_id'   => 2002,
            'item_sku'  => 'yu-i-f', // SKU from your screenshot for "jelly"
            'item_name' => 'Supplier Jelly Product',
        ],
        [
            'item_id'   => 2003,
            'item_sku'  => 'NEW-SKU-003',
            'item_name' => 'Another New Product for Bulk Test',
        ],
        [
            'item_id'   => 2004,
            'item_sku'  => 'BULK-TEST-004',
            'item_name' => 'Fourth Product for Bulk Sync',
        ],
    ];
}

/**
 * Matches products from a marketplace with products in the TapToSell database.
 *
 * @param array $marketplace_products The list of products from the marketplace API.
 * @return array An array containing matched and unmatched products.
 */
function taptosell_match_shopee_products($marketplace_products) {
    $results = [
        'matched'   => [],
        'unmatched' => [],
    ];

    // Get all published TapToSell products (ID, title, and SKU) for comparison
    $taptosell_products_query = new WP_Query([
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
    ]);

    $taptosell_products = [];
    if ($taptosell_products_query->have_posts()) {
        while ($taptosell_products_query->have_posts()) {
            $taptosell_products_query->the_post();
            $product_id = get_the_ID();
            $taptosell_products[] = [
                'id'    => $product_id,
                'title' => get_the_title(),
                'sku'   => get_post_meta($product_id, '_sku', true),
            ];
        }
    }
    wp_reset_postdata();

    // Loop through each product from the marketplace
    foreach ($marketplace_products as $shopee_product) {
        $found_match = false;
        // Loop through TapToSell products to find a match
        foreach ($taptosell_products as $tts_product) {
            // Primary Matching Criteria: SKU (exact match)
            if (!empty($shopee_product['item_sku']) && $shopee_product['item_sku'] === $tts_product['sku']) {
                $results['matched'][] = [
                    'shopee_product' => $shopee_product,
                    'taptosell_product' => $tts_product,
                    'match_type' => 'SKU',
                ];
                $found_match = true;
                break; // Exit inner loop once a match is found
            }

            // Secondary Matching Criteria: Product Title (partial match)
            // We use stripos() to see if the TapToSell title exists within the Shopee title
            if (stripos($shopee_product['item_name'], $tts_product['title']) !== false) {
                 $results['matched'][] = [
                    'shopee_product' => $shopee_product,
                    'taptosell_product' => $tts_product,
                    'match_type' => 'Title',
                ];
                $found_match = true;
                break; // Exit inner loop once a match is found
            }
        }

        // If after checking all TapToSell products, no match was found
        if (!$found_match) {
            $results['unmatched'][] = $shopee_product;
        }
    }

    return $results;
}

/**
 * Shortcode to display the product matching review UI.
 * (This version adds checkboxes for bulk actions).
 */
function taptosell_product_matching_ui_shortcode() {
    if ( !is_user_logged_in() || !current_user_can('dropshipper') ) { return '<p>This feature is for Dropshippers only.</p>'; }

    ob_start();

    // --- Display Status Messages ---
    if (isset($_GET['match_approved']) && $_GET['match_approved'] === 'true') {
        echo '<div style="background-color: #d4edda; color: #155724; padding: 15px; margin-bottom: 20px;">Match approved and product linked!</div>';
    }
    if (isset($_GET['bulk_approved_count'])) {
        $count = (int)$_GET['bulk_approved_count'];
        echo '<div style="background-color: #d4edda; color: #155724; padding: 15px; margin-bottom: 20px;">' . sprintf( _n( '%d match approved successfully.', '%d matches approved successfully.', $count ), $count ) . '</div>';
    }
    if (isset($_GET['match_error']) && $_GET['match_error'] === 'limit_reached') {
        echo '<div style="background-color: #f8d7da; color: #721c24; padding: 15px; margin-bottom: 20px;"><strong>Limit Reached:</strong> You have reached your free product linking limit. Please subscribe to link more products.</div>';
    }
    
    global $wpdb;
    $dropshipper_id = get_current_user_id();
    $table_name = $wpdb->prefix . 'taptosell_dropshipper_products';
    $linked_product_ids = $wpdb->get_col( $wpdb->prepare( "SELECT marketplace_product_id FROM $table_name WHERE dropshipper_id = %d AND marketplace_product_id IS NOT NULL", $dropshipper_id ) );
    $all_shopee_products = taptosell_get_dummy_shopee_products();
    $unlinked_shopee_products = array_filter($all_shopee_products, function($product) use ($linked_product_ids) { return !in_array($product['item_id'], $linked_product_ids); });
    $matching_results = taptosell_match_shopee_products($unlinked_shopee_products);


    echo '<h2>Product Matching Review</h2>';

    if (empty($matching_results['matched']) && empty($matching_results['unmatched'])) {
        echo '<p>All of your store products have been linked. There are no new products to review.</p>';
        return ob_get_clean();
    }
    
    if (!empty($matching_results['matched'])) {
        echo '<h3>Suggested Matches</h3>';
        
        // --- NEW: Wrap table in a form for bulk actions ---
        echo '<form method="post" action="">';
        wp_nonce_field('taptosell_bulk_approve_action', 'taptosell_bulk_approve_nonce');

        echo '<table style="width: 100%; border-collapse: collapse;"><thead><tr>
              <th style="width: 5%;"><input type="checkbox" title="Select All"></th>
              <th>Your Product (Shopee)</th>
              <th>Suggested Match (TapToSell)</th>
              <th>Match Type</th>
              <th>Action</th>
              </tr></thead><tbody>';

        foreach ($matching_results['matched'] as $match) {
            // --- NEW: Create a composite value for the checkbox ---
            $checkbox_value = esc_attr($match['shopee_product']['item_id'] . ':' . $match['taptosell_product']['id']);

            echo '<tr>';
            // --- NEW: Add a checkbox to each row ---
            echo '<td style="text-align: center;"><input type="checkbox" name="matches[]" value="' . $checkbox_value . '"></td>';

            echo '<td>' . esc_html($match['shopee_product']['item_name']) . '<br><small>SKU: ' . esc_html($match['shopee_product']['item_sku']) . '</small></td>';
            echo '<td>' . esc_html($match['taptosell_product']['title']) . '<br><small>SKU: ' . esc_html($match['taptosell_product']['sku']) . '</small></td>';
            echo '<td>' . esc_html($match['match_type']) . '</td>';
            echo '<td>';
            echo '<form method="post" action=""><input type="hidden" name="shopee_product_id" value="' . esc_attr($match['shopee_product']['item_id']) . '"><input type="hidden" name="taptosell_product_id" value="' . esc_attr($match['taptosell_product']['id']) . '">';
            wp_nonce_field('taptosell_approve_match_action', 'taptosell_approve_nonce');
            echo '<button type="submit" name="taptosell_action" value="approve_match">Approve Match</button></form>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        // --- NEW: Add the bulk submit button ---
        echo '<div style="margin-top: 20px;">';
        echo '<button type="submit" name="taptosell_action" value="bulk_approve_matches" class="button button-primary">Approve Selected</button>';
        echo '</div>';

        echo '</form>';
    }
    
    if (!empty($matching_results['unmatched'])) {
        echo '<h3 style="margin-top: 30px;">Unmatched Products</h3>';
        echo '<table style="width: 100%; border-collapse: collapse;"><thead><tr><th>Your Product (Shopee)</th><th>Action</th></tr></thead><tbody>';
        foreach ($matching_results['unmatched'] as $product) {
            echo '<tr>';
            echo '<td>' . esc_html($product['item_name']) . '<br><small>SKU: ' . esc_html($product['item_sku']) . '</small></td>';
            echo '<td><a href="#">Find Match Manually</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    return ob_get_clean();
}
add_shortcode('product_matching_ui', 'taptosell_product_matching_ui_shortcode');

/**
 * Handles the "Approve Match" action from the matching UI.
 */
function taptosell_handle_approve_match() {
    if ( !isset($_POST['taptosell_action']) || $_POST['taptosell_action'] !== 'approve_match' ) { return; }
    if ( !isset($_POST['taptosell_approve_nonce']) || !wp_verify_nonce($_POST['taptosell_approve_nonce'], 'taptosell_approve_match_action') ) { wp_die('Security check failed!'); }
    if ( !current_user_can('dropshipper') ) { return; }

    $dropshipper_id = get_current_user_id();

    // --- NEW: Subscription Check ---
    if ( ! taptosell_can_user_link_product($dropshipper_id) ) {
        $redirect_url = add_query_arg('match_error', 'limit_reached', wp_get_referer());
        wp_redirect($redirect_url);
        exit;
    }
    // --- END: Subscription Check ---

    $taptosell_product_id = (int)$_POST['taptosell_product_id'];
    $shopee_product_id = (int)$_POST['shopee_product_id'];

    if ($taptosell_product_id <= 0 || $shopee_product_id <= 0) { return; }

    global $wpdb;
    $table_name = $wpdb->prefix . 'taptosell_dropshipper_products';

    // Using INSERT...ON DUPLICATE KEY UPDATE is a robust way to handle this.
    // It will add the marketplace ID if the row exists, or create a new row if it doesn't.
    $wpdb->query($wpdb->prepare(
        "INSERT INTO $table_name (dropshipper_id, taptosell_product_id, marketplace_product_id, marketplace, date_added)
         VALUES (%d, %d, %d, %s, %s)
         ON DUPLICATE KEY UPDATE marketplace_product_id = %d, marketplace = %s",
        $dropshipper_id, $taptosell_product_id, $shopee_product_id, 'shopee', current_time('mysql'),
        $shopee_product_id, 'shopee'
    ));

    $redirect_url = add_query_arg('match_approved', 'true', wp_get_referer());
    wp_redirect($redirect_url);
    exit;
}
add_action('init', 'taptosell_handle_approve_match');

/**
 * --- NEW: Handles the "Approve Selected" bulk action from the matching UI. ---
 */
function taptosell_handle_bulk_approve_matches() {
    // Check if our specific form action was triggered
    if ( !isset($_POST['taptosell_action']) || $_POST['taptosell_action'] !== 'bulk_approve_matches' ) {
        return;
    }
    // Security check for nonce
    if ( !isset($_POST['taptosell_bulk_approve_nonce']) || !wp_verify_nonce($_POST['taptosell_bulk_approve_nonce'], 'taptosell_bulk_approve_action') ) {
        wp_die('Security check failed!');
    }
    // Security check for user role
    if ( !current_user_can('dropshipper') ) {
        return;
    }
    // Check if any checkboxes were ticked. If not, do nothing.
    if ( empty($_POST['matches']) || !is_array($_POST['matches']) ) {
        wp_redirect(wp_get_referer());
        exit;
    }

    $dropshipper_id = get_current_user_id();
    $approved_count = 0;
    global $wpdb;
    $table_name = $wpdb->prefix . 'taptosell_dropshipper_products';

    // Loop through each selected checkbox
    foreach ($_POST['matches'] as $match_value) {
        
        // On each iteration, check if the user is still allowed to link products
        if ( ! taptosell_can_user_link_product($dropshipper_id) ) {
            $redirect_url = add_query_arg('match_error', 'limit_reached', wp_get_referer());
            // If the limit is reached partway through, still show a success message for the ones that were approved
            if ($approved_count > 0) {
                $redirect_url = add_query_arg('bulk_approved_count', $approved_count, $redirect_url);
            }
            wp_redirect($redirect_url);
            exit;
        }

        // The checkbox value is 'shopee_id:taptosell_id'. We need to split it into two variables.
        list($shopee_product_id, $taptosell_product_id) = explode(':', $match_value);
        $shopee_product_id = (int)$shopee_product_id;
        $taptosell_product_id = (int)$taptosell_product_id;

        // Make sure the IDs are valid before processing
        if ($taptosell_product_id > 0 && $shopee_product_id > 0) {
            // Use the same robust database logic as the single approve action
            $wpdb->query($wpdb->prepare(
                "INSERT INTO $table_name (dropshipper_id, taptosell_product_id, marketplace_product_id, marketplace, date_added)
                 VALUES (%d, %d, %d, %s, %s)
                 ON DUPLICATE KEY UPDATE marketplace_product_id = %d, marketplace = %s",
                $dropshipper_id, $taptosell_product_id, $shopee_product_id, 'shopee', current_time('mysql'),
                $shopee_product_id, 'shopee'
            ));
            $approved_count++;
        }
    }

    // Redirect back to the review page with a success message showing how many were approved
    if ($approved_count > 0) {
        $redirect_url = add_query_arg('bulk_approved_count', $approved_count, wp_get_referer());
        wp_redirect($redirect_url);
        exit;
    }
}
add_action('init', 'taptosell_handle_bulk_approve_matches');