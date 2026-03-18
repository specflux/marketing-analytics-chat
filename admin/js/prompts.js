jQuery(document).ready(function($) {
	'use strict';

	// Show argument example
	$('#show-argument-example').on('click', function(e) {
		e.preventDefault();
		$('#argument-example').slideToggle();
	});

	// View prompt modal
	$('.view-prompt').on('click', function() {
		var promptId = $(this).data('prompt-id');
		var prompts = macPrompts.customPrompts;
		var prompt = prompts[promptId];

		if (prompt) {
			$('#modal-prompt-label').text(prompt.label || prompt.name);
			$('#modal-prompt-id').text(promptId);
			$('#modal-prompt-description').text(prompt.description);
			$('#modal-prompt-instructions').text(prompt.instructions);

			if (prompt.arguments && prompt.arguments.length > 0) {
				var argsHtml = '<ul>';
				prompt.arguments.forEach(function(arg) {
					argsHtml += '<li><strong>' + $('<span>').text(arg.name).html() + '</strong> (' + $('<span>').text(arg.type).html() + ')';
					if (arg.required) {
						argsHtml += ' <span style="color: red;">*required</span>';
					}
					argsHtml += '<br><em>' + $('<span>').text(arg.description).html() + '</em>';
					if (arg['default'] !== undefined) {
						argsHtml += '<br>Default: <code>' + $('<span>').text(arg['default']).html() + '</code>';
					}
					argsHtml += '</li>';
				});
				argsHtml += '</ul>';
				$('#modal-prompt-arguments').html(argsHtml);
				$('#modal-prompt-arguments-section').show();
			} else {
				$('#modal-prompt-arguments-section').hide();
			}

			$('#prompt-details-modal').fadeIn();
		}
	});

	// Close modal
	$('#close-modal, .prompt-modal-overlay').on('click', function() {
		$('#prompt-details-modal').fadeOut();
	});

	// Validate JSON before submit
	$('#create-prompt-form').on('submit', function(e) {
		var argsJson = $('#prompt_arguments').val().trim();
		if (argsJson) {
			try {
				JSON.parse(argsJson);
			} catch (error) {
				e.preventDefault();
				alert(macPrompts.strings.invalidJson);
				$('#prompt_arguments').focus();
				return false;
			}
		}
	});
});
