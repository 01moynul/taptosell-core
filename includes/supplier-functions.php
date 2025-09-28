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

/**
 * --- UPDATED (Phase 10): Handler for the NEW product form ---
 * This function now handles both "Save as Draft" and "Save and Publish" actions.
 */
function taptosell_handle_product_upload() {
    // Check if either of our form buttons were clicked.
    if ( ! isset( $_POST['taptosell_new_product_submit'] ) && ! isset( $_POST['save_as_draft'] ) ) {
        return;
    }

    // Security and permission checks.
    if ( ! current_user_can('edit_posts') ) { return; }
    if ( ! isset( $_POST['taptosell_product_nonce'] ) || ! wp_verify_nonce( $_POST['taptosell_product_nonce'], 'taptosell_add_product' ) ) { 
        wp_die('Security check failed!'); 
    }
    
    // --- Determine the product's status based on which button was clicked ---
    $product_status = '';
    if ( isset( $_POST['save_as_draft'] ) ) {
        $product_status = 'draft'; // If "Save as Draft" was clicked
    } elseif ( isset( $_POST['taptosell_new_product_submit'] ) ) {
        $product_status = 'pending'; // If "Save and Publish" was clicked, it goes to pending for review
    }

    // Sanitize all the form input data.
    $product_title = sanitize_text_field($_POST['product_title']);
    $product_description = isset($_POST['product_description']) ? wp_kses_post($_POST['product_description']) : '';
    $product_category = isset($_POST['product_category']) ? (int)$_POST['product_category'] : 0;

    // --- We will add more fields here as we build the form ---
    // For now, we'll use placeholder data for required fields like price and SKU.
    $product_price = '0.00'; 
    $product_sku = 'temp-' . time();

    // Make sure we have a title before proceeding.
    if ( empty($product_title) ) { return; }

    // Create the new product post.
    $product_id = wp_insert_post([
        'post_title'   => $product_title,
        'post_content' => $product_description,
        'post_status'  => $product_status, // Use the status we determined earlier
        'post_type'    => 'product',
        'post_author'  => get_current_user_id(),
    ]);

    if ( $product_id && !is_wp_error($product_id) ) {
        // Save the meta data (price, SKU, etc.).
        update_post_meta($product_id, '_price', $product_price);
        update_post_meta($product_id, '_sku', $product_sku);

        // Set the product category.
        if ($product_category > 0) { 
            wp_set_post_terms($product_id, [$product_category], 'product_category'); 
        }

        // --- Handle file uploads (we will add this logic later) ---

        // --- Notification Logic ---
        // Only send a notification to admins if the product was submitted for review.
        if ($product_status === 'pending') {
            $op_admins = get_users(['role' => 'operational_admin', 'fields' => 'ID']);
            if (!empty($op_admins)) {
                $message = 'New product "' . esc_html($product_title) . '" has been submitted for approval.';
                $link = admin_url('post.php?post=' . $product_id . '&action=edit'); // Direct link to edit the product
                foreach ($op_admins as $admin_id) {
                    taptosell_add_notification($admin_id, $message, $link);
                }
            }
        }

        // Redirect the user back to the dashboard with a success message.
        $dashboard_page = get_page_by_title('Supplier Dashboard');
        if ($dashboard_page) {
            $redirect_url = add_query_arg('message', 'product_submitted', get_permalink($dashboard_page->ID));
            wp_redirect($redirect_url); 
            exit;
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

/**
 * --- NEW (Phase 10): Shortcode for the NEW "Add a New Product" page. ---
 * This function builds the UI for the multi-section product submission form.
 * [taptosell_add_new_product_form]
 */
function taptosell_add_new_product_form_shortcode() {
    // Security check: ensure user is a logged-in supplier.
    if ( ! is_user_logged_in() || ! in_array( 'supplier', (array) wp_get_current_user()->roles ) ) {
        return '<div class="taptosell-container"><p class="taptosell-error">' . __( 'You must be logged in as a Supplier to view this page.', 'taptosell-core' ) . '</p></div>';
    }

    ob_start();
    ?>
    <div class="taptosell-container taptosell-add-product-form">
        <form id="new-product-form" method="post" action="" enctype="multipart/form-data">

            <div class="form-header">
                <h1><?php _e('Add a New Product', 'taptosell-core'); ?></h1>
                    <div class="form-actions">
                        <button type="submit" name="save_as_draft" class="taptosell-button secondary"><?php _e('Save as Draft', 'taptosell-core'); ?></button>
                        <button type="submit" name="taptosell_new_product_submit" class="taptosell-button primary"><?php _e('Save and Publish', 'taptosell-core'); ?></button>
                    </div>
            </div>

            <div class="taptosell-form-section">
                <div class="section-header">
                    <h2><?php _e('1. Basic Information', 'taptosell-core'); ?></h2>
                </div>
                <div class="section-content">
                    <div class="form-row">
                        <label for="product_title"><?php _e('Product Name', 'taptosell-core'); ?></label>
                        <input type="text" id="product_title" name="product_title" placeholder="<?php _e('Enter product name', 'taptosell-core'); ?>" required>
                        <p class="form-hint"><?php _e('A good product name includes brand, model, and key features.', 'taptosell-core'); ?></p>
                    </div>

                    <div class="form-row">
                        <label for="product_category"><?php _e('Category', 'taptosell-core'); ?></label>
                        <?php
                        // Arguments for the category dropdown
                        wp_dropdown_categories([
                            'taxonomy'         => 'product_category',
                            'name'             => 'product_category',
                            'show_option_none' => __('Select a Category', 'taptosell-core'),
                            'hierarchical'     => 1,
                            'required'         => true,
                            'hide_empty'       => 0,
                            'class'            => 'taptosell-select'
                        ]);
                        ?>
                    </div>

                    <div class="form-row">
                        <label for="product_description"><?php _e('Product Description', 'taptosell-core'); ?></label>
                        <?php
                        // WordPress rich text editor
                        wp_editor('', 'product_description', [
                            'textarea_name' => 'product_description',
                            'media_buttons' => false,
                            'textarea_rows' => 10,
                            'teeny'         => true,
                        ]);
                        ?>
                    </div>
                </div>
            </div>

            <?php wp_nonce_field('taptosell_add_product', 'taptosell_product_nonce'); ?>
        </form>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('taptosell_add_new_product_form', 'taptosell_add_new_product_form_shortcode');

/**
 * --- UPDATED (UI/UX Styling): Shortcode for the supplier's "My Products" list ---
 */
function taptosell_supplier_my_products_shortcode() {
    // Ensure the user is logged in and is a supplier.
    if (!is_user_logged_in() || !in_array('supplier', (array) wp_get_current_user()->roles)) {
        return '<div class="taptosell-container"><p class="taptosell-error">' . __('You must be logged in as a Supplier to view this page.', 'taptosell-core') . '</p></div>';
    }

    $current_user_id = get_current_user_id();
    ob_start();
    ?>
    <div class="taptosell-container taptosell-supplier-dashboard">
        
        <?php
        // Display any success or error messages from URL parameters
        if (isset($_GET['product_updated'])) {
            echo '<div class="taptosell-notice success"><p>' . __('Product updated successfully!', 'taptosell-core') . '</p></div>';
        }
        if (isset($_GET['product_submitted'])) {
            echo '<div class="taptosell-notice success"><p>' . __('Your product has been submitted for review.', 'taptosell-core') . '</p></div>';
        }
        if (isset($_GET['product_deleted'])) {
            echo '<div class="taptosell-notice success"><p>' . __('Product deleted successfully.', 'taptosell-core') . '</p></div>';
        }
        ?>

        <div class="dashboard-header">
            <h1><?php _e('My Products', 'taptosell-core'); ?></h1>
            <a href="<?php echo esc_url(home_url('/add-new-product/')); ?>" class="taptosell-button primary"><?php _e('Add a New Product', 'taptosell-core'); ?></a>
        </div>


        <?php
        // Define the statuses for our tabs
        $tabs = [
            'all'       => __('All', 'taptosell-core'),
            'publish'   => __('Live', 'taptosell-core'),
            'pending'   => __('Pending', 'taptosell-core'),
            'draft'     => __('Draft', 'taptosell-core'),
            'rejected'  => __('Rejected', 'taptosell-core'),
        ];

        // Get product counts for the current supplier
        $counts = [
            'all' => 0, 'publish' => 0, 'pending' => 0,
            'draft' => 0, 'rejected' => 0,
        ];
        
        global $wpdb;
        $status_counts = $wpdb->get_results( $wpdb->prepare(
            "SELECT post_status, COUNT(*) as num_posts FROM {$wpdb->posts} WHERE post_type = 'product' AND post_author = %d GROUP BY post_status",
            $current_user_id
        ), ARRAY_A );

        foreach ($status_counts as $row) {
            if (array_key_exists($row['post_status'], $counts)) {
                $counts[$row['post_status']] = (int) $row['num_posts'];
            }
        }
        $counts['all'] = array_sum($counts);

        $current_tab = isset($_GET['status']) && array_key_exists($_GET['status'], $tabs) ? sanitize_key($_GET['status']) : 'all';

        // Display the tab navigation menu
        echo '<div class="supplier-product-tabs">';
        foreach ($tabs as $status_slug => $status_label) {
            $class = ($current_tab === $status_slug) ? 'nav-tab-active' : '';
            $url = add_query_arg('status', $status_slug, get_permalink());
            echo '<a href="' . esc_url($url) . '" class="' . esc_attr($class) . '">';
            echo esc_html($status_label);
            echo ' <span class="count">' . esc_html($counts[$status_slug]) . '</span>';
            echo '</a>';
        }
        echo '</div>';

        // Set up WP_Query arguments to fetch the products
        $paged = (get_query_var('paged')) ? get_query_var('paged') : 1;
        $args = [
            'post_type'      => 'product',
            'author'         => $current_user_id,
            'posts_per_page' => 10,
            'paged'          => $paged,
        ];

        if ($current_tab !== 'all') {
            $args['post_status'] = $current_tab;
        } else {
            $args['post_status'] = ['publish', 'pending', 'draft', 'rejected'];
        }

        $product_query = new WP_Query($args);

        if ( $product_query->have_posts() ) {
            // --- REVISED TABLE STRUCTURE ---
            echo '<table class="taptosell-table taptosell-product-list"><thead><tr>';
            echo '<th>Product</th>';
            echo '<th>Price</th>';
            echo '<th>Stock</th>';
            echo '<th>Status</th>';
            echo '<th>Actions</th>';
            echo '</tr></thead><tbody>';

            while ( $product_query->have_posts() ) {
                $product_query->the_post();
                $product_id = get_the_ID();
                $status = get_post_status($product_id);
                $stock = get_post_meta($product_id, '_stock_quantity', true);
                $price = get_post_meta($product_id, '_price', true);

                echo '<tr>';
                // New combined "Product" column
                echo '<td data-label="Product">';
                echo '<div class="product-info-flex">';
                echo get_the_post_thumbnail($product_id, [60, 60]);
                echo '<div class="product-details">';
                echo '<strong>' . get_the_title() . '</strong>';
                echo '<small>SKU: ' . esc_html(get_post_meta($product_id, '_sku', true)) . '</small>';
                echo '</div></div></td>';
                
                // Price and Stock columns
                echo '<td data-label="Price">RM ' . number_format((float)$price, 2) . '</td>';
                echo '<td data-label="Stock">' . (is_numeric($stock) ? esc_html($stock) : 'N/A') . '</td>';
                
                // Status column with badge
                echo '<td data-label="Status">';
                echo '<span class="taptosell-status-badge status-' . esc_attr($status) . '">' . esc_html(ucfirst($status)) . '</span>';
                if ($status === 'rejected') {
                    $reason = get_post_meta($product_id, '_rejection_reason', true);
                    if (!empty($reason)) {
                        echo '<em class="rejection-reason">Reason: ' . esc_html($reason) . '</em>';
                    }
                }
                echo '</td>';

                // Actions column with icons
                echo '<td data-label="Actions">';
                $edit_page = get_page_by_title('Edit Product');
                if ($edit_page) {
                    $edit_link = add_query_arg('product_id', get_the_ID(), get_permalink($edit_page->ID));
                    echo '<a href="' . esc_url($edit_link) . '" class="taptosell-button-icon" title="Edit"><span class="dashicons dashicons-edit"></span></a>';
                }
                $delete_link = wp_nonce_url(add_query_arg(['action' => 'delete_product', 'product_id' => $product_id], get_permalink()), 'taptosell_delete_product_nonce', 'security');
                echo '<a href="' . esc_url($delete_link) . '" class="taptosell-button-icon delete" title="Delete" onclick="return confirm(\'Are you sure you want to delete this product?\');"><span class="dashicons dashicons-trash"></span></a>';
                echo '</td></tr>';
            }
            echo '</tbody></table>';

            // Pagination links
            $big = 999999999;
            echo paginate_links([
                'base'    => str_replace($big, '%#%', esc_url(get_pagenum_link($big))),
                'format'  => '?paged=%#%',
                'current' => max(1, get_query_var('paged')),
                'total'   => $product_query->max_num_pages,
            ]);

        } else { 
            echo '<p>No products found for this status.</p>'; 
        }
        wp_reset_postdata();
    ?>
    </div>
    <?php
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