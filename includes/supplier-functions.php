<?php
// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * =================================================================
 * SUPPLIER DASHBOARD & FORMS
 * =================================================================
 */

// --- Access control for the main Supplier Dashboard ---
function taptosell_supplier_dashboard_access() {
    $dashboard_page = get_page_by_title('Supplier Dashboard');
    if ( $dashboard_page && is_page( $dashboard_page->ID ) ) {
        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            $roles = (array) $user->roles;
            $is_supplier = in_array('supplier', $roles);
            $can_manage_site = current_user_can('manage_options');
            if ( !$is_supplier && !$can_manage_site ) {
                wp_redirect( home_url() );
                exit;
            }
        } else {
            wp_redirect( home_url() );
            exit;
        }
    }
}
add_action( 'template_redirect', 'taptosell_supplier_dashboard_access' );

// --- UPDATED (Notifications): Handler for the NEW product form ---
function taptosell_handle_product_upload() {
    if ( isset( $_POST['taptosell_new_product_submit'] ) && current_user_can('edit_posts') ) {
        if ( ! isset( $_POST['taptosell_product_nonce'] ) || ! wp_verify_nonce( $_POST['taptosell_product_nonce'], 'taptosell_add_product' ) ) { wp_die('Security check failed!'); }
        
        $product_title = sanitize_text_field($_POST['product_title']);
        $product_price = sanitize_text_field($_POST['product_price']);
        $product_sku = sanitize_text_field($_POST['product_sku']);
        $product_stock = isset($_POST['product_stock']) ? (int)$_POST['product_stock'] : 0;
        $product_category = isset($_POST['product_category']) ? (int)$_POST['product_category'] : 0;
        $product_brands = sanitize_text_field($_POST['product_brands']);
        $product_description = isset($_POST['product_description']) ? wp_kses_post($_POST['product_description']) : '';

        $product_weight = isset($_POST['product_weight']) ? (float)$_POST['product_weight'] : 0;
        $product_length = isset($_POST['product_length']) ? (float)$_POST['product_length'] : 0;
        $product_width = isset($_POST['product_width']) ? (float)$_POST['product_width'] : 0;
        $product_height = isset($_POST['product_height']) ? (float)$_POST['product_height'] : 0;
        $product_video = isset($_POST['product_video']) ? esc_url_raw($_POST['product_video']) : '';


        if ( empty($product_title) || empty($product_price) || empty($product_sku) ) { return; }

        $product_id = wp_insert_post([
            'post_title'   => $product_title,
            'post_content' => $product_description,
            'post_status'  => 'draft',
            'post_type'    => 'product',
            'post_author'  => get_current_user_id(),
        ]);

        if ( $product_id && !is_wp_error($product_id) ) {
            update_post_meta($product_id, '_price', $product_price);
            update_post_meta($product_id, '_sku', $product_sku);
            update_post_meta($product_id, '_stock_quantity', $product_stock);

            update_post_meta($product_id, '_weight', $product_weight);
            update_post_meta($product_id, '_length', $product_length);
            update_post_meta($product_id, '_width', $product_width);
            update_post_meta($product_id, '_height', $product_height);
            update_post_meta($product_id, '_video_url', $product_video);


            if ($product_category > 0) { wp_set_post_terms($product_id, [$product_category], 'product_category'); }
            if (!empty($product_brands)) { wp_set_post_terms($product_id, $product_brands, 'brand'); }
            if ( ! empty( $_FILES['product_image']['name'] ) ) {
                require_once( ABSPATH . 'wp-admin/includes/image.php' );
                require_once( ABSPATH . 'wp-admin/includes/file.php' );
                require_once( ABSPATH . 'wp-admin/includes/media.php' );
                $attachment_id = media_handle_upload('product_image', $product_id);
                if ( ! is_wp_error($attachment_id) ) { set_post_thumbnail($product_id, $attachment_id); }
            }

            // --- NEW: Generate a notification for all Operational Admins ---
            $op_admins = get_users(['role' => 'operational_admin', 'fields' => 'ID']);
            if (!empty($op_admins)) {
                $message = 'New product "' . esc_html($product_title) . '" has been submitted for approval.';
                $link = admin_url('edit.php?post_status=draft&post_type=product');
                foreach ($op_admins as $admin_id) {
                    taptosell_add_notification($admin_id, $message, $link);
                }
            }

            $dashboard_page = get_page_by_title('Supplier Dashboard');
            if ($dashboard_page) {
                $redirect_url = add_query_arg('product_submitted', 'true', get_permalink($dashboard_page->ID));
                wp_redirect($redirect_url); exit;
            }
        }
    }
}
add_action('init', 'taptosell_handle_product_upload', 20);

// --- UPDATED (Post-Approval Edit FINAL FIX): Handler for the UPDATE product form ---
function taptosell_handle_product_update() {
    if ( isset( $_POST['taptosell_update_product_submit'] ) && current_user_can('edit_posts') ) {
        if ( ! isset( $_POST['taptosell_product_edit_nonce'] ) || ! wp_verify_nonce( $_POST['taptosell_product_edit_nonce'], 'taptosell_edit_product' ) ) { wp_die('Security check failed!'); }

        $product_id = (int)$_POST['product_id'];
        $product_author_id = get_post_field('post_author', $product_id);

        if ( get_current_user_id() != $product_author_id && !current_user_can('manage_options') ) { wp_die('You do not have permission to edit this product.'); }

        // --- NEW: Get the product's current status BEFORE making changes ---
        $product_status = get_post_status($product_id);
        $is_approved = ($product_status === 'publish');

        // --- ALWAYS update the stock quantity, as it's always editable ---
        $stock_quantity = isset($_POST['product_stock']) ? (int)$_POST['product_stock'] : 0;
        update_post_meta($product_id, '_stock_quantity', $stock_quantity);

        // --- ONLY update the other fields if the product is NOT approved ---
        if (!$is_approved) {
            $product_title = sanitize_text_field($_POST['product_title']);
            $product_price = sanitize_text_field($_POST['product_price']);
            $product_sku = sanitize_text_field($_POST['product_sku']);
            $product_category = isset($_POST['product_category']) ? (int)$_POST['product_category'] : 0;
            $product_brands = sanitize_text_field($_POST['product_brands']);
            $product_description = isset($_POST['product_description']) ? wp_kses_post($_POST['product_description']) : '';
            $product_weight = isset($_POST['product_weight']) ? (float)$_POST['product_weight'] : 0;
            $product_length = isset($_POST['product_length']) ? (float)$_POST['product_length'] : 0;
            $product_width = isset($_POST['product_width']) ? (float)$_POST['product_width'] : 0;
            $product_height = isset($_POST['product_height']) ? (float)$_POST['product_height'] : 0;
            $product_video = isset($_POST['product_video']) ? esc_url_raw($_POST['product_video']) : '';

            wp_update_post([
                'ID' => $product_id,
                'post_title' => $product_title,
                'post_content' => $product_description,
            ]);
            update_post_meta($product_id, '_price', $product_price);
            update_post_meta($product_id, '_sku', $product_sku);
            update_post_meta($product_id, '_weight', $product_weight);
            update_post_meta($product_id, '_length', $product_length);
            update_post_meta($product_id, '_width', $product_width);
            update_post_meta($product_id, '_height', $product_height);
            update_post_meta($product_id, '_video_url', $product_video);

            if ($product_category > 0) { wp_set_post_terms($product_id, [$product_category], 'product_category'); }
            if (!empty($product_brands)) { wp_set_post_terms($product_id, $product_brands, 'brand', false); } else { wp_set_post_terms($product_id, '', 'brand'); }
        }

        // Redirect back to the supplier dashboard
        $dashboard_page = get_page_by_title('Supplier Dashboard');
        if ($dashboard_page) {
            $redirect_url = add_query_arg('product_updated', 'true', get_permalink($dashboard_page->ID));
            wp_redirect($redirect_url); exit;
        }
    }
}
add_action('init', 'taptosell_handle_product_update', 20);

// --- UPDATED (Advanced): Shortcode for the NEW product form ---
function taptosell_product_upload_form_shortcode() {
    if ( !is_user_logged_in() || !current_user_can('edit_posts') ) { return '<p>You do not have permission to view this content.</p>'; }
    ob_start();
    if (isset($_GET['product_submitted']) && $_GET['product_submitted'] === 'true') { echo '<div style="background-color: #d4edda; color: #155724; padding: 15px; margin-bottom: 20px;">Your product has been submitted for review.</div>'; }
    ?>
    <h3>Add a New Product</h3>
    <form id="new-product-form" method="post" action="" enctype="multipart/form-data">
        <p><label for="product_title">Product Name</label><br /><input type="text" id="product_title" value="" name="product_title" required /></p>
        
        <div style="margin-bottom: 20px;">
            <label for="product_description">Product Description</label><br />
            <?php
            wp_editor('', 'product_description', [
                'textarea_name' => 'product_description',
                'media_buttons' => false,
                'textarea_rows' => 10,
                'teeny'         => true,
            ]);
            ?>
        </div>

        <p><label for="product_price">Your Price (RM)</label><br /><input type="number" step="0.01" id="product_price" value="" name="product_price" required /></p>
        <p><label for="product_sku">SKU</label><br /><input type="text" id="product_sku" value="" name="product_sku" required /></p>
        <p><label for="product_stock">Stock Quantity</label><br /><input type="number" step="1" id="product_stock" value="" name="product_stock" required /></p>

        <div style="border: 1px solid #ddd; padding: 15px; margin: 20px 0;">
            <h4>Shipping & Media</h4>
            <p>
                <label for="product_weight">Weight (kg)</label><br />
                <input type="number" step="0.01" id="product_weight" name="product_weight" placeholder="e.g., 0.5">
            </p>
            <p>
                <label>Package Dimensions (cm)</label><br />
                <input type="number" step="0.01" name="product_length" placeholder="Length" style="width: 100px;">
                <input type="number" step="0.01" name="product_width" placeholder="Width" style="width: 100px;">
                <input type="number" step="0.01" name="product_height" placeholder="Height" style="width: 100px;">
            </p>
            <p>
                <label for="product_video">Product Video URL (Optional)</label><br />
                <input type="url" id="product_video" name="product_video" placeholder="e.g., https://youtube.com/watch?v=..." style="width: 100%; max-width: 400px;">
            </p>
        </div>

        <p><label for="product_category">Category</label><br /><?php wp_dropdown_categories(['taxonomy' => 'product_category', 'name' => 'product_category', 'show_option_none' => 'Select a Category', 'hierarchical' => 1, 'required' => true, 'hide_empty' => 0]); ?></p>
        <p><label for="product_brands">Brands</label><br /><input type="text" id="product_brands" value="" name="product_brands" /><small>Enter brands separated by commas.</small></p>
        <p><label for="product_image">Image</label><br /><input type="file" id="product_image" name="product_image" accept="image/*" /></p>
        <?php wp_nonce_field('taptosell_add_product', 'taptosell_product_nonce'); ?>
        <p><input type="submit" value="Add Product" name="taptosell_new_product_submit" /></p>
    </form>
    <?php
    return ob_get_clean();
}
add_shortcode('supplier_product_upload_form', 'taptosell_product_upload_form_shortcode');


// --- UPDATED (UI/UX Styling): Shortcode for the supplier's "My Products" list ---
function taptosell_supplier_my_products_shortcode() {
    if ( ! is_user_logged_in() ) return '';
    $user = wp_get_current_user();
    if ( !in_array('supplier', (array)$user->roles) && !current_user_can('manage_options') ) { return ''; }
    if (current_user_can('manage_options') && !in_array('supplier', (array)$user->roles)) { return '<h3>Admin View: Supplier Products List</h3>'; }

    ob_start();
    echo '<h2>My Product Submissions</h2>';
    if (isset($_GET['product_updated']) && $_GET['product_updated'] === 'true') { echo '<div style="background-color: #d4edda; color: #155724; padding: 15px; margin-bottom: 20px;">Product updated successfully!</div>'; }

    $args = ['post_type' => 'product', 'author' => get_current_user_id(), 'posts_per_page' => -1, 'post_status' => ['publish', 'draft', 'pending', 'rejected']];
    $product_query = new WP_Query($args);
    if ( $product_query->have_posts() ) {
        // --- UPDATED: Use the standard WordPress table class for better compatibility ---
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>Image</th><th>Name</th><th>SKU</th><th>Stock</th><th>Category</th><th>Brands</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
        while ( $product_query->have_posts() ) {
            $product_query->the_post();
            $product_id = get_the_ID();
            $status = get_post_status($product_id);
            $stock = get_post_meta($product_id, '_stock_quantity', true);
            $category_terms = get_the_terms($product_id, 'product_category');
            $brand_terms = get_the_terms($product_id, 'brand');
            $category_name = (!empty($category_terms)) ? $category_terms[0]->name : '—';
            $brand_names = [];
            if (!empty($brand_terms)) { foreach ($brand_terms as $brand) { $brand_names[] = $brand->name; } }
            echo '<tr>';
            echo '<td>' . get_the_post_thumbnail($product_id, [60, 60]) . '</td>';
            echo '<td>' . get_the_title() . '</td>';
            echo '<td>' . esc_html(get_post_meta($product_id, '_sku', true)) . '</td>';
            echo '<td>' . (is_numeric($stock) ? esc_html($stock) : 'N/A') . '</td>';
            echo '<td>' . esc_html($category_name) . '</td>';
            echo '<td>' . (empty($brand_names) ? '—' : esc_html(implode(', ', $brand_names))) . '</td>';
            echo '<td>';
            
            // --- UPDATED: Use new CSS classes for status badges ---
            if ($status === 'publish') {
                echo '<span class="status-badge status-approved">Approved</span>';
            } elseif ($status === 'rejected') {
                echo '<span class="status-badge status-rejected">Rejected</span>';
                $reason = get_post_meta($product_id, '_rejection_reason', true);
                if (!empty($reason)) {
                    // Use the new CSS class for the rejection reason
                    echo '<em class="rejection-reason">Reason: ' . esc_html($reason) . '</em>';
                }
            } else { // Covers 'draft' and 'pending'
                echo '<span class="status-badge status-pending">Pending Review</span>';
            }

            echo '</td>';
            echo '<td>';
            
            if ($status === 'draft' || $status === 'pending' || $status === 'rejected' || $status === 'publish') {
                $edit_page = get_page_by_title('Edit Product');
                if ($edit_page) {
                    $edit_link = add_query_arg('product_id', get_the_ID(), get_permalink($edit_page->ID));
                    echo '<a href="' . esc_url($edit_link) . '">Edit</a>';
                }
            } else {
                echo '—';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    } else { echo '<p>You have not submitted any products yet.</p>'; }
    wp_reset_postdata();
    return ob_get_clean();
}
add_shortcode('supplier_my_products', 'taptosell_supplier_my_products_shortcode');


// --- UPDATED (Price Request): Shortcode for the EDIT product form ---
function taptosell_product_edit_form_shortcode() {
    if ( !is_user_logged_in() || !current_user_can('edit_posts') ) { return '<p>You do not have permission to view this content.</p>'; }
    if (!isset($_GET['product_id'])) { return '<p>No product selected. Go back to your <a href="/supplier-dashboard">dashboard</a> to select a product to edit.</p>'; }

    $product_id = (int)$_GET['product_id'];
    $product = get_post($product_id);
    
    if (!$product || ($product->post_author != get_current_user_id() && !current_user_can('manage_options'))) {
        return '<p>You do not have permission to edit this product.</p>';
    }

    $is_approved = ($product->post_status === 'publish');
    $readonly_attr = $is_approved ? 'readonly' : '';
    
    $price = get_post_meta($product_id, '_price', true);
    $sku = get_post_meta($product_id, '_sku', true);
    $stock = get_post_meta($product_id, '_stock_quantity', true);
    $category_terms = get_the_terms($product_id, 'product_category');
    $brand_terms = get_the_terms($product_id, 'brand');
    $selected_category = (!empty($category_terms)) ? $category_terms[0]->term_id : 0;
    $brand_names = [];
    if (!empty($brand_terms)) { foreach ($brand_terms as $term) { $brand_names[] = $term->name; } }

    $weight = get_post_meta($product_id, '_weight', true);
    $length = get_post_meta($product_id, '_length', true);
    $width = get_post_meta($product_id, '_width', true);
    $height = get_post_meta($product_id, '_height', true);
    $video_url = get_post_meta($product_id, '_video_url', true);
    
    ob_start();

    // --- NEW: Show a success message if a price request was submitted ---
    if (isset($_GET['price_request']) && $_GET['price_request'] === 'success') {
        echo '<div style="background-color: #d4edda; color: #155724; padding: 15px; margin-bottom: 20px;">Your price change request has been submitted for review.</div>';
    }

    ?>
    <h3>Edit Product: <?php echo esc_html($product->post_title); ?></h3>
    <form id="edit-product-form" method="post" action="">
        <input type="hidden" name="product_id" value="<?php echo esc_attr($product_id); ?>">
        <p><label>Name</label><br /><input type="text" value="<?php echo esc_attr($product->post_title); ?>" name="product_title" required <?php echo $readonly_attr; ?> /></p>

        <div style="margin-bottom: 20px;">
            <label for="product_description">Product Description</label><br />
            <?php
            wp_editor($product->post_content, 'product_description', [
                'textarea_name' => 'product_description', 'media_buttons' => false, 'textarea_rows' => 10,
                'teeny' => true, 'editor_css' => $is_approved ? '<style>.wp-editor-container{background-color:#f0f0f0;}</style>' : '',
                'tinymce' => !$is_approved, 'quicktags' => !$is_approved,
            ]);
            ?>
        </div>

        <p><label>Your Price (RM)</label><br /><input type="number" step="0.01" value="<?php echo esc_attr($price); ?>" name="product_price" required <?php echo $readonly_attr; ?> /></p>
        <p><label>SKU</label><br /><input type="text" value="<?php echo esc_attr($sku); ?>" name="product_sku" required <?php echo $readonly_attr; ?> /></p>
        <p><label for="product_stock">Stock Quantity</label><br /><input type="number" step="1" id="product_stock" value="<?php echo esc_attr($stock); ?>" name="product_stock" required /></p>

        <div style="border: 1px solid #ddd; padding: 15px; margin: 20px 0;">
            <h4>Shipping & Media</h4>
            <p><label>Weight (kg)</label><br /><input type="number" step="0.01" name="product_weight" value="<?php echo esc_attr($weight); ?>" <?php echo $readonly_attr; ?>></p>
            <p><label>Package Dimensions (cm)</label><br />
                <input type="number" step="0.01" name="product_length" value="<?php echo esc_attr($length); ?>" placeholder="Length" style="width: 100px;" <?php echo $readonly_attr; ?>>
                <input type="number" step="0.01" name="product_width" value="<?php echo esc_attr($width); ?>" placeholder="Width" style="width: 100px;" <?php echo $readonly_attr; ?>>
                <input type="number" step="0.01" name="product_height" value="<?php echo esc_attr($height); ?>" placeholder="Height" style="width: 100px;" <?php echo $readonly_attr; ?>>
            </p>
            <p><label>Product Video URL</label><br /><input type="url" name="product_video" value="<?php echo esc_attr($video_url); ?>" style="width: 100%; max-width: 400px;" <?php echo $readonly_attr; ?>></p>
        </div>

        <p><label>Category</label><br />
        <?php 
        $cat_args = [ 'taxonomy' => 'product_category', 'name' => 'product_category', 'show_option_none' => 'Select a Category', 'hierarchical' => 1, 'required' => true, 'hide_empty' => 0, 'selected' => $selected_category, ];
        if ($is_approved) { $cat_args['disabled'] = 'disabled'; }
        wp_dropdown_categories($cat_args); 
        ?>
        </p>
        <p><label>Brands</label><br /><input type="text" value="<?php echo esc_attr(implode(', ', $brand_names)); ?>" name="product_brands" <?php echo $readonly_attr; ?> /><small>Enter brands separated by commas.</small></p>
        <p><strong>Current Image:</strong><br/><?php echo get_the_post_thumbnail($product_id, 'thumbnail'); ?></p>
        <?php wp_nonce_field('taptosell_edit_product', 'taptosell_product_edit_nonce'); ?>
        <p><input type="submit" value="Update Product" name="taptosell_update_product_submit" /></p>
    </form>

    <?php 
    // --- NEW: Add the Price Change Request Form for approved products ---
    if ($is_approved): ?>
    <hr style="margin: 40px 0;">
    <div class="price-change-request-form">
        <h3>Request a Price Change</h3>
        <form method="post" action="">
            <input type="hidden" name="product_id" value="<?php echo esc_attr($product_id); ?>">
            <input type="hidden" name="old_price" value="<?php echo esc_attr($price); ?>">
            <?php wp_nonce_field('taptosell_price_request_action', 'taptosell_price_request_nonce'); ?>
            <p>
                <label for="new_price">New Price (RM)</label><br/>
                <input type="number" step="0.01" name="new_price" id="new_price" required>
            </p>
            <p>
                <button type="submit" name="taptosell_action" value="request_price_change">Submit Request</button>
            </p>
        </form>
    </div>
    <?php endif;

    return ob_get_clean();
}
add_shortcode('supplier_edit_product_form', 'taptosell_product_edit_form_shortcode');


/**
 * Shortcode to display a list of orders for the current supplier's products.
 * (This version adds the "Actions" column with an "Update Order" link).
 * Usage: [supplier_my_orders]
 */
function taptosell_supplier_my_orders_shortcode() {
    // Access Control - ensure user is a supplier or an admin.
    if ( ! is_user_logged_in() ) return '';
    $user = wp_get_current_user();
    if ( !in_array('supplier', (array)$user->roles) && !current_user_can('manage_options') ) {
        return '';
    }

    // On the admin side, we don't have a supplier context, so show a message.
    if (current_user_can('manage_options') && !in_array('supplier', (array)$user->roles)) {
        return '<h3>Admin View: Supplier Orders List</h3><p>This block shows the incoming orders for the logged-in supplier.</p>';
    }

    ob_start();

    echo '<h2>Incoming Orders</h2>';
    if (isset($_GET['order_updated']) && $_GET['order_updated'] === 'true') {
        echo '<div style="background-color: #d4edda; color: #155724; padding: 15px; margin-bottom: 20px;">Order updated successfully!</div>';
    }

    // Step 1: Get all product IDs that belong to the current supplier.
    $supplier_id = get_current_user_id();
    $args_my_products = [
        'post_type'      => 'product',
        'author'         => $supplier_id,
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ];
    $my_product_ids = get_posts($args_my_products);

    if (empty($my_product_ids)) {
        echo '<p>No orders found for your products.</p>';
        return ob_get_clean();
    }

    // Step 2: Get all orders where the ordered product ID is one of the supplier's products.
    $args_orders = [
        'post_type'      => 'taptosell_order',
        'posts_per_page' => -1,
        'post_status'    => ['wc-processing', 'wc-on-hold', 'wc-shipped', 'wc-completed'],
        'meta_query'     => [
            [
                'key'     => '_product_id',
                'value'   => $my_product_ids,
                'compare' => 'IN',
            ],
        ],
    ];
    $order_query = new WP_Query($args_orders);

    if ( $order_query->have_posts() ) {
        echo '<table style="width: 100%; border-collapse: collapse;">';
        echo '<thead><tr style="background-color: #f9f9f9;">';
        echo '<th style="padding: 10px; text-align: left; border-bottom: 1px solid #ddd;">Order ID</th>';
        echo '<th style="padding: 10px; text-align: left; border-bottom: 1px solid #ddd;">Product Ordered</th>';
        echo '<th style="padding: 10px; text-align: left; border-bottom: 1px solid #ddd;">Date</th>';
        echo '<th style="padding: 10px; text-align: left; border-bottom: 1px solid #ddd;">Status</th>';
        echo '<th style="padding: 10px; text-align: left; border-bottom: 1px solid #ddd;">Actions</th>'; // New Column Header
        echo '</tr></thead><tbody>';

        while ( $order_query->have_posts() ) {
            $order_query->the_post();
            $order_id = get_the_ID();
            $product_id = get_post_meta($order_id, '_product_id', true);
            $status = get_post_status($order_id);
            $status_object = get_post_status_object($status);

            echo '<tr>';
            echo '<td style="padding: 10px; border-bottom: 1px solid #eee;">#' . $order_id . '</td>';
            echo '<td style="padding: 10px; border-bottom: 1px solid #eee;">' . esc_html(get_the_title($product_id)) . '</td>';
            echo '<td style="padding: 10px; border-bottom: 1px solid #eee;">' . get_the_date('Y-m-d H:i', $order_id) . '</td>';
            echo '<td style="padding: 10px; border-bottom: 1px solid #eee;">' . esc_html($status_object->label) . '</td>';
            // New Actions Column Content
            echo '<td style="padding: 10px; border-bottom: 1px solid #eee;">';
            if ($status === 'wc-processing') {
                $fulfill_page = get_page_by_title('Fulfill Order');
                if ($fulfill_page) {
                    $fulfill_link = add_query_arg('order_id', $order_id, get_permalink($fulfill_page->ID));
                    echo '<a href="' . esc_url($fulfill_link) . '">Update Order</a>';
                }
            } else {
                echo '—';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

    } else {
        echo '<p>No orders found for your products.</p>';
    }
    wp_reset_postdata();

    return ob_get_clean();
}
// Make sure this line exists right after the function:
    add_shortcode('supplier_my_orders', 'taptosell_supplier_my_orders_shortcode');

/**
 * Shortcode to display the product fulfillment form.
 * Usage: [supplier_fulfill_order_form]
 */
function taptosell_fulfill_order_form_shortcode() {
    // Access control for suppliers and admins
    if ( ! is_user_logged_in() ) { return '<p>You do not have permission to view this content.</p>'; }
    $user = wp_get_current_user();
    if ( !in_array('supplier', (array)$user->roles) && !current_user_can('manage_options') ) {
        return '<p>You do not have permission to view this content.</p>';
    }
    
    // Check if an order ID was passed in the URL
    if (!isset($_GET['order_id'])) {
        return '<p>No order selected. Please go back to your <a href="/supplier-dashboard">dashboard</a> and click "Update Order".</p>';
    }

    $order_id = (int)$_GET['order_id'];
    $order = get_post($order_id);
    $product_id = get_post_meta($order_id, '_product_id', true);

    // Security check: ensure the current supplier owns the product in this order OR the user is an admin
    if (get_post_field('post_author', $product_id) != get_current_user_id() && !current_user_can('manage_options')) {
        return '<p>You do not have permission to manage this order.</p>';
    }

    ob_start();
    ?>
    <h3>Fulfill Order #<?php echo esc_html($order_id); ?></h3>
    <p><strong>Product:</strong> <?php echo esc_html(get_the_title($product_id)); ?></p>
    
    <form id="fulfill-order-form" method="post" action="">
        <input type="hidden" name="order_id" value="<?php echo esc_attr($order_id); ?>">
        <?php wp_nonce_field('taptosell_fulfill_action', 'taptosell_fulfill_nonce'); ?>
        <p>
            <label for="tracking_number">Tracking Number (Optional)</label><br>
            <input type="text" name="tracking_number" id="tracking_number" style="width: 100%; max-width: 400px;">
        </p>
        <p>
            <input type="submit" name="taptosell_fulfill_order_submit" value="Mark as Shipped" style="padding: 10px 15px;">
        </p>
    </form>
    <?php
    return ob_get_clean();
}
add_shortcode('supplier_fulfill_order_form', 'taptosell_fulfill_order_form_shortcode');

/**
 * Handles the submission of the FULFILL order form.
 */
function taptosell_handle_order_fulfillment() {
    if ( isset( $_POST['taptosell_fulfill_order_submit'] ) && current_user_can('edit_posts') ) {
        if ( ! isset( $_POST['taptosell_fulfill_nonce'] ) || ! wp_verify_nonce( $_POST['taptosell_fulfill_nonce'], 'taptosell_fulfill_action' ) ) {
            wp_die('Security check failed!');
        }

        $order_id = (int)$_POST['order_id'];
        $tracking_number = sanitize_text_field($_POST['tracking_number']);
        $product_id = get_post_meta($order_id, '_product_id', true);

        // Security Check: Make sure this supplier is allowed to update this order
        if (get_post_field('post_author', $product_id) != get_current_user_id() && !current_user_can('manage_options')) {
            wp_die('You do not have permission to update this order.');
        }

        // Save tracking number (if provided) and update the order status to "Shipped"
        if (!empty($tracking_number)) {
            update_post_meta($order_id, '_tracking_number', $tracking_number);
        }
        wp_update_post(['ID' => $order_id, 'post_status' => 'wc-shipped']);

        // Redirect back to the supplier dashboard with a success message
        $dashboard_page = get_page_by_title('Supplier Dashboard');
        if ($dashboard_page) {
            $redirect_url = add_query_arg('order_updated', 'true', get_permalink($dashboard_page->ID));
            wp_redirect($redirect_url);
            exit;
        }
    }
}
add_action('init', 'taptosell_handle_order_fulfillment');

/**
 * --- NEW: Handles the price change request form submission. ---
 */
function taptosell_handle_price_change_request() {
    // Check if our specific form was submitted
    if ( !isset($_POST['taptosell_action']) || $_POST['taptosell_action'] !== 'request_price_change' ) {
        return;
    }
    // Security checks
    if ( !isset($_POST['taptosell_price_request_nonce']) || !wp_verify_nonce($_POST['taptosell_price_request_nonce'], 'taptosell_price_request_action') ) {
        wp_die('Security check failed!');
    }
    if ( !current_user_can('supplier') ) {
        return;
    }

    // Get and sanitize data from the form
    $product_id = (int)$_POST['product_id'];
    $supplier_id = get_current_user_id();
    $old_price = (float)$_POST['old_price'];
    $new_price = (float)$_POST['new_price'];

    // Basic validation
    if ( $product_id <= 0 || $new_price <= 0 ) {
        return;
    }

    // Insert the request into our new database table
    global $wpdb;
    $table_name = $wpdb->prefix . 'taptosell_price_changes';
    $wpdb->insert(
        $table_name,
        [
            'product_id'   => $product_id,
            'supplier_id'  => $supplier_id,
            'old_price'    => $old_price,
            'new_price'    => $new_price,
            'request_date' => current_time('mysql'),
            'status'       => 'pending'
        ],
        ['%d', '%d', '%f', '%f', '%s', '%s']
    );

    // Redirect back with a success message
    $redirect_url = add_query_arg('price_request', 'success', wp_get_referer());
    wp_redirect($redirect_url);
    exit;
}
add_action('init', 'taptosell_handle_price_change_request');

/**
 * --- NEW: Adds a default markup field to the user profile screen. ---
 */
function taptosell_add_markup_field_to_profile($user) {
    // Only show for dropshippers
    if (!current_user_can('edit_user', $user->ID) || !in_array('dropshipper', (array)$user->roles)) {
        return;
    }

    $markup = get_user_meta($user->ID, '_default_markup_percentage', true);
    ?>
    <table class="form-table">
        <tr>
            <th><label for="default_markup_percentage">Default Markup (%)</label></th>
            <td>
                <input type="number" name="default_markup_percentage" id="default_markup_percentage" value="<?php echo esc_attr($markup); ?>" class="regular-text" placeholder="e.g., 30">
                <p class="description">Set a default markup percentage to auto-calculate selling prices in the catalog.</p>
            </td>
        </tr>
    </table>
    <?php
}
// Hook into the same actions as the other profile fields
add_action('show_user_profile', 'taptosell_add_markup_field_to_profile');
add_action('edit_user_profile', 'taptosell_add_markup_field_to_profile');


/**
 * --- NEW: Saves the default markup field when a user profile is updated. ---
 */
function taptosell_save_markup_field($user_id) {
    if (!current_user_can('edit_user', $user_id)) {
        return false;
    }

    // Save the default markup percentage
    if (isset($_POST['default_markup_percentage'])) {
        update_user_meta($user_id, '_default_markup_percentage', sanitize_text_field($_POST['default_markup_percentage']));
    }
}
// Hook into the same save actions as the other profile fields
add_action('personal_options_update', 'taptosell_save_markup_field');
add_action('edit_user_profile_update', 'taptosell_save_markup_field');