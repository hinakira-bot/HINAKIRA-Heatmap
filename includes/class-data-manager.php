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

		$result = $wpdb->query( $wpdb->prepare(
			"INSERT INTO {$table} (post_id, date_key, pv_count, unique_count)
			 VALUES (%d, %s, 1, %d)
			 ON DUPLICATE KEY UPDATE pv_count = pv_count + 1, unique_count = unique_count + %d",
			$post_id,
			$date_key,
			$unique_inc,
			$unique_inc
		) );

		if ( false === $result ) {
			self::log_error( 'record_pageview', $wpdb->last_error );
		}
	}

	/**
	 * クリック記録
	 */
	public static function record_click( $post_id, $x_pct, $y_px, $viewport_w, $element_tag ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wbh_clicks';

		$result = $wpdb->insert( $table, array(
			'post_id'     => $post_id,
			'x_pct'       => $x_pct,
			'y_px'        => $y_px,
			'viewport_w'  => $viewport_w,
			'element_tag' => $element_tag,
		), array( '%d', '%f', '%d', '%d', '%s' ) );

		if ( false === $result ) {
			self::log_error( 'record_click', $wpdb->last_error );
		}
	}

	/**
	 * スクロール記録
	 */
	public static function record_scroll( $post_id, $max_depth, $content_h, $viewport_w ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wbh_scrolls';

		$result = $wpdb->insert( $table, array(
			'post_id'    => $post_id,
			'max_depth'  => $max_depth,
			'content_h'  => $content_h,
			'viewport_w' => $viewport_w,
		), array( '%d', '%d', '%d', '%d' ) );

		if ( false === $result ) {
			self::log_error( 'record_scroll', $wpdb->last_error );
		}
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
			 ORDER BY total_pv DESC
			 LIMIT 500",
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
	 * スクロールデータ取得（10%刻みの分布 — 1クエリで集約）
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

		// 1クエリで COUNT, AVG, 10%刻み分布を同時取得（N+1解消）
		$query = $wpdb->prepare(
			"SELECT
				COUNT(*) AS total,
				ROUND(AVG(max_depth), 1) AS avg_depth,
				SUM(CASE WHEN max_depth >= 10 THEN 1 ELSE 0 END) AS d10,
				SUM(CASE WHEN max_depth >= 20 THEN 1 ELSE 0 END) AS d20,
				SUM(CASE WHEN max_depth >= 30 THEN 1 ELSE 0 END) AS d30,
				SUM(CASE WHEN max_depth >= 40 THEN 1 ELSE 0 END) AS d40,
				SUM(CASE WHEN max_depth >= 50 THEN 1 ELSE 0 END) AS d50,
				SUM(CASE WHEN max_depth >= 60 THEN 1 ELSE 0 END) AS d60,
				SUM(CASE WHEN max_depth >= 70 THEN 1 ELSE 0 END) AS d70,
				SUM(CASE WHEN max_depth >= 80 THEN 1 ELSE 0 END) AS d80,
				SUM(CASE WHEN max_depth >= 90 THEN 1 ELSE 0 END) AS d90,
				SUM(CASE WHEN max_depth >= 100 THEN 1 ELSE 0 END) AS d100
			 FROM {$table}
			 WHERE post_id = %d AND recorded_at BETWEEN %s AND %s {$viewport_clause}",
			$params
		);

		$row = $wpdb->get_row( $query );

		$total = (int) ( $row->total ?? 0 );

		if ( 0 === $total ) {
			return array(
				'total'        => 0,
				'avg_depth'    => 0,
				'distribution' => array_fill( 0, 10, 0 ),
			);
		}

		$distribution = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$key   = 'd' . ( $i * 10 );
			$count = (int) ( $row->$key ?? 0 );
			$distribution[] = round( ( $count / $total ) * 100, 1 );
		}

		return array(
			'total'        => $total,
			'avg_depth'    => (float) ( $row->avg_depth ?? 0 ),
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
	 * 記事一覧取得（セレクトボックス用・最大500件）
	 */
	public static function get_tracked_posts() {
		global $wpdb;
		$table = $wpdb->prefix . 'wbh_pageviews';

		return $wpdb->get_results(
			"SELECT pv.post_id, p.post_title
			 FROM {$table} pv
			 INNER JOIN {$wpdb->posts} p ON p.ID = pv.post_id
			 WHERE p.post_status = 'publish'
			 GROUP BY pv.post_id
			 ORDER BY p.post_title ASC
			 LIMIT 500"
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
	 * 訪問者ハッシュ（擬似匿名化・日次ローテーション）
	 * 注: IP + salt のみ使用。UAは除外しフィンガープリント精度を意図的に下げる。
	 */
	private static function get_visitor_hash() {
		$ip   = WBH_REST_API::get_client_ip_static();
		$salt = wp_salt( 'auth' ) . current_time( 'Y-m-d' );
		return substr( hash( 'sha256', $ip . $salt ), 0, 16 );
	}

	/**
	 * DBエラーをログに記録
	 */
	private static function log_error( $method, $error ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && $error ) {
			error_log( sprintf( 'WBH DB Error in %s: %s', $method, $error ) );
		}
	}
}
