<?php
// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Enqueue admin scripts for the roadmap functionality.
 */
function taptosell_enqueue_admin_scripts($hook) {
    // Only load this script on the taxonomy term list screen.
    if ('edit-tags.php' !== $hook || 'phase' !== get_current_screen()->taxonomy) {
        return;
    }
    // Get the URL to our new javascript file.
    $script_url = plugin_dir_url( dirname( __FILE__ ) ) . 'assets/js/admin-scripts.js';
    // Load the script and tell WordPress it depends on jQuery.
    wp_enqueue_script('taptosell-admin-scripts', $script_url, ['jquery'], '1.0', true);
}
add_action('admin_enqueue_scripts', 'taptosell_enqueue_admin_scripts');


/**
 * =================================================================
 * 1. CPT & TAXONOMY REGISTRATION
 * =================================================================
 */
function taptosell_create_roadmap_cpt() {
    register_post_type('roadmap_item', [
        'labels'      => [
            'name'          => __('Roadmap Items'), 'singular_name' => __('Roadmap Item'),
            'add_new'       => __('Add New'), 'add_new_item'  => __('Add New Roadmap Item'),
            'edit_item'     => __('Edit Roadmap Item'),
        ],
        'public'      => true, 'has_archive' => false,
        'rewrite'     => ['slug' => 'roadmap-item'], 'show_in_rest' => true,
        'supports'    => ['title', 'editor', 'page-attributes'], 'menu_icon'   => 'dashicons-list-view',
    ]);
    register_taxonomy('phase', 'roadmap_item', [
        'labels'       => ['name' => __('Phases'), 'singular_name' => __('Phase')],
        'public'       => true, 'hierarchical' => true,
        'show_admin_column' => true, 'show_in_rest' => true,
    ]);
}
add_action('init', 'taptosell_create_roadmap_cpt');


/**
 * =================================================================
 * 2. ROADMAP STATUS METABOX (FOR COMPLETING ITEMS)
 * =================================================================
 */
function taptosell_add_roadmap_status_metabox() {
    add_meta_box('roadmap_item_status_metabox', 'Status', 'taptosell_roadmap_status_metabox_html', 'roadmap_item', 'side');
}
add_action('add_meta_boxes', 'taptosell_add_roadmap_status_metabox');

function taptosell_roadmap_status_metabox_html($post) {
    $status = get_post_meta($post->ID, '_roadmap_item_status', true);
    wp_nonce_field('roadmap_status_save', 'roadmap_status_nonce');
    echo '<input type="checkbox" name="roadmap_item_status" id="roadmap_item_status" value="complete" ' . checked($status, 'complete', false) . '>';
    echo '<label for="roadmap_item_status">Mark as complete</label>';
}

function taptosell_save_roadmap_status($post_id) {
    if (!isset($_POST['roadmap_status_nonce']) || !wp_verify_nonce($_POST['roadmap_status_nonce'], 'roadmap_status_save')) return;
    if (!current_user_can('edit_post', $post_id)) return;
    $new_status = isset($_POST['roadmap_item_status']) ? 'complete' : 'incomplete';
    update_post_meta($post_id, '_roadmap_item_status', $new_status);
}
add_action('save_post_roadmap_item', 'taptosell_save_roadmap_status');


/**
 * =================================================================
 * 3. CUSTOM ORDER FIELD FOR PHASES
 * =================================================================
 */

// Add the "Order" field to the "Add New Phase" screen
function taptosell_add_phase_order_field() {
    echo '<div class="form-field"><label for="term_order">Order</label><input type="number" name="term_order" id="term_order" value="0" /><p>Enter a number to sort the phases. Lower numbers appear first.</p></div>';
}
add_action('phase_add_form_fields', 'taptosell_add_phase_order_field');

// Add the "Order" field to the "Edit Phase" screen
function taptosell_edit_phase_order_field($term) {
    $order = get_term_meta($term->term_id, 'term_order', true) ?: 0;
    echo '<tr class="form-field"><th><label for="term_order">Order</label></th><td><input type="number" name="term_order" id="term_order" value="' . esc_attr($order) . '" /><p class="description">Enter a number to sort the phases. Lower numbers appear first.</p></td></tr>';
}
add_action('phase_edit_form_fields', 'taptosell_edit_phase_order_field');

// Save the custom "Order" field value
function taptosell_save_phase_order_meta($term_id) {
    if (isset($_POST['term_order'])) {
        update_term_meta($term_id, 'term_order', sanitize_text_field($_POST['term_order']));
    }
}
add_action('edited_phase', 'taptosell_save_phase_order_meta');
add_action('create_phase', 'taptosell_save_phase_order_meta');


/**
 * =================================================================
 * 4. FRONTEND ROADMAP DISPLAY SHORTCODE
 * =================================================================
 */
function taptosell_roadmap_shortcode() {
    ob_start();
    // Get top-level phases, now ordered by our custom field
    $parent_phases = get_terms([
        'taxonomy'   => 'phase', 'hide_empty' => true, 'parent' => 0,
        'orderby'    => 'meta_value_num', 'meta_key' => 'term_order', 'order' => 'ASC'
    ]);
    if (empty($parent_phases) || is_wp_error($parent_phases)) {
        return '<p>No roadmap items have been created yet.</p>';
    }
    foreach ($parent_phases as $phase) {
        echo '<details style="margin-bottom: 10px; border: 1px solid #eee; border-radius: 5px;">';
        echo '<summary style="padding: 10px; font-weight: bold; cursor: pointer; background-color: #f9f9f9;">' . esc_html($phase->name) . '</summary>';
        echo '<div style="padding: 10px;">';
        taptosell_render_roadmap_items_for_phase($phase->term_id, 0);
        echo '</div></details>';
    }
    return ob_get_clean();
}
add_shortcode('taptosell_roadmap', 'taptosell_roadmap_shortcode');

function taptosell_render_roadmap_items_for_phase($phase_id, $level) {
    $padding = $level * 20;
    echo '<ul style="list-style: none; padding-left: ' . $padding . 'px;">';
    $items_args = [
        'post_type' => 'roadmap_item', 'posts_per_page' => -1,
        'tax_query' => [['taxonomy' => 'phase', 'field' => 'term_id', 'terms' => $phase_id, 'include_children' => false]],
        'orderby'   => 'menu_order', 'order' => 'ASC'
    ];
    $items = new WP_Query($items_args);
    if ($items->have_posts()) {
        while ($items->have_posts()) {
            $items->the_post();
            $status = get_post_meta(get_the_ID(), '_roadmap_item_status', true);
            $icon = ($status === 'complete') ? '✅' : '⬜️';
            $style = ($status === 'complete') ? 'text-decoration: line-through; color: #888;' : '';
            echo '<li style="margin-bottom: 5px;' . $style . '">' . $icon . ' ' . get_the_title() . '</li>';
        }
    }
    wp_reset_postdata();
    echo '</ul>';
    // Get child phases, now ordered by our custom field
    $child_phases = get_terms([
        'taxonomy'   => 'phase', 'parent' => $phase_id, 'hide_empty' => true,
        'orderby'    => 'meta_value_num', 'meta_key' => 'term_order', 'order' => 'ASC'
    ]);
    if (!empty($child_phases)) {
        echo '<div style="padding-left: ' . ($padding + 20) . 'px; margin-top: 10px;">';
        foreach ($child_phases as $child_phase) {
            echo '<details style="margin-bottom: 5px; border-top: 1px solid #f0f0f0;">';
            echo '<summary style="padding: 10px 0; font-weight: bold; cursor: pointer;">' . esc_html($child_phase->name) . '</summary>';
            echo '<div style="padding: 0 10px 10px 10px;">';
            taptosell_render_roadmap_items_for_phase($child_phase->term_id, $level + 1);
            echo '</div></details>';
        }
        echo '</div>';
    }
}

/**
 * =================================================================
 * 5. QUICK EDIT FOR PHASE ORDERING
 * =================================================================
 */

// Add a new "Order" column to the Phases list table
function taptosell_phase_add_order_column($columns) {
    $columns['term_order'] = 'Order';
    return $columns;
}
add_filter('manage_edit-phase_columns', 'taptosell_phase_add_order_column');

// Render the content for our custom "Order" column
function taptosell_phase_render_order_column($content, $column_name, $term_id) {
    if ('term_order' === $column_name) {
        $order = get_term_meta($term_id, 'term_order', true) ?: 0;
        echo esc_html($order);
    }
}
add_filter('manage_phase_custom_column', 'taptosell_phase_render_order_column', 10, 3);

// Add the input field to the Quick Edit form
// Add the input fields to the Quick Edit form
function taptosell_phase_quick_edit_field($column_name, $screen) {
    // We add our fields only when the 'term_order' column is being processed.
    if ('term_order' !== $column_name) {
        return;
    }
    
    // Add a nonce for security
    wp_nonce_field('taptosell_phase_quick_edit', 'taptosell_phase_quick_edit_nonce');
    ?>
    <fieldset>
        <div class="inline-edit-col-left">
            <div class="inline-edit-group">
                <label class="alignleft">
                    <span class="title">Order</span>
                    <span class="input-text-wrap"><input type="number" name="term_order" class="ptitle" value=""></span>
                </label>
            </div>
        </div>
        <div class="inline-edit-col-right">
             <div class="inline-edit-group">
                <label class="alignleft">
                    <span class="title">Parent Category</span>
                    <?php
                    // Use wp_dropdown_categories to generate the parent selection dropdown
                    wp_dropdown_categories([
                        'taxonomy'         => 'phase',
                        'hide_empty'       => 0,
                        'name'             => 'parent',
                        'orderby'          => 'name',
                        'show_option_none' => __('— No Parent —'),
                        'hierarchical'     => 1,
                    ]);
                    ?>
                </label>
            </div>
        </div>
    </fieldset>
    <?php
}
// The add_action hook for this function remains the same
add_action('quick_edit_custom_box', 'taptosell_phase_quick_edit_field', 10, 2);

