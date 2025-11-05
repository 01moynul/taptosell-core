<?php
// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register all custom REST API endpoints for TapToSell.
 */
function taptosell_register_rest_routes() {
    $namespace = 'taptosell/v1'; // Our API namespace and version

    // --- REGISTER TEST ENDPOINT ---
    // (We can remove this later, but it's good for testing)
    register_rest_route( $namespace, '/test', array(
        'methods'             => WP_REST_Server::READABLE, // Corresponds to GET requests
        'callback'            => 'taptosell_api_test_callback',
        'permission_callback' => '__return_true', // Allow anyone to access this test endpoint
    ));

    // --- REGISTER PRODUCT CATEGORIES ENDPOINT (GET) ---
    // Used by the "Add Product" form to get the category list
    register_rest_route( $namespace, '/product/categories', array(
        'methods'             => WP_REST_Server::READABLE, // GET request
        'callback'            => 'taptosell_api_get_product_categories',
        'permission_callback' => 'taptosell_api_check_supplier_permission', // Secure this endpoint
    ));

    // --- NEW: REGISTER PRODUCT CREATION ENDPOINT (POST) ---
    // Used by the "Add Product" form to create a new product
    register_rest_route( $namespace, '/product/create', array(
        'methods'             => WP_REST_Server::CREATABLE, // Corresponds to POST requests
        'callback'            => 'taptosell_api_create_product',
        'permission_callback' => 'taptosell_api_check_supplier_permission', // Reuse the same security check
        'args'                => array(
            // --- NEW: Status (draft vs. publish) ---
            'status' => array(
                'required'          => true,
                'type'              => 'string',
                'description'       => 'The desired post status, e.g., "draft" or "pending".',
                'sanitize_callback' => 'sanitize_key', // Cleans the string to 'draft' or 'pending'
                'validate_callback' => function( $param ) {
                    return in_array( $param, ['draft', 'pending'] ); // Only allow these two values
                }
            ),

            // --- Basic Info ---
            'productName' => array(
                'required'          => true,
                'type'              => 'string',
                'description'       => 'The title of the product.',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'selectedCategory' => array(
                'required'          => true,
                'type'              => 'integer',
                'description'       => 'The term ID for the product category.',
                'sanitize_callback' => 'absint',
            ),
            'productDescription' => array(
                'required'          => true,
                'type'              => 'string',
                'description'       => 'The main description of the product.',
                'sanitize_callback' => 'wp_kses_post', // Allows safe HTML
            ),
            'brand' => array(
                'required'          => false,
                'type'              => 'string',
                'description'       => 'The brand name.',
                'sanitize_callback' => 'sanitize_text_field',
            ),

            // --- Sales Info (Simple) ---
            'price' => array(
                'required'          => false,
                'type'              => 'string', // Use string to accept decimals
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'sku' => array(
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'stock' => array(
                'required'          => false,
                'type'              => 'string', // Use string to accept numbers
                'sanitize_callback' => 'sanitize_text_field',
            ),

            // --- Variation Info ---
            'hasVariations' => array(
                'required'          => true,
                'type'              => 'boolean',
                'description'       => 'Flag for simple or variable product.',
            ),
            // 'variationConfig' and 'variationDetails' will be JSON objects.
            // We'll validate them as arrays, as WordPress converts JSON objects/arrays.
            'variationConfig' => array(
                'required'          => false,
                'type'              => 'array',
                'description'       => 'The configuration object for variation groups.',
                // No sanitize callback, we'll handle this complex array in the function
            ),
            'variationDetails' => array(
                'required'          => false,
                'type'              => 'array',
                'description'       => 'The array of data from the variation table.',
            ),

            // --- Shipping Info ---
            'weight' => array(
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'packageDimensions' => array(
                'required'          => true,
                'type'              => 'object',
                'description'       => 'Object containing length, width, and height.',
                'properties'        => array(
                    'length' => array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
                    'width'  => array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
                    'height' => array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
                ),
            ),
        ),
    ));
    // --- END OF NEW CODE BLOCK ---

    // --- MORE ENDPOINTS WILL BE REGISTERED HERE LATER ---
    // Example: register_rest_route( $namespace, '/product/create', ... );
    // ...

}
add_action( 'rest_api_init', 'taptosell_register_rest_routes' );

/**
 * Callback function for the /test endpoint.
 * Returns a simple success message.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response
 */
function taptosell_api_test_callback( WP_REST_Request $request ) {
    // WP_REST_Response allows us to set status codes, headers, etc.
    return new WP_REST_Response( array(
        'success' => true,
        'message' => 'TapToSell API v1 is active!',
    ), 200 ); // HTTP 200 OK status
}

/**
 * Callback function for the GET /product/categories endpoint.
 * Returns a list of product categories.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response|WP_Error List of categories or error.
 */
function taptosell_api_get_product_categories( WP_REST_Request $request ) {
    $categories = get_terms( array(
        'taxonomy'   => 'product_category', // Our custom taxonomy
        'hide_empty' => false,             // Include categories with no products
        'orderby'    => 'name',            // Order alphabetically
        'order'      => 'ASC',
    ) );

    if ( is_wp_error( $categories ) ) {
        // Return an error if something went wrong fetching terms
        return new WP_Error( 'category_fetch_error', 'Could not retrieve product categories.', array( 'status' => 500 ) );
    }

    // Format the data for the API response
    $formatted_categories = array();
    foreach ( $categories as $category ) {
        $formatted_categories[] = array(
            'id'   => $category->term_id,
            'name' => $category->name,
            'slug' => $category->slug,
        );
    }

    // Return the categories with a 200 OK status
    return new WP_REST_Response( $formatted_categories, 200 );
}

/**
 * FINAL Permission callback to check if the current user is a logged-in Supplier or Admin.
 * Uses current_user_can() which is standard for REST API capability checks.
 * WordPress REST API handles nonce/cookie authentication automatically before calling this.
 *
 * @param WP_REST_Request $request The request object.
 * @return bool|WP_Error True if allowed, WP_Error if denied.
 */
function taptosell_api_check_supplier_permission( WP_REST_Request $request ) {
    // Check if the user making the request has the 'supplier' role OR can 'manage_options' (administrator).
    if ( current_user_can( 'supplier' ) || current_user_can( 'manage_options' ) ) {
        // Allow access if they have the correct role/capability
        return true;
    }

    // If the capability check fails, check if they are even logged in.
    if ( ! is_user_logged_in() ) {
         // Return an error if not logged in
         return new WP_Error( 'rest_not_logged_in', 'You are not currently logged in.', array( 'status' => 401 ) ); // 401 Unauthorized
    }

    // If they made it here, they are logged in but DO NOT have the required role/capability.
    return new WP_Error( 'rest_forbidden', 'You do not have permission to access this resource. Your role is not Supplier or Admin.', array( 'status' => 403 ) ); // 403 Forbidden
}