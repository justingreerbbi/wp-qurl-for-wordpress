( function ( wp ) {
	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var TextareaControl = wp.components.TextareaControl;
	var ToggleControl = wp.components.ToggleControl;
	var createElement = wp.element.createElement;
	var __ = wp.i18n.__;

	registerBlockType( 'wp-qurl/mint', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = useBlockProps( { className: 'wp-qurl-mint-editor' } );
			var allowVisitors = !! attributes.allowVisitors;

			return createElement(
				'div',
				blockProps,
				createElement(
					InspectorControls,
					null,
					createElement(
						PanelBody,
						{ title: __( 'qURL', 'wp-qurl' ), initialOpen: true },
						createElement( TextControl, {
							label: __( 'Page', 'wp-qurl' ),
							help: __( 'The page the link should open. Use a path like /private-page/ or a full URL.', 'wp-qurl' ),
							value: attributes.endpoint,
							onChange: function ( value ) {
								setAttributes( { endpoint: value } );
							},
						} ),
						createElement( ToggleControl, {
							label: __( 'Allow visitors', 'wp-qurl' ),
							help: __( 'Let people who are not logged in make a link.', 'wp-qurl' ),
							checked: allowVisitors,
							onChange: function ( value ) {
								setAttributes( { allowVisitors: value } );
							},
						} ),
						createElement( TextControl, {
							label: __( 'Button Text', 'wp-qurl' ),
							value: attributes.buttonText,
							onChange: function ( value ) {
								setAttributes( { buttonText: value } );
							},
						} ),
						createElement( TextControl, {
							label: __( 'Success Message', 'wp-qurl' ),
							value: attributes.successMessage,
							onChange: function ( value ) {
								setAttributes( { successMessage: value } );
							},
						} ),
						! allowVisitors && createElement( TextareaControl, {
							label: __( 'Login Message', 'wp-qurl' ),
							value: attributes.loginMessage,
							onChange: function ( value ) {
								setAttributes( { loginMessage: value } );
							},
						} )
					)
				),
				createElement(
					'div',
					{ className: 'wp-qurl-mint-editor__preview' },
					createElement( 'strong', null, __( 'Mint qURL', 'wp-qurl' ) ),
					createElement(
						'p',
						null,
						attributes.endpoint
							? __( 'Page:', 'wp-qurl' ) + ' ' + attributes.endpoint
							: __( 'Choose a page in the block settings.', 'wp-qurl' )
					),
					allowVisitors && createElement(
						'p',
						{ className: 'description' },
						__( 'People who are not logged in can make a link.', 'wp-qurl' )
					),
					createElement(
						'button',
						{ type: 'button', className: 'button button-primary', disabled: true },
						attributes.buttonText || __( 'Mint qURL', 'wp-qurl' )
					)
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
