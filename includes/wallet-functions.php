<?php
// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Helper function to get a user's current wallet balance.
 */
function taptosell_get_user_wallet_balance($user_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'taptosell_wallet_transactions';
    $balance = $wpdb->get_var($wpdb->prepare("SELECT SUM(amount) FROM $table_name WHERE user_id = %d", $user_id));
    return (float) $balance;
}

/**
 * Helper function to add a new transaction to the wallet table.
 */
function taptosell_add_wallet_transaction($user_id, $amount, $type, $details) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'taptosell_wallet_transactions';
    $wpdb->insert(
        $table_name,
        ['user_id' => $user_id, 'amount' => $amount, 'type' => $type, 'details' => $details, 'transaction_date' => current_time('mysql')],
        ['%d', '%f', '%s', '%s', '%s']
    );
}

/**
 * Shortcode to display the user's wallet: balance, history, and forms.
 */
function taptosell_wallet_shortcode() {
    if ( ! is_user_logged_in() ) {
        return '<p>You must be logged in to view your wallet.</p>';
    }

    $user_id = get_current_user_id();
    $user = wp_get_current_user();
    $roles = (array) $user->roles;

    ob_start();
    ?>
    <div class="taptosell-wallet-container">
        <h2>My Wallet</h2>

        <?php 
        if (isset($_GET['topup']) && $_GET['topup'] === 'success') { echo '<div style="background-color: #d4edda; color: #155724; padding: 15px; margin-bottom: 20px;">Top-up successful!</div>'; }
        if (isset($_GET['withdrawal']) && $_GET['withdrawal'] === 'success') { echo '<div style="background-color: #d4edda; color: #155724; padding: 15px; margin-bottom: 20px;">Withdrawal request submitted successfully.</div>'; }
        if (isset($_GET['withdrawal']) && $_GET['withdrawal'] === 'failed') { echo '<div style="background-color: #f8d7da; color: #721c24; padding: 15px; margin-bottom: 20px;">Error: Invalid withdrawal amount.</div>'; }
        
        // --- RENDER VIEW BASED ON USER ROLE ---
        if (in_array('dropshipper', $roles)) {
            // --- DROPSHIPPER VIEW ---
            $balance = taptosell_get_user_wallet_balance($user_id);
            ?>
            <div class="wallet-balance" style="background-color: #f1f1f1; padding: 20px; margin-bottom: 20px;">
                <h4>Current Balance</h4>
                <p style="font-size: 2em; font-weight: bold; margin: 0;">RM <?php echo number_format($balance, 2); ?></p>
            </div>
            <div class="wallet-top-up" style="margin-bottom: 30px;">
                <h4>Top-Up Wallet</h4>
                <p><em>This is a test form. No real payment will be processed.</em></p>
                <form method="post" action="">
                    <?php wp_nonce_field('taptosell_topup_action', 'taptosell_topup_nonce'); ?>
                    <label for="topup_amount">Amount (RM):</label>
                    <input type="number" step="10" min="10" name="topup_amount" id="topup_amount" value="50" required>
                    <button type="submit" name="taptosell_action" value="do_topup">Add Funds</button>
                </form>
            </div>
            <?php
        } elseif (in_array('supplier', $roles)) {
            // --- SUPPLIER VIEW ---
            $available_balance = taptosell_get_user_wallet_balance($user_id);
            
            $pending_balance = 0;
            $my_product_ids = get_posts(['post_type' => 'product', 'author' => $user_id, 'posts_per_page' => -1, 'fields' => 'ids']);
            if (!empty($my_product_ids)) {
                $shipped_orders_query = new WP_Query([
                    'post_type' => 'taptosell_order',
                    'post_status' => 'wc-shipped',
                    'posts_per_page' => -1,
                    'meta_query' => [['key' => '_product_id', 'value' => $my_product_ids, 'compare' => 'IN']],
                ]);
                if ($shipped_orders_query->have_posts()) {
                    while ($shipped_orders_query->have_posts()) {
                        $shipped_orders_query->the_post();
                        $product_id = get_post_meta(get_the_ID(), '_product_id', true);
                        $price = (float) get_post_meta($product_id, '_price', true);
                        $pending_balance += $price;
                    }
                }
                wp_reset_postdata();
            }
            ?>
            <div class="wallet-balance" style="background-color: #d4edda; color: #155724; padding: 20px; margin-bottom: 10px;">
                <h4>Available for Withdrawal</h4>
                <p style="font-size: 2em; font-weight: bold; margin: 0;">RM <?php echo number_format($available_balance, 2); ?></p>
            </div>
            <div class="wallet-balance" style="background-color: #fff3cd; color: #856404; padding: 20px; margin-bottom: 20px;">
                <h4>Pending Balance</h4>
                <p style="font-size: 1.5em; font-weight: bold; margin: 0;">RM <?php echo number_format($pending_balance, 2); ?></p>
            </div>

            <div class="wallet-withdrawal" style="margin-bottom: 30px;">
                <h4>Request Withdrawal</h4>
                <form method="post" action="">
                    <?php wp_nonce_field('taptosell_withdrawal_action', 'taptosell_withdrawal_nonce'); ?>
                    <label for="withdrawal_amount">Amount (RM):</label>
                    <input type="number" step="0.01" name="withdrawal_amount" id="withdrawal_amount" max="<?php echo esc_attr($available_balance); ?>" required>
                    <button type="submit" name="taptosell_action" value="request_withdrawal">Request Withdrawal</button>
                </form>
            </div>
            <?php
        } // <-- This closing brace was missing.
        ?>

        <div class="wallet-history">
            <h4>Transaction History</h4>
            <?php
            global $wpdb;
            $table_name = $wpdb->prefix . 'taptosell_wallet_transactions';
            $transactions = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name WHERE user_id = %d ORDER BY transaction_date DESC", $user_id));

            if ($transactions) {
                echo '<table style="width: 100%; border-collapse: collapse;"><thead><tr><th>Date</th><th>Type</th><th>Details</th><th>Amount (RM)</th></tr></thead><tbody>';
                foreach ($transactions as $tx) {
                    $amount_style = ($tx->amount > 0) ? 'color: green;' : 'color: red;';
                    echo '<tr>';
                    echo '<td>' . date('Y-m-d H:i', strtotime($tx->transaction_date)) . '</td>';
                    echo '<td>' . esc_html(ucfirst($tx->type)) . '</td>';
                    echo '<td>' . esc_html($tx->details) . '</td>';
                    echo '<td style="text-align: right; ' . $amount_style . '">' . number_format($tx->amount, 2) . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            } else {
                echo '<p>No transactions found.</p>';
            }
            ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('taptosell_wallet', 'taptosell_wallet_shortcode');


/**
 * Handles the manual top-up form submission.
 */
function taptosell_handle_wallet_topup() {
    if ( !isset($_POST['taptosell_action']) || $_POST['taptosell_action'] !== 'do_topup' ) {
        return;
    }
    if ( !isset($_POST['taptosell_topup_nonce']) || !wp_verify_nonce($_POST['taptosell_topup_nonce'], 'taptosell_topup_action') ) {
        wp_die('Security check failed!');
    }
    if ( !current_user_can('dropshipper') ) {
        return;
    }
    
    $amount = (float)$_POST['topup_amount'];
    if ($amount > 0) {
        taptosell_add_wallet_transaction(get_current_user_id(), $amount, 'deposit', 'Manual top-up by user');
    }

    // --- CORRECTED REDIRECT ---
    $wallet_page = get_page_by_title('My Wallet');
    if ($wallet_page) {
        $wallet_url = get_permalink($wallet_page->ID);
        // Add a query argument for our success message
        $redirect_url = add_query_arg('topup', 'success', $wallet_url);
        wp_redirect($redirect_url);
        exit;
    } else {
        // Fallback if the page doesn't exist
        wp_redirect(home_url());
        exit;
    }
}
add_action('init', 'taptosell_handle_wallet_topup');

/**
 * Handles the supplier withdrawal request form.
 */
function taptosell_handle_withdrawal_request() {
    if ( !isset($_POST['taptosell_action']) || $_POST['taptosell_action'] !== 'request_withdrawal' ) { return; }
    if ( !isset($_POST['taptosell_withdrawal_nonce']) || !wp_verify_nonce($_POST['taptosell_withdrawal_nonce'], 'taptosell_withdrawal_action') ) { wp_die('Security check failed!'); }
    if ( !current_user_can('supplier') ) { return; }

    $supplier_id = get_current_user_id();
    $requested_amount = (float)$_POST['withdrawal_amount'];
    $available_balance = taptosell_get_user_wallet_balance($supplier_id);

    // Backend validation: ensure request is valid and user has enough funds
    if ($requested_amount <= 0 || $requested_amount > $available_balance) {
        $redirect_url = add_query_arg('withdrawal', 'failed', wp_get_referer());
        wp_redirect($redirect_url);
        exit;
    }
    
    // 1. Create the withdrawal request post
    $withdrawal_id = wp_insert_post([
        'post_title' => 'Withdrawal Request – ' . wp_get_current_user()->user_login . ' – RM ' . number_format($requested_amount, 2),
        'post_type'   => 'withdrawal_request',
        'post_status' => 'wd-pending',
        'post_author' => $supplier_id,
    ]);
    
    if ($withdrawal_id) {
        // 2. Save the amount as post meta
        update_post_meta($withdrawal_id, '_withdrawal_amount', $requested_amount);
        
        // 3. Deduct from wallet and mark as a pending withdrawal transaction
        taptosell_add_wallet_transaction($supplier_id, -$requested_amount, 'withdrawal_request', 'Withdrawal Request #' . $withdrawal_id);
    }
    
    $redirect_url = add_query_arg('withdrawal', 'success', wp_get_referer());
    wp_redirect($redirect_url);
    exit;
}
add_action('init', 'taptosell_handle_withdrawal_request');
