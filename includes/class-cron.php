<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WBH_Cron {

	public static function init() {
		add_action( 'wbh_daily_cleanup', array( __CLASS__, 'run_cleanup' ) );
	}

	public static function run_cleanup() {
		$settings   = get_option( 'wbh_settings', array() );
		$days       = isset( $settings['data_retention_days'] ) ? absint( $settings['data_retention_days'] ) : 90;
		$batch_size = isset( $settings['cleanup_batch_size'] ) ? absint( $settings['cleanup_batch_size'] ) : 1000;

		if ( $days < 1 ) {
			$days = 90;
		}

		$deleted = WBH_Data_Manager::cleanup_old_data( $days, $batch_size );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( 'WBH Cleanup: %d rows deleted (retention: %d days)', $deleted, $days ) );
		}
	}
}
