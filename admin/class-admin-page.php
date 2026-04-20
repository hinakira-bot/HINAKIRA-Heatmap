<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WBH_Admin_Page {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function add_menu() {
		add_menu_page(
			'HINAKIRA Heatmap',
			'HINAKIRA Heatmap',
			'manage_options',
			'wp-blog-heatmap',
			array( __CLASS__, 'render_page' ),
			'dashicons-chart-area',
			30
		);
	}

	public static function enqueue_assets( $hook ) {
		if ( 'toplevel_page_wp-blog-heatmap' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wbh-admin',
			WBH_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			WBH_VERSION
		);

		// Chart.js（SRI付き）
		wp_enqueue_script(
			'chartjs',
			'https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js',
			array(),
			'4.4.4',
			true
		);
		// SRI integrity ハッシュを追加
		add_filter( 'script_loader_tag', function( $tag, $handle ) {
			if ( 'chartjs' === $handle ) {
				$tag = str_replace(
					' src=',
					' integrity="sha384-6BWFQ7q9TO+kyOClyBMGIYBMbOm1e3YNWE/MIWBjQPN5HIYBr/UWaFMtiHYqEzC" crossorigin="anonymous" src=',
					$tag
				);
			}
			return $tag;
		}, 10, 2 );

		wp_enqueue_script(
			'wbh-dashboard',
			WBH_PLUGIN_URL . 'assets/js/dashboard.js',
			array( 'jquery', 'chartjs' ),
			WBH_VERSION,
			true
		);

		$settings = get_option( 'wbh_settings', array() );

		wp_localize_script( 'wbh-dashboard', 'wbhDashboard', array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'wbh_admin_nonce' ),
			'settings' => $settings,
			'i18n'     => array(
				'selectPost'      => '記事を選択してください',
				'loadingData'     => 'データを読み込み中...',
				'noData'          => 'データがまだありません。アクセスデータが溜まるまでお待ちください。',
				'loadFailed'      => 'データの取得に失敗しました',
				'saveFailed'      => '設定の保存に失敗しました',
				'cleanupFailed'   => 'クリーンアップに失敗しました',
				'cleanupConfirm'  => '保持期間を過ぎた古いデータを削除します。よろしいですか？',
				'noDataShort'     => 'データなし',
				'noDataTable'     => 'データがありません',
				'reachPct'        => '読者の {pct}% が到達',
				'remainPct'       => '読者の {pct}% が残存',
				'scrollDepth'     => 'スクロール深度',
				'reachRate'       => '到達率 (%)',
				'readerRetention' => '読者残存率',
				'selectPostFirst' => '記事を選択...',
			),
		) );
	}

	public static function render_page() {
		$settings = get_option( 'wbh_settings', array() );
		?>
		<div class="wrap wbh-wrap">
			<div class="wbh-header">
				<div class="wbh-header-inner">
					<h1><img src="<?php echo esc_url( WBH_PLUGIN_URL . 'assets/images/logo.svg' ); ?>" alt="HINAKIRA Heatmap" class="wbh-logo"> HINAKIRA Heatmap</h1>
					<p class="wbh-subtitle">ブログ記事のパフォーマンス・クリック・スクロールを可視化</p>
				</div>
			</div>

			<nav class="wbh-tabs">
				<button class="wbh-tab active" data-tab="performance">
					<span class="dashicons dashicons-performance"></span>
					パフォーマンス
				</button>
				<button class="wbh-tab" data-tab="clicks">
					<span class="dashicons dashicons-admin-links"></span>
					クリック
				</button>
				<button class="wbh-tab" data-tab="scroll">
					<span class="dashicons dashicons-arrow-down-alt2"></span>
					スクロール
				</button>
				<button class="wbh-tab" data-tab="guide">
					<span class="dashicons dashicons-book"></span>
					ガイド
				</button>
				<button class="wbh-tab" data-tab="settings">
					<span class="dashicons dashicons-admin-settings"></span>
					設定
				</button>
			</nav>

			<!-- パフォーマンスタブ -->
			<div class="wbh-tab-content active" id="tab-performance">
				<div class="wbh-toolbar">
					<div class="wbh-toolbar-group">
						<label>期間:</label>
						<select id="wbh-perf-period">
							<option value="7">過去7日</option>
							<option value="30" selected>過去30日</option>
							<option value="90">過去90日</option>
						</select>
					</div>
					<div class="wbh-toolbar-group">
						<label>投稿タイプ:</label>
						<select id="wbh-perf-posttype">
							<option value="post">投稿</option>
							<option value="page">固定ページ</option>
						</select>
					</div>
					<button class="button button-primary" id="wbh-perf-reload">
						<span class="dashicons dashicons-update"></span> 更新
					</button>
				</div>

				<div class="wbh-stats-cards" id="wbh-perf-stats">
					<div class="wbh-card">
						<div class="wbh-card-icon"><span class="dashicons dashicons-visibility"></span></div>
						<div class="wbh-card-body">
							<div class="wbh-card-value" id="stat-total-pv">-</div>
							<div class="wbh-card-label">総PV</div>
						</div>
					</div>
					<div class="wbh-card">
						<div class="wbh-card-icon"><span class="dashicons dashicons-chart-line"></span></div>
						<div class="wbh-card-body">
							<div class="wbh-card-value" id="stat-avg-pv">-</div>
							<div class="wbh-card-label">平均PV/記事</div>
						</div>
					</div>
					<div class="wbh-card">
						<div class="wbh-card-icon"><span class="dashicons dashicons-star-filled"></span></div>
						<div class="wbh-card-body">
							<div class="wbh-card-value" id="stat-top-post">-</div>
							<div class="wbh-card-label">トップ記事</div>
						</div>
					</div>
					<div class="wbh-card">
						<div class="wbh-card-icon"><span class="dashicons dashicons-admin-page"></span></div>
						<div class="wbh-card-body">
							<div class="wbh-card-value" id="stat-post-count">-</div>
							<div class="wbh-card-label">計測中の記事</div>
						</div>
					</div>
				</div>

				<div class="wbh-section">
					<h3>記事パフォーマンスマップ</h3>
					<div class="wbh-treemap-legend">
						<span class="wbh-legend-low">Low PV</span>
						<span class="wbh-legend-bar"></span>
						<span class="wbh-legend-high">High PV</span>
					</div>
					<div id="wbh-treemap" class="wbh-treemap">
						<div class="wbh-loading"><span class="spinner is-active"></span> データを読み込み中...</div>
					</div>
				</div>

				<div class="wbh-section">
					<h3>記事一覧</h3>
					<table class="wp-list-table widefat striped" id="wbh-perf-table">
						<thead>
							<tr>
								<th class="wbh-sortable" data-sort="rank">#</th>
								<th class="wbh-sortable" data-sort="title">タイトル</th>
								<th class="wbh-sortable active" data-sort="pv">PV</th>
								<th class="wbh-sortable" data-sort="unique">ユニーク</th>
								<th>操作</th>
							</tr>
						</thead>
						<tbody id="wbh-perf-tbody"></tbody>
					</table>
				</div>
			</div>

			<!-- クリックタブ -->
			<div class="wbh-tab-content" id="tab-clicks">
				<div class="wbh-toolbar">
					<div class="wbh-toolbar-group">
						<label>記事:</label>
						<select id="wbh-click-post" class="wbh-post-select">
							<option value="">記事を選択...</option>
						</select>
					</div>
					<div class="wbh-toolbar-group">
						<label>期間:</label>
						<select id="wbh-click-period">
							<option value="7">過去7日</option>
							<option value="30" selected>過去30日</option>
							<option value="90">過去90日</option>
						</select>
					</div>
					<div class="wbh-toolbar-group">
						<label>デバイス:</label>
						<select id="wbh-click-device">
							<option value="all">すべて</option>
							<option value="sp">スマホ</option>
							<option value="pc">PC</option>
						</select>
					</div>
					<button class="button button-primary" id="wbh-click-reload">
						<span class="dashicons dashicons-update"></span> 表示
					</button>
				</div>

				<div class="wbh-stats-cards" id="wbh-click-stats" style="display:none;">
					<div class="wbh-card">
						<div class="wbh-card-icon"><span class="dashicons dashicons-admin-links"></span></div>
						<div class="wbh-card-body">
							<div class="wbh-card-value" id="stat-total-clicks">-</div>
							<div class="wbh-card-label">総クリック</div>
						</div>
					</div>
					<div class="wbh-card wbh-card-wide">
						<div class="wbh-card-icon"><span class="dashicons dashicons-star-filled"></span></div>
						<div class="wbh-card-body">
							<div class="wbh-card-value" id="stat-top-element">-</div>
							<div class="wbh-card-label">最多クリック要素</div>
						</div>
					</div>
				</div>

				<div class="wbh-heatmap-layout" id="wbh-click-content" style="display:none;">
					<div class="wbh-heatmap-main">
						<div class="wbh-heatmap-container">
							<div class="wbh-heatmap-notice">
								<span class="dashicons dashicons-info"></span>
								ページをiframeで表示し、その上にクリック位置をオーバーレイ表示します
							</div>
							<div class="wbh-iframe-wrap" id="wbh-click-iframe-wrap">
								<iframe id="wbh-click-iframe" sandbox="allow-same-origin" scrolling="yes"></iframe>
								<canvas id="wbh-click-canvas"></canvas>
							</div>
						</div>
					</div>
					<div class="wbh-heatmap-sidebar">
						<div class="wbh-panel">
							<h4>要素別クリック数</h4>
							<div id="wbh-click-elements"></div>
						</div>
					</div>
				</div>

				<div class="wbh-empty-state" id="wbh-click-empty">
					<span class="dashicons dashicons-admin-links"></span>
					<p>記事を選択して「表示」をクリックしてください</p>
				</div>
			</div>

			<!-- スクロールタブ -->
			<div class="wbh-tab-content" id="tab-scroll">
				<div class="wbh-toolbar">
					<div class="wbh-toolbar-group">
						<label>記事:</label>
						<select id="wbh-scroll-post" class="wbh-post-select">
							<option value="">記事を選択...</option>
						</select>
					</div>
					<div class="wbh-toolbar-group">
						<label>期間:</label>
						<select id="wbh-scroll-period">
							<option value="7">過去7日</option>
							<option value="30" selected>過去30日</option>
							<option value="90">過去90日</option>
						</select>
					</div>
					<div class="wbh-toolbar-group">
						<label>デバイス:</label>
						<select id="wbh-scroll-device">
							<option value="all">すべて</option>
							<option value="sp">スマホ</option>
							<option value="pc">PC</option>
						</select>
					</div>
					<button class="button button-primary" id="wbh-scroll-reload">
						<span class="dashicons dashicons-update"></span> 表示
					</button>
				</div>

				<div class="wbh-stats-cards" id="wbh-scroll-stats" style="display:none;">
					<div class="wbh-card">
						<div class="wbh-card-icon"><span class="dashicons dashicons-arrow-down-alt2"></span></div>
						<div class="wbh-card-body">
							<div class="wbh-card-value" id="stat-avg-depth">-</div>
							<div class="wbh-card-label">平均到達率</div>
						</div>
					</div>
					<div class="wbh-card">
						<div class="wbh-card-icon"><span class="dashicons dashicons-yes-alt"></span></div>
						<div class="wbh-card-body">
							<div class="wbh-card-value" id="stat-completion">-</div>
							<div class="wbh-card-label">完読率(90%以上)</div>
						</div>
					</div>
					<div class="wbh-card">
						<div class="wbh-card-icon"><span class="dashicons dashicons-groups"></span></div>
						<div class="wbh-card-body">
							<div class="wbh-card-value" id="stat-scroll-total">-</div>
							<div class="wbh-card-label">セッション数</div>
						</div>
					</div>
				</div>

				<div class="wbh-heatmap-layout" id="wbh-scroll-content" style="display:none;">
					<div class="wbh-heatmap-main">
						<div class="wbh-section">
							<h4>読者到達率チャート</h4>
							<div class="wbh-chart-container">
								<canvas id="wbh-scroll-chart"></canvas>
							</div>
						</div>
						<div class="wbh-section">
							<h4>注目度カーブ</h4>
							<div class="wbh-chart-container">
								<canvas id="wbh-attention-chart"></canvas>
							</div>
						</div>
					</div>
					<div class="wbh-heatmap-sidebar">
						<div class="wbh-panel">
							<h4>深度別データ</h4>
							<div id="wbh-scroll-depth-bars"></div>
						</div>
					</div>
				</div>

				<div class="wbh-empty-state" id="wbh-scroll-empty">
					<span class="dashicons dashicons-arrow-down-alt2"></span>
					<p>記事を選択して「表示」をクリックしてください</p>
				</div>
			</div>

			<!-- ガイドタブ -->
			<div class="wbh-tab-content" id="tab-guide">
				<div class="wbh-guide">
					<div class="wbh-guide-hero">
						<h2>HINAKIRA Heatmap へようこそ</h2>
						<p>ブログ記事のパフォーマンスを3つの視点で可視化し、改善ポイントを見つけましょう。</p>
					</div>

					<div class="wbh-guide-steps">
						<div class="wbh-guide-step">
							<div class="wbh-step-number">1</div>
							<div class="wbh-step-content">
								<h3>トラッキング開始</h3>
								<p>プラグインを有効化すると、自動的にデータ収集が始まります。<br>
								軽量なJavaScript（約3KB）がページに挿入され、訪問者のクリック位置・スクロール深度・ページビューを匿名で記録します。</p>
								<div class="wbh-step-note">
									<span class="dashicons dashicons-shield"></span>
									IPアドレスやCookieの生データは保存しません（ユニーク推定に不可逆ハッシュのみ使用）
								</div>
							</div>
						</div>

						<div class="wbh-guide-step">
							<div class="wbh-step-number">2</div>
							<div class="wbh-step-content">
								<h3>データが溜まるのを待つ</h3>
								<p>最低24時間〜72時間のデータがあると、より正確な分析ができます。</p>
								<div class="wbh-data-status" id="wbh-guide-data-status">
									<div class="wbh-data-item">
										<span class="wbh-data-count" id="guide-pv-count">-</span>
										<span class="wbh-data-label">PV</span>
									</div>
									<div class="wbh-data-item">
										<span class="wbh-data-count" id="guide-click-count">-</span>
										<span class="wbh-data-label">クリック</span>
									</div>
									<div class="wbh-data-item">
										<span class="wbh-data-count" id="guide-scroll-count">-</span>
										<span class="wbh-data-label">スクロール</span>
									</div>
								</div>
							</div>
						</div>

						<div class="wbh-guide-step">
							<div class="wbh-step-number">3</div>
							<div class="wbh-step-content">
								<h3>パフォーマンスマップを確認</h3>
								<p>「パフォーマンス」タブで、どの記事がよく読まれているか一目で把握できます。<br>
								大きくて赤いブロックほどPVが多い記事です。</p>
							</div>
						</div>

						<div class="wbh-guide-step">
							<div class="wbh-step-number">4</div>
							<div class="wbh-step-content">
								<h3>クリック・スクロールを分析</h3>
								<p>個別記事のユーザー行動を詳しく分析しましょう。</p>
								<ul class="wbh-guide-list">
									<li><strong>クリックタブ:</strong> ページ上のどこがクリックされているか、ヒートマップで確認</li>
									<li><strong>スクロールタブ:</strong> 記事のどこまで読まれているか、到達率で確認</li>
								</ul>
							</div>
						</div>
					</div>

					<div class="wbh-guide-faq">
						<h3>よくある質問</h3>

						<div class="wbh-faq-item">
							<button class="wbh-faq-question">
								<span class="dashicons dashicons-arrow-right-alt2"></span>
								データはどこに保存されますか？
							</button>
							<div class="wbh-faq-answer">
								<p>すべてのデータはWordPressデータベース内の専用テーブルに保存されます。外部サービスへの送信は一切ありません。</p>
							</div>
						</div>

						<div class="wbh-faq-item">
							<button class="wbh-faq-question">
								<span class="dashicons dashicons-arrow-right-alt2"></span>
								サイトのパフォーマンスへの影響は？
							</button>
							<div class="wbh-faq-answer">
								<p>トラッキングスクリプトは約3KBと非常に軽量です。データ送信はページ離脱時にまとめて行うため、ページの読み込み速度への影響はほぼありません。</p>
							</div>
						</div>

						<div class="wbh-faq-item">
							<button class="wbh-faq-question">
								<span class="dashicons dashicons-arrow-right-alt2"></span>
								古いデータは自動削除されますか？
							</button>
							<div class="wbh-faq-answer">
								<p>はい。設定で指定した保持期間（デフォルト90日）を過ぎたデータは、毎日自動的にクリーンアップされます。</p>
							</div>
						</div>

						<div class="wbh-faq-item">
							<button class="wbh-faq-question">
								<span class="dashicons dashicons-arrow-right-alt2"></span>
								プライバシーについて
							</button>
							<div class="wbh-faq-answer">
								<p>IPアドレスやCookieの生データはデータベースに保存されません。ユニーク訪問者の推定にはIPの不可逆ハッシュ（毎日変更）を一時的に使用しますが、元のIPに復元することはできません。GDPR対応のプライバシーポリシー提案テキストも自動生成されます。</p>
							</div>
						</div>

						<div class="wbh-faq-item">
							<button class="wbh-faq-question">
								<span class="dashicons dashicons-arrow-right-alt2"></span>
								管理者のアクセスも記録されますか？
							</button>
							<div class="wbh-faq-answer">
								<p>デフォルトでは、ログインしているユーザーのアクセスは記録されません。「設定」タブから変更できます。</p>
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- 設定タブ -->
			<div class="wbh-tab-content" id="tab-settings">
				<div class="wbh-settings-form">
					<h3>基本設定</h3>

					<table class="form-table">
						<tr>
							<th scope="row">トラッキング</th>
							<td>
								<label>
									<input type="checkbox" id="wbh-set-tracking" <?php checked( ! empty( $settings['tracking_enabled'] ) ); ?>>
									データ収集を有効にする
								</label>
								<p class="description">無効にすると、フロントエンドのトラッキングスクリプトが読み込まれなくなります。</p>
							</td>
						</tr>
						<tr>
							<th scope="row">ログインユーザー</th>
							<td>
								<label>
									<input type="checkbox" id="wbh-set-logged-in" <?php checked( ! empty( $settings['track_logged_in'] ) ); ?>>
									ログインユーザーのアクセスも記録する
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">データ保持期間</th>
							<td>
								<input type="number" id="wbh-set-retention" value="<?php echo esc_attr( $settings['data_retention_days'] ?? 90 ); ?>" min="7" max="365" class="small-text"> 日
								<p class="description">指定日数を過ぎたデータは毎日自動的に削除されます（最小7日）</p>
							</td>
						</tr>
					</table>

					<div class="wbh-settings-actions">
						<button class="button button-primary" id="wbh-save-settings">
							<span class="dashicons dashicons-saved"></span> 設定を保存
						</button>
						<button class="button" id="wbh-manual-cleanup">
							<span class="dashicons dashicons-trash"></span> 古いデータを今すぐ削除
						</button>
					</div>

					<div id="wbh-settings-notice" class="wbh-notice" style="display:none;"></div>
				</div>
			</div>
		</div>
		<?php
	}
}
