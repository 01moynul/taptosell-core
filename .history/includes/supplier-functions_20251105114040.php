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
 * --- FINAL (Phase 10): Handler for the NEW product form ---
 * This function now handles both simple and variable products, including all new fields.
 */
function taptosell_handle_product_upload() {
    // This handler should ONLY trigger for NEW products. 
    // We check that the form was submitted AND that a product_id is NOT set.
    if ( (isset($_POST['taptosell_new_product_submit']) || isset($_POST['save_as_draft'])) && !isset($_POST['product_id']) ) {

        // Security and permission checks.
        if (!current_user_can('edit_posts')) { return; }
        if (!isset($_POST['taptosell_product_nonce']) || !wp_verify_nonce($_POST['taptosell_product_nonce'], 'taptosell_add_product')) {
            wp_die('Security check failed!');
        }

        // --- Determine the product's status based on which button was clicked ---
        $product_status = (isset($_POST['save_as_draft'])) ? 'draft' : 'pending';
        
        // --- Sanitize all form input data ---
        $product_title       = isset($_POST['product_title']) ? sanitize_text_field($_POST['product_title']) : '';
        $product_description = isset($_POST['product_description']) ? wp_kses_post($_POST['product_description']) : '';
        $product_category    = isset($_POST['product_category']) ? (int)$_POST['product_category'] : 0;
        $product_video       = isset($_POST['product_video']) ? esc_url_raw($_POST['product_video']) : '';
        $product_weight      = isset($_POST['product_weight']) ? (float)$_POST['product_weight'] : 0;
        $product_length      = isset($_POST['product_length']) ? (float)$_POST['product_length'] : 0;
        $product_width       = isset($_POST['product_width']) ? (float)$_POST['product_width'] : 0;
        $product_height      = isset($_POST['product_height']) ? (float)$_POST['product_height'] : 0;
        
        if (empty($product_title)) { return; } // Title is the absolute minimum requirement

        // --- Create the main product post ---
        $product_id = wp_insert_post([
            'post_title'   => $product_title,
            'post_content' => $product_description,
            'post_status'  => $product_status,
            'post_type'    => 'product',
            'post_author'  => get_current_user_id(),
        ]);

        if ($product_id && !is_wp_error($product_id)) {
            // --- Save common product data (meta fields) ---
            if ($product_category > 0) { wp_set_post_terms($product_id, [$product_category], 'product_category'); }
            // --- NEW: Handle Brand Assignment ---
            $brand_name = isset($_POST['product_brand']) ? sanitize_text_field(trim($_POST['product_brand'])) : '';

            // If the brand field is empty, default to "No Brand".
            if (empty($brand_name)) {
                $brand_name = 'No Brand';
            }

            // Check if the brand term already exists.
            $term = term_exists($brand_name, 'brand');

            if ($term !== 0 && $term !== null) {
                // If the brand exists, assign it to the product using its term ID.
                wp_set_post_terms($product_id, $term['term_id'], 'brand');
            } else {
                // If the brand does not exist, create it first.
                $new_term = wp_insert_term($brand_name, 'brand');
                if (!is_wp_error($new_term)) {
                    // If the new brand was created successfully, assign it to the product.
                    wp_set_post_terms($product_id, $new_term['term_id'], 'brand');
                }
            }
            // --- End of Brand Handling ---
            update_post_meta($product_id, '_video_url', $product_video);
            update_post_meta($product_id, '_weight', $product_weight);
            update_post_meta($product_id, '_length', $product_length);
            update_post_meta($product_id, '_width', $product_width);
            update_post_meta($product_id, '_height', $product_height);

            // --- Handle Image Upload ---
            if (!empty($_FILES['product_image']['name'])) {
                require_once(ABSPATH . 'wp-admin/includes/image.php');
                require_once(ABSPATH . 'wp-admin/includes/file.php');
                require_once(ABSPATH . 'wp-admin/includes/media.php');
                $attachment_id = media_handle_upload('product_image', $product_id);
                if (!is_wp_error($attachment_id)) { set_post_thumbnail($product_id, $attachment_id); }
            }

            // --- Check for variations or simple product data ---
            if (isset($_POST['enable_variations'])) {
                // This is a VARIABLE product
                wp_set_object_terms($product_id, 'variable', 'product_type');
                $sanitized_attributes = [];
                if (isset($_POST['variation']) && is_array($_POST['variation'])) {
                    foreach ($_POST['variation'] as $group_data) {
                        $sanitized_options = [];
                        if (isset($group_data['options']) && is_array($group_data['options'])) {
                            foreach ($group_data['options'] as $option) { $sanitized_options[] = sanitize_text_field($option); }
                        }
                        $sanitized_attributes[] = ['name' => isset($group_data['name']) ? sanitize_text_field($group_data['name']) : '', 'options' => $sanitized_options];
                    }
                }
                update_post_meta($product_id, '_variation_attributes', $sanitized_attributes);
                $sanitized_variations = [];
                if (isset($_POST['variants']) && is_array($_POST['variants'])) {
                    foreach ($_POST['variants'] as $variant) {
                        if (!empty($variant['name'])) {
                             $sanitized_variations[] = [
                                'name'  => sanitize_text_field($variant['name']),
                                'price' => isset($variant['price']) ? sanitize_text_field($variant['price']) : '',
                                'stock' => isset($variant['stock']) ? sanitize_text_field($variant['stock']) : '',
                                'sku'   => isset($variant['sku']) ? sanitize_text_field($variant['sku']) : '',
                            ];
                        }
                    }
                }
                update_post_meta($product_id, '_variations', $sanitized_variations);

            } else {
                // This is a SIMPLE product
                wp_set_object_terms($product_id, 'simple', 'product_type');
                update_post_meta($product_id, '_price', isset($_POST['product_price']) ? sanitize_text_field($_POST['product_price']) : '');
                update_post_meta($product_id, '_sku', isset($_POST['product_sku']) ? sanitize_text_field($_POST['product_sku']) : '');
                update_post_meta($product_id, '_stock_quantity', isset($_POST['product_stock']) ? (int)$_POST['product_stock'] : 0);
            }

            // --- Send notification to admins if submitted for review ---
            if ($product_status === 'pending') {
                $op_admins = get_users(['role' => 'operational_admin', 'fields' => 'ID']);
                if (!empty($op_admins)) {
                    $message = 'New product "' . esc_html($product_title) . '" has been submitted for approval.';
                    $link = admin_url('post.php?post=' . $product_id . '&action=edit');
                    foreach ($op_admins as $admin_id) { taptosell_add_notification($admin_id, $message, $link); }
                }
            }
            
            // --- Redirect user after submission ---
            $dashboard_page = get_page_by_title('Supplier Dashboard');
            if ($dashboard_page) {
                $message_type = ($product_status === 'draft') ? 'draft_saved' : 'product_submitted';
                $redirect_url = add_query_arg('message', $message_type, get_permalink($dashboard_page->ID));
                wp_redirect($redirect_url);
                exit;
            }
        }
    }
}
add_action('init', 'taptosell_handle_product_upload', 20);

/**
 * --- FINAL (Phase 10): Handler for the UPDATE product form ---
 * This function now handles updating both simple and variable products,
 * including "Save as Draft" functionality.
 */
function taptosell_handle_product_update() {
    // This handler triggers for both "Update Product" and "Save Draft" on the EDIT form,
    // identified by the presence of a 'product_id'.
    if ( (isset($_POST['taptosell_update_product_submit']) || isset($_POST['save_as_draft'])) && isset($_POST['product_id']) ) {

        // Security and permission checks
        if ( ! current_user_can('edit_posts') ) { return; }
        if ( ! isset( $_POST['taptosell_product_edit_nonce'] ) || ! wp_verify_nonce( $_POST['taptosell_product_edit_nonce'], 'taptosell_edit_product' ) ) {
            wp_die('Security check failed!');
        }

        $product_id = (int)$_POST['product_id'];
        $product_author_id = get_post_field('post_author', $product_id);

        // Ensure the current user owns this product
        if ( get_current_user_id() != $product_author_id && !current_user_can('manage_options') ) {
            wp_die('You do not have permission to edit this product.');
        }

        // --- Determine the new status based on which button was clicked ---
        $new_status = (isset($_POST['save_as_draft'])) ? 'draft' : 'pending'; // Re-submit for review

        // --- Sanitize all form input data ---
        $product_title       = sanitize_text_field($_POST['product_title']);
        $product_description = wp_kses_post($_POST['product_description']);
        $product_category    = isset($_POST['product_category']) ? (int)$_POST['product_category'] : 0;
        $product_video       = isset($_POST['product_video']) ? esc_url_raw($_POST['product_video']) : '';
        $product_weight      = isset($_POST['product_weight']) ? (float)$_POST['product_weight'] : 0;
        $product_length      = isset($_POST['product_length']) ? (float)$_POST['product_length'] : 0;
        $product_width       = isset($_POST['product_width']) ? (float)$_POST['product_width'] : 0;
        $product_height      = isset($_POST['product_height']) ? (float)$_POST['product_height'] : 0;

        // --- Update the main post data ---
        wp_update_post([
            'ID'           => $product_id,
            'post_title'   => $product_title,
            'post_content' => $product_description,
            'post_status'  => $new_status,
        ]);

        // --- Save common product data (meta fields) ---
        if ($product_category > 0) { wp_set_post_terms($product_id, [$product_category], 'product_category'); }
        // --- NEW: Handle Brand Assignment ---
        $brand_name = isset($_POST['product_brand']) ? sanitize_text_field(trim($_POST['product_brand'])) : '';

        // If the brand field is empty, default to "No Brand".
        if (empty($brand_name)) {
            $brand_name = 'No Brand';
        }

        // Check if the brand term already exists.
        $term = term_exists($brand_name, 'brand');

        if ($term !== 0 && $term !== null) {
            // If the brand exists, assign it to the product using its term ID.
            wp_set_post_terms($product_id, $term['term_id'], 'brand');
        } else {
            // If the brand does not exist, create it first.
            $new_term = wp_insert_term($brand_name, 'brand');
            if (!is_wp_error($new_term)) {
                // If the new brand was created successfully, assign it to the product.
                wp_set_post_terms($product_id, $new_term['term_id'], 'brand');
            }
        }
        // --- End of Brand Handling ---
        update_post_meta($product_id, '_video_url', $product_video);
        update_post_meta($product_id, '_weight', $product_weight);
        update_post_meta($product_id, '_length', $product_length);
        update_post_meta($product_id, '_width', $product_width);
        update_post_meta($product_id, '_height', $product_height);

        // --- Handle Image Upload (if a new one was provided) ---
        if (!empty($_FILES['product_image']['name'])) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            $attachment_id = media_handle_upload('product_image', $product_id);
            if (!is_wp_error($attachment_id)) { set_post_thumbnail($product_id, $attachment_id); }
        }

        // --- Check for variations or simple product data ---
        if (isset($_POST['enable_variations'])) {
            // This is a VARIABLE product
            wp_set_object_terms($product_id, 'variable', 'product_type');

            // 1. Sanitize and save the variation attributes (e.g., Color: Red, Blue)
            $sanitized_attributes = [];
            if (isset($_POST['variation']) && is_array($_POST['variation'])) {
                foreach ($_POST['variation'] as $group_data) {
                    $sanitized_options = [];
                    if (isset($group_data['options']) && is_array($group_data['options'])) {
                        foreach ($group_data['options'] as $option) {
                            $sanitized_options[] = sanitize_text_field($option);
                        }
                    }
                    $sanitized_attributes[] = [
                        'name'    => isset($group_data['name']) ? sanitize_text_field($group_data['name']) : '',
                        'options' => $sanitized_options,
                    ];
                }
            }
            update_post_meta($product_id, '_variation_attributes', $sanitized_attributes);

            // 2. Sanitize and save the variation data (price, stock, SKU)
            $sanitized_variations = [];
            if (isset($_POST['variants']) && is_array($_POST['variants'])) {
                foreach ($_POST['variants'] as $variant) {
                    // Only save the variant if it has a name to keep data clean
                    if (!empty($variant['name'])) {
                        $sanitized_variations[] = [
                            'name'  => sanitize_text_field($variant['name']),
                            'price' => isset($variant['price']) ? sanitize_text_field($variant['price']) : '',
                            'stock' => isset($variant['stock']) ? sanitize_text_field($variant['stock']) : '',
                            'sku'   => isset($variant['sku']) ? sanitize_text_field($variant['sku']) : '',
                        ];
                    }
                }
            }
            update_post_meta($product_id, '_variations', $sanitized_variations);

        } else {
            // This is a SIMPLE product
            $product_price = isset($_POST['product_price']) ? sanitize_text_field($_POST['product_price']) : '';
            $product_sku   = isset($_POST['product_sku']) ? sanitize_text_field($_POST['product_sku']) : '';
            $product_stock = isset($_POST['product_stock']) ? (int)$_POST['product_stock'] : 0;

            wp_set_object_terms($product_id, 'simple', 'product_type');
            update_post_meta($product_id, '_price', $product_price);
            update_post_meta($product_id, '_sku', $product_sku);
            update_post_meta($product_id, '_stock_quantity', $product_stock);
        }

        // --- Redirect user after submission ---
        $dashboard_page = get_page_by_title('Supplier Dashboard');
        if ($dashboard_page) {
            $message_type = ($new_status === 'draft') ? 'draft_saved' : 'product_updated';
            $redirect_url = add_query_arg('message', $message_type, get_permalink($dashboard_page->ID));
            wp_redirect($redirect_url);
            exit;
        }
    }
}
add_action('init', 'taptosell_handle_product_update', 20);

/**
 * --- REVISED (Phase 15): Shortcode for the "Add a New Product" page. ---
 * This function no longer renders a PHP form.
 * It now renders a single <div id="root"></div>, which our React app
 * will use as its main entry point. The 'core-hooks.php' file
 * handles enqueuing the React scripts on this page.
 * [taptosell_add_new_product_form]
 */
function taptosell_add_new_product_form_shortcode() {
    // Security check: ensure user is a logged-in supplier.
    if ( ! is_user_logged_in() || ! in_array( 'supplier', (array) wp_get_current_user()->roles ) ) {
        return '<div class="taptosell-container"><p class="taptosell-error">' . __( 'You must be logged in as a Supplier to view this page.', 'taptosell-core' ) . '</p></div>';
    }

    // This is the mount point for our React application.
    return '<div id="root"></div>';
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


/**
 * --- REVISED: Shortcode for the EDIT product form ---
 * Separates Sales Info and Variations into distinct sections.
 * [supplier_edit_product_form]
 */
function taptosell_product_edit_form_shortcode() {
    // Security Checks
    if ( !is_user_logged_in() || !current_user_can('edit_posts') ) { return '<p>You do not have permission to view this content.</p>'; }
    if (!isset($_GET['product_id'])) { return '<p>No product selected. Go back to your <a href="/supplier-dashboard">dashboard</a> to select a product to edit.</p>'; }

    $product_id = (int)$_GET['product_id'];
    $product = get_post($product_id);
    
    // Permission check: ensure current user is the author of the product.
    if (!$product || ($product->post_author != get_current_user_id() && !current_user_can('manage_options'))) {
        return '<p>You do not have permission to edit this product.</p>';
    }

    // --- Load all existing product data ---
    $product_title = $product->post_title;
    $product_description = $product->post_content;
    $category_terms = get_the_terms($product_id, 'product_category');
    $selected_category = (!empty($category_terms)) ? $category_terms[0]->term_id : 0;
    
    $is_variable = has_term('variable', 'product_type', $product_id);

    // Load simple product data
    $simple_price = get_post_meta($product_id, '_price', true);
    $simple_sku = get_post_meta($product_id, '_sku', true);
    $simple_stock = get_post_meta($product_id, '_stock_quantity', true);

    // Load common meta
    $video_url = get_post_meta($product_id, '_video_url', true);
    $weight = get_post_meta($product_id, '_weight', true);
    $length = get_post_meta($product_id, '_length', true);
    $width = get_post_meta($product_id, '_width', true);
    $height = get_post_meta($product_id, '_height', true);

    ob_start();
    ?>
    <div class="taptosell-container taptosell-add-product-form">
        <form id="edit-product-form" method="post" action="" enctype="multipart/form-data">
            <input type="hidden" name="product_id" value="<?php echo esc_attr($product_id); ?>">

            <div class="form-header">
                <h1><?php _e('Edit Product', 'taptosell-core'); ?></h1>
                <div class="form-actions">
                    <button type="submit" name="save_as_draft" class="taptosell-button secondary"><?php _e('Save Draft', 'taptosell-core'); ?></button>
                    <button type="submit" name="taptosell_update_product_submit" class="taptosell-button primary"><?php _e('Update Product', 'taptosell-core'); ?></button>
                </div>
            </div>

            <div class="taptosell-form-section">
                <div class="section-header"><h2><?php _e('1. Basic Information', 'taptosell-core'); ?></h2></div>
                <div class="section-content">
                    <div class="form-row">
                        <label for="product_title"><?php _e('Product Name', 'taptosell-core'); ?></label>
                        <input type="text" id="product_title" name="product_title" value="<?php echo esc_attr($product_title); ?>" required>
                    </div>
                    <div class="form-row">
                        <label for="product_category"><?php _e('Category', 'taptosell-core'); ?></label>
                        <?php
                        wp_dropdown_categories([
                            'taxonomy' => 'product_category', 'name' => 'product_category',
                            'selected' => $selected_category, 'hierarchical' => 1, 'required' => true,
                            'hide_empty' => 0, 'class' => 'taptosell-select'
                        ]);
                        ?>
                    </div>
                    <div class="form-row">
                        <label for="product_brand">Brand</label>
                        <?php
                        $brand_terms = get_the_terms($product_id, 'brand');
                        $brand_name = (!empty($brand_terms)) ? $brand_terms[0]->name : '';
                        ?>
                        <input type="text" id="product_brand" name="product_brand" class="input" value="<?php echo esc_attr($brand_name); ?>" placeholder="e.g., Nike, Adidas, etc.">
                        <p class="description">Leave blank to set as "No Brand".</p>
                    </div>
                    <div class="form-row">
                        <label for="product_description"><?php _e('Product Description', 'taptosell-core'); ?></label>
                        <?php
                        wp_editor($product_description, 'product_description', [
                            'textarea_name' => 'product_description', // Specifies the 'name' attribute for the textarea
                            'media_buttons' => false,                 // Hides the "Add Media" button
                            'textarea_rows' => 10,                    // Sets the initial height of the editor
                            'teeny'         => true,                  // Shows a simplified version of the editor toolbar
                            'quicktags'     => false                  // --- THIS IS THE FIX: It disables the "Text" tab ---
                        ]);
                        ?>
                    </div>
                </div>
            </div>

            <div class="taptosell-form-section">
                <div class="section-header"><h2><?php _e('2. Sales Information (Simple Product)', 'taptosell-core'); ?></h2></div>
                <div class="section-content">
                    <div class="form-grid-3" id="simple-product-fields">
                        <div class="form-row">
                            <label for="product_price"><?php _e('Your Price (RM)', 'taptosell-core'); ?></label>
                            <input type="number" step="0.01" id="product_price" name="product_price" value="<?php echo esc_attr($simple_price); ?>" <?php echo !$is_variable ? 'required' : ''; ?>>
                        </div>
                        <div class="form-row">
                            <label for="product_sku"><?php _e('SKU', 'taptosell-core'); ?></label>
                            <input type="text" id="product_sku" name="product_sku" value="<?php echo esc_attr($simple_sku); ?>" <?php echo !$is_variable ? 'required' : ''; ?>>
                        </div>
                        <div class="form-row">
                            <label for="product_stock"><?php _e('Stock Quantity', 'taptosell-core'); ?></label>
                            <input type="number" step="1" id="product_stock" name="product_stock" value="<?php echo esc_attr($simple_stock); ?>" <?php echo !$is_variable ? 'required' : ''; ?>>
                        </div>
                    </div>
                </div>
            </div>

            <div class="taptosell-form-section">
                <div class="section-header"><h2><?php _e('3. Product Variations', 'taptosell-core'); ?></h2></div>
                <div class="section-content">
                    <div id="variations-container">
                        <div id="variation-groups-wrapper"></div>
                        <button type="button" id="add-variation-group" class="taptosell-button secondary"><?php _e('+ Add Variation Type', 'taptosell-core'); ?></button>
                        <hr class="form-divider">
                        <h4><?php _e('Variation List', 'taptosell-core'); ?></h4>
                        <div id="variation-list-table-wrapper"></div>
                    </div>
                </div>
            </div>
            
            <div class="taptosell-form-section">
                 <div class="section-header"><h2><?php _e('4. Media', 'taptosell-core'); ?></h2></div>
                 <div class="section-content">
                    <div class="form-row">
                        <label><?php _e('Current Image', 'taptosell-core'); ?></label>
                        <div style="margin-bottom: 10px;"><?php if (has_post_thumbnail($product_id)) { echo get_the_post_thumbnail($product_id, 'thumbnail'); } else { echo 'No image set.'; } ?></div>
                        <label for="product_image"><?php _e('Upload New Image', 'taptosell-core'); ?></label>
                        <input type="file" id="product_image" name="product_image" accept="image/*">
                        <p class="form-hint"><?php _e('Only upload a new image if you want to replace the current one.', 'taptosell-core'); ?></p>
                    </div>
                     <div class="form-row">
                        <label for="product_video"><?php _e('Product Video URL (Optional)', 'taptosell-core'); ?></label>
                        <input type="url" id="product_video" name="product_video" value="<?php echo esc_attr($video_url); ?>">
                    </div>
                </div>
            </div>

            <div class="taptosell-form-section">
                <div class="section-header"><h2><?php _e('5. Shipping', 'taptosell-core'); ?></h2></div>
                <div class="section-content">
                    <div class="form-row">
                        <label for="product_weight"><?php _e('Weight (kg)', 'taptosell-core'); ?></label>
                        <input type="number" step="0.01" id="product_weight" name="product_weight" value="<?php echo esc_attr($weight); ?>">
                    </div>
                    <div class="form-row">
                        <label><?php _e('Package Dimensions (cm)', 'taptosell-core'); ?></label>
                        <div class="form-grid-3">
                            <input type="number" step="0.01" name="product_length" placeholder="Length" value="<?php echo esc_attr($length); ?>">
                            <input type="number" step="0.01" name="product_width" placeholder="Width" value="<?php echo esc_attr($width); ?>">
                            <input type="number" step="0.01" name="product_height" placeholder="Height" value="<?php echo esc_attr($height); ?>">
                        </div>
                    </div>
                </div>
            </div>

            <?php wp_nonce_field('taptosell_edit_product', 'taptosell_product_edit_nonce'); ?>
        </form>
    </div>
    <?php
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

/**
 * --- NEW: Handles the deletion of a product from the supplier dashboard. ---
 * This function is hooked to 'init' and listens for the 'delete_product' action.
 */
function taptosell_handle_product_delete() {
    // 1. Check if our specific action has been triggered in the URL.
    if ( !isset($_GET['action']) || $_GET['action'] !== 'delete_product' || !isset($_GET['product_id']) ) {
        return;
    }

    // 2. Security Check 1: Verify the nonce (the security token in the URL).
    if ( !isset($_GET['security']) || !wp_verify_nonce($_GET['security'], 'taptosell_delete_product_nonce') ) {
        wp_die('Security check failed. Please go back and try again.');
    }

    // 3. Security Check 2: Ensure the user is logged in and has basic editing rights.
    if ( !is_user_logged_in() || !current_user_can('edit_posts') ) {
        wp_die('You do not have permission to perform this action.');
    }

    $product_id = (int)$_GET['product_id'];
    $current_user_id = get_current_user_id();

    // 4. Security Check 3: Verify that the current user is the author of the product.
    if ( get_post_field('post_author', $product_id) != $current_user_id ) {
        wp_die('Permission denied. You can only delete your own products.');
    }
    
    // 5. If all security checks pass, move the product to the trash.
    // We use wp_trash_post() because it's safer than permanent deletion.
    $trashed = wp_trash_post($product_id);

    if ($trashed) {
        // 6. Redirect back to the supplier dashboard with a success message.
        $dashboard_page = taptosell_get_page_by_title('Supplier Dashboard');
        if ($dashboard_page) {
            // We use the same 'message' key that our shortcode already looks for.
            $redirect_url = add_query_arg('message', 'product_deleted', get_permalink($dashboard_page->ID));
            wp_redirect($redirect_url);
            exit;
        }
    }
    
    // As a fallback, redirect to the previous page if something went wrong.
    wp_redirect(wp_get_referer());
    exit;
}
add_action('init', 'taptosell_handle_product_delete');