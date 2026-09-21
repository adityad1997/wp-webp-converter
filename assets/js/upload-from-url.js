/**
 * Adds "Upload from URL" into the core Upload files panel (UploaderInline).
 */
( function ( $, _ ) {
	'use strict';

	if ( typeof wp === 'undefined' || ! wp.media || ! wp.media.view || ! wp.media.view.UploaderInline ) {
		return;
	}

	var l10n = window.webpConverterUploadFromUrl || {};
	var i18n = l10n.i18n || {};
	var OriginalUploaderInline = wp.media.view.UploaderInline;

	/**
	 * Sideload a remote image URL into the Media Library.
	 *
	 * @param {string} url Image URL.
	 * @param {wp.media.view.MediaFrame} controller Frame controller.
	 * @return {jQuery.Promise}
	 */
	function sideloadFromUrl( url, controller ) {
		var deferred = $.Deferred();

		$.ajax( {
			url: l10n.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'webp_converter_upload_from_url',
				nonce: l10n.nonce,
				url: url,
				alt: ''
			}
		} )
			.done( function ( response ) {
				var attachment;
				var state;
				var library;
				var selection;

				if ( ! response || ! response.success || ! response.data ) {
					deferred.reject(
						( response && response.data && response.data.message ) || i18n.uploadFailed
					);
					return;
				}

				attachment = wp.media.model.Attachment.create( response.data );

				if ( controller ) {
					state = controller.state();
					library = state && state.get ? state.get( 'library' ) : null;
					selection = state && state.get ? state.get( 'selection' ) : null;

					if ( library ) {
						library.add( attachment );
					}
					if ( selection ) {
						selection.reset( [ attachment ] );
					}

					if ( controller.content && controller.content.mode ) {
						try {
							controller.content.mode( 'browse' );
						} catch ( e ) {
							// Manage / custom frames may not support browse mode.
						}
					}

					controller.trigger( 'webp:uploaded-from-url', attachment );
				}

				deferred.resolve( attachment );
			} )
			.fail( function ( xhr ) {
				var message = i18n.uploadFailed;
				if ( xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
					message = xhr.responseJSON.data.message;
				}
				deferred.reject( message );
			} );

		return deferred.promise();
	}

	wp.media.view.UploaderInline = OriginalUploaderInline.extend( {
		events: _.extend( {}, OriginalUploaderInline.prototype.events || {}, {
			'input .webp-upload-from-url__input': 'onUrlInput',
			'change .webp-upload-from-url__input': 'onUrlInput',
			'paste .webp-upload-from-url__input': 'onUrlPaste',
			'keyup .webp-upload-from-url__input': 'onUrlInput',
			'click .webp-upload-from-url__submit': 'onUrlSubmit',
			'keydown .webp-upload-from-url__input': 'onUrlKeydown'
		} ),

		render: function () {
			OriginalUploaderInline.prototype.render.apply( this, arguments );
			this.injectUrlForm();
			return this;
		},

		injectUrlForm: function () {
			var $uploadUi = this.$( '.upload-ui' );
			var $form;

			if ( ! $uploadUi.length || this.$( '.webp-upload-from-url' ).length ) {
				return;
			}

			$form = $(
				'<div class="webp-upload-from-url">' +
					'<p class="upload-instructions webp-upload-from-url__or">' +
						_.escape( i18n.orPasteUrl || 'or' ) +
					'</p>' +
					'<div class="webp-upload-from-url__row">' +
						'<label class="webp-upload-from-url__label">' +
							'<span class="screen-reader-text">' +
								_.escape( i18n.title || 'Upload from URL' ) +
							'</span>' +
							'<input type="url" class="webp-upload-from-url__input" placeholder="' +
								_.escape( i18n.urlPlaceholder || 'https://' ) +
							'" />' +
						'</label>' +
						'<button type="button" class="button button-primary webp-upload-from-url__submit" disabled>' +
							_.escape( i18n.button || 'Upload' ) +
						'</button>' +
						'<span class="spinner"></span>' +
					'</div>' +
					'<p class="webp-upload-from-url__error" hidden></p>' +
				'</div>'
			);

			$uploadUi.append( $form );
			this.$urlInput = $form.find( '.webp-upload-from-url__input' );
			this.$urlSubmit = $form.find( '.webp-upload-from-url__submit' );
			this.$urlSpinner = $form.find( '.spinner' );
			this.$urlError = $form.find( '.webp-upload-from-url__error' );
		},

		getUrlValue: function ( event ) {
			if ( this.$urlInput && this.$urlInput.length ) {
				return ( this.$urlInput.val() || '' ).trim();
			}
			if ( event && event.target ) {
				return ( event.target.value || '' ).trim();
			}
			return '';
		},

		onUrlPaste: function ( event ) {
			var view = this;
			// Paste updates the value after the event; refresh enable state on next tick.
			_.defer( function () {
				view.onUrlInput( event );
			} );
		},

		onUrlInput: function ( event ) {
			var url = this.getUrlValue( event );
			var disabled = ! /^https?:\/\//i.test( url ) || !! this._urlUploading;

			this.clearUrlError();
			if ( this.$urlSubmit && this.$urlSubmit.length ) {
				this.$urlSubmit.prop( 'disabled', disabled );
			}
		},

		onUrlKeydown: function ( event ) {
			if ( 13 === event.which ) {
				event.preventDefault();
				this.onUrlSubmit();
			}
		},

		onUrlSubmit: function () {
			var view = this;
			var url = this.getUrlValue();

			if ( this._urlUploading || ! /^https?:\/\//i.test( url ) ) {
				return;
			}

			this._urlUploading = true;
			this.clearUrlError();
			this.$urlSubmit.prop( 'disabled', true ).text( i18n.uploading || 'Uploading…' );
			this.$urlSpinner.addClass( 'is-active' );
			this.$el.addClass( 'webp-upload-from-url--loading' );

			sideloadFromUrl( url, this.controller )
				.done( function ( attachment ) {
					view.$urlInput.val( '' );
					view.onUrlInput();

					// Media Library grid inline uploader (no modal): open the new item.
					var attachmentId = attachment && attachment.get ? attachment.get( 'id' ) : null;
					if (
						attachmentId &&
						l10n.pagenow === 'upload' &&
						view.controller &&
						! view.controller.modal
					) {
						window.location.href = 'upload.php?item=' + encodeURIComponent( attachmentId );
					}
				} )
				.fail( function ( message ) {
					view.showUrlError( message || i18n.uploadFailed );
				} )
				.always( function () {
					view._urlUploading = false;
					view.$urlSubmit.text( i18n.button || 'Upload' );
					view.$urlSpinner.removeClass( 'is-active' );
					view.$el.removeClass( 'webp-upload-from-url--loading' );
					view.onUrlInput();
				} );
		},

		showUrlError: function ( message ) {
			this.$urlError.text( message ).prop( 'hidden', false );
		},

		clearUrlError: function () {
			if ( this.$urlError ) {
				this.$urlError.text( '' ).prop( 'hidden', true );
			}
		}
	} );
}( jQuery, window._ ) );
