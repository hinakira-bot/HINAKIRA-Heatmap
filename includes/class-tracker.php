<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WBH_Tracker {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_tracker' ) );
	}

	public static function enqueue_tracker() {
		if ( ! is_singular() ) {
			return;
		}

		$settings = get_option( 'wbh_settings', array() );

		// トラッキングが無効
		if ( empty( $settings['tracking_enabled'] ) ) {
			return;
		}

		// ログインユーザーを除外
		if ( is_user_logged_in() && empty( $settings['track_logged_in'] ) ) {
			return;
		}

		// 除外投稿タイプ
		$excluded = isset( $settings['excluded_post_types'] ) ? $settings['excluded_post_types'] : array();
		if ( in_array( get_post_type(), $excluded, true ) ) {
			return;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return;
		}

		wp_enqueue_script(
			'wbh-tracker',
			WBH_PLUGIN_URL . 'assets/js/tracker.js',
			array(),
			WBH_VERSION,
			true
		);

		wp_localize_script( 'wbh-tracker', 'wbhConfig', array(
			'postId' => $post_id,
			'apiUrl' => esc_url_raw( rest_url( 'wbh/v1/track' ) ),
			'nonce'  => wp_create_nonce( 'wp_rest' ),
		) );
	}
}
