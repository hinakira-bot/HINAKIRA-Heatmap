<?php
/**
 * プラグインアンインストール時のクリーンアップ
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// テーブル削除
$tables = array(
	$wpdb->prefix . 'wbh_pageviews',
	$wpdb->prefix . 'wbh_clicks',
	$wpdb->prefix . 'wbh_scrolls',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

// オプション削除
delete_option( 'wbh_settings' );
delete_option( 'wbh_db_version' );

// Cron削除
wp_clear_scheduled_hook( 'wbh_daily_cleanup' );

// Transientクリーンアップ（wbh_で始まるもの）
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wbh_%' OR option_name LIKE '_transient_timeout_wbh_%'"
);
