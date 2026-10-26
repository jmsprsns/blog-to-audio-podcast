/**
 * The "Exclude from audio" block: a wrapper whose contents show on the page
 * as normal but are left out of the post's audio.
 *
 * It saves no markup of its own, only its inner blocks, so wrapping or
 * unwrapping content never changes how the page looks.
 */

/* global wp */
( function ( blocks, element, blockEditor, i18n ) {
	'use strict';

	if ( ! blocks || ! element || ! blockEditor ) {
		return;
	}

	var el = element.createElement;
	var __ = i18n && i18n.__ ? i18n.__ : function ( text ) {
		return text;
	};
	var InnerBlocks = blockEditor.InnerBlocks;
	var useBlockProps = blockEditor.useBlockProps || function ( props ) {
		return props;
	};

	var icon = el(
		'svg',
		{ width: 24, height: 24, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round', 'aria-hidden': true, focusable: false },
		el( 'path', { d: 'M21 14h-1.343' } ),
		el( 'path', { d: 'M9.128 3.47A9 9 0 0 1 21 12v3.343' } ),
		el( 'path', { d: 'm2 2 20 20' } ),
		el( 'path', { d: 'M20.414 20.414A2 2 0 0 1 19 21h-1a2 2 0 0 1-2-2v-3' } ),
		el( 'path', { d: 'M3 14h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a9 9 0 0 1 2.636-6.364' } )
	);

	blocks.registerBlockType( 'blog-to-audio-podcast/exclude', {
		apiVersion: 2,
		title: __( 'Exclude from audio', 'blog-to-audio-podcast' ),
		description: __( 'Shown on the page as usual, but left out of the post\'s audio version and podcast episode.', 'blog-to-audio-podcast' ),
		category: 'text',
		icon: icon,
		keywords: [ __( 'audio', 'blog-to-audio-podcast' ), __( 'podcast', 'blog-to-audio-podcast' ), __( 'skip', 'blog-to-audio-podcast' ) ],
		supports: {
			html: false,
			className: false,
			customClassName: false
		},

		edit: function () {
			var props = useBlockProps( { className: 'btap-exclude' } );

			return el(
				'div',
				props,
				el(
					'div',
					{ className: 'btap-exclude-label' },
					icon,
					el( 'span', null, __( 'Not read aloud', 'blog-to-audio-podcast' ) )
				),
				el( InnerBlocks, { templateLock: false } )
			);
		},

		save: function () {
			return el( InnerBlocks.Content );
		}
	} );
} )( window.wp && wp.blocks, window.wp && wp.element, window.wp && wp.blockEditor, window.wp && wp.i18n );
