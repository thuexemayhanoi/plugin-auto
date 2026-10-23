/**
 * Editor UI for the dynamic "Related content" block (server-side rendered).
 */
( function ( wp ) {
	if ( ! wp || ! wp.blocks ) {
		return;
	}
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var RangeControl = wp.components.RangeControl;
	var TextControl = wp.components.TextControl;
	var ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'semantic-seo/related-content', {
		edit: function ( props ) {
			var a = props.attributes;
			return el(
				'div',
				{ className: 'sseo-related-block-editor' },
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Related content', 'semantic-seo-engine-vi-en' ) },
						el( RangeControl, {
							label: __( 'Number of items', 'semantic-seo-engine-vi-en' ),
							min: 1,
							max: 10,
							value: a.limit,
							onChange: function ( v ) { props.setAttributes( { limit: v } ); }
						} ),
						el( TextControl, {
							label: __( 'Heading (optional)', 'semantic-seo-engine-vi-en' ),
							value: a.title,
							onChange: function ( v ) { props.setAttributes( { title: v } ); }
						} )
					)
				),
				el( ServerSideRender, {
					block: 'semantic-seo/related-content',
					attributes: a,
					EmptyResponsePlaceholder: function () {
						return el( 'p', { className: 'sseo-related-empty' }, __( 'No related content to show (output disabled in Semantic SEO settings, or this post has not been analyzed yet).', 'semantic-seo-engine-vi-en' ) );
					}
				} )
			);
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp );
