(function($) {
	'use strict';

	var D = window.wbhDashboard || {};
	var L = D.i18n || {};
	var scrollChart = null;
	var attentionChart = null;

	// ========================================
	// 初期化
	// ========================================
	$(document).ready(function() {
		initTabs();
		initFAQ();
		loadOverviewStats();
		loadPerformanceMap();
		loadTrackedPosts();
		initSettingsHandlers();
	});

	// ========================================
	// タブ管理
	// ========================================
	function initTabs() {
		$('.wbh-tab').on('click', function() {
			var tab = $(this).data('tab');
			$('.wbh-tab').removeClass('active');
			$(this).addClass('active');
			$('.wbh-tab-content').removeClass('active');
			$('#tab-' + tab).addClass('active');

			// URLハッシュ更新
			window.history.replaceState(null, '', '#' + tab);
		});

		// URLハッシュからタブ復元
		var hash = window.location.hash.replace('#', '');
		if (hash && $('.wbh-tab[data-tab="' + hash + '"]').length) {
			$('.wbh-tab[data-tab="' + hash + '"]').trigger('click');
		}
	}

	// ========================================
	// FAQ アコーディオン
	// ========================================
	function initFAQ() {
		$('.wbh-faq-question').on('click', function() {
			$(this).closest('.wbh-faq-item').toggleClass('open');
		});
	}

	// ========================================
	// 概要統計の読み込み
	// ========================================
	function loadOverviewStats() {
		ajax('wbh_get_overview_stats', {}, function(data) {
			$('#stat-post-count').text(numberFormat(data.tracked_posts));
			$('#guide-pv-count').text(numberFormat(data.total_pv));
			$('#guide-click-count').text(numberFormat(data.total_clicks));
			$('#guide-scroll-count').text(numberFormat(data.total_scrolls));
		});
	}

	// ========================================
	// パフォーマンスマップ
	// ========================================
	function loadPerformanceMap() {
		var period = $('#wbh-perf-period').val();
		var postType = $('#wbh-perf-posttype').val();
		var dateTo = todayStr();
		var dateFrom = daysAgo(period);

		$('#wbh-treemap').html('<div class="wbh-loading"><span class="spinner is-active"></span> ' + escHtml(L.loadingData || 'データを読み込み中...') + '</div>');

		ajax('wbh_get_performance_map', {
			date_from: dateFrom,
			date_to: dateTo,
			post_types: [postType]
		}, function(data) {
			renderPerformanceStats(data);
			renderTreemap(data);
			renderPerformanceTable(data);
		}, function() {
			$('#wbh-treemap').html('<div class="wbh-empty-state"><p>' + escHtml(L.loadFailed || 'データの取得に失敗しました') + '</p></div>');
		});
	}

	$('#wbh-perf-reload').on('click', loadPerformanceMap);

	function renderPerformanceStats(data) {
		var totalPV = 0;
		var topPost = '-';
		var topPV = 0;

		for (var i = 0; i < data.length; i++) {
			var pv = parseInt(data[i].total_pv, 10);
			totalPV += pv;
			if (pv > topPV) {
				topPV = pv;
				topPost = data[i].post_title;
			}
		}

		var avgPV = data.length > 0 ? Math.round(totalPV / data.length) : 0;

		$('#stat-total-pv').text(numberFormat(totalPV));
		$('#stat-avg-pv').text(numberFormat(avgPV));
		$('#stat-top-post').text(truncate(topPost, 20));
		$('#stat-post-count').text(numberFormat(data.length));
	}

	function renderTreemap(data) {
		var $map = $('#wbh-treemap');
		$map.empty();

		if (data.length === 0) {
			$map.html('<div class="wbh-empty-state"><span class="dashicons dashicons-chart-area"></span><p>' + escHtml(L.noData || 'データがまだありません') + '</p></div>');
			return;
		}

		var maxPV = parseInt(data[0].total_pv, 10) || 1;
		var totalPV = 0;
		for (var i = 0; i < data.length; i++) {
			totalPV += parseInt(data[i].total_pv, 10);
		}

		var mapWidth = $map.width();
		var mapHeight = Math.max(300, Math.min(500, data.length * 8));
		$map.css('height', mapHeight + 'px');

		// 簡易ツリーマップ: 面積をPVに比例させる
		var totalArea = mapWidth * mapHeight;

		for (var i = 0; i < Math.min(data.length, 100); i++) {
			var item = data[i];
			var pv = parseInt(item.total_pv, 10);
			var ratio = pv / totalPV;
			var area = totalArea * ratio;
			var width = Math.max(80, Math.sqrt(area * (mapWidth / mapHeight)));
			var height = Math.max(50, area / width);

			// 色: PV比率に応じて緑→黄→赤
			var intensity = pv / maxPV;
			var color = pvColor(intensity);

			var $cell = $('<div class="wbh-treemap-cell"></div>')
				.css({
					width: width + 'px',
					height: height + 'px',
					backgroundColor: color,
					flexGrow: ratio * 100
				})
				.attr('data-post-id', item.post_id)
				.attr('data-title', item.post_title)
				.attr('data-pv', pv)
				.attr('data-unique', item.total_unique);

			if (width > 100 && height > 40) {
				$cell.html(
					'<span class="wbh-cell-title">' + escHtml(truncate(item.post_title, 30)) + '</span>' +
					'<span class="wbh-cell-pv">' + numberFormat(pv) + '</span>'
				);
			} else if (width > 60) {
				$cell.html('<span class="wbh-cell-pv">' + numberFormat(pv) + '</span>');
			}

			$map.append($cell);
		}

		// ツールチップ
		var $tooltip = $('<div class="wbh-tooltip" style="display:none;"></div>');
		$('body').append($tooltip);

		$map.on('mouseenter', '.wbh-treemap-cell', function(e) {
			var $el = $(this);
			$tooltip.html(
				'<strong>' + escHtml(String($el.data('title') || '')) + '</strong>' +
				'PV: ' + escHtml(numberFormat($el.data('pv'))) + '<br>' +
				'ユニーク: ' + escHtml(numberFormat($el.data('unique')))
			).show();
		}).on('mousemove', '.wbh-treemap-cell', function(e) {
			$tooltip.css({ top: e.clientY + 12, left: e.clientX + 12 });
		}).on('mouseleave', '.wbh-treemap-cell', function() {
			$tooltip.hide();
		}).on('click', '.wbh-treemap-cell', function() {
			var postId = $(this).data('post-id');
			// クリックタブに遷移してこの記事を選択
			$('#wbh-click-post').val(postId);
			$('.wbh-tab[data-tab="clicks"]').trigger('click');
			$('#wbh-click-reload').trigger('click');
		});
	}

	function renderPerformanceTable(data) {
		var $tbody = $('#wbh-perf-tbody');
		$tbody.empty();

		if (data.length === 0) {
			$tbody.html('<tr><td colspan="5" style="text-align:center;padding:20px;color:#787c82;">' + escHtml(L.noDataTable || 'データがありません') + '</td></tr>');
			return;
		}

		var maxPV = parseInt(data[0].total_pv, 10) || 1;

		for (var i = 0; i < data.length; i++) {
			var item = data[i];
			var pv = parseInt(item.total_pv, 10);
			var barWidth = Math.round((pv / maxPV) * 100);

			$tbody.append(
				'<tr>' +
				'<td>' + (i + 1) + '</td>' +
				'<td><a href="' + escAttr(getEditUrl(item.post_id)) + '" target="_blank">' + escHtml(item.post_title) + '</a></td>' +
				'<td><span class="wbh-pv-bar" style="width:' + barWidth + 'px;"></span>' + numberFormat(pv) + '</td>' +
				'<td>' + numberFormat(item.total_unique) + '</td>' +
				'<td class="wbh-table-actions">' +
				'<a href="#" class="wbh-view-clicks" data-id="' + item.post_id + '" title="クリック分析">クリック</a>' +
				'<a href="#" class="wbh-view-scroll" data-id="' + item.post_id + '" title="スクロール分析">スクロール</a>' +
				'</td>' +
				'</tr>'
			);
		}

		// テーブルからの遷移リンク
		$tbody.on('click', '.wbh-view-clicks', function(e) {
			e.preventDefault();
			var postId = $(this).data('id');
			$('#wbh-click-post').val(postId);
			$('.wbh-tab[data-tab="clicks"]').trigger('click');
			$('#wbh-click-reload').trigger('click');
		});

		$tbody.on('click', '.wbh-view-scroll', function(e) {
			e.preventDefault();
			var postId = $(this).data('id');
			$('#wbh-scroll-post').val(postId);
			$('.wbh-tab[data-tab="scroll"]').trigger('click');
			$('#wbh-scroll-reload').trigger('click');
		});

		// ソート
		$('.wbh-sortable').off('click').on('click', function() {
			var $th = $(this);
			var sort = $th.data('sort');
			var asc = $th.hasClass('asc');
			$('.wbh-sortable').removeClass('asc desc');
			$th.addClass(asc ? 'desc' : 'asc');

			var rows = $tbody.find('tr').get();
			rows.sort(function(a, b) {
				var colIdx = $th.index();
				var aVal = $(a).find('td').eq(colIdx).text().replace(/[^0-9.-]/g, '');
				var bVal = $(b).find('td').eq(colIdx).text().replace(/[^0-9.-]/g, '');
				var diff = parseFloat(aVal || 0) - parseFloat(bVal || 0);
				return asc ? diff : -diff;
			});
			$tbody.append(rows);
		});
	}

	// ========================================
	// 記事一覧読み込み（セレクトボックス用）
	// ========================================
	function loadTrackedPosts() {
		ajax('wbh_get_tracked_posts', {}, function(data) {
			var options = '<option value="">記事を選択...</option>';
			for (var i = 0; i < data.length; i++) {
				options += '<option value="' + data[i].post_id + '">' + escHtml(data[i].post_title) + '</option>';
			}
			$('.wbh-post-select').html(options);
		});
	}

	// ========================================
	// クリックヒートマップ
	// ========================================
	$('#wbh-click-reload').on('click', function() {
		var postId = $('#wbh-click-post').val();
		if (!postId) {
			alert(L.selectPost || '記事を選択してください');
			return;
		}

		var period = $('#wbh-click-period').val();
		var device = $('#wbh-click-device').val();

		$('#wbh-click-empty').hide();
		$('#wbh-click-stats').show();
		$('#wbh-click-content').show();

		ajax('wbh_get_click_heatmap', {
			post_id: postId,
			date_from: daysAgo(period),
			date_to: todayStr(),
			viewport: device
		}, function(data) {
			renderClickStats(data);
			renderClickHeatmap(data);
			renderClickElements(data.elements);
		});
	});

	function renderClickStats(data) {
		$('#stat-total-clicks').text(numberFormat(data.total));
		var topEl = '-';
		if (data.elements) {
			var keys = Object.keys(data.elements);
			if (keys.length > 0) topEl = '<' + keys[0] + '>';
		}
		$('#stat-top-element').text(topEl);
	}

	function renderClickHeatmap(data) {
		var iframe = document.getElementById('wbh-click-iframe');
		var canvas = document.getElementById('wbh-click-canvas');

		if (!data.post_url) return;

		// URLのプロトコルをhttp/httpsに制限（javascript:等を防止）
		var url = data.post_url;
		if (url.indexOf('http://') !== 0 && url.indexOf('https://') !== 0) return;
		iframe.src = url;

		iframe.onload = function() {
			// iframeのコンテンツサイズに合わせてcanvasを調整
			try {
				var doc = iframe.contentDocument || iframe.contentWindow.document;
				var h = doc.documentElement.scrollHeight;
				canvas.width = iframe.offsetWidth;
				canvas.height = h;
				$('#wbh-click-iframe-wrap').css('height', Math.min(h, 800) + 'px');
			} catch(e) {
				canvas.width = iframe.offsetWidth;
				canvas.height = 800;
			}

			drawClickDots(canvas, data.clicks);
		};
	}

	function drawClickDots(canvas, clicks) {
		var ctx = canvas.getContext('2d');
		ctx.clearRect(0, 0, canvas.width, canvas.height);

		if (!clicks || clicks.length === 0) return;

		for (var i = 0; i < clicks.length; i++) {
			var c = clicks[i];
			var x = (parseFloat(c.x_pct) / 100) * canvas.width;
			var y = parseInt(c.y_px, 10);

			// グロー効果
			var gradient = ctx.createRadialGradient(x, y, 0, x, y, 20);
			gradient.addColorStop(0, 'rgba(214, 54, 56, 0.6)');
			gradient.addColorStop(0.5, 'rgba(214, 54, 56, 0.2)');
			gradient.addColorStop(1, 'rgba(214, 54, 56, 0)');

			ctx.beginPath();
			ctx.arc(x, y, 20, 0, Math.PI * 2);
			ctx.fillStyle = gradient;
			ctx.fill();

			// 中心点
			ctx.beginPath();
			ctx.arc(x, y, 3, 0, Math.PI * 2);
			ctx.fillStyle = 'rgba(214, 54, 56, 0.9)';
			ctx.fill();
		}
	}

	function renderClickElements(elements) {
		var $container = $('#wbh-click-elements');
		$container.empty();

		if (!elements) return;

		var keys = Object.keys(elements);
		if (keys.length === 0) {
			$container.html('<p style="color:#787c82;font-size:13px;">データなし</p>');
			return;
		}

		var max = elements[keys[0]];

		for (var i = 0; i < Math.min(keys.length, 10); i++) {
			var tag = keys[i];
			var count = elements[tag];
			var pct = Math.round((count / max) * 100);

			$container.append(
				'<div class="wbh-element-bar">' +
				'<span class="wbh-element-name">&lt;' + escHtml(tag) + '&gt;</span>' +
				'<span class="wbh-element-fill"><span class="wbh-element-fill-inner" style="width:' + pct + '%;"></span></span>' +
				'<span class="wbh-element-count">' + numberFormat(count) + '</span>' +
				'</div>'
			);
		}
	}

	// ========================================
	// スクロールヒートマップ
	// ========================================
	$('#wbh-scroll-reload').on('click', function() {
		var postId = $('#wbh-scroll-post').val();
		if (!postId) {
			alert(L.selectPost || '記事を選択してください');
			return;
		}

		var period = $('#wbh-scroll-period').val();
		var device = $('#wbh-scroll-device').val();

		$('#wbh-scroll-empty').hide();
		$('#wbh-scroll-stats').show();
		$('#wbh-scroll-content').show();

		ajax('wbh_get_scroll_data', {
			post_id: postId,
			date_from: daysAgo(period),
			date_to: todayStr(),
			viewport: device
		}, function(data) {
			renderScrollStats(data);
			renderScrollChart(data);
			renderAttentionChart(data);
			renderScrollDepthBars(data);
		});
	});

	function renderScrollStats(data) {
		$('#stat-avg-depth').text(data.avg_depth + '%');
		var completion = data.distribution && data.distribution.length >= 10 ? data.distribution[8] : 0;
		$('#stat-completion').text(completion + '%');
		$('#stat-scroll-total').text(numberFormat(data.total));
	}

	function renderScrollChart(data) {
		var ctx = document.getElementById('wbh-scroll-chart');
		if (!ctx) return;

		if (scrollChart) scrollChart.destroy();

		var labels = [];
		var values = [];
		var colors = [];

		for (var i = 0; i < 10; i++) {
			labels.push((i * 10 + 1) + '-' + ((i + 1) * 10) + '%');
			var val = data.distribution ? data.distribution[i] : 0;
			values.push(val);

			// 緑→黄→赤のグラデーション（到達率が低いほど赤）
			var ratio = val / 100;
			colors.push(depthColor(ratio));
		}

		scrollChart = new Chart(ctx, {
			type: 'bar',
			data: {
				labels: labels,
				datasets: [{
					label: '到達率 (%)',
					data: values,
					backgroundColor: colors,
					borderRadius: 4,
					borderSkipped: false
				}]
			},
			options: {
				indexAxis: 'y',
				responsive: true,
				maintainAspectRatio: false,
				plugins: {
					legend: { display: false },
					tooltip: {
						callbacks: {
							label: function(ctx) {
								return '読者の ' + ctx.raw + '% が到達';
							}
						}
					}
				},
				scales: {
					x: {
						max: 100,
						grid: { color: '#f0f0f1' },
						ticks: { callback: function(v) { return v + '%'; } }
					},
					y: {
						grid: { display: false }
					}
				}
			}
		});
	}

	function renderAttentionChart(data) {
		var ctx = document.getElementById('wbh-attention-chart');
		if (!ctx) return;

		if (attentionChart) attentionChart.destroy();

		var labels = [];
		var values = [];

		// 100%から開始して、各深度での到達率を描画
		labels.push('0%');
		values.push(100);
		for (var i = 0; i < 10; i++) {
			labels.push(((i + 1) * 10) + '%');
			values.push(data.distribution ? data.distribution[i] : 0);
		}

		attentionChart = new Chart(ctx, {
			type: 'line',
			data: {
				labels: labels,
				datasets: [{
					label: '読者残存率',
					data: values,
					borderColor: '#2271b1',
					backgroundColor: 'rgba(34, 113, 177, 0.1)',
					fill: true,
					tension: 0.3,
					pointRadius: 4,
					pointHoverRadius: 6,
					pointBackgroundColor: '#2271b1'
				}]
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				plugins: {
					legend: { display: false },
					tooltip: {
						callbacks: {
							label: function(ctx) {
								return '読者の ' + ctx.raw + '% が残存';
							}
						}
					}
				},
				scales: {
					y: {
						min: 0,
						max: 100,
						grid: { color: '#f0f0f1' },
						ticks: { callback: function(v) { return v + '%'; } }
					},
					x: {
						title: { display: true, text: 'スクロール深度' },
						grid: { color: '#f0f0f1' }
					}
				}
			}
		});
	}

	function renderScrollDepthBars(data) {
		var $container = $('#wbh-scroll-depth-bars');
		$container.empty();

		for (var i = 0; i < 10; i++) {
			var pct = data.distribution ? data.distribution[i] : 0;
			var color = depthColor(pct / 100);
			var label = ((i + 1) * 10) + '%';

			$container.append(
				'<div class="wbh-depth-bar-item">' +
				'<span class="wbh-depth-label">' + label + '</span>' +
				'<span class="wbh-depth-bar-bg"><span class="wbh-depth-bar-fill" style="width:' + pct + '%;background:' + color + ';"></span></span>' +
				'<span class="wbh-depth-pct">' + pct + '%</span>' +
				'</div>'
			);
		}
	}

	// ========================================
	// 設定
	// ========================================
	function initSettingsHandlers() {
		$('#wbh-save-settings').on('click', function() {
			var $btn = $(this).prop('disabled', true);

			ajax('wbh_update_settings', {
				tracking_enabled: $('#wbh-set-tracking').is(':checked') ? 1 : 0,
				track_logged_in: $('#wbh-set-logged-in').is(':checked') ? 1 : 0,
				data_retention_days: $('#wbh-set-retention').val()
			}, function(data) {
				showNotice('success', data.message);
				$btn.prop('disabled', false);
			}, function() {
				showNotice('error', L.saveFailed || '設定の保存に失敗しました');
				$btn.prop('disabled', false);
			});
		});

		$('#wbh-manual-cleanup').on('click', function() {
			if (!confirm(L.cleanupConfirm || '保持期間を過ぎた古いデータを削除します。よろしいですか？')) return;
			var $btn = $(this).prop('disabled', true);

			ajax('wbh_cleanup_now', {}, function(data) {
				showNotice('success', data.message);
				$btn.prop('disabled', false);
			}, function() {
				showNotice('error', L.cleanupFailed || 'クリーンアップに失敗しました');
				$btn.prop('disabled', false);
			});
		});
	}

	function showNotice(type, msg) {
		$('#wbh-settings-notice')
			.removeClass('success error')
			.addClass(type)
			.text(msg)
			.show()
			.delay(4000)
			.fadeOut();
	}

	// ========================================
	// ユーティリティ
	// ========================================
	function ajax(action, data, onSuccess, onError) {
		data.action = action;
		data.nonce = D.nonce;

		$.post(D.ajaxUrl, data, function(response) {
			if (response.success && onSuccess) {
				onSuccess(response.data);
			} else if (!response.success && onError) {
				onError(response);
			}
		}).fail(function() {
			if (onError) onError();
		});
	}

	function todayStr() {
		var d = new Date();
		return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
	}

	function daysAgo(n) {
		var d = new Date();
		d.setDate(d.getDate() - parseInt(n, 10));
		return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
	}

	function pad(n) {
		return n < 10 ? '0' + n : '' + n;
	}

	function numberFormat(n) {
		return parseInt(n || 0, 10).toLocaleString();
	}

	function truncate(str, len) {
		if (!str) return '';
		return str.length > len ? str.substring(0, len) + '...' : str;
	}

	function escHtml(str) {
		if (!str) return '';
		return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}

	function escAttr(str) {
		return escHtml(str);
	}

	function getEditUrl(postId) {
		return D.ajaxUrl.replace('admin-ajax.php', 'post.php?post=' + postId + '&action=edit');
	}

	function pvColor(intensity) {
		// 0 = 緑, 0.5 = 黄, 1.0 = 赤
		var r, g, b;
		if (intensity < 0.5) {
			var t = intensity * 2;
			r = Math.round(0 + t * 240);
			g = Math.round(163 - t * 43);
			b = Math.round(42 - t * 15);
		} else {
			var t = (intensity - 0.5) * 2;
			r = Math.round(240 - t * 26);
			g = Math.round(120 - t * 66);
			b = Math.round(27 + t * 29);
		}
		return 'rgb(' + r + ',' + g + ',' + b + ')';
	}

	function depthColor(ratio) {
		// 高到達 = 緑, 低到達 = 赤
		if (ratio > 0.7) return '#00a32a';
		if (ratio > 0.5) return '#7ad03a';
		if (ratio > 0.3) return '#f0b849';
		if (ratio > 0.15) return '#e27730';
		return '#d63638';
	}

})(jQuery);
