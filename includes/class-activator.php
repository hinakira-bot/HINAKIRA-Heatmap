<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WBH_Activator {

	public static function activate() {
		self::create_tables();
		self::set_default_options();
		self::schedule_cron();
	}

	public static function check_db_version() {
		$installed_version = get_option( 'wbh_db_version', '0' );
		if ( version_compare( $installed_version, WBH_DB_VERSION, '<' ) ) {
			self::create_tables();
			update_option( 'wbh_db_version', WBH_DB_VERSION );
		}
	}

	private static function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$prefix = $wpdb->prefix;

		$sql = "CREATE TABLE {$prefix}wbh_pageviews (
			id BIGINT UNSIGNED AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL,
			date_key DATE NOT NULL,
			pv_count INT UNSIGNED NOT NULL DEFAULT 0,
			unique_count INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY idx_post_date (post_id, date_key),
			KEY idx_date (date_key)
		) $charset_collate;

		CREATE TABLE {$prefix}wbh_clicks (
			id BIGINT UNSIGNED AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL,
			x_pct DECIMAL(5,2) NOT NULL,
			y_px INT UNSIGNED NOT NULL,
			viewport_w SMALLINT UNSIGNED NOT NULL,
			element_tag VARCHAR(30) DEFAULT NULL,
			recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_post (post_id),
			KEY idx_recorded (recorded_at)
		) $charset_collate;

		CREATE TABLE {$prefix}wbh_scrolls (
			id BIGINT UNSIGNED AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL,
			max_depth TINYINT UNSIGNED NOT NULL,
			content_h INT UNSIGNED NOT NULL,
			viewport_w SMALLINT UNSIGNED NOT NULL,
			recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_post (post_id),
			KEY idx_recorded (recorded_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'wbh_db_version', WBH_DB_VERSION );
	}

	private static function set_default_options() {
		$defaults = array(
			'tracking_enabled'    => true,
			'track_logged_in'     => false,
			'data_retention_days' => 90,
			'cleanup_batch_size'  => 1000,
			'excluded_post_types' => array(),
			'excluded_urls'       => array(),
		);

		if ( false === get_option( 'wbh_settings' ) ) {
			add_option( 'wbh_settings', $defaults );
		}
	}

	private static function schedule_cron() {
		if ( ! wp_next_scheduled( 'wbh_daily_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'wbh_daily_cleanup' );
		}
	}
}
