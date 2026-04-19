<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WBH_REST_API {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route( 'wbh/v1', '/track', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_track' ),
			'permission_callback' => '__return_true',
		) );
	}

	public static function handle_track( WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) );
		$type    = sanitize_text_field( $request->get_param( 'type' ) );
		$data    = $request->get_param( 'data' );

		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_post' ), 400 );
		}

		if ( ! in_array( $type, array( 'pageview', 'click', 'scroll' ), true ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_type' ), 400 );
		}

		// レート制限（IPハッシュベース、1分100リクエスト）
		if ( self::is_rate_limited() ) {
			return new WP_REST_Response( array( 'error' => 'rate_limited' ), 429 );
		}

		switch ( $type ) {
			case 'pageview':
				WBH_Data_Manager::record_pageview( $post_id );
				break;

			case 'click':
				if ( ! is_array( $data ) ) {
					return new WP_REST_Response( array( 'error' => 'invalid_data' ), 400 );
				}
				$x_pct       = isset( $data['x'] ) ? floatval( $data['x'] ) : 0;
				$y_px        = isset( $data['y'] ) ? absint( $data['y'] ) : 0;
				$viewport_w  = isset( $data['vw'] ) ? absint( $data['vw'] ) : 0;
				$element_tag = isset( $data['el'] ) ? sanitize_text_field( substr( $data['el'], 0, 30 ) ) : '';

				if ( $x_pct < 0 || $x_pct > 100 || $viewport_w < 200 || $viewport_w > 5000 ) {
					return new WP_REST_Response( array( 'error' => 'invalid_data' ), 400 );
				}

				WBH_Data_Manager::record_click( $post_id, $x_pct, $y_px, $viewport_w, $element_tag );
				break;

			case 'scroll':
				if ( ! is_array( $data ) ) {
					return new WP_REST_Response( array( 'error' => 'invalid_data' ), 400 );
				}
				$max_depth  = isset( $data['depth'] ) ? min( 100, absint( $data['depth'] ) ) : 0;
				$content_h  = isset( $data['ch'] ) ? absint( $data['ch'] ) : 0;
				$viewport_w = isset( $data['vw'] ) ? absint( $data['vw'] ) : 0;

				if ( $content_h < 10 || $viewport_w < 200 ) {
					return new WP_REST_Response( array( 'error' => 'invalid_data' ), 400 );
				}

				WBH_Data_Manager::record_scroll( $post_id, $max_depth, $content_h, $viewport_w );
				break;
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	private static function is_rate_limited() {
		$ip        = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$hash      = substr( md5( $ip . wp_salt() ), 0, 8 );
		$cache_key = 'wbh_rl_' . $hash;
		$count     = (int) get_transient( $cache_key );

		if ( $count >= 100 ) {
			return true;
		}

		set_transient( $cache_key, $count + 1, MINUTE_IN_SECONDS );
		return false;
	}
}
