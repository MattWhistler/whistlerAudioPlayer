/**
 * Audio Summary Player — frontend runtime.
 *
 * Stage 2: state machine + EventTracker that POSTs to /wp-json/asp/v1/event,
 * with sendBeacon for `abandon` and an offline-buffer queue that retries on
 * the next `online` event.
 */

( function () {
	'use strict';

	const SPEEDS = [ 1, 1.25, 1.5, 2 ];
	const ROTATE_CTA_MS = 3000;
	const ROTATE_TITLE_MS = 4000;
	const FADE_OUT_MS = 50;
	const SEEK_STEP_S = 5;
	const RING_CIRCUMFERENCE = 100.53; /* matches CSS stroke-dasharray */

	const SESSION_KEY = 'asp_session_id';
	const SESSION_TS_KEY = 'asp_session_ts';
	const SESSION_TTL_MS = 30 * 24 * 60 * 60 * 1000; /* 30 days */

	const reducedMotion = window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	const cfg = window.aspConfig || { i18n: {}, debug: false, restUrl: '', nonce: '', reactionUrl: '', statsUrl: '' };

	function debug( ...args ) {
		if ( cfg.debug && typeof console !== 'undefined' ) {
			console.warn( '[ASP]', ...args );
		}
	}

	/**
	 * Polska liczba mnoga: zwraca formę [singular, paucal, plural] zgodnie
	 * z regułami CLDR dla `pl`. n=1 → singular; n%10∈{2..4} ∧ n%100∉{12..14}
	 * → paucal; reszta (w tym 0) → plural.
	 */
	function aspPluralPL( n, forms ) {
		if ( ! forms || forms.length < 3 ) return '';
		n = Math.abs( parseInt( n, 10 ) || 0 );
		if ( n === 1 ) return forms[ 0 ];
		const mod10 = n % 10;
		const mod100 = n % 100;
		if ( mod10 >= 2 && mod10 <= 4 && ( mod100 < 12 || mod100 > 14 ) ) {
			return forms[ 1 ];
		}
		return forms[ 2 ];
	}

	function uuidv4() {
		if ( window.crypto && typeof window.crypto.randomUUID === 'function' ) {
			return window.crypto.randomUUID();
		}
		const bytes = new Uint8Array( 16 );
		( window.crypto || window.msCrypto ).getRandomValues( bytes );
		bytes[ 6 ] = ( bytes[ 6 ] & 0x0f ) | 0x40;
		bytes[ 8 ] = ( bytes[ 8 ] & 0x3f ) | 0x80;
		const h = Array.from( bytes, ( b ) => b.toString( 16 ).padStart( 2, '0' ) );
		return (
			h.slice( 0, 4 ).join( '' ) + '-' +
			h.slice( 4, 6 ).join( '' ) + '-' +
			h.slice( 6, 8 ).join( '' ) + '-' +
			h.slice( 8, 10 ).join( '' ) + '-' +
			h.slice( 10, 16 ).join( '' )
		);
	}

	/**
	 * Session id manager. Persists UUID v4 in localStorage with a 30-day TTL.
	 * Falls back to in-memory id when localStorage is unavailable
	 * (private mode, ITP, etc.) — deduplication then degrades to per-pageload.
	 */
	const Session = ( function () {
		let memoryId = null;
		let storageWorks = true;
		try {
			window.localStorage.setItem( '__asp_probe', '1' );
			window.localStorage.removeItem( '__asp_probe' );
		} catch ( e ) {
			storageWorks = false;
		}

		function rotate() {
			const id = uuidv4();
			if ( storageWorks ) {
				try {
					window.localStorage.setItem( SESSION_KEY, id );
					window.localStorage.setItem( SESSION_TS_KEY, String( Date.now() ) );
				} catch ( e ) {
					storageWorks = false;
				}
			}
			memoryId = id;
			return id;
		}

		function get() {
			if ( ! storageWorks ) {
				return memoryId || ( memoryId = uuidv4() );
			}
			try {
				const stored = window.localStorage.getItem( SESSION_KEY );
				const ts = parseInt( window.localStorage.getItem( SESSION_TS_KEY ) || '0', 10 );
				if ( stored && Date.now() - ts < SESSION_TTL_MS ) {
					return stored;
				}
			} catch ( e ) {
				storageWorks = false;
				return memoryId || ( memoryId = uuidv4() );
			}
			return rotate();
		}

		return { get };
	} )();

	/**
	 * EventTracker — minimal transport over the REST endpoint.
	 *
	 * - Regular events: fetch with keepalive (so they survive tab close most of the time).
	 * - `abandon`: navigator.sendBeacon (guaranteed delivery on unload).
	 * - Network failures: enqueue and replay on next `online`. Failures swallowed.
	 */
	const Tracker = ( function () {
		const queue = [];
		let online = typeof navigator !== 'undefined' ? navigator.onLine !== false : true;

		window.addEventListener( 'online', () => {
			online = true;
			flush();
		} );
		window.addEventListener( 'offline', () => {
			online = false;
		} );

		function flush() {
			if ( ! online || ! cfg.restUrl ) return;
			while ( queue.length ) {
				const body = queue.shift();
				postJson( body );
			}
		}

		function postJson( body ) {
			if ( ! cfg.restUrl ) return Promise.resolve();
			return fetch( cfg.restUrl, {
				method: 'POST',
				credentials: 'same-origin',
				keepalive: true,
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': cfg.nonce || '',
				},
				body: JSON.stringify( body ),
			} ).then(
				( res ) => {
					if ( ! res.ok ) {
						debug( 'event rejected', res.status );
					}
				},
				( err ) => {
					debug( 'event network error', err );
					queue.push( body );
				}
			);
		}

		function send( body ) {
			if ( ! cfg.restUrl ) return;
			if ( ! online ) {
				queue.push( body );
				return;
			}
			postJson( body );
		}

		function sendBeacon( body ) {
			if ( ! cfg.restUrl ) return;
			if ( typeof navigator !== 'undefined' && typeof navigator.sendBeacon === 'function' ) {
				try {
					/* sendBeacon ignores custom headers — nonce travels in payload too. */
					const url = cfg.restUrl + ( cfg.restUrl.indexOf( '?' ) === -1 ? '?' : '&' ) + '_wpnonce=' + encodeURIComponent( cfg.nonce || '' );
					const blob = new Blob( [ JSON.stringify( body ) ], { type: 'application/json' } );
					if ( navigator.sendBeacon( url, blob ) ) {
						return;
					}
				} catch ( e ) {
					debug( 'sendBeacon failed', e );
				}
			}
			send( body );
		}

		return { send, sendBeacon };
	} )();

	function formatTime( seconds ) {
		if ( ! isFinite( seconds ) || seconds < 0 ) return '0:00';
		const s = Math.floor( seconds );
		const m = Math.floor( s / 60 );
		const r = s % 60;
		return m + ':' + String( r ).padStart( 2, '0' );
	}

	class TextRotator {
		constructor( container, ctaText, titleText ) {
			this.container = container;
			this.cta = ctaText || '';
			this.title = titleText || ctaText || '';
			this.current = 'cta';
			this.timer = null;
			this.stopped = false;
		}

		start() {
			if ( this.stopped ) return;
			if ( ! this.cta || ! this.title || this.cta === this.title ) return;
			this.scheduleNext();
		}

		scheduleNext() {
			if ( this.stopped ) return;
			const delay = this.current === 'cta' ? ROTATE_CTA_MS : ROTATE_TITLE_MS;
			this.timer = window.setTimeout( () => this.swap(), delay );
		}

		swap() {
			if ( this.stopped ) return;
			const next = this.current === 'cta' ? 'title' : 'cta';
			const nextText = next === 'cta' ? this.cta : this.title;
			const el = this.container.querySelector( '.asp-player__text' );
			if ( ! el ) return;

			el.classList.remove( 'is-active' );
			el.classList.add( 'is-exit' );

			window.setTimeout( () => {
				if ( this.stopped ) return;
				el.textContent = nextText;
				el.dataset.aspText = next;
				el.classList.remove( 'is-exit' );
				el.classList.add( 'is-incoming' );
				/* force reflow so transition replays */
				void el.offsetWidth;
				el.classList.remove( 'is-incoming' );
				el.classList.add( 'is-active' );
				this.current = next;
				this.scheduleNext();
			}, FADE_OUT_MS + ( reducedMotion ? 0 : 350 ) );
		}

		freezeOnTitle() {
			this.stop();
			const el = this.container.querySelector( '.asp-player__text' );
			if ( el && this.title ) {
				el.textContent = this.title;
				el.dataset.aspText = 'title';
				el.classList.remove( 'is-exit', 'is-incoming' );
				el.classList.add( 'is-active' );
			}
			this.current = 'title';
		}

		stop() {
			this.stopped = true;
			if ( this.timer ) {
				window.clearTimeout( this.timer );
				this.timer = null;
			}
		}
	}

	class Player {
		constructor( root ) {
			this.root = root;
			this.audio = root.querySelector( '[data-asp-audio]' );
			this.playBtn = root.querySelector( '.asp-player__play' );
			this.progress = root.querySelector( '.asp-player__progress' );
			this.progressFill = root.querySelector( '[data-asp-progress-fill]' );
			this.timeCurrent = root.querySelector( '[data-asp-time="current"]' );
			this.timeTotal = root.querySelector( '[data-asp-time="total"]' );
			this.speedBtn = root.querySelector( '[data-asp-action="speed"]' );
			this.speedLabel = root.querySelector( '[data-asp-speed-label]' );
			this.textEl = root.querySelector( '.asp-player__text' );

			this.postId = parseInt( root.dataset.postId || '0', 10 );
			this.audioId = parseInt( root.dataset.audioId || '0', 10 );
			this.duration = parseFloat( root.dataset.audioDuration || '0' ) || 0;
			this.minibarMinDuration = parseInt( root.dataset.minibarMinDuration || '30', 10 );
			this.minibarEnabled = root.dataset.minibarEnabled === '1';

			this.state = 'idle';
			this.hasInteracted = false;
			this.miniBarDismissed = sessionStorage.getItem( 'asp_minibar_dismissed' ) === '1';
			this.speed = this.restoreSpeed();
			this.totalListenedSeconds = 0;
			this.lastTickAt = 0;
			this.checkpointsFired = new Set();
			this.lastReportedPosition = 0;

			const cta = this.textEl ? this.textEl.dataset.aspCta || '' : '';
			const title = this.textEl ? this.textEl.dataset.aspTitle || '' : '';
			this.titleText = title;
			this.rotator = new TextRotator( root, cta, title );

			this.minibar = null;
			if ( this.minibarEnabled ) {
				const id = root.id;
				const candidate = document.querySelector( '[data-asp-minibar][data-target="' + id + '"]' );
				if ( candidate ) {
					this.minibar = new MiniBar( candidate, this );
				}
			}

			this.bind();
			this.applySpeed( this.speed, true );
			this.updateProgress( 0 );
			if ( this.duration > 0 ) {
				this.timeTotal.textContent = formatTime( this.duration );
				this.progress.setAttribute( 'aria-valuemax', String( Math.floor( this.duration ) ) );
			}
			this.rotator.start();
		}

		restoreSpeed() {
			const stored = parseFloat( sessionStorage.getItem( 'asp_speed' ) || '1' );
			return SPEEDS.includes( stored ) ? stored : 1;
		}

		bind() {
			this.playBtn.addEventListener( 'click', () => this.toggle() );

			this.audio.addEventListener( 'loadedmetadata', () => {
				if ( this.audio.duration && isFinite( this.audio.duration ) ) {
					this.duration = this.audio.duration;
					this.timeTotal.textContent = formatTime( this.duration );
					this.progress.setAttribute( 'aria-valuemax', String( Math.floor( this.duration ) ) );
				}
			} );

			this.audio.addEventListener( 'timeupdate', () => this.onTimeUpdate() );
			this.audio.addEventListener( 'ended', () => this.onEnded() );
			this.audio.addEventListener( 'play', () => this.setPlayingUI( true ) );
			this.audio.addEventListener( 'pause', () => this.setPlayingUI( false ) );
			this.audio.addEventListener( 'error', () => debug( 'audio error', this.audio.error ) );

			this.progress.addEventListener( 'click', ( e ) => this.onProgressClick( e ) );
			this.progress.addEventListener( 'keydown', ( e ) => this.onProgressKey( e ) );

			if ( this.speedBtn ) {
				this.speedBtn.addEventListener( 'click', () => this.cycleSpeed() );
			}

			window.addEventListener( 'beforeunload', () => this.onBeforeUnload() );
			window.addEventListener( 'pagehide', () => this.onBeforeUnload() );

			if ( this.minibarEnabled && this.minibar ) {
				this.observeMiniBar();
			}
		}

		toggle() {
			if ( this.audio.paused || this.audio.ended ) {
				this.play();
			} else {
				this.pause();
			}
		}

		play() {
			const wasIdle = this.state === 'idle';
			const wasPaused = this.state === 'paused';
			const wasEnded = this.state === 'ended';
			if ( wasEnded ) {
				this.audio.currentTime = 0;
			}
			const playPromise = this.audio.play();
			if ( playPromise && typeof playPromise.catch === 'function' ) {
				playPromise.catch( ( err ) => {
					debug( 'play() rejected', err );
				} );
			}
			this.state = 'playing';
			this.hasInteracted = true;
			this.lastTickAt = performance.now();
			this.rotator.freezeOnTitle();

			if ( wasIdle || wasEnded ) {
				this.dispatch( 'play_intent', { position: this.audio.currentTime } );
			} else if ( wasPaused ) {
				this.dispatch( 'resume', { position: this.audio.currentTime } );
			}
		}

		pause() {
			if ( this.audio.paused ) return;
			this.audio.pause();
			this.state = 'paused';
			this.flushListenTime();
			this.dispatch( 'pause', { position: this.audio.currentTime } );
		}

		setPlayingUI( playing ) {
			this.root.classList.toggle( 'is-playing', playing );
			if ( this.minibar ) this.minibar.setPlaying( playing );
			const label = playing ? cfg.i18n.pause : cfg.i18n.play;
			if ( label ) this.playBtn.setAttribute( 'aria-label', label );
		}

		onTimeUpdate() {
			const now = performance.now();
			if ( this.state === 'playing' && this.lastTickAt ) {
				const delta = ( now - this.lastTickAt ) / 1000;
				if ( delta > 0 && delta < 2 ) {
					this.totalListenedSeconds += delta * this.speed;
				}
			}
			this.lastTickAt = now;

			const pos = this.audio.currentTime;
			const dur = this.audio.duration || this.duration;
			if ( dur > 0 ) {
				const pct = Math.min( 100, ( pos / dur ) * 100 );
				this.updateProgress( pct );
				this.timeCurrent.textContent = formatTime( pos );
				this.progress.setAttribute( 'aria-valuenow', String( Math.floor( pos ) ) );
				this.checkCheckpoints( pos / dur );
				this.checkComplete( pos, dur );
			}
		}

		updateProgress( pct ) {
			if ( this.progressFill ) this.progressFill.style.width = pct + '%';
			if ( this.minibar ) this.minibar.updateRing( pct );
		}

		checkCheckpoints( progress ) {
			const points = [ 25, 50, 75 ];
			for ( const p of points ) {
				if ( progress >= p / 100 && ! this.checkpointsFired.has( p ) ) {
					this.checkpointsFired.add( p );
					this.dispatch( 'checkpoint_' + p, { position: this.audio.currentTime } );
				}
			}
		}

		checkComplete( pos, dur ) {
			if ( this.state === 'ended' ) return;
			if ( pos / dur >= 0.95 && this.totalListenedSeconds >= dur * 0.9 ) {
				this.markComplete();
			}
		}

		markComplete() {
			if ( this.state === 'ended' ) return;
			this.state = 'ended';
			this.dispatch( 'complete', {
				position: this.audio.currentTime,
				total_listened_seconds: Math.round( this.totalListenedSeconds * 100 ) / 100,
			} );
		}

		onEnded() {
			this.flushListenTime();
			this.markComplete();
		}

		flushListenTime() {
			this.lastTickAt = 0;
		}

		onProgressClick( event ) {
			const rect = this.progress.getBoundingClientRect();
			const ratio = Math.max( 0, Math.min( 1, ( event.clientX - rect.left ) / rect.width ) );
			const dur = this.audio.duration || this.duration;
			if ( ! dur ) return;
			const from = this.audio.currentTime;
			const to = ratio * dur;
			if ( Math.abs( to - from ) > 1 ) {
				this.audio.currentTime = to;
				this.scheduleSeekDispatch( from, to );
			}
		}

		onProgressKey( event ) {
			const dur = this.audio.duration || this.duration;
			if ( ! dur ) return;
			let target = null;
			switch ( event.key ) {
				case 'ArrowLeft':
					target = Math.max( 0, this.audio.currentTime - SEEK_STEP_S );
					break;
				case 'ArrowRight':
					target = Math.min( dur, this.audio.currentTime + SEEK_STEP_S );
					break;
				case 'Home':
					target = 0;
					break;
				case 'End':
					target = dur;
					break;
				default:
					return;
			}
			event.preventDefault();
			const from = this.audio.currentTime;
			this.audio.currentTime = target;
			this.scheduleSeekDispatch( from, target );
		}

		scheduleSeekDispatch( from, to ) {
			if ( this._seekTimer ) window.clearTimeout( this._seekTimer );
			this._pendingSeekFrom = this._pendingSeekFrom !== undefined ? this._pendingSeekFrom : from;
			this._pendingSeekTo = to;
			this._seekTimer = window.setTimeout( () => {
				this.dispatch( 'seek', {
					from_position: this._pendingSeekFrom,
					to_position: this._pendingSeekTo,
				} );
				this._pendingSeekFrom = undefined;
			}, 500 );
		}

		cycleSpeed() {
			const idx = SPEEDS.indexOf( this.speed );
			const next = SPEEDS[ ( idx + 1 ) % SPEEDS.length ];
			this.applySpeed( next, false );
			this.dispatch( 'speed_change', { new_speed: next } );
		}

		applySpeed( speed, silent ) {
			this.speed = speed;
			this.audio.playbackRate = speed;
			sessionStorage.setItem( 'asp_speed', String( speed ) );
			if ( this.speedLabel ) {
				this.speedLabel.textContent = speed.toString().replace( '.', ',' ) + '×';
			}
			if ( this.speedBtn ) {
				const tpl = cfg.i18n.speedLabel || 'Speed %s×';
				this.speedBtn.setAttribute( 'aria-label', tpl.replace( '%s', String( speed ) ) );
			}
		}

		observeMiniBar() {
			if ( ! ( 'IntersectionObserver' in window ) ) {
				return;
			}
			const observer = new IntersectionObserver(
				( entries ) => {
					for ( const entry of entries ) {
						const outOfView = entry.intersectionRatio === 0 && entry.boundingClientRect.bottom < 0;
						this.evaluateMiniBar( outOfView );
					}
				},
				{ root: null, threshold: 0 }
			);
			observer.observe( this.root );
		}

		evaluateMiniBar( outOfView ) {
			if ( ! this.minibar ) return;
			const eligible =
				this.hasInteracted &&
				! this.miniBarDismissed &&
				outOfView &&
				( this.audio.duration || this.duration ) >= this.minibarMinDuration;
			this.minibar.setVisible( eligible );
		}

		dismissMiniBar() {
			this.miniBarDismissed = true;
			sessionStorage.setItem( 'asp_minibar_dismissed', '1' );
			if ( this.minibar ) this.minibar.setVisible( false );
		}

		onBeforeUnload() {
			if ( this.state === 'playing' || this.state === 'paused' ) {
				this.dispatch( 'abandon', {
					position: this.audio.currentTime,
					total_listened_seconds: Math.round( this.totalListenedSeconds * 100 ) / 100,
				} );
			}
		}

		dispatch( eventType, extra ) {
			const dur = this.audio.duration || this.duration || 0;
			if ( ! this.postId || dur <= 0 ) {
				/* Validator rejects payloads with duration <= 0 or post_id 0;
				 * skip silently to avoid 400 spam before metadata loads. */
				return;
			}
			const detail = {
				post_id: this.postId,
				session_id: Session.get(),
				event_type: eventType,
				position: this.audio.currentTime,
				duration: dur,
				speed: this.speed,
				extra: extra || {},
			};

			/* Mirror as DOM event for any third-party subscriber. */
			document.dispatchEvent( new CustomEvent( 'asp:event', { detail } ) );
			debug( 'event', detail );

			if ( eventType === 'abandon' ) {
				Tracker.sendBeacon( detail );
			} else {
				Tracker.send( detail );
			}
		}
	}

	class MiniBar {
		constructor( el, player ) {
			this.el = el;
			this.player = player;
			this.playBtn = el.querySelector( '.asp-minibar__play' );
			this.closeBtn = el.querySelector( '.asp-minibar__close' );
			this.ring = el.querySelector( '[data-asp-ring]' );
			this.bind();
		}

		bind() {
			if ( this.playBtn ) {
				this.playBtn.addEventListener( 'click', () => this.player.toggle() );
			}
			if ( this.closeBtn ) {
				this.closeBtn.addEventListener( 'click', () => this.player.dismissMiniBar() );
			}
		}

		setVisible( visible ) {
			this.el.classList.toggle( 'is-visible', visible );
			this.el.setAttribute( 'aria-hidden', visible ? 'false' : 'true' );
		}

		setPlaying( playing ) {
			this.el.classList.toggle( 'is-playing', playing );
		}

		updateRing( pct ) {
			if ( ! this.ring ) return;
			const offset = RING_CIRCUMFERENCE * ( 1 - pct / 100 );
			this.ring.style.strokeDashoffset = String( offset );
		}
	}

	/**
	 * MetaBar — public play counter and like/dislike reactions rendered
	 * under the player block. Counts are fetched once per pageload and
	 * patched optimistically when the user clicks a reaction.
	 */
	class MetaBar {
		constructor( root ) {
			this.root = root;
			this.postId = parseInt( root.dataset.postId || '0', 10 );
			this.playsValueEl = root.querySelector( '[data-asp-plays-value]' );
			this.playsLabelEl = root.querySelector( '[data-asp-plays-label]' );
			this.likeBtn = root.querySelector( '[data-asp-reaction="like"]' );
			this.dislikeBtn = root.querySelector( '[data-asp-reaction="dislike"]' );
			this.likeCountEl = root.querySelector( '[data-asp-likes]' );
			this.dislikeCountEl = root.querySelector( '[data-asp-dislikes]' );
			this.current = 'none';
			this.busy = false;

			if ( this.likeBtn ) {
				this.likeBtn.addEventListener( 'click', () => this.toggle( 'like' ) );
			}
			if ( this.dislikeBtn ) {
				this.dislikeBtn.addEventListener( 'click', () => this.toggle( 'dislike' ) );
			}
		}

		load() {
			if ( ! cfg.statsUrl || ! this.postId ) return;
			const url = cfg.statsUrl + ( cfg.statsUrl.indexOf( '?' ) === -1 ? '?' : '&' ) +
				'post_id=' + encodeURIComponent( this.postId ) +
				'&session_id=' + encodeURIComponent( Session.get() );
			fetch( url, { credentials: 'same-origin' } )
				.then( ( res ) => res.ok ? res.json() : null )
				.then( ( data ) => {
					if ( ! data ) return;
					this.applyCounts( data );
				} )
				.catch( ( err ) => debug( 'stats fetch failed', err ) );
		}

		applyCounts( data ) {
			const playsCount = parseInt( data.plays, 10 ) || 0;
			if ( this.playsValueEl ) {
				this.playsValueEl.textContent = String( playsCount );
			}
			if ( this.playsLabelEl && cfg.i18n && cfg.i18n.playsForms ) {
				this.playsLabelEl.textContent = aspPluralPL( playsCount, cfg.i18n.playsForms );
			}
			if ( this.likeCountEl ) {
				this.likeCountEl.textContent = String( data.likes || 0 );
			}
			if ( this.dislikeCountEl ) {
				this.dislikeCountEl.textContent = String( data.dislikes || 0 );
			}
			this.setActive( data.user_reaction || 'none' );
		}

		setActive( reaction ) {
			this.current = reaction;
			if ( this.likeBtn ) {
				this.likeBtn.setAttribute( 'aria-pressed', reaction === 'like' ? 'true' : 'false' );
			}
			if ( this.dislikeBtn ) {
				this.dislikeBtn.setAttribute( 'aria-pressed', reaction === 'dislike' ? 'true' : 'false' );
			}
		}

		toggle( target ) {
			if ( this.busy || ! cfg.reactionUrl || ! this.postId ) return;
			const desired = this.current === target ? 'none' : target;
			this.busy = true;
			this.setBusy( true );
			fetch( cfg.reactionUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': cfg.nonce || '',
				},
				body: JSON.stringify( {
					post_id: this.postId,
					session_id: Session.get(),
					reaction: desired,
				} ),
			} )
				.then( ( res ) => res.ok ? res.json() : Promise.reject( res.status ) )
				.then( ( data ) => {
					if ( this.likeCountEl ) {
						this.likeCountEl.textContent = String( data.likes || 0 );
					}
					if ( this.dislikeCountEl ) {
						this.dislikeCountEl.textContent = String( data.dislikes || 0 );
					}
					this.setActive( data.reaction || 'none' );
				} )
				.catch( ( err ) => debug( 'reaction failed', err ) )
				.then( () => {
					this.busy = false;
					this.setBusy( false );
				} );
		}

		setBusy( busy ) {
			[ this.likeBtn, this.dislikeBtn ].forEach( ( btn ) => {
				if ( ! btn ) return;
				if ( busy ) {
					btn.setAttribute( 'disabled', 'disabled' );
				} else {
					btn.removeAttribute( 'disabled' );
				}
			} );
		}
	}

	function initMetaBars() {
		const bars = document.querySelectorAll( '[data-asp-meta]' );
		bars.forEach( ( root ) => {
			if ( root._aspMetaInitialized ) return;
			root._aspMetaInitialized = true;
			try {
				const bar = new MetaBar( root );
				bar.load();
			} catch ( err ) {
				debug( 'meta init failure', err );
			}
		} );
	}

	function init() {
		const roots = document.querySelectorAll( '[data-asp-player]' );
		roots.forEach( ( root ) => {
			if ( root._aspInitialized ) return;
			root._aspInitialized = true;
			try {
				new Player( root );
			} catch ( err ) {
				debug( 'init failure', err );
			}
		} );
		initMetaBars();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
