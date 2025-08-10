( function () {
	$( () => {
		let switchingNoHash;

		const tabs = OO.ui.infuse( $( '.ext-requestcustomdomain-tabs' ) );
		tabs.$element.addClass( 'ext-requestcustomdomain-tabs-infused' );

		function enhancePanel( panel ) {
			const $infuse = $( panel.$element ).find( '.ext-requestcustomdomain-infuse' );
			$infuse.each( function () {
				try {
					OO.ui.infuse( this );
				} catch ( error ) {
					return;
				}
			} );

			if ( !panel.$element.data( 'mw-section-infused' ) ) {
				panel.$element.removeClass( 'mw-htmlform-autoinfuse-lazy' );
				mw.hook( 'htmlform.enhance' ).fire( panel.$element );
				panel.$element.data( 'mw-section-infused', true );
			}
		}

		function onTabPanelSet( panel ) {
			if ( switchingNoHash ) {
				return;
			}
			// Handle hash manually to prevent jumping,
			// therefore save and restore scrollTop to prevent jumping.
			const scrollTop = $( window ).scrollTop();
			// Changing the hash apparently causes keyboard focus to be lost?
			// Save and restore it. This makes no sense though.
			const active = document.activeElement;
			location.hash = '#' + panel.getName();
			if ( active ) {
				active.focus();
			}
			$( window ).scrollTop( scrollTop );
		}

		tabs.on( 'set', onTabPanelSet );

		/**
		 * @ignore
		 * @param {string} name The name of a tab
		 * @param {boolean} [noHash] A hash will be set according to the current
		 *  open section. Use this flag to suppress this.
		 */
		function switchRequestCustomDomainTab( name, noHash ) {
			if ( noHash ) {
				switchingNoHash = true;
			}
			tabs.setTabPanel( name );
			enhancePanel( tabs.getCurrentTabPanel() );
			if ( noHash ) {
				switchingNoHash = false;
			}
		}

		// Jump to correct section as indicated by the hash.
		// This function is called onload and onhashchange.
		function detectHash() {
			const hash = location.hash;
			let matchedElement, $parentSection;
			if ( hash.match( /^#mw-section-[\w-]+$/ ) ) {
				mw.storage.session.remove( 'requestcustomdomain-prevTab' );
				switchRequestCustomDomainTab( hash.slice( 1 ) );
			} else if ( hash.match( /^#mw-[\w-]+$/ ) ) {
				matchedElement = document.getElementById( hash.slice( 1 ) );
				$parentSection = $( matchedElement ).closest( '.ext-requestcustomdomain-section-fieldset' );
				if ( $parentSection.length ) {
					mw.storage.session.remove( 'requestcustomdomain-prevTab' );
					// Switch to proper tab and scroll to selected item.
					switchRequestCustomDomainTab( $parentSection.attr( 'id' ), true );
					matchedElement.scrollIntoView();
				}
			}
		}

		$( window ).on( 'hashchange', () => {
			const hash = location.hash;
			if ( hash.match( /^#mw-[\w-]+/ ) ) {
				detectHash();
			} else if ( hash === '' ) {
				switchRequestCustomDomainTab( $( '[id*=mw-section-]' ).attr( 'id' ), true );
			}
		} )
			// Run the function immediately to select the proper tab on startup.
			.trigger( 'hashchange' );

		// Restore the active tab after saving
		const previousTab = mw.storage.session.get( 'requestcustomdomain-prevTab' );
		if ( previousTab ) {
			switchRequestCustomDomainTab( previousTab, true );
			// Deleting the key, the tab states should be reset until we press Save
			mw.storage.session.remove( 'requestcustomdomain-prevTab' );
		}

		$( '#requestcustomdomain-form' ).on( 'submit', () => {
			const value = tabs.getCurrentTabPanelName();
			mw.storage.session.set( 'requestcustomdomain-prevTab', value );
		} );
	} );
}() );
