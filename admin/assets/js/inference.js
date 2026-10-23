/**
 * Tier 1 browser inference controller (Jobs & Inference Device page).
 *
 * Flow: detect device → explicit consent → load + verify model in a module worker → self-test
 * against the model-card reference → claim / embed / complete loop with pause and stop.
 * The lease token stays in this script; the worker only sees text.
 */
( function () {
	'use strict';

	var cfg = window.SSEO_MODEL;
	var api = window.SSEO;
	var root = document.getElementById( 'sseo-inference' );
	if ( ! cfg || ! api || ! root ) {
		return;
	}
	var t = cfg.i18n;
	var el = {
		status: root.querySelector( '[data-sseo-device-status]' ),
		facts: root.querySelector( '[data-sseo-device-facts]' ),
		consent: root.querySelector( '[data-sseo-consent]' ),
		log: root.querySelector( '[data-sseo-log]' ),
		btn: {},
	};
	[ 'detect', 'load', 'start', 'pause', 'cancel' ].forEach( function ( a ) {
		el.btn[ a ] = root.querySelector( '[data-sseo-action="' + a + '"]' );
	} );

	var run = {
		caps: null,
		device: null,
		worker: null,
		ready: false,
		verified: false,
		active: false,
		paused: false,
		stopped: false,
		lease: '',
		seq: 0,
		pending: {},
		stored: 0,
	};

	function log( msg, level ) {
		if ( ! el.log ) {
			return;
		}
		var p = document.createElement( 'p' );
		p.className = 'sseo-log-line' + ( level ? ' is-' + level : '' );
		p.textContent = new Date().toLocaleTimeString() + ' — ' + msg;
		el.log.appendChild( p );
		while ( el.log.childNodes.length > 200 ) {
			el.log.removeChild( el.log.firstChild );
		}
		el.log.scrollTop = el.log.scrollHeight;
	}

	function setStatus( msg ) {
		if ( el.status ) {
			el.status.textContent = msg;
		}
	}

	function setButtons() {
		var can = !! cfg.canEmbed;
		if ( el.btn.load ) {
			el.btn.load.disabled = ! can || run.active || ! cfg.vendorReady || ( run.caps && ! run.device );
		}
		if ( el.btn.start ) {
			el.btn.start.disabled = ! can || ! run.verified || ( run.active && ! run.paused );
		}
		if ( el.btn.pause ) {
			el.btn.pause.disabled = ! run.active || run.paused;
		}
		if ( el.btn.cancel ) {
			el.btn.cancel.disabled = ! run.active;
		}
	}

	function fact( label, value ) {
		var li = document.createElement( 'li' );
		var b = document.createElement( 'strong' );
		b.textContent = label + ': ';
		li.appendChild( b );
		li.appendChild( document.createTextNode( value ) );
		el.facts.appendChild( li );
	}

	function simdSupported() {
		try {
			// Minimal module using a v128 instruction; validate() is synchronous and side-effect free.
			return WebAssembly.validate(
				new Uint8Array( [ 0, 97, 115, 109, 1, 0, 0, 0, 1, 5, 1, 96, 0, 1, 123, 3, 2, 1, 0, 10, 10, 1, 8, 0, 65, 0, 253, 15, 253, 98, 11 ] )
			);
		} catch ( e ) {
			return false;
		}
	}

	function moduleWorkerSupported() {
		var supported = false;
		try {
			var opts = {};
			Object.defineProperty( opts, 'type', {
				get: function () {
					supported = true;
					return 'module';
				},
			} );
			var w = new Worker( 'data:text/javascript,', opts );
			w.terminate();
		} catch ( e ) {
			// Ignore: detection only.
		}
		return supported;
	}

	function isIos() {
		var ua = navigator.userAgent || '';
		return /iPad|iPhone|iPod/.test( ua ) || ( navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1 );
	}

	function safariMajor() {
		var ua = navigator.userAgent || '';
		if ( ! /Safari\//.test( ua ) || /Chrome|Chromium|CriOS|Edg|FxiOS/.test( ua ) ) {
			return 0;
		}
		var m = ua.match( /Version\/(\d+)/ );
		return m ? parseInt( m[ 1 ], 10 ) : 0;
	}

	function chooseDevice( caps, dtype ) {
		// Mirrors chooseDevice() in assets/js/ai-worker/embedder-core.js (unit-tested there).
		if ( ! caps.moduleWorker ) {
			return { device: null, reason: 'module-workers-unsupported' };
		}
		var needsF16 = /f16/.test( dtype );
		if ( caps.webgpu && ( ! needsF16 || caps.shaderF16 ) ) {
			return { device: 'webgpu', reason: 'webgpu' };
		}
		if ( caps.wasm && caps.simd ) {
			return { device: 'wasm', reason: caps.webgpu ? 'webgpu-without-f16' : 'wasm' };
		}
		return { device: null, reason: 'no-webgpu-no-wasm-simd' };
	}

	function detect() {
		var caps = {
			webgpu: false,
			shaderF16: false,
			wasm: typeof WebAssembly === 'object' && typeof WebAssembly.validate === 'function',
			simd: false,
			moduleWorker: typeof Worker === 'function' && moduleWorkerSupported(),
			isolated: !! window.crossOriginIsolated,
			threads: navigator.hardwareConcurrency || 1,
			memory: navigator.deviceMemory || 0,
			ios: isIos(),
			safari: safariMajor(),
		};
		caps.simd = caps.wasm && simdSupported();
		var gpu = navigator.gpu && typeof navigator.gpu.requestAdapter === 'function' ? navigator.gpu.requestAdapter() : Promise.resolve( null );
		return Promise.resolve( gpu )
			.catch( function () {
				return null;
			} )
			.then( function ( adapter ) {
				caps.webgpu = !! adapter;
				caps.shaderF16 = !! ( adapter && adapter.features && adapter.features.has( 'shader-f16' ) );
				run.caps = caps;
				var pick = chooseDevice( caps, cfg.profile.dtype );
				run.device = pick.device;
				el.facts.textContent = '';
				fact( 'WebGPU', caps.webgpu ? t.yes + ( caps.shaderF16 ? ' (shader-f16)' : ' (' + t.noF16 + ')' ) : t.no );
				fact( 'WebAssembly SIMD', caps.simd ? t.yes : t.no );
				fact( t.threads, caps.isolated ? String( caps.threads ) : t.oneThread );
				if ( caps.memory ) {
					fact( t.memory, '≈' + caps.memory + ' GB' );
				}
				if ( caps.ios ) {
					fact( 'iOS / iPadOS', t.iosNote );
				}
				if ( ! cfg.vendorReady ) {
					setStatus( t.noVendor );
				} else if ( ! pick.device ) {
					setStatus( t.unsupported );
				} else {
					setStatus( pick.device === 'webgpu' ? 'WebGPU' : t.wasmSlower );
					if ( pick.device === 'wasm' && /f16/.test( cfg.profile.dtype ) ) {
						log( t.f16OnWasm.replace( '%s', cfg.profile.dtype ), 'warning' );
					}
				}
				setButtons();
				return caps;
			} );
	}

	function rest( path, body, keepalive ) {
		return fetch( api.rest + path, {
			method: body ? 'POST' : 'GET',
			credentials: 'same-origin',
			keepalive: !! keepalive,
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': api.nonce },
			body: body ? JSON.stringify( body ) : undefined,
		} ).then( function ( r ) {
			return r
				.json()
				.catch( function () {
					return {};
				} )
				.then( function ( json ) {
					if ( ! r.ok || ! json.ok ) {
						var err = new Error( ( json.error && json.error.message ) || 'HTTP ' + r.status );
						err.status = r.status;
						err.retryAfter = parseInt( r.headers.get( 'Retry-After' ) || '0', 10 );
						throw err;
					}
					return json.data;
				} );
		} );
	}

	function ask( msg ) {
		run.seq += 1;
		var id = run.seq;
		msg.id = id;
		return new Promise( function ( resolve, reject ) {
			run.pending[ id ] = { resolve: resolve, reject: reject };
			run.worker.postMessage( msg );
		} );
	}

	function onWorker( ev ) {
		var m = ev.data || {};
		if ( m.type === 'progress' ) {
			var pct = m.total ? Math.floor( ( m.loaded / m.total ) * 100 ) : 0;
			setStatus( t.loading + ' ' + m.file + ' ' + pct + '%' + ( m.cached ? ' (' + t.cached + ')' : '' ) );
			return;
		}
		if ( m.type === 'ready' ) {
			run.ready = true;
			log( t.loaded + ': ' + m.device + ' / ' + m.dtype + ' (' + Math.round( m.ms / 1000 ) + ' s)' );
			if ( run.pending.init ) {
				run.pending.init.resolve( m );
				delete run.pending.init;
			}
			return;
		}
		var p = run.pending[ m.id ] || ( m.type === 'error' && run.pending.init );
		if ( ! p ) {
			return;
		}
		delete run.pending[ m.id ];
		if ( m.type === 'error' ) {
			if ( run.pending.init === p ) {
				delete run.pending.init;
			}
			p.reject( new Error( m.message ) );
		} else {
			p.resolve( m );
		}
	}

	function load() {
		if ( ! el.consent || ! el.consent.checked ) {
			log( t.consent, 'warning' );
			if ( el.consent ) {
				el.consent.focus();
			}
			return;
		}
		var ready = run.caps ? Promise.resolve( run.caps ) : detect();
		ready
			.then( function () {
				if ( ! run.device || ! cfg.vendorReady ) {
					throw new Error( cfg.vendorReady ? t.unsupported : t.noVendor );
				}
				if ( run.worker ) {
					run.worker.terminate();
				}
				run.worker = new Worker( cfg.workerUrl, { type: 'module' } );
				run.worker.onmessage = onWorker;
				run.worker.onerror = function ( e ) {
					log( t.workerError + ' ' + ( e.message || '' ), 'error' );
				};
				el.btn.load.disabled = true;
				setStatus( t.loading );
				return new Promise( function ( resolve, reject ) {
					run.pending.init = { resolve: resolve, reject: reject };
					run.worker.postMessage( {
						type: 'init',
						vendorUrl: cfg.vendorUrl,
						profile: cfg.profile,
						device: run.device,
						wasmSuffix: run.caps.safari && run.caps.safari < 26 && ! run.caps.webgpu ? '' : '.asyncify',
						threads: run.caps.isolated ? run.caps.threads : 1,
					} );
				} );
			} )
			.then( function () {
				return ask( { type: 'selftest', reference: cfg.reference } );
			} )
			.then( function ( r ) {
				run.verified = !! r.ok;
				var m = r.matrix
					.map( function ( row ) {
						return row
							.map( function ( x ) {
								return x.toFixed( 4 );
							} )
							.join( ' ' );
					} )
					.join( ' | ' );
				log( ( r.ok ? t.selftestOk : t.selftestFail ) + ' [' + m + '] max Δ ' + r.maxDiff.toFixed( 4 ) + ' ≤ ' + r.tolerance, r.ok ? '' : 'error' );
				setStatus( r.ok ? t.selftestOk : t.selftestFail );
			} )
			.catch( function ( err ) {
				run.verified = false;
				setStatus( err.message );
				log( err.message, 'error' );
			} )
			.then( setButtons );
	}

	function release( reason, keepalive ) {
		if ( ! run.lease ) {
			return Promise.resolve();
		}
		var token = run.lease;
		run.lease = '';
		return rest( '/embeddings/release', { lease_token: token, error: String( reason || '' ).slice( 0, 240 ) }, keepalive ).catch( function () {
			// The lease expires on its own if this fails.
		} );
	}

	function wait( ms ) {
		return new Promise( function ( r ) {
			setTimeout( r, ms );
		} );
	}

	function loop() {
		if ( run.stopped || run.paused ) {
			run.active = run.paused;
			setButtons();
			return Promise.resolve();
		}
		return rest( '/embeddings/claim', { limit: run.device === 'webgpu' ? 8 : 4 } )
			.then( function ( claim ) {
				if ( claim.profile.model_key !== cfg.profile.model_key ) {
					run.lease = claim.lease_token;
					throw new Error( t.profileChanged );
				}
				if ( ! claim.items.length ) {
					log( t.idle );
					run.active = false;
					run.stopped = true;
					return null;
				}
				run.lease = claim.lease_token;
				return ask( { type: 'embed', items: claim.items } ).then( function ( r ) {
					if ( run.stopped ) {
						return release( 'stopped by user' );
					}
					var token = run.lease;
					run.lease = '';
					return rest( '/embeddings/complete', {
						lease_token: token,
						model_key: cfg.profile.model_key,
						source: 'browser',
						results: r.results,
					} ).then( function ( res ) {
						run.stored += res.stored;
						log( t.batchDone.replace( '%1$d', res.stored ).replace( '%2$d', res.rejected.length ).replace( '%3$d', Math.round( r.ms ) ).replace( '%4$d', run.stored ) );
						res.rejected.forEach( function ( x ) {
							log( '#' + x.queue_id + ': ' + x.reason, 'warning' );
						} );
					} );
				} );
			} )
			.then( function ( done ) {
				if ( done === null ) {
					setButtons();
					return null;
				}
				return loop();
			} )
			.catch( function ( err ) {
				if ( err.status === 429 ) {
					log( err.message, 'warning' );
					return wait( Math.max( 5, err.retryAfter || 30 ) * 1000 ).then( loop );
				}
				log( err.message, 'error' );
				return release( err.message ).then( function () {
					run.active = false;
					setButtons();
				} );
			} );
	}

	function start() {
		if ( ! run.verified ) {
			return;
		}
		run.active = true;
		run.paused = false;
		run.stopped = false;
		log( t.tabWarning );
		setButtons();
		loop();
	}

	function pause() {
		// The batch in flight finishes and is saved; no new work is claimed.
		run.paused = true;
		setButtons();
	}

	function cancel() {
		run.stopped = true;
		run.paused = false;
		run.active = false;
		release( 'stopped by user' );
		log( t.stopped );
		setButtons();
	}

	if ( el.btn.detect ) {
		el.btn.detect.addEventListener( 'click', detect );
	}
	if ( el.btn.load ) {
		el.btn.load.addEventListener( 'click', load );
	}
	if ( el.btn.start ) {
		el.btn.start.addEventListener( 'click', function () {
			if ( run.paused ) {
				run.paused = false;
				run.active = true;
				setButtons();
				loop();
			} else {
				start();
			}
		} );
	}
	if ( el.btn.pause ) {
		el.btn.pause.addEventListener( 'click', pause );
	}
	if ( el.btn.cancel ) {
		el.btn.cancel.addEventListener( 'click', cancel );
	}
	window.addEventListener( 'pagehide', function () {
		// A claim or an upload may still be in flight (its token unknown or already handed over), so
		// ask the server to return every lease this user holds; nothing waits for the 180 s expiry.
		if ( run.active || run.lease ) {
			run.lease = '';
			rest( '/embeddings/release', { all_mine: true, error: 'page closed' }, true ).catch( function () {} );
		}
	} );
	setButtons();
} )();
