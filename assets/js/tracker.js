(function() {
	'use strict';

	if ( ! window.wbhConfig || ! window.wbhConfig.postId ) return;

	var C = window.wbhConfig;
	var clicks = [];
	var maxDepth = 0;
	var contentEl = null;
	var contentH = 0;
	var contentTop = 0;
	var vw = 0;
	var scrollTimer = null;
	var flushTimer = null;
	var sent = false;

	function init() {
		vw = Math.round( document.documentElement.clientWidth );
		contentEl = document.querySelector( '.entry-content' )
			|| document.querySelector( 'article' )
			|| document.querySelector( '.post-content' )
			|| document.querySelector( 'main' )
			|| document.body;

		var rect = contentEl.getBoundingClientRect();
		contentTop = rect.top + window.pageYOffset;
		contentH = contentEl.scrollHeight || contentEl.offsetHeight;

		// PV送信
		send( [{ type: 'pageview', post_id: C.postId, data: {} }] );

		// クリック
		document.addEventListener( 'click', onClickCapture, true );

		// スクロール
		window.addEventListener( 'scroll', onScroll, { passive: true } );
		onScroll(); // 初期位置記録

		// 5秒ごとにクリックバッファをフラッシュ
		flushTimer = setInterval( flushClicks, 5000 );

		// ページ離脱時にスクロール＆残クリックを送信
		document.addEventListener( 'visibilitychange', function() {
			if ( document.visibilityState === 'hidden' ) onUnload();
		});
		window.addEventListener( 'pagehide', onUnload );
	}

	function onClickCapture( e ) {
		var t = e.target;
		var tag = t.tagName ? t.tagName.toLowerCase() : '';
		var x = ( e.clientX / document.documentElement.clientWidth ) * 100;
		var y = e.pageY - contentTop;
		if ( y < 0 ) y = 0;

		clicks.push({
			type: 'click',
			post_id: C.postId,
			data: {
				x: Math.round( x * 100 ) / 100,
				y: Math.round( y ),
				vw: vw,
				el: tag.substring( 0, 30 )
			}
		});
	}

	function onScroll() {
		if ( scrollTimer ) return;
		scrollTimer = setTimeout( function() {
			scrollTimer = null;
			var scrollY = window.pageYOffset || document.documentElement.scrollTop;
			var viewH = document.documentElement.clientHeight;
			var depth = ( scrollY + viewH - contentTop ) / contentH * 100;
			depth = Math.max( 0, Math.min( 100, Math.round( depth ) ) );
			if ( depth > maxDepth ) maxDepth = depth;
		}, 200 );
	}

	function flushClicks() {
		if ( clicks.length === 0 ) return;
		var batch = clicks.splice( 0, clicks.length );
		send( batch );
	}

	function onUnload() {
		if ( sent ) return;
		sent = true;
		clearInterval( flushTimer );

		var batch = clicks.splice( 0, clicks.length );
		// スクロールデータ追加
		batch.push({
			type: 'scroll',
			post_id: C.postId,
			data: {
				depth: maxDepth,
				ch: contentH,
				vw: vw
			}
		});

		send( batch, true );
	}

	function send( items, useBeacon ) {
		if ( items.length === 0 ) return;

		for ( var i = 0; i < items.length; i++ ) {
			var payload = JSON.stringify( items[i] );

			if ( useBeacon && navigator.sendBeacon ) {
				navigator.sendBeacon(
					C.apiUrl,
					new Blob( [payload], { type: 'application/json' } )
				);
			} else {
				try {
					fetch( C.apiUrl, {
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce': C.nonce
						},
						body: payload,
						keepalive: true
					} );
				} catch(e) {}
			}
		}
	}

	// DOM準備完了後に初期化
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
})();
