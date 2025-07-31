<?php
// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Create/update all custom roles, capabilities, and database tables on plugin activation.
 */
function taptosell_add_custom_roles() {
    // --- Role and capability definitions remain the same ---
    $custom_caps = [
        'manage_taptosell_settings' => true, 'manage_product_category' => true, 'edit_product_category' => true,
        'delete_product_category'   => true, 'assign_product_category' => true, 'manage_brand' => true,
        'edit_brand' => true, 'delete_brand' => true, 'assign_brand' => true,
    ];
    $admin_role = get_role('administrator');
    if ($admin_role) { foreach ($custom_caps as $cap => $grant) { $admin_role->add_cap($cap); } }

    remove_role('operational_admin');
    $op_admin_caps = get_role('editor')->capabilities;
    $op_admin_caps['list_users'] = true; $op_admin_caps['edit_users'] = true; $op_admin_caps['remove_users'] = true;
    $op_admin_caps['create_users'] = true; $op_admin_caps['promote_users'] = true;
    $op_admin_caps = array_merge($op_admin_caps, $custom_caps);
    add_role('operational_admin', 'Operational Admin', $op_admin_caps);
    
    remove_role('supplier');
    add_role('supplier', 'Supplier', get_role('editor')->capabilities);

    remove_role('dropshipper');
    add_role('dropshipper', 'Dropshipper', ['read' => true]);


    // --- Create/Update Database Tables ---
    global $wpdb;
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    $charset_collate = $wpdb->get_charset_collate();

    // UPDATED: Table for Dropshipper SRPs and Product Links
    $srp_table_name = $wpdb->prefix . 'taptosell_dropshipper_products';
    $sql_srp = "CREATE TABLE $srp_table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        dropshipper_id bigint(20) UNSIGNED NOT NULL,
        taptosell_product_id bigint(20) UNSIGNED NOT NULL,
        marketplace_product_id bigint(20) UNSIGNED,
        marketplace varchar(50),
        srp decimal(10,2),
        date_added datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY dropshipper_product (dropshipper_id, taptosell_product_id)
    ) $charset_collate;";
    dbDelta($sql_srp);

    // Wallet Transactions Table (remains the same)
    $wallet_table_name = $wpdb->prefix . 'taptosell_wallet_transactions';
    $sql_wallet = "CREATE TABLE $wallet_table_name ( transaction_id bigint(20) NOT NULL AUTO_INCREMENT, user_id bigint(20) UNSIGNED NOT NULL, amount decimal(10,2) NOT NULL, type varchar(50) NOT NULL, details text, transaction_date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL, PRIMARY KEY  (transaction_id), KEY user_id (user_id) ) $charset_collate;";
    dbDelta($sql_wallet);
}
register_activation_hook( TAPTOSELL_CORE_PATH . 'taptosell-core.php', 'taptosell_add_custom_roles' );


/**
 * Remove custom user roles and all custom capabilities on plugin deactivation.
 */
function taptosell_remove_custom_roles() {
    // --- Define All Custom Capabilities ---
    $custom_caps = [
        'manage_taptosell_settings', 'manage_product_category', 'edit_product_category',
        'delete_product_category', 'assign_product_category', 'manage_brand', 'edit_brand',
        'delete_brand', 'assign_brand'
    ];
    
    // --- Remove capabilities from Administrator and Operational Admin ---
    $roles_to_update = ['administrator', 'operational_admin'];
    foreach ($roles_to_update as $role_name) {
        $role = get_role($role_name);
        if ($role) {
            foreach ($custom_caps as $cap) {
                $role->remove_cap($cap);
            }
        }
    }
    
    // --- Remove the custom roles ---
    remove_role('operational_admin');
    remove_role('supplier');
    remove_role('dropshipper');
}
register_deactivation_hook( TAPTOSELL_CORE_PATH . 'taptosell-core.php', 'taptosell_remove_custom_roles' );