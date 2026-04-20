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
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_wbh_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_wbh_' ) . '%'
	)
);

// オブジェクトキャッシュのクリア（Redis/Memcached環境対応）
wp_cache_flush_group( 'wbh_rate_limit' );
