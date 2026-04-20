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
			'permission_callback' => array( __CLASS__, 'verify_track_token' ),
		) );
	}

	/**
	 * トラッキングリクエストの検証
	 * ログインユーザー: WP REST nonceで検証
	 * 非ログインユーザー: カスタムトークンで検証（CSRF防止）
	 */
	public static function verify_track_token( WP_REST_Request $request ) {
		// WP REST nonce があればそれで検証
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return true;
		}

		// カスタムトークン検証（非ログインユーザー用）
		$token = $request->get_header( 'X-WBH-Token' );
		// sendBeacon用: URLパラメータからもトークンを取得
		if ( ! $token ) {
			$token = $request->get_param( '_wbh_token' );
		}
		if ( $token && self::verify_custom_token( $token ) ) {
			return true;
		}

		return new WP_Error( 'rest_forbidden', 'Invalid tracking token.', array( 'status' => 403 ) );
	}

	public static function handle_track( WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) );
		$type    = sanitize_text_field( $request->get_param( 'type' ) );
		$data    = $request->get_param( 'data' );

		// 投稿の存在確認（キャッシュ付き）
		if ( ! $post_id ) {
			return new WP_REST_Response( array( 'error' => 'invalid_post' ), 400 );
		}
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return new WP_REST_Response( array( 'error' => 'invalid_post' ), 400 );
		}

		if ( ! in_array( $type, array( 'pageview', 'click', 'scroll' ), true ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_type' ), 400 );
		}

		// レート制限（プロキシ対応IPハッシュベース、1分100リクエスト）
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

				// バウンドチェック
				if ( $x_pct < 0 || $x_pct > 100 ) {
					return new WP_REST_Response( array( 'error' => 'invalid_data' ), 400 );
				}
				if ( $y_px > 100000 ) {
					return new WP_REST_Response( array( 'error' => 'invalid_data' ), 400 );
				}
				if ( $viewport_w < 200 || $viewport_w > 5000 ) {
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

				// バウンドチェック
				if ( $content_h < 10 || $content_h > 200000 ) {
					return new WP_REST_Response( array( 'error' => 'invalid_data' ), 400 );
				}
				if ( $viewport_w < 200 || $viewport_w > 5000 ) {
					return new WP_REST_Response( array( 'error' => 'invalid_data' ), 400 );
				}

				WBH_Data_Manager::record_scroll( $post_id, $max_depth, $content_h, $viewport_w );
				break;
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * クライアントIP取得（プロキシ/CDN対応）
	 */
	private static function get_client_ip() {
		$headers = array(
			'HTTP_CF_CONNECTING_IP',  // Cloudflare
			'HTTP_X_FORWARDED_FOR',   // 一般的なプロキシ
			'HTTP_X_REAL_IP',         // Nginx
			'REMOTE_ADDR',            // 直接接続
		);

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
				// X-Forwarded-For は複数IPをカンマ区切りで含む場合がある
				if ( strpos( $ip, ',' ) !== false ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return 'unknown';
	}

	/**
	 * クライアントIP取得（他クラスから利用可能）
	 */
	public static function get_client_ip_static() {
		return self::get_client_ip();
	}

	/**
	 * レート制限（プロキシ対応・アトミック更新）
	 */
	private static function is_rate_limited() {
		$ip        = self::get_client_ip();
		$hash      = substr( hash( 'sha256', $ip . wp_salt() ), 0, 12 );
		$cache_key = 'wbh_rl_' . $hash;

		// wp_cache使用でオブジェクトキャッシュ対応
		$count = wp_cache_get( $cache_key, 'wbh_rate_limit' );
		if ( false === $count ) {
			$count = (int) get_transient( $cache_key );
		}

		if ( $count >= 100 ) {
			return true;
		}

		$new_count = $count + 1;
		set_transient( $cache_key, $new_count, MINUTE_IN_SECONDS );
		wp_cache_set( $cache_key, $new_count, 'wbh_rate_limit', MINUTE_IN_SECONDS );

		return false;
	}

	/**
	 * カスタムトークン生成（フロント用）
	 */
	public static function generate_track_token() {
		$salt = wp_salt( 'auth' );
		$day  = current_time( 'Y-m-d' );
		return substr( hash_hmac( 'sha256', 'wbh_track_' . $day, $salt ), 0, 32 );
	}

	/**
	 * カスタムトークン検証
	 */
	private static function verify_custom_token( $token ) {
		// 今日のトークン
		if ( hash_equals( self::generate_track_token(), $token ) ) {
			return true;
		}
		// 昨日のトークン（日付跨ぎ対応）
		$salt      = wp_salt( 'auth' );
		$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
		$old_token = substr( hash_hmac( 'sha256', 'wbh_track_' . $yesterday, $salt ), 0, 32 );
		return hash_equals( $old_token, $token );
	}
}
