/**
 * Onboarding Wizard JavaScript
 *
 * Handles step navigation, progress bar updates, and wizard dismissal.
 *
 * @package Marketing_Analytics_MCP
 */

/* global jQuery, macOnboardingWizard */
( function( $ ) {
	'use strict';

	var wizard = {
		currentStep: 1,
		totalSteps: 4,

		init: function() {
			var $wizard = $( '#smac-onboarding-wizard' );
			if ( ! $wizard.length ) {
				return;
			}

			this.currentStep = parseInt( $wizard.data( 'initial-step' ), 10 ) || 1;
			this.showStep( this.currentStep );
			this.bindEvents();
		},

		bindEvents: function() {
			var self = this;

			$( document ).on( 'click', '.smac-wizard-next', function( e ) {
				e.preventDefault();
				self.goToStep( self.currentStep + 1 );
			} );

			$( document ).on( 'click', '.smac-wizard-prev', function( e ) {
				e.preventDefault();
				self.goToStep( self.currentStep - 1 );
			} );

			$( document ).on( 'click', '#smac-wizard-skip', function( e ) {
				e.preventDefault();
				self.dismissWizard();
			} );

			$( document ).on( 'click', '#smac-wizard-dismiss', function( e ) {
				e.preventDefault();
				self.dismissWizard();
			} );
		},

		goToStep: function( step ) {
			if ( step < 1 || step > this.totalSteps ) {
				return;
			}

			this.hideStep( this.currentStep );
			this.currentStep = step;
			this.showStep( step );
		},

		showStep: function( step ) {
			var $step = $( '.smac-wizard-step[data-step="' + step + '"]' );
			$step.addClass( 'active' );

			// Update progress bar.
			this.updateProgress( step );

			// Update step dots.
			$( '.smac-wizard-step-dot' ).removeClass( 'active completed' );
			$( '.smac-wizard-step-dot' ).each( function() {
				var dotStep = parseInt( $( this ).data( 'step' ), 10 );
				if ( dotStep < step ) {
					$( this ).addClass( 'completed' );
				} else if ( dotStep === step ) {
					$( this ).addClass( 'active' );
				}
			} );
		},

		hideStep: function( step ) {
			$( '.smac-wizard-step[data-step="' + step + '"]' ).removeClass( 'active' );
		},

		updateProgress: function( step ) {
			var progress = ( ( step - 1 ) / ( this.totalSteps - 1 ) ) * 100;
			$( '#smac-wizard-progress-bar' ).css( 'width', progress + '%' );
		},

		dismissWizard: function() {
			var $wizard = $( '#smac-onboarding-wizard' );

			$.ajax( {
				url: macOnboardingWizard.ajaxUrl,
				type: 'POST',
				data: {
					action: 'specflux_mac_dismiss_wizard',
					nonce: macOnboardingWizard.nonce
				},
				success: function() {
					$wizard.slideUp( 300, function() {
						$wizard.remove();

						// Show the getting started section that was hidden.
						$( '.smac-getting-started' ).show();
					} );
				},
				error: function() {
					// Dismiss locally even if AJAX fails.
					$wizard.slideUp( 300, function() {
						$wizard.remove();
						$( '.smac-getting-started' ).show();
					} );
				}
			} );
		}
	};

	$( document ).ready( function() {
		wizard.init();
	} );
} )( jQuery );
