/**
 * Content Powered > Blog Post to Audio Podcast page behaviour. Only loaded on that page.
 *
 * - Overview: count the cost of bulk creation in batches, start it, then work
 *   on the queue while the page is open (the background jobs carry on if it's
 *   closed), redrawing the progress box after every step.
 * - ElevenLabs: voice samples, sliders, and "Try it" with unsaved settings.
 * - Content: preview the text a post's audio would read.
 * - Podcast: the cover art picker and the copy button.
 *
 * blog_to_audio_podcast_admin.ajaxUrl, .nonce, .models and .i18n are passed
 * in from PHP (see Blog_To_Audio_Podcast_Admin::enqueue_assets).
 */

/* global blog_to_audio_podcast_admin, wp */
( function () {
	'use strict';

	var data = window.blog_to_audio_podcast_admin;

	function post( fields ) {
		var body = new window.FormData();

		body.append( 'action', 'blog_to_audio_podcast_admin' );
		body.append( 'nonce', data.nonce );

		Object.keys( fields ).forEach( function ( key ) {
			body.append( key, fields[ key ] );
		} );

		return window.fetch( data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} );
	}

	function format( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );

		return template.replace( /%(\d+)\$s|%s/g, function ( match, index ) {
			return String( index ? args[ index - 1 ] : args.shift() );
		} );
	}

	function errorMessage( response ) {
		return response && response.data && response.data.message ? response.data.message : data.i18n.error;
	}

	// Confirm before forms that need it (cancel the queue).
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;

		if ( form.hasAttribute && form.hasAttribute( 'data-btap-confirm' ) && ! window.confirm( form.getAttribute( 'data-btap-confirm' ) ) ) {
			event.preventDefault();
		}
	} );

	// Overview: bulk creation ---------------------------------------------------

	function initBulk() {
		var box = document.querySelector( '[data-btap-bulk]' );

		if ( ! box ) {
			return;
		}

		var area = box.querySelector( '[data-btap-progress]' );
		var form = box.querySelector( '[data-btap-bulk-form]' );
		var estimate = box.querySelector( '[data-btap-estimate]' );
		var result = box.querySelector( '[data-btap-estimate-result]' );
		var startRow = box.querySelector( '[data-btap-start-row]' );
		var start = box.querySelector( '[data-btap-start]' );
		var startLabel = box.querySelector( '[data-btap-start-label]' );
		var limit = box.querySelector( '[data-btap-limit]' );
		var running = false;
		var failures = 0;

		function scope() {
			var checked = box.querySelector( 'input[name="btap_scope"]:checked' );

			return checked && 'stale' === checked.value ? '1' : '';
		}

		function render( state ) {
			if ( area ) {
				area.innerHTML = state.html;
			}

			if ( form ) {
				form.hidden = state.remaining > 0;
			}
		}

		function step() {
			post( { do: 'run' } )
				.then( function ( response ) {
					if ( ! response || ! response.success ) {
						throw new Error( errorMessage( response ) );
					}

					failures = 0;
					render( response.data );

					if ( response.data.working ) {
						// Another run (a background job) has the lock: check back shortly.
						window.setTimeout( step, response.data.busy ? 5000 : 250 );
						return;
					}

					running = false;

					// All done: reload for the new numbers and the recent audio.
					if ( ! response.data.remaining ) {
						window.location.reload();
					}
				} )
				.catch( function () {
					failures++;

					if ( failures > 3 ) {
						running = false;

						if ( area ) {
							var note = document.createElement( 'p' );
							note.className = 'btap-run-paused';
							note.textContent = data.i18n.stopped;
							area.appendChild( note );
						}

						return;
					}

					window.setTimeout( step, 5000 );
				} );
		}

		function run() {
			if ( running ) {
				return;
			}

			running = true;
			step();
		}

		function reset() {
			if ( result ) {
				result.hidden = true;
			}

			if ( startRow ) {
				startRow.hidden = true;
			}
		}

		if ( estimate ) {
			estimate.addEventListener( 'click', function () {
				var stale = scope();
				var max = limit && limit.value ? parseInt( limit.value, 10 ) : 0;

				estimate.disabled = true;
				startRow.hidden = true;
				result.hidden = false;
				result.textContent = data.i18n.loading;

				function next( offset ) {
					post( { do: 'estimate', stale: stale, offset: offset, limit: max > 0 ? max : '' } )
						.then( function ( response ) {
							if ( ! response || ! response.success ) {
								throw new Error( errorMessage( response ) );
							}

							if ( ! response.data.finished ) {
								result.textContent = format( data.i18n.counting, response.data.done, response.data.total );
								next( response.data.done );
								return;
							}

							estimate.disabled = false;
							result.innerHTML = response.data.html;

							if ( response.data.posts > 0 ) {
								startLabel.textContent = format( data.i18n.startPosts, response.data.posts );
								startRow.hidden = false;
							}
						} )
						.catch( function ( error ) {
							estimate.disabled = false;
							result.textContent = error && error.message ? error.message : data.i18n.error;
						} );
				}

				next( 0 );
			} );
		}

		Array.prototype.forEach.call( box.querySelectorAll( 'input[name="btap_scope"]' ), function ( input ) {
			input.addEventListener( 'change', reset );
		} );

		if ( limit ) {
			limit.addEventListener( 'input', reset );
		}

		if ( start ) {
			start.addEventListener( 'click', function () {
				var max = limit && limit.value ? parseInt( limit.value, 10 ) : 0;

				start.disabled = true;

				post( { do: 'start', stale: scope(), limit: max > 0 ? max : '' } )
					.then( function ( response ) {
						start.disabled = false;

						if ( ! response || ! response.success ) {
							throw new Error( errorMessage( response ) );
						}

						reset();
						render( response.data );
						run();
					} )
					.catch( function ( error ) {
						start.disabled = false;
						result.textContent = error && error.message ? error.message : data.i18n.error;
					} );
			} );
		}

		// A run already in progress: help it along while the page is open.
		if ( '1' === box.getAttribute( 'data-working' ) ) {
			run();
		}
	}

	// ElevenLabs tab ---------------------------------------------------------------

	function initVoice() {
		var model = document.querySelector( '[data-btap-model]' );
		var voice = document.querySelector( '[data-btap-voice]' );
		var voiceInfo = document.querySelector( '[data-btap-voice-info]' );
		var sample = document.querySelector( '[data-btap-preview-voice]' );
		var player = null;

		Array.prototype.forEach.call( document.querySelectorAll( '.btap-slider input[type="range"]' ), function ( input ) {
			var output = input.parentNode.querySelector( 'output' );

			input.addEventListener( 'input', function () {
				output.textContent = Number( input.value ).toFixed( 2 );
			} );
		} );

		if ( model ) {
			model.addEventListener( 'change', function () {
				var info = data.models[ model.value ] || {};
				var text = document.querySelector( '[data-btap-model-info]' );

				Array.prototype.forEach.call( document.querySelectorAll( '[data-btap-needs]' ), function ( row ) {
					row.hidden = ! info[ row.getAttribute( 'data-btap-needs' ) ];
				} );

				if ( text ) {
					text.textContent = ( info.description ? info.description.replace( /\.$/, '' ) + '. ' : '' ) + format( data.i18n.listPrice, Number( info.cost || 1 ).toFixed( 1 ) );
				}
			} );
		}

		function updateVoice() {
			var option = voice.options[ voice.selectedIndex ];

			if ( voiceInfo ) {
				voiceInfo.textContent = option ? option.getAttribute( 'data-description' ) || '' : '';
			}

			if ( sample ) {
				sample.disabled = ! option || ! option.getAttribute( 'data-preview' );
			}

			if ( player ) {
				player.pause();
				player = null;
			}
		}

		if ( voice ) {
			voice.addEventListener( 'change', updateVoice );
			updateVoice();
		}

		if ( sample && voice ) {
			sample.addEventListener( 'click', function () {
				var option = voice.options[ voice.selectedIndex ];
				var url = option ? option.getAttribute( 'data-preview' ) : '';

				if ( ! url ) {
					return;
				}

				if ( player && ! player.paused ) {
					player.pause();
					return;
				}

				player = player || new window.Audio( url );
				player.play();
			} );
		}

		initTry( model, voice );
	}

	function initTry( model, voice ) {
		var box = document.querySelector( '[data-btap-try]' );
		var button = box ? box.querySelector( '[data-btap-try-button]' ) : null;

		if ( ! button ) {
			return;
		}

		var text = box.querySelector( '[data-btap-try-text]' );
		var status = box.querySelector( '[data-btap-try-status]' );
		var audio = box.querySelector( '[data-btap-try-audio]' );

		function value( key, fallback ) {
			var input = document.querySelector( '[data-btap-setting="' + key + '"]' );

			return input ? input.value : fallback;
		}

		button.addEventListener( 'click', function () {
			var custom = document.querySelector( '[data-btap-voice-custom]' );
			var boost = document.querySelector( 'input[name$="[speaker_boost]"]' );

			button.disabled = true;
			status.textContent = data.i18n.generating;

			post( {
				do: 'test',
				text: text.value,
				model_id: model ? model.value : '',
				voice_id: custom && custom.value.trim() ? custom.value.trim() : ( voice ? voice.value : '' ),
				stability: value( 'stability', 0.5 ),
				similarity: value( 'similarity', 0.75 ),
				style: value( 'style', 0 ),
				speed: value( 'speed', 1 ),
				speaker_boost: boost && boost.checked ? '1' : ''
			} )
				.then( function ( response ) {
					button.disabled = false;

					if ( ! response || ! response.success ) {
						status.textContent = errorMessage( response );
						return;
					}

					status.textContent = response.data.message;
					audio.src = response.data.audio;
					audio.hidden = false;
					audio.play();
				} )
				.catch( function () {
					button.disabled = false;
					status.textContent = data.i18n.error;
				} );
		} );
	}

	// Content tab ------------------------------------------------------------------------

	function initPreview() {
		var button = document.querySelector( '[data-btap-preview-text]' );

		if ( ! button ) {
			return;
		}

		var select = document.querySelector( '[data-btap-preview-post]' );
		var summary = document.querySelector( '[data-btap-preview-summary]' );
		var output = document.querySelector( '[data-btap-preview-output]' );

		button.addEventListener( 'click', function () {
			button.disabled = true;
			summary.textContent = data.i18n.loading;

			post( { do: 'preview', post_id: select.value } )
				.then( function ( response ) {
					button.disabled = false;

					if ( ! response || ! response.success ) {
						summary.textContent = errorMessage( response );
						return;
					}

					summary.textContent = response.data.summary;
					output.value = response.data.text;
					output.hidden = false;
				} )
				.catch( function () {
					button.disabled = false;
					summary.textContent = data.i18n.error;
				} );
		} );
	}

	// Podcast tab --------------------------------------------------------------------------

	function initArt() {
		var box = document.querySelector( '[data-btap-art]' );

		if ( ! box || ! window.wp || ! wp.media ) {
			return;
		}

		var id = box.querySelector( '[data-btap-art-id]' );
		var preview = box.querySelector( '[data-btap-art-preview]' );
		var remove = box.querySelector( '[data-btap-art-remove]' );
		var note = document.querySelector( '[data-btap-art-note]' );
		var placeholder = preview.innerHTML;
		var frame = null;

		function check( image ) {
			if ( 'image/jpeg' !== image.mime && 'image/png' !== image.mime ) {
				return data.i18n.artType;
			}

			if ( image.width !== image.height ) {
				return format( data.i18n.artSquare, image.width, image.height );
			}

			if ( image.width < 1400 || image.width > 3000 ) {
				return format( data.i18n.artSize, image.width );
			}

			return '';
		}

		box.querySelector( '[data-btap-art-choose]' ).addEventListener( 'click', function () {
			if ( ! frame ) {
				frame = wp.media( {
					title: data.i18n.chooseArt,
					button: { text: data.i18n.useArt },
					library: { type: 'image' },
					multiple: false
				} );

				frame.on( 'select', function () {
					var image = frame.state().get( 'selection' ).first().toJSON();
					var img = document.createElement( 'img' );
					var problem = check( image );

					img.src = image.sizes && image.sizes.medium ? image.sizes.medium.url : image.url;
					img.alt = '';
					preview.innerHTML = '';
					preview.appendChild( img );
					id.value = image.id;
					remove.hidden = false;

					if ( note ) {
						note.textContent = problem || data.i18n.artGood;
						note.classList.toggle( 'btap-warn-text', !! problem );
					}
				} );
			}

			frame.open();
		} );

		remove.addEventListener( 'click', function () {
			id.value = '0';
			preview.innerHTML = placeholder;
			remove.hidden = true;

			if ( note ) {
				note.textContent = data.i18n.artMissing;
				note.classList.remove( 'btap-warn-text' );
			}
		} );
	}

	function initCopy() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-btap-copy]' ), function ( button ) {
			var input = button.parentNode.querySelector( '[data-btap-copy-source]' );

			button.addEventListener( 'click', function () {
				function done() {
					button.classList.add( 'is-copied' );
					button.setAttribute( 'title', data.i18n.copied );
					window.setTimeout( function () {
						button.classList.remove( 'is-copied' );
					}, 1500 );
				}

				if ( window.navigator.clipboard && window.isSecureContext ) {
					window.navigator.clipboard.writeText( input.value ).then( done );
					return;
				}

				input.select();
				document.execCommand( 'copy' );
				done();
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( typeof data === 'undefined' || ! window.fetch ) {
			return;
		}

		initBulk();
		initVoice();
		initPreview();
		initArt();
		initCopy();
	} );
} )();
