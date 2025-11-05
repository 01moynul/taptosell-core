<?php
// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Callback function for the POST /product/create endpoint.
 * Handles the creation of a new product from the React form data.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response|WP_Error Response or error.
 */
function taptosell_api_create_product( WP_REST_Request $request ) {
    
    // --- 1. Get Current User ID (Supplier) ---
    $supplier_id = get_current_user_id();
    if ( $supplier_id === 0 ) {
        return new WP_Error( 'rest_not_logged_in', 'You must be logged in as a supplier.', array( 'status' => 401 ) );
    }

    // --- 2. Get All Sanitized Parameters ---
    // The 'args' in api-endpoints.php have already sanitized these.
    $status               = $request->get_param('status'); // 'draft' or 'pending'
    $product_name         = $request->get_param('productName');
    $category_id          = $request->get_param('selectedCategory');
    $description          = $request->get_param('productDescription');
    $brand_name           = $request->get_param('brand');
    $price                = $request->get_param('price');
    $sku                  = $request->get_param('sku');
    $stock                = $request->get_param('stock');
    $has_variations       = $request->get_param('hasVariations');
    $variation_config     = $request->get_param('variationConfig'); // Array of group configs
    $variation_details    = $request->get_param('variationDetails'); // Array of table data
    $weight               = $request->get_param('weight');
    $package_dimensions   = $request->get_param('packageDimensions'); // Array with length, width, height

    // --- 3. Create the Main Product Post ---
    $post_data = array(
        'post_title'   => $product_name,
        'post_content' => $description,
        'post_status'  => $status, // Use the status from React ('draft' or 'pending')
        'post_type'    => 'product',
        'post_author'  => $supplier_id,
    );
    
    $product_id = wp_insert_post( $post_data, true ); // true = return WP_Error on failure

    if ( is_wp_error( $product_id ) ) {
        return $product_id; // Return the error response
    }

    // --- 4. Save Taxonomy (Category & Brand) ---
    if ( $category_id > 0 ) {
        wp_set_post_terms( $product_id, $category_id, 'product_category' );
    }

    // Handle Brand assignment (create if not exists)
    if ( empty( $brand_name ) ) {
        $brand_name = 'No Brand';
    }
    $brand_term = term_exists( $brand_name, 'brand' );
    if ( $brand_term !== 0 && $brand_term !== null ) {
        wp_set_post_terms( $product_id, $brand_term['term_id'], 'brand' );
    } else {
        $new_term = wp_insert_term( $brand_name, 'brand' );
        if ( ! is_wp_error( $new_term ) ) {
            wp_set_post_terms( $product_id, $new_term['term_id'], 'brand' );
        }
    }

    // --- 5. Save Shipping Meta ---
    update_post_meta( $product_id, '_weight', $weight );
    if ( is_array( $package_dimensions ) ) {
        update_post_meta( $product_id, '_length', $package_dimensions['length'] ?? '' );
        update_post_meta( $product_id, '_width', $package_dimensions['width'] ?? '' );
        update_post_meta( $product_id, '_height', $package_dimensions['height'] ?? '' );
    }

    // --- 6. Save Product Type & Sales Data (Simple vs. Variable) ---
    if ( $has_variations ) {
        // This is a VARIABLE product
        wp_set_object_terms( $product_id, 'variable', 'product_type' );
        
        // Save the variation setup (e.g., "Color": ["Red", "Blue"], "Size": ["S"])
        // Our React state $variation_config matches this structure perfectly
        update_post_meta( $product_id, '_variation_attributes', $variation_config );
        
        // Save the table data (e.g., [ { id: "Red-S", price: "10", ... } ])
        // Our React state $variation_details matches this structure
        update_post_meta( $product_id, '_variations', $variation_details );

    } else {
        // This is a SIMPLE product
        wp_set_object_terms( $product_id, 'simple', 'product_type' );
        update_post_meta( $product_id, '_price', $price );
        update_post_meta( $product_id, '_sku', $sku );
        update_post_meta( $product_id, '_stock_quantity', $stock );
    }

    // --- 7. Send Notification to Admins (if submitted for review) ---
    if ( $status === 'pending' ) {
        $op_admins = get_users( array( 'role' => 'operational_admin', 'fields' => 'ID' ) );
        if ( ! empty( $op_admins ) ) {
            $message = 'New product "' . esc_html( $product_name ) . '" has been submitted for approval.';
            // We can't link to the React edit page yet, so just link to the OA dashboard
            $dashboard_page = taptosell_taptosell_get_page_by_title('Operational Admin Dashboard');
            $link = $dashboard_page ? get_permalink($dashboard_page->ID) . '?view=products' : admin_url('edit.php?post_type=product');

            foreach ( $op_admins as $admin_id ) {
                taptosell_add_notification( $admin_id, $message, $link );
            }
        }
    }

    // --- 8. Return Success Response ---
    return new WP_REST_Response( array(
        'success'    => true,
        'message'    => 'Product created successfully!',
        'product_id' => $product_id,
        'status'     => $status,
    ), 200 ); // HTTP 200 OK
}

// --- Other API helper functions will go here in the future ---