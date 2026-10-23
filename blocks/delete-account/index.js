/**
 * Delete Account block, in the editor: its heading and introduction are inner blocks, the rest of its texts are edited
 * in place. What's shown to whom, the form and deleting the account are done in PHP.
 *
 * @see \Daan\Mods\DeleteAccount
 */
( function ( wp ) {
	const { registerBlockType } = wp.blocks;
	const { InnerBlocks, InspectorControls, RichText, useBlockProps, useInnerBlocksProps } = wp.blockEditor;
	const { PanelBody } = wp.components;
	const { createElement: el } = wp.element;
	const { __ } = wp.i18n;

	const TEMPLATE = [
		[ 'core/heading', { level: 3, content: __( 'Delete account', 'daan-mods' ) } ],
		[ 'core/paragraph', { content: __( 'Deleting your account removes your personal data from {site_name}. This can\'t be undone.', 'daan-mods' ) } ],
	];

	// The list's items, in order. Items with a note are only shown to customers they apply to.
	const ITEMS = [
		{ attribute: 'accountText' },
		{ attribute: 'ordersText' },
		{ attribute: 'subscriptionsText', note: __( 'Only shown to customers with an active subscription.', 'daan-mods' ) },
		{ attribute: 'licensesText', note: __( 'Only shown to customers with license keys.', 'daan-mods' ) },
		{ attribute: 'newsletterText' },
	];

	registerBlockType( 'daan/delete-account', {
		edit: function ( { attributes, setAttributes } ) {
			const blockProps = useBlockProps();
			const innerBlocksProps = useInnerBlocksProps( {}, { template: TEMPLATE } );
			const text = ( attribute, props ) =>
				el( RichText, Object.assign( {
					value: attributes[ attribute ],
					onChange: ( value ) => setAttributes( { [ attribute ]: value } ),
					allowedFormats: [],
				}, props ) );

			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'About this block', 'daan-mods' ) },
						el(
							'p',
							null,
							__( 'Only logged-in customers see this block. Staff, and visitors who aren\'t logged in, don\'t. After deleting their account, customers see a confirmation here instead.', 'daan-mods' )
						),
						el(
							'p',
							null,
							__( 'Items marked with an asterisk (*) are only shown to customers they apply to.', 'daan-mods' )
						),
						el(
							'p',
							null,
							__( '{site_name} is replaced with the name of your site, in all of this block\'s texts.', 'daan-mods' )
						)
					)
				),
				el( 'div', innerBlocksProps ),
				el(
					'ul',
					null,
					ITEMS.map( ( item ) =>
						el(
							'li',
							{ key: item.attribute, title: item.note },
							text( item.attribute, { tagName: 'span' } ),
							item.note ? ' *' : null
						)
					)
				),
				el(
					'p',
					null,
					el(
						'label',
						null,
						el( 'input', { type: 'checkbox', disabled: true } ),
						' ',
						text( 'confirmLabel', { tagName: 'span' } )
					)
				),
				el(
					'p',
					null,
					text( 'buttonText', { tagName: 'span', className: 'button daan-delete-account-button' } )
				)
			);
		},

		// The heading and introduction. The rest is rendered by PHP.
		save: function () {
			return el( InnerBlocks.Content );
		},
	} );
} )( window.wp );
