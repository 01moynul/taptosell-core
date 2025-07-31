<?php
// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function taptosell_maintenance_mode_settings() {
    add_settings_section(
        'taptosell_maintenance_section',
        'Maintenance / Coming Soon Mode',
        null,
        'general'
    );
    add_settings_field(
        'taptosell_maintenance_mode_enable',
        'Enable Mode',
        'taptosell_maintenance_mode_enable_callback',
        'general',
        'taptosell_maintenance_section',
        [ 'capability' => 'manage_taptosell_settings' ]
    );
    add_settings_field(
        'taptosell_maintenance_mode_page',
        'Select Page',
        'taptosell_maintenance_mode_page_callback',
        'general',
        'taptosell_maintenance_section'
    );
    register_setting('general', 'taptosell_maintenance_mode_enable');
    register_setting('general', 'taptosell_maintenance_mode_page');
}
add_action('admin_init', 'taptosell_maintenance_mode_settings');


function taptosell_maintenance_mode_enable_callback() {
    echo '<input name="taptosell_maintenance_mode_enable" type="checkbox" id="taptosell_maintenance_mode_enable" value="1"' . checked(1, get_option('taptosell_maintenance_mode_enable'), false) . ' /> <label for="taptosell_maintenance_mode_enable">Redirect all visitors to a specific page.</label>';
}


function taptosell_maintenance_mode_page_callback() {
    wp_dropdown_pages([
        'name'             => 'taptosell_maintenance_mode_page',
        'selected'         => get_option('taptosell_maintenance_mode_page'),
        'show_option_none' => '— Select a Page —',
    ]);
}


function taptosell_maintenance_mode_redirect() {
    $is_enabled = get_option('taptosell_maintenance_mode_enable');
    $page_id    = get_option('taptosell_maintenance_mode_page');

    if ( $is_enabled && $page_id && ! current_user_can('edit_pages') ) {
        if ( ! is_page($page_id) ) {
            wp_redirect( get_permalink($page_id) );
            exit();
        }
    }
}
add_action('template_redirect', 'taptosell_maintenance_mode_redirect');