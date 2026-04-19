<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WBH_Admin_Ajax {

	public static function init() {
		$actions = array(
			'wbh_get_performance_map',
			'wbh_get_click_heatmap',
			'wbh_get_scroll_data',
			'wbh_get_overview_stats',
			'wbh_get_tracked_posts',
			'wbh_cleanup_now',
			'wbh_update_settings',
		);

		foreach ( $actions as $action ) {
			add_action( 'wp_ajax_' . $action, array( __CLASS__, $action ) );
		}
	}

	public static function wbh_get_performance_map() {
		self::verify_request();

		$date_from  = sanitize_text_field( wp_unslash( $_POST['date_from'] ?? '' ) );
		$date_to    = sanitize_text_field( wp_unslash( $_POST['date_to'] ?? '' ) );
		$post_types = isset( $_POST['post_types'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['post_types'] ) ) : array( 'post' );

		if ( empty( $date_from ) || empty( $date_to ) ) {
			$date_to   = current_time( 'Y-m-d' );
			$date_from = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		}

		$data = WBH_Data_Manager::get_performance_data( $date_from, $date_to, $post_types );
		wp_send_json_success( $data );
	}

	public static function wbh_get_click_heatmap() {
		self::verify_request();

		$post_id    = absint( $_POST['post_id'] ?? 0 );
		$date_from  = sanitize_text_field( wp_unslash( $_POST['date_from'] ?? '' ) );
		$date_to    = sanitize_text_field( wp_unslash( $_POST['date_to'] ?? '' ) );
		$viewport   = sanitize_text_field( wp_unslash( $_POST['viewport'] ?? 'all' ) );

		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => '記事を選択してください' ) );
		}

		if ( empty( $date_from ) || empty( $date_to ) ) {
			$date_to   = current_time( 'Y-m-d' );
			$date_from = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		}

		$data = WBH_Data_Manager::get_click_data( $post_id, $date_from, $date_to, $viewport );

		// 要素別集計
		$element_counts = array();
		foreach ( $data as $click ) {
			$tag = $click->element_tag ?: 'other';
			if ( ! isset( $element_counts[ $tag ] ) ) {
				$element_counts[ $tag ] = 0;
			}
			$element_counts[ $tag ]++;
		}
		arsort( $element_counts );

		wp_send_json_success( array(
			'clicks'   => $data,
			'total'    => count( $data ),
			'elements' => $element_counts,
			'post_url' => get_permalink( $post_id ),
		) );
	}

	public static function wbh_get_scroll_data() {
		self::verify_request();

		$post_id   = absint( $_POST['post_id'] ?? 0 );
		$date_from = sanitize_text_field( wp_unslash( $_POST['date_from'] ?? '' ) );
		$date_to   = sanitize_text_field( wp_unslash( $_POST['date_to'] ?? '' ) );
		$viewport  = sanitize_text_field( wp_unslash( $_POST['viewport'] ?? 'all' ) );

		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => '記事を選択してください' ) );
		}

		if ( empty( $date_from ) || empty( $date_to ) ) {
			$date_to   = current_time( 'Y-m-d' );
			$date_from = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		}

		$data = WBH_Data_Manager::get_scroll_data( $post_id, $date_from, $date_to, $viewport );
		wp_send_json_success( $data );
	}

	public static function wbh_get_overview_stats() {
		self::verify_request();
		$data = WBH_Data_Manager::get_overview_stats();
		wp_send_json_success( $data );
	}

	public static function wbh_get_tracked_posts() {
		self::verify_request();
		$data = WBH_Data_Manager::get_tracked_posts();
		wp_send_json_success( $data );
	}

	public static function wbh_cleanup_now() {
		self::verify_request( 'manage_options' );

		$settings   = get_option( 'wbh_settings', array() );
		$days       = isset( $settings['data_retention_days'] ) ? absint( $settings['data_retention_days'] ) : 90;
		$deleted    = WBH_Data_Manager::cleanup_old_data( $days );

		wp_send_json_success( array(
			'deleted' => $deleted,
			'message' => sprintf( '%d件のデータを削除しました', $deleted ),
		) );
	}

	public static function wbh_update_settings() {
		self::verify_request( 'manage_options' );

		$new_settings = array(
			'tracking_enabled'    => ! empty( $_POST['tracking_enabled'] ),
			'track_logged_in'     => ! empty( $_POST['track_logged_in'] ),
			'data_retention_days' => absint( $_POST['data_retention_days'] ?? 90 ),
			'cleanup_batch_size'  => absint( $_POST['cleanup_batch_size'] ?? 1000 ),
			'excluded_post_types' => isset( $_POST['excluded_post_types'] )
				? array_map( 'sanitize_text_field', wp_unslash( $_POST['excluded_post_types'] ) )
				: array(),
		);

		if ( $new_settings['data_retention_days'] < 7 ) {
			$new_settings['data_retention_days'] = 7;
		}

		update_option( 'wbh_settings', $new_settings );
		wp_send_json_success( array( 'message' => '設定を保存しました' ) );
	}

	private static function verify_request( $capability = 'edit_posts' ) {
		check_ajax_referer( 'wbh_admin_nonce', 'nonce' );
		if ( ! current_user_can( $capability ) ) {
			wp_send_json_error( array( 'message' => '権限がありません' ), 403 );
		}
	}
}
