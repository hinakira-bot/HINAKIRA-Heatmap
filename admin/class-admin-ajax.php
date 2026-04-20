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
		self::validate_dates( $date_from, $date_to );

		// 投稿タイプはホワイトリストで制限
		$allowed_types = get_post_types( array( 'public' => true ) );
		$post_types    = isset( $_POST['post_types'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['post_types'] ) ) : array( 'post' );
		$post_types    = array_intersect( $post_types, $allowed_types );
		if ( empty( $post_types ) ) {
			$post_types = array( 'post' );
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

		// ビューポートフィルタのホワイトリスト
		if ( ! in_array( $viewport, array( 'all', 'sp', 'pc' ), true ) ) {
			$viewport = 'all';
		}

		self::validate_dates( $date_from, $date_to );

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

		if ( ! in_array( $viewport, array( 'all', 'sp', 'pc' ), true ) ) {
			$viewport = 'all';
		}

		self::validate_dates( $date_from, $date_to );

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

	private static function verify_request( $capability = 'manage_options' ) {
		if ( ! check_ajax_referer( 'wbh_admin_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'セキュリティトークンが無効です。ページを再読み込みしてください。' ), 403 );
		}
		if ( ! current_user_can( $capability ) ) {
			wp_send_json_error( array( 'message' => '権限がありません' ), 403 );
		}
	}

	/**
	 * 日付バリデーション（YYYY-MM-DD形式＆論理チェック）
	 */
	private static function validate_dates( &$date_from, &$date_to ) {
		$pattern = '/^\d{4}-\d{2}-\d{2}$/';

		if ( empty( $date_from ) || empty( $date_to )
			|| ! preg_match( $pattern, $date_from )
			|| ! preg_match( $pattern, $date_to )
		) {
			$date_to   = current_time( 'Y-m-d' );
			$date_from = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
			return;
		}

		// 日付の妥当性チェック
		$from_ts = strtotime( $date_from );
		$to_ts   = strtotime( $date_to );
		if ( false === $from_ts || false === $to_ts ) {
			$date_to   = current_time( 'Y-m-d' );
			$date_from = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
			return;
		}

		// from > to なら入れ替え
		if ( $from_ts > $to_ts ) {
			$tmp       = $date_from;
			$date_from = $date_to;
			$date_to   = $tmp;
		}

		// 最大365日に制限
		$max_range = 365 * DAY_IN_SECONDS;
		if ( ( $to_ts - $from_ts ) > $max_range ) {
			$date_from = gmdate( 'Y-m-d', $to_ts - $max_range );
		}
	}
}
