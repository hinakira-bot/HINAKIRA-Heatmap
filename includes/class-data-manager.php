<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WBH_Data_Manager {

	/**
	 * PV記録（1日1投稿1行に集約）
	 */
	public static function record_pageview( $post_id ) {
		global $wpdb;
		$table    = $wpdb->prefix . 'wbh_pageviews';
		$date_key = current_time( 'Y-m-d' );

		// ユニーク判定（短期transientでクッキー不要）
		$hash      = self::get_visitor_hash();
		$cache_key = 'wbh_uv_' . $post_id . '_' . $hash;
		$is_unique = false === get_transient( $cache_key );

		if ( $is_unique ) {
			set_transient( $cache_key, 1, HOUR_IN_SECONDS );
		}

		$unique_inc = $is_unique ? 1 : 0;

		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$table} (post_id, date_key, pv_count, unique_count)
			 VALUES (%d, %s, 1, %d)
			 ON DUPLICATE KEY UPDATE pv_count = pv_count + 1, unique_count = unique_count + %d",
			$post_id,
			$date_key,
			$unique_inc,
			$unique_inc
		) );
	}

	/**
	 * クリック記録
	 */
	public static function record_click( $post_id, $x_pct, $y_px, $viewport_w, $element_tag ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wbh_clicks';

		$wpdb->insert( $table, array(
			'post_id'     => $post_id,
			'x_pct'       => $x_pct,
			'y_px'        => $y_px,
			'viewport_w'  => $viewport_w,
			'element_tag' => $element_tag,
		), array( '%d', '%f', '%d', '%d', '%s' ) );
	}

	/**
	 * スクロール記録
	 */
	public static function record_scroll( $post_id, $max_depth, $content_h, $viewport_w ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wbh_scrolls';

		$wpdb->insert( $table, array(
			'post_id'    => $post_id,
			'max_depth'  => $max_depth,
			'content_h'  => $content_h,
			'viewport_w' => $viewport_w,
		), array( '%d', '%d', '%d', '%d' ) );
	}

	/**
	 * パフォーマンスマップ用データ取得
	 */
	public static function get_performance_data( $date_from, $date_to, $post_types = array( 'post' ) ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wbh_pageviews';

		$type_placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		$query = $wpdb->prepare(
			"SELECT pv.post_id,
			        SUM(pv.pv_count) AS total_pv,
			        SUM(pv.unique_count) AS total_unique,
			        p.post_title,
			        p.post_type,
			        p.post_date
			 FROM {$table} pv
			 INNER JOIN {$wpdb->posts} p ON p.ID = pv.post_id
			 WHERE pv.date_key BETWEEN %s AND %s
			   AND p.post_type IN ({$type_placeholders})
			   AND p.post_status = 'publish'
			 GROUP BY pv.post_id
			 ORDER BY total_pv DESC",
			array_merge( array( $date_from, $date_to ), $post_types )
		);

		return $wpdb->get_results( $query );
	}

	/**
	 * クリックヒートマップデータ取得
	 */
	public static function get_click_data( $post_id, $date_from, $date_to, $viewport_filter = 'all' ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wbh_clicks';

		$viewport_clause = '';
		$params = array( $post_id, $date_from . ' 00:00:00', $date_to . ' 23:59:59' );

		if ( 'sp' === $viewport_filter ) {
			$viewport_clause = ' AND viewport_w <= 768';
		} elseif ( 'pc' === $viewport_filter ) {
			$viewport_clause = ' AND viewport_w > 768';
		}

		$query = $wpdb->prepare(
			"SELECT x_pct, y_px, viewport_w, element_tag
			 FROM {$table}
			 WHERE post_id = %d
			   AND recorded_at BETWEEN %s AND %s
			   {$viewport_clause}
			 ORDER BY recorded_at DESC
			 LIMIT 5000",
			$params
		);

		return $wpdb->get_results( $query );
	}

	/**
	 * スクロールデータ取得（10%刻みの分布）
	 */
	public static function get_scroll_data( $post_id, $date_from, $date_to, $viewport_filter = 'all' ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wbh_scrolls';

		$viewport_clause = '';
		$params = array( $post_id, $date_from . ' 00:00:00', $date_to . ' 23:59:59' );

		if ( 'sp' === $viewport_filter ) {
			$viewport_clause = ' AND viewport_w <= 768';
		} elseif ( 'pc' === $viewport_filter ) {
			$viewport_clause = ' AND viewport_w > 768';
		}

		$total_query = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table}
			 WHERE post_id = %d AND recorded_at BETWEEN %s AND %s {$viewport_clause}",
			$params
		);
		$total = (int) $wpdb->get_var( $total_query );

		if ( 0 === $total ) {
			return array(
				'total'        => 0,
				'avg_depth'    => 0,
				'distribution' => array_fill( 0, 10, 0 ),
			);
		}

		// 平均到達率
		$avg_query = $wpdb->prepare(
			"SELECT AVG(max_depth) FROM {$table}
			 WHERE post_id = %d AND recorded_at BETWEEN %s AND %s {$viewport_clause}",
			$params
		);
		$avg_depth = round( (float) $wpdb->get_var( $avg_query ), 1 );

		// 10%刻みの分布
		$distribution = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$threshold = ( $i + 1 ) * 10;
			$count_query = $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				 WHERE post_id = %d AND recorded_at BETWEEN %s AND %s
				   AND max_depth >= %d {$viewport_clause}",
				array_merge( $params, array( $threshold ) )
			);
			$count = (int) $wpdb->get_var( $count_query );
			$distribution[] = round( ( $count / $total ) * 100, 1 );
		}

		return array(
			'total'        => $total,
			'avg_depth'    => $avg_depth,
			'distribution' => $distribution,
		);
	}

	/**
	 * ダッシュボード概要統計
	 */
	public static function get_overview_stats() {
		global $wpdb;
		$pv_table     = $wpdb->prefix . 'wbh_pageviews';
		$click_table  = $wpdb->prefix . 'wbh_clicks';
		$scroll_table = $wpdb->prefix . 'wbh_scrolls';

		$total_pv      = (int) $wpdb->get_var( "SELECT COALESCE(SUM(pv_count), 0) FROM {$pv_table}" );
		$total_clicks  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$click_table}" );
		$total_scrolls = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$scroll_table}" );
		$tracked_posts = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$pv_table}" );

		return array(
			'total_pv'      => $total_pv,
			'total_clicks'  => $total_clicks,
			'total_scrolls' => $total_scrolls,
			'tracked_posts' => $tracked_posts,
		);
	}

	/**
	 * 記事一覧取得（セレクトボックス用）
	 */
	public static function get_tracked_posts() {
		global $wpdb;
		$table = $wpdb->prefix . 'wbh_pageviews';

		return $wpdb->get_results(
			"SELECT DISTINCT pv.post_id, p.post_title
			 FROM {$table} pv
			 INNER JOIN {$wpdb->posts} p ON p.ID = pv.post_id
			 WHERE p.post_status = 'publish'
			 ORDER BY p.post_title ASC"
		);
	}

	/**
	 * 古いデータのクリーンアップ
	 */
	public static function cleanup_old_data( $days = 90, $batch_size = 1000 ) {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		$tables = array(
			$wpdb->prefix . 'wbh_clicks'  => 'recorded_at',
			$wpdb->prefix . 'wbh_scrolls' => 'recorded_at',
		);

		$total_deleted = 0;
		foreach ( $tables as $table => $date_col ) {
			do {
				$deleted = $wpdb->query( $wpdb->prepare(
					"DELETE FROM {$table} WHERE {$date_col} < %s LIMIT %d",
					$cutoff,
					$batch_size
				) );
				$total_deleted += $deleted;
			} while ( $deleted >= $batch_size );
		}

		// PV テーブルは date_key で判定
		$pv_table = $wpdb->prefix . 'wbh_pageviews';
		$pv_cutoff = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		do {
			$deleted = $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$pv_table} WHERE date_key < %s LIMIT %d",
				$pv_cutoff,
				$batch_size
			) );
			$total_deleted += $deleted;
		} while ( $deleted >= $batch_size );

		return $total_deleted;
	}

	/**
	 * 訪問者ハッシュ（個人特定不可・日次ローテーション）
	 */
	private static function get_visitor_hash() {
		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ua   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$salt = wp_salt( 'auth' ) . current_time( 'Y-m-d' );
		return substr( hash( 'sha256', $ip . $ua . $salt ), 0, 12 );
	}
}
