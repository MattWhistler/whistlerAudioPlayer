/**
 * Audio Summary Player — frontend runtime.
 *
 * Stage 1: full UI behavior (state machine, text rotator, mini-bar, speed,
 * keyboard a11y). Tracking is wired as a no-op `dispatchEvent` so Stage 2
 * can add transport without touching this file.
 */

( function () {
	'use strict';

	const SPEEDS = [ 1, 1.25, 1.5, 2 ];
	const ROTATE_CTA_MS = 3000;
	const ROTATE_TITLE_MS = 4000;
	const FADE_OUT_MS = 50;
	const SEEK_STEP_S = 5;
	const RING_CIRCUMFERENCE = 100.53; /* matches CSS stroke-dasharray */

	const reducedMotion = window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	const cfg = window.aspConfig || { i18n: {}, debug: false };

	function debug( ...args ) {
		if ( cfg.debug && typeof console !== 'undefined' ) {
			console.warn( '[ASP]', ...args );
		}
	}

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
			/* Stage 1: emit DOM event so future tracker module can subscribe.
			 * Stage 2 will swap this for a REST POST + sendBeacon transport. */
			const detail = {
				post_id: this.postId,
				event_type: eventType,
				position: this.audio.currentTime,
				duration: this.audio.duration || this.duration,
				speed: this.speed,
				extra: extra || {},
			};
			document.dispatchEvent( new CustomEvent( 'asp:event', { detail } ) );
			debug( 'event', detail );
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
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
