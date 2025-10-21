<?php
// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Callback function for the POST /product/create endpoint.
 * Handles the creation of a new product from API data.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response|WP_Error Response or error.
 */
function taptosell_api_create_product( WP_REST_Request $request ) {
    // Get parameters from the request. 
    // They have already been validated and sanitized based on the 'args' we defined.
    $title       = $request->get_param('product_title');
    $description = $request->get_param('product_description');
    $category_id = $request->get_param('product_category');

    // --- TODO: ---
    // In the next phase, we will add the full logic here:
    // 1. wp_insert_post() to create the 'product' post.
    // 2. wp_set_object_terms() to set the category.
    // 3. update_post_meta() to save all other fields (price, sku, variations, etc.)
    // 4. Handle image/video uploads.
    // 5. Return the new product's ID and data.
    // --- END TODO ---

    // For now, just return a success message confirming we received the data.
    return new WP_REST_Response( array(
        'success' => true,
        'message' => 'Product endpoint received. (Full logic not yet implemented)',
        'data_received' => array(
            'title'       => $title,
            'description' => $description,
            'category_id' => $category_id,
        )
    ), 200 ); // HTTP 200 OK
}

// --- Other API helper functions will go here in the future ---