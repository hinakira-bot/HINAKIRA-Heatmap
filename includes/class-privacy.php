<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WBH_Privacy {

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'add_privacy_policy' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	public static function add_privacy_policy() {
		if ( ! function_exists( 'wp_add_inline_privacy_policy_content' ) ) {
			return;
		}

		$content = '<h2>HINAKIRA Heatmap プラグイン</h2>' .
			'<p>このプラグインはサイトの改善を目的として、以下のデータを収集します：</p>' .
			'<ul>' .
			'<li>ページビュー数（どの記事が読まれたか）</li>' .
			'<li>クリック位置（ページ上のどこがクリックされたか・座標のみ）</li>' .
			'<li>スクロール到達率（どこまで読まれたか・パーセンテージのみ）</li>' .
			'</ul>' .
			'<p><strong>データの擬似匿名化について：</strong>ユニーク訪問者数を推定するため、' .
			'IPアドレスを暗号学的ハッシュ関数で不可逆変換した短いトークンを一時的に使用します。' .
			'このトークンは毎日自動的に変更され、元のIPアドレスに復元することはできません。' .
			'IPアドレス自体、Cookie、ユーザーエージェント等の生データはデータベースに保存されません。</p>' .
			'<p>収集データは設定された保持期間（デフォルト90日）を過ぎると自動的に削除されます。' .
			'外部サービスへのデータ送信は一切行いません。</p>';

		wp_add_inline_privacy_policy_content( 'HINAKIRA Heatmap', $content );
	}

	public static function register_exporter( $exporters ) {
		$exporters['wp-blog-heatmap'] = array(
			'exporter_friendly_name' => 'HINAKIRA Heatmap',
			'callback'               => array( __CLASS__, 'export_data' ),
		);
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers['wp-blog-heatmap'] = array(
			'eraser_friendly_name' => 'HINAKIRA Heatmap',
			'callback'             => array( __CLASS__, 'erase_data' ),
		);
		return $erasers;
	}

	/**
	 * 個人データは保存していないため、エクスポートするデータなし
	 */
	public static function export_data( $email, $page = 1 ) {
		return array(
			'data' => array(),
			'done' => true,
		);
	}

	/**
	 * 個人データは保存していないため、削除するデータなし
	 */
	public static function erase_data( $email, $page = 1 ) {
		return array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array( 'HINAKIRA Heatmap は個人を特定するデータを保存していません。' ),
			'done'           => true,
		);
	}
}
