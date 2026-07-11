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
						{ title: __( 'qURL Settings', 'wp-qurl' ), initialOpen: true },
						createElement( TextControl, {
							label: __( 'Endpoint', 'wp-qurl' ),
							help: __( 'Relative path (e.g. /private-page/) or full HTTPS URL users will access via qURL.', 'wp-qurl' ),
							value: attributes.endpoint,
							onChange: function ( value ) {
								setAttributes( { endpoint: value } );
							},
						} ),
						createElement( ToggleControl, {
							label: __( 'Allow visitors', 'wp-qurl' ),
							help: __( 'When enabled, logged-out visitors can generate an access link without signing in.', 'wp-qurl' ),
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
					createElement( 'strong', null, __( 'qURL Access Link', 'wp-qurl' ) ),
					createElement(
						'p',
						null,
						attributes.endpoint
							? __( 'Endpoint:', 'wp-qurl' ) + ' ' + attributes.endpoint
							: __( 'Set an endpoint in the block sidebar.', 'wp-qurl' )
					),
					allowVisitors && createElement(
						'p',
						{ className: 'description' },
						__( 'Visitors can generate links without logging in.', 'wp-qurl' )
					),
					createElement(
						'button',
						{ type: 'button', className: 'button button-primary', disabled: true },
						attributes.buttonText || __( 'Generate Access Link', 'wp-qurl' )
					)
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
