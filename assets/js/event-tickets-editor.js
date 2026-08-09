/**
 * Event Tickets — editor registration.
 *
 * Plain ES5 with wp.element.createElement, NOT JSX. That is deliberate: this
 * repo has no build step, and adding one to ship a single block would put a
 * node_modules/webpack toolchain between Jonathan and a two-line change. If a
 * block library grows large enough to justify a build, revisit then.
 *
 * The block is server-rendered — `save` returns null and ServerSideRender shows
 * the real output in the editor, so there is exactly one implementation of the
 * markup and no block-validation errors when the PHP changes.
 */
( function ( blocks, element, components, blockEditor, serverSideRender ) {
	'use strict';

	var el = element.createElement;
	var SSR = serverSideRender;

	blocks.registerBlockType( 'ans/event-tickets', {
		edit: function ( props ) {
			var a = props.attributes;

			return el(
				element.Fragment,
				{},
				el(
					blockEditor.InspectorControls,
					{},
					el(
						components.PanelBody,
						{ title: 'Tickets', initialOpen: true },

						el( components.TextControl, {
							label: 'Heading',
							value: a.heading,
							onChange: function ( v ) { props.setAttributes( { heading: v } ); }
						} ),

						el( components.TextControl, {
							label: 'Event IDs (optional)',
							help: 'Comma-separated tc_events IDs. Leave blank to use this page’s own concert automatically.',
							value: a.events,
							onChange: function ( v ) { props.setAttributes( { events: v } ); }
						} ),

						el( components.TextControl, {
							label: 'Event category (optional)',
							help: 'Term ID or slug. Only needed if this block is on a page that is not the concert’s own page.',
							value: a.event_category,
							onChange: function ( v ) { props.setAttributes( { event_category: v } ); }
						} ),

						el( components.TextControl, {
							label: 'Empty message',
							value: a.empty_text,
							onChange: function ( v ) { props.setAttributes( { empty_text: v } ); }
						} )
					)
				),

				el(
					'div',
					blockEditor.useBlockProps ? blockEditor.useBlockProps() : {},
					el( SSR, {
						block: 'ans/event-tickets',
						attributes: a
					} )
				)
			);
		},

		save: function () {
			return null;
		}
	} );
}(
	window.wp.blocks,
	window.wp.element,
	window.wp.components,
	window.wp.blockEditor,
	window.wp.serverSideRender
) );
