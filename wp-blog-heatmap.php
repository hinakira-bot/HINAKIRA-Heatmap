<?php
/**
 * Plugin Name: HINAKIRA Heatmap
 * Plugin URI: https://example.com/wp-blog-heatmap
 * Description: ブログ記事のパフォーマンス・クリック・スクロールをヒートマップで可視化するプラグイン
 * Version: 1.0.0
 * Author: Blog Tools
 * Text Domain: wp-blog-heatmap
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WBH_VERSION', '1.0.0' );
define( 'WBH_DB_VERSION', '1.0.0' );
define( 'WBH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WBH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WBH_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once WBH_PLUGIN_DIR . 'includes/class-activator.php';
require_once WBH_PLUGIN_DIR . 'includes/class-data-manager.php';
require_once WBH_PLUGIN_DIR . 'includes/class-tracker.php';
require_once WBH_PLUGIN_DIR . 'includes/class-rest-api.php';
require_once WBH_PLUGIN_DIR . 'includes/class-privacy.php';
require_once WBH_PLUGIN_DIR . 'includes/class-cron.php';
require_once WBH_PLUGIN_DIR . 'admin/class-admin-page.php';
require_once WBH_PLUGIN_DIR . 'admin/class-admin-ajax.php';

register_activation_hook( __FILE__, array( 'WBH_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, 'wbh_deactivate' );

function wbh_deactivate() {
	wp_clear_scheduled_hook( 'wbh_daily_cleanup' );
}

function wbh_init() {
	WBH_Tracker::init();
	WBH_REST_API::init();
	WBH_Privacy::init();
	WBH_Cron::init();
	WBH_Admin_Page::init();
	WBH_Admin_Ajax::init();

	// Check DB version on every load
	WBH_Activator::check_db_version();
}
add_action( 'plugins_loaded', 'wbh_init' );
