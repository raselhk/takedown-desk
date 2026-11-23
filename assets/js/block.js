/**
 * Takedown Desk editor blocks. No build step: the editor shows the real server rendering.
 * Titles, icons and descriptions come from each block.json.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;

	[ 'takedown-desk/request-form', 'takedown-desk/copyright-form', 'takedown-desk/notice-link' ].forEach( function ( name ) {
		wp.blocks.registerBlockType( name, {
			edit: function () {
				return el(
					'div',
					wp.blockEditor.useBlockProps(),
					el( wp.components.Disabled, null, el( wp.serverSideRender, { block: name } ) )
				);
			},
			save: function () {
				return null;
			},
		} );
	} );

	// Public summary: the period is its only option.
	wp.blocks.registerBlockType( 'takedown-desk/summary', {
		edit: function ( props ) {
			var i18n = wp.i18n;
			return el(
				'div',
				wp.blockEditor.useBlockProps(),
				el(
					wp.blockEditor.InspectorControls,
					null,
					el(
						wp.components.PanelBody,
						{ title: i18n.__( 'Period', 'takedown-desk' ) },
						el( wp.components.SelectControl, {
							label: i18n.__( 'Count requests from the last', 'takedown-desk' ),
							value: String( props.attributes.months ),
							options: [ 3, 6, 12, 24 ].map( function ( n ) {
								/* translators: %d: number of months. */
								return { value: String( n ), label: i18n.sprintf( i18n._n( '%d month', '%d months', n, 'takedown-desk' ), n ) };
							} ),
							onChange: function ( v ) {
								props.setAttributes( { months: parseInt( v, 10 ) } );
							},
							__next40pxDefaultSize: true,
							__nextHasNoMarginBottom: true,
						} )
					)
				),
				el( wp.components.Disabled, null, el( wp.serverSideRender, { block: 'takedown-desk/summary', attributes: props.attributes } ) )
			);
		},
		save: function () {
			return null;
		},
	} );
}( window.wp ) );
