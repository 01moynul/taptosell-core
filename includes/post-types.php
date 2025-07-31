<?php
// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register all Custom Post Types and Taxonomies.
 */
function taptosell_register_all_cpts_taxonomies() {
    // --- PRODUCT CPT ---
    register_post_type('product', [
        'labels' => [
            'name'          => __('Products'),
            'singular_name' => __('Product'),
            'add_new_item'  => __('Add New Product'),
            'edit_item'     => __('Edit Product'),
        ],
        'public'       => true,
        'has_archive'  => true,
        'rewrite'      => ['slug' => 'products'],
        'show_in_rest' => true,
        'supports'     => ['title', 'editor', 'thumbnail', 'custom-fields', 'author'],
        'menu_icon'    => 'dashicons-cart',
    ]);

    // --- PRODUCT TAXONOMIES ---
    register_taxonomy('product_category', 'product', [
        'labels'       => ['name' => __('Product Categories'), 'singular_name' => __('Category')],
        'public'       => true,
        'hierarchical' => true,
        'rewrite'      => ['slug' => 'product-category'],
        'show_in_rest' => true,
        'capabilities' => [
            'manage_terms' => 'manage_product_category',
            'edit_terms'   => 'edit_product_category',
            'delete_terms' => 'delete_product_category',
            'assign_terms' => 'assign_product_category',
        ],
    ]);
    register_taxonomy('brand', 'product', [
        'labels'       => ['name' => __('Brands'), 'singular_name' => __('Brand')],
        'public'       => true,
        'hierarchical' => false,
        'rewrite'      => ['slug' => 'brand'],
        'show_in_rest' => true,
        'capabilities' => [
            'manage_terms' => 'manage_brand',
            'edit_terms'   => 'edit_brand',
            'delete_terms' => 'delete_brand',
            'assign_terms' => 'assign_brand',
        ],
    ]);

    // --- ORDER CPT ---
    register_post_type('taptosell_order', [
        'labels' => [
            'name'          => __('Orders'),
            'singular_name' => __('Order'),
            'add_new_item'  => __('Add New Order'),
            'edit_item'     => __('Edit Order'),
        ],
        'public'            => false,
        'publicly_queryable'=> false,
        'show_ui'           => true,
        'show_in_menu'      => true,
        'capability_type'   => 'post',
        'has_archive'       => false,
        'hierarchical'      => false,
        'supports'          => ['title', 'custom-fields', 'author'],
        'menu_icon'         => 'dashicons-clipboard',
    ]);
    
    // --- WITHDRAWAL CPT ---
    register_post_type('withdrawal_request', [
        'labels' => [
            'name'          => __('Withdrawals'),
            'singular_name' => __('Withdrawal'),
            'edit_item'     => __('View Withdrawal'),
            'add_new'       => _x('Add New', 'withdrawal', 'taptosell-core'),
        ],
        'public'            => false,
        'publicly_queryable'=> false,
        'show_ui'           => true,
        'show_in_menu'      => 'taptosell_settings', // Place it under our "TapToSell" menu
        'capability_type'   => 'post',
        'capabilities'      => ['create_posts' => false], // Prevent users from creating requests from the admin menu
        'map_meta_cap'      => true,
        'has_archive'       => false,
        'hierarchical'      => false,
        'supports'          => ['title', 'custom-fields', 'author'],
    ]);
}
add_action('init', 'taptosell_register_all_cpts_taxonomies');

/**
 * Register all custom post statuses.
 */
function taptosell_register_all_statuses() {
    // --- ORDER STATUSES ---
    register_post_status('wc-on-hold', ['label' => _x('On Hold', 'Order status'),'public' => true,'show_in_admin_all_list' => true,'show_in_admin_status_list' => true,'label_count' => _n_noop('On Hold <span class="count">(%s)</span>', 'On Hold <span class="count">(%s)</span>'),]);
    register_post_status('wc-processing', ['label' => _x('Processing', 'Order status'),'public' => true,'show_in_admin_all_list' => true,'show_in_admin_status_list' => true,'label_count' => _n_noop('Processing <span class="count">(%s)</span>', 'Processing <span class="count">(%s)</span>'),]);
    register_post_status('wc-shipped', ['label' => _x('Shipped', 'Order status'),'public' => true,'show_in_admin_all_list' => true,'show_in_admin_status_list' => true,'label_count' => _n_noop('Shipped <span class="count">(%s)</span>', 'Shipped <span class="count">(%s)</span>'),]);
    register_post_status('wc-completed', ['label' => _x('Completed', 'Order status'),'public' => true,'show_in_admin_all_list' => true,'show_in_admin_status_list' => true,'label_count' => _n_noop('Completed <span class="count">(%s)</span>', 'Completed <span class="count">(%s)</span>'),]);

    // --- WITHDRAWAL STATUSES ---
    register_post_status('wd-pending', [
        'label'                     => _x('Pending', 'Withdrawal status'),
        'public'                    => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop('Pending <span class="count">(%s)</span>', 'Pending <span class="count">(%s)</span>'),
    ]);
    register_post_status('wd-processed', [
        'label'                     => _x('Processed', 'Withdrawal status'),
        'public'                    => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop('Processed <span class="count">(%s)</span>', 'Processed <span class="count">(%s)</span>'),
    ]);
}
add_action('init', 'taptosell_register_all_statuses');

/**
 * Add custom order statuses to the Order edit screen dropdown.
 */
function taptosell_add_order_statuses_to_dropdown($statuses) {
    global $post;
    if (get_post_type($post) === 'taptosell_order') {
        // Add our custom statuses
        $statuses['wc-on-hold']    = __('On Hold', 'textdomain');
        $statuses['wc-processing'] = __('Processing', 'textdomain');
        $statuses['wc-shipped']    = __('Shipped', 'textdomain');
        $statuses['wc-completed']  = __('Completed', 'textdomain');
    }
    return $statuses;
}
add_filter('post_submitbox_misc_actions', 'taptosell_add_order_statuses_to_dropdown');