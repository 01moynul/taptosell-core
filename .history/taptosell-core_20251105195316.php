<?php
/**
 * Plugin Name:       TapToSell Core
 * Plugin URI:        https://taptosell.my
 * Description:       Core functionality plugin for the TapToSell platform. Manages suppliers, dropshippers, products, and orders.
 * Version:           1.2.0
 * Author:            01moynul
 * Author URI:        zotss.com
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define a constant for the plugin path
define( 'TAPTOSELL_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'TAPTOSELL_CORE_URL', plugin_dir_url( __FILE__ ) );
define( 'TAPTOSELL_CORE_VERSION', '1.2.0' ); // Get version from plugin header

// Include all the core functionality files.
require_once TAPTOSELL_CORE_PATH . 'includes/core-hooks.php';
require_once TAPTOSELL_CORE_PATH . 'includes/helpers.php';
require_once TAPTOSELL_CORE_PATH . 'includes/security.php';
require_once TAPTOSELL_CORE_PATH . 'includes/login-functions.php';
require_once TAPTOSELL_CORE_PATH . 'includes/admin-ui.php';
require_once TAPTOSELL_CORE_PATH . 'includes/user-registration.php';
require_once TAPTOSELL_CORE_PATH . 'includes/supplier-functions.php';
require_once TAPTOSELL_CORE_PATH . 'includes/maintenance-mode.php';
//require_once TAPTOSELL_CORE_PATH . 'includes/roadmap-functions.php';
//require_once TAPTOSELL_CORE_PATH . 'includes/post-types.php';
//require_once TAPTOSELL_CORE_PATH . 'includes/admin-actions.php';
//require_once TAPTOSELL_CORE_PATH . 'includes/dropshipper-functions.php';
//require_once TAPTOSELL_CORE_PATH . 'includes/wallet-functions.php';
//require_once TAPTOSELL_CORE_PATH . 'includes/admin-settings-page.php';
//require_once TAPTOSELL_CORE_PATH . 'includes/admin-price-requests.php';
//require_once TAPTOSELL_CORE_PATH . 'includes/api-shopee.php';
//require_once TAPTOSELL_CORE_PATH . 'includes/subscription-functions.php';
//require_once TAPTOSELL_CORE_PATH . 'includes/notifications-functions.php';
//require_once TAPTOSELL_CORE_PATH . 'includes/modal-login.php';
//require_once TAPTOSELL_CORE_PATH . 'includes/security-2fa-functions.php';
//require_once TAPTOSELL_CORE_PATH . 'includes/admin-dashboard.php';
//include_once( TAPTOSELL_CORE_PATH . 'includes/api-functions.php' ); // Core API functions
//include_once( TAPTOSELL_CORE_PATH . 'includes/api-endpoints.php' ); // API endpoint registration