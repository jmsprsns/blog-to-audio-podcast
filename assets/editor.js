/**
 * The Audio box in the post editor: Create, Recreate and Delete, with live
 * progress while the audio is made.
 *
 * While the post is in the queue the box asks the server to keep working on
 * it (users who may create audio) or just checks on it (everyone else), then
 * redraws itself. The background jobs carry on if the page is closed.
 *
 * blog_to_audio_podcast_editor.ajaxUrl, .nonce, .confirmDelete and .error
 * are passed in from PHP (see Blog_To_Audio_Podcast_Editor::enqueue).
 */

/* global blog_to_audio_podcast_editor */
( function () {
	'use strict';

	var data = window.blog_to_audio_podcast_editor;

	function init( box ) {
		var postId = box.getAttribute( 'data-post' );
		var timer = null;
		var failures = 0;

		function request( action ) {
			var body = new window.FormData();

			body.append( 'action', 'blog_to_audio_podcast_post' );
			body.append( 'nonce', data.nonce );
			body.append( 'post_id', postId );
			body.append( 'do', action );

			return window.fetch( data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( response ) {
					return response.json();
				} );
		}

		function update( response ) {
			if ( ! response || ! response.success ) {
				throw new Error( response && response.data && response.data.message ? response.data.message : data.error );
			}

			failures = 0;
			box.innerHTML = response.data.html;
			box.setAttribute( 'data-working', response.data.working ? '1' : '0' );
			box.setAttribute( 'data-can', response.data.can ? '1' : '0' );
			bind();

			if ( response.data.working ) {
				schedule( response.data.busy ? 5000 : 300 );
			}
		}

		function fail( error ) {
			failures++;

			if ( failures > 3 ) {
				var note = document.createElement( 'p' );
				note.className = 'btap-state btap-state-error';
				note.textContent = error && error.message ? error.message : data.error;
				box.appendChild( note );
				return;
			}

			schedule( 5000 );
		}

		function schedule( delay ) {
			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				request( '1' === box.getAttribute( 'data-can' ) ? 'run' : 'status' ).then( update ).catch( fail );
			}, delay );
		}

		function bind() {
			Array.prototype.forEach.call( box.querySelectorAll( '[data-btap-action]' ), function ( button ) {
				button.addEventListener( 'click', function ( event ) {
					var action = button.getAttribute( 'data-btap-action' );

					event.preventDefault();

					if ( 'delete' === action && ! window.confirm( data.confirmDelete ) ) {
						return;
					}

					button.disabled = true;
					request( action ).then( update ).catch( fail );
				} );
			} );
		}

		bind();

		if ( '1' === box.getAttribute( 'data-working' ) ) {
			schedule( 500 );
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( typeof data === 'undefined' || ! window.fetch ) {
			return;
		}

		Array.prototype.forEach.call( document.querySelectorAll( '[data-btap-box]' ), init );
	} );
} )();
