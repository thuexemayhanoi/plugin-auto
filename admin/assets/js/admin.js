/**
 * Semantic SEO admin: confirmations, clipboard, job polling and (when WP-Cron is off) page-driven ticks.
 */
( function () {
	'use strict';
	var cfg = window.SSEO || {};

	document.addEventListener( 'submit', function ( e ) {
		var btn = e.submitter || ( e.target && e.target.querySelector( '[data-sseo-confirm]' ) );
		if ( btn && btn.getAttribute && btn.getAttribute( 'data-sseo-confirm' ) && ! window.confirm( btn.getAttribute( 'data-sseo-confirm' ) ) ) {
			e.preventDefault();
		}
	} );

	document.addEventListener( 'click', function ( e ) {
		var t = e.target.closest && e.target.closest( '[data-sseo-copy]' );
		if ( ! t ) {
			return;
		}
		var src = document.querySelector( t.getAttribute( 'data-sseo-copy' ) );
		if ( src && navigator.clipboard ) {
			navigator.clipboard.writeText( src.value || src.textContent ).then( function () {
				t.textContent = cfg.i18n.copied;
			} );
		}
	} );

	function api( path, method ) {
		return fetch( cfg.rest + path, {
			method: method || 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' }
		} ).then( function ( r ) {
			return r.json();
		} );
	}

	var active = Array.prototype.slice.call( document.querySelectorAll( '[data-sseo-job]' ) ).filter( function ( el ) {
		var s = el.getAttribute( 'data-sseo-status' );
		return s === 'queued' || s === 'running';
	} );
	if ( ! active.length || ! cfg.rest ) {
		return;
	}
	var delay = 4000;
	function poll() {
		var pending = active.length;
		active.forEach( function ( el ) {
			var id = el.getAttribute( 'data-sseo-job' );
			// Without WP-Cron, the open page advances the job in short bounded ticks.
			var req = cfg.cronOff ? api( '/jobs/' + id + '/tick', 'POST' ) : api( '/jobs/' + id );
			req.then( function ( res ) {
				if ( ! res || ! res.ok ) {
					return;
				}
				var job = res.data;
				var prog = document.querySelector( '[data-sseo-progress="' + id + '"]' );
				if ( prog ) {
					prog.textContent = job.progress_done + ' / ' + job.progress_total;
					var bar = prog.parentNode.querySelector( 'progress' );
					if ( bar && job.progress_pct !== null ) {
						bar.value = job.progress_pct;
					}
				}
				if ( [ 'completed', 'failed', 'cancelled' ].indexOf( job.status ) !== -1 ) {
					el.textContent = job.status === 'completed' ? cfg.i18n.completed : cfg.i18n.failed;
					el.setAttribute( 'data-sseo-status', job.status );
					var note = document.createElement( 'p' );
					note.className = 'sseo-notice';
					note.textContent = cfg.i18n.reload;
					el.parentNode.appendChild( note );
				}
			} ).catch( function () {
				delay = Math.min( delay * 2, 60000 );
			} ).finally( function () {
				pending--;
				if ( pending === 0 ) {
					active = active.filter( function ( x ) {
						var s = x.getAttribute( 'data-sseo-status' );
						return s === 'queued' || s === 'running';
					} );
					if ( active.length ) {
						window.setTimeout( poll, delay );
					}
				}
			} );
		} );
	}
	window.setTimeout( poll, 1500 );
} )();
