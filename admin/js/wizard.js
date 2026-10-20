(function($) {
	'use strict';

	let currentStep = 1;
	const totalSteps = 6;
	const strings = (typeof specfluxMacWizard !== 'undefined' && specfluxMacWizard) || {};
	const reducedMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
	const slideMs = reducedMotion ? 0 : 200;

	function t(key, fallback) {
		return strings[key] || fallback;
	}

	function icon(name) {
		return '<svg class="aui-i" aria-hidden="true" focusable="false"><use href="#aui-i-' + name + '"/></svg>';
	}

	// Initialize wizard on document ready
	$(document).ready(function() {
		// Only initialize if wizard container exists
		if ($('.wizard-container').length === 0) {
			return;
		}

		initWizard();
		setupEventListeners();
		loadSavedProgress();
	});

	function initWizard() {
		// Show only first step
		$('.wizard-step').not('[data-step="1"]').hide();

		updateStepper();

		// Check if credentials already exist
		if ($('#google_client_id').val()) {
			// Jump to step 6 if credentials exist
			goToStep(6);
		}
	}

	function setupEventListeners() {
		// Navigation buttons
		$('.wizard-next').on('click', handleNextStep);
		$('.wizard-prev').on('click', handlePrevStep);

		// Step confirmations
		$('input[type="checkbox"][id^="step"]').on('change', handleStepConfirmation);

		// API enable checkboxes
		$('.api-enabled-checkbox').on('change', checkAllAPIsEnabled);

		// Credential validation
		$('#google_client_id, #google_client_secret').on('blur', validateCredentialField);

		// Form submission
		$('#credentials-form').on('submit', handleCredentialSave);

		// Password toggle
		$('.toggle-password').on('click', togglePasswordVisibility);

		// Stepper clicks (allow jumping to completed steps)
		$('.progress-step').on('click', handleProgressStepClick);
	}

	function goToStep(stepNumber) {
		if (stepNumber < 1 || stepNumber > totalSteps) return;

		// Hide current step
		$(`.wizard-step[data-step="${currentStep}"]`).slideUp(slideMs);

		// Show target step
		setTimeout(function() {
			$(`.wizard-step[data-step="${stepNumber}"]`).slideDown(slideMs);

			// Update active states
			$('.wizard-step').removeClass('active');
			$(`.wizard-step[data-step="${stepNumber}"]`).addClass('active');

			currentStep = stepNumber;

			// Update navigation buttons and stepper
			updateNavigationButtons();
			updateStepper();

			// Save progress
			saveProgress();

			// Scroll to wizard
			$('html, body').animate({
				scrollTop: $('.wizard-container').offset().top - 32
			}, reducedMotion ? 0 : 200);
		}, slideMs);
	}

	function handleNextStep() {
		// Validate current step before proceeding
		if (!validateStep(currentStep)) {
			showValidationError(currentStep);
			return;
		}

		// Mark current step as complete
		markStepComplete(currentStep);

		// Go to next step
		goToStep(currentStep + 1);
	}

	function handlePrevStep() {
		goToStep(currentStep - 1);
	}

	function hasExistingSecret() {
		return $('#google_client_secret').data('hasSecret') === 1;
	}

	function validateStep(stepNumber) {
		switch(stepNumber) {
			case 1:
				return $('#step1-complete').is(':checked');
			case 2:
				return $('.api-enabled-checkbox:checked').length === 3;
			case 3:
				return $('#step3-complete').is(':checked');
			case 4:
				return $('#step4-complete').is(':checked');
			case 5:
				// Validate credential format
				const clientId = $('#google_client_id').val();
				const clientSecret = $('#google_client_secret').val();
				return validateClientIdFormat(clientId) &&
					   (validateClientSecretFormat(clientSecret) || hasExistingSecret());
			default:
				return true;
		}
	}

	function showMessage(stepNumber, message) {
		const $notice = $('<div class="aui-notice aui-notice--error" role="alert">' + icon('warning') + '<div></div></div>');
		$notice.find('div').text(message);
		$(`.wizard-step[data-step="${stepNumber}"] .step-content`).prepend($notice);

		setTimeout(function() {
			$notice.slideUp(slideMs, function() { $(this).remove(); });
		}, 4000);
	}

	function showValidationError(stepNumber) {
		let message = t('completeStep', 'Please complete this step before continuing.');

		if (stepNumber === 2) {
			message = t('enableAllApis', 'Please enable all 3 APIs before continuing.');
			$('.apis-remaining').slideDown(slideMs);
		}

		showMessage(stepNumber, message);
	}

	function markStepComplete(stepNumber) {
		$(`.progress-step[data-step="${stepNumber}"]`).addClass('is-done');
		$(`.wizard-step[data-step="${stepNumber}"]`).addClass('completed');
	}

	// Reflect state on the kit stepper: done steps show a check, the current one is marked.
	function updateStepper() {
		$('.progress-step').each(function() {
			const $step = $(this);
			const n = parseInt($step.data('step'), 10);
			const $badge = $step.find('.aui-step-n');

			if (n === currentStep) {
				$step.attr('aria-current', 'step');
			} else {
				$step.removeAttr('aria-current');
			}

			if ($step.hasClass('is-done') && n !== currentStep) {
				$badge.html(icon('check'));
			} else {
				$badge.text(n);
			}
		});
	}

	function updateNavigationButtons() {
		$('.wizard-prev').prop('disabled', currentStep === 1);
		$('.wizard-next').toggle(currentStep !== totalSteps);
	}

	function handleStepConfirmation() {
		if ($(this).is(':checked')) {
			$('.wizard-next').prop('disabled', false);
		}
	}

	function checkAllAPIsEnabled() {
		const enabledCount = $('.api-enabled-checkbox:checked').length;

		if (enabledCount === 3) {
			$('.apis-remaining').slideUp(slideMs);
			$('.wizard-next').prop('disabled', false);
		} else {
			$('.apis-remaining').slideDown(slideMs);
		}
	}

	function setFeedback($field, isValid, message) {
		const $feedback = $field.closest('.aui-field').find('.validation-feedback');
		$feedback
			.removeClass('is-ok is-bad')
			.addClass(isValid ? 'is-ok' : 'is-bad')
			.html(icon(isValid ? 'check' : 'x'))
			.append($('<span></span>').text(message));
		$field.attr('aria-invalid', isValid ? 'false' : 'true');
	}

	function validateCredentialField() {
		const $field = $(this);
		const value = $field.val().trim();
		const $feedback = $field.closest('.aui-field').find('.validation-feedback');

		if (!value) {
			$feedback.empty().removeClass('is-ok is-bad');
			$field.removeAttr('aria-invalid');
			return;
		}

		if ($field.attr('id') === 'google_client_id') {
			const ok = validateClientIdFormat(value);
			setFeedback($field, ok, ok ?
				t('validClientId', 'Valid Client ID format') :
				t('invalidClientIdHint', 'Invalid format (must end with .apps.googleusercontent.com)'));
		} else if ($field.attr('id') === 'google_client_secret') {
			const ok = validateClientSecretFormat(value);
			setFeedback($field, ok, ok ?
				t('validClientSecret', 'Valid Client Secret format') :
				t('invalidClientSecretHint', 'Invalid format (must start with GOCSPX-)'));
		}
	}

	function validateClientIdFormat(clientId) {
		return /^[0-9]+-[a-z0-9]+\.apps\.googleusercontent\.com$/.test(clientId);
	}

	function validateClientSecretFormat(clientSecret) {
		return clientSecret.startsWith('GOCSPX-');
	}

	function handleCredentialSave(e) {
		e.preventDefault();

		const clientId = $('#google_client_id').val().trim();
		const clientSecret = $('#google_client_secret').val().trim();

		// Validate
		if (!validateClientIdFormat(clientId)) {
			setFeedback($('#google_client_id'), false, t('invalidClientId', 'Invalid Client ID format'));
			$('#google_client_id').trigger('focus');
			return;
		}

		// Client secret optional if already saved
		if (clientSecret && !validateClientSecretFormat(clientSecret)) {
			setFeedback($('#google_client_secret'), false, t('invalidClientSecret', 'Invalid Client Secret format'));
			$('#google_client_secret').trigger('focus');
			return;
		}

		if (!clientSecret && !hasExistingSecret()) {
			setFeedback($('#google_client_secret'), false, t('secretRequired', 'Client Secret is required for initial setup'));
			$('#google_client_secret').trigger('focus');
			return;
		}

		// A disabled submit button is left out of the posted data, so submit first,
		// then lock it against double clicks. The form still posts `save_google_oauth`.
		const form = $('#credentials-form')[0];
		$('<input type="hidden" name="save_google_oauth" value="1" />').appendTo(form);
		$('#save-credentials-btn').prop('disabled', true);
		form.submit();
	}

	function togglePasswordVisibility() {
		const $input = $('#google_client_secret');
		const $button = $(this);
		const showing = $input.attr('type') === 'password';

		$input.attr('type', showing ? 'text' : 'password');
		$button.attr('aria-pressed', showing ? 'true' : 'false');
		$button.find('.toggle-password-label').text(showing ? t('hide', 'Hide') : t('show', 'Show'));
	}

	function handleProgressStepClick() {
		const stepNumber = parseInt($(this).data('step'), 10);

		// Only allow jumping to completed steps or next step
		if ($(this).hasClass('is-done') || stepNumber === currentStep + 1) {
			goToStep(stepNumber);
		}
	}

	function saveProgress() {
		if (!window.localStorage) return;

		const progress = {
			currentStep: currentStep,
			completedSteps: [],
			apiCheckboxes: {}
		};

		// Save completed steps
		$('.wizard-step.completed').each(function() {
			progress.completedSteps.push(parseInt($(this).data('step'), 10));
		});

		// Save API checkboxes
		$('.api-enabled-checkbox').each(function() {
			progress.apiCheckboxes[$(this).data('api')] = $(this).is(':checked');
		});

		localStorage.setItem('wizard_progress_google_oauth', JSON.stringify(progress));
	}

	function loadSavedProgress() {
		if (!window.localStorage) return;

		const saved = localStorage.getItem('wizard_progress_google_oauth');
		if (!saved) return;

		try {
			const progress = JSON.parse(saved);

			// Restore API checkboxes
			if (progress.apiCheckboxes) {
				$.each(progress.apiCheckboxes, function(api, checked) {
					$(`.api-enabled-checkbox[data-api="${api}"]`).prop('checked', checked);
				});
			}

			// Restore completed steps
			if (progress.completedSteps) {
				progress.completedSteps.forEach(function(stepNum) {
					markStepComplete(stepNum);
				});
				updateStepper();
			}

			// Don't auto-restore current step - let user decide
		} catch (e) {
			console.error('Failed to load wizard progress', e);
		}
	}

})(jQuery);
