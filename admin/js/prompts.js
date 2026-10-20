/**
 * Prompts screen.
 *
 * Dialogs (open, close, focus trap, Esc) come from the admin UI kit. This fills
 * them: the details view, the create/edit form, the delete confirmation, and the
 * JSON check on the Arguments field.
 */
(function () {
	'use strict';

	var data = window.specfluxMacPrompts || { customPrompts: {}, strings: {} };
	var prompts = data.customPrompts || {};
	var strings = data.strings || {};

	function byId(id) {
		return document.getElementById(id);
	}

	function el(tag, text, className) {
		var node = document.createElement(tag);
		if (className) {
			node.className = className;
		}
		if (text !== undefined && text !== null) {
			node.textContent = text;
		}
		return node;
	}

	// ---- Details dialog.
	function fillView(id) {
		var prompt = prompts[id];
		if (!prompt) {
			return;
		}

		byId('modal-prompt-label').textContent = prompt.label || prompt.name || id;
		byId('modal-prompt-id').textContent = id;
		byId('modal-prompt-description').textContent = prompt.description || '';
		byId('modal-prompt-instructions').textContent = prompt.instructions || '';

		var list = byId('modal-prompt-arguments');
		var section = byId('modal-prompt-arguments-section');
		list.textContent = '';

		if (!prompt.arguments || !prompt.arguments.length) {
			section.hidden = true;
			return;
		}

		prompt.arguments.forEach(function (arg) {
			var item = el('li');
			var head = el('div', null, 'smac-arg-head');
			head.appendChild(el('code', arg.name, 'aui-code'));
			head.appendChild(el('span', arg.type, 'aui-muted'));
			if (arg.required) {
				head.appendChild(el('span', strings.required || 'Required', 'aui-badge aui-badge--warn'));
			}
			item.appendChild(head);
			if (arg.description) {
				item.appendChild(el('div', arg.description));
			}
			if (arg['default'] !== undefined && arg['default'] !== null) {
				var def = el('div', (strings.defaultLabel || 'Default:') + ' ', 'aui-muted');
				def.appendChild(el('code', String(arg['default']), 'aui-code'));
				item.appendChild(def);
			}
			list.appendChild(item);
		});
		section.hidden = false;
	}

	// ---- Create / edit dialog.
	var formDialog = byId('smac-prompt-form');
	var form = byId('create-prompt-form');

	function setMode(mode, id) {
		var editing = mode === 'edit';
		byId('smac-prompt-action').value = editing ? 'update_prompt' : 'create_prompt';
		byId('smac-prompt-edit-id').value = editing ? id : '';
		byId('smac-prompt-form-title').textContent = formDialog.getAttribute(editing ? 'data-title-edit' : 'data-title-create');
		byId('smac-prompt-submit').textContent = formDialog.getAttribute(editing ? 'data-submit-edit' : 'data-submit-create');
		byId('prompt_name').readOnly = editing;
		clearArgumentsError();
	}

	function fillForm(id) {
		var prompt = prompts[id] || {};
		byId('prompt_name').value = prompt.name || '';
		byId('prompt_label').value = prompt.label || '';
		byId('prompt_description').value = prompt.description || '';
		byId('prompt_instructions').value = prompt.instructions || '';
		byId('prompt_arguments').value = prompt.arguments && prompt.arguments.length
			? JSON.stringify(prompt.arguments, null, 2)
			: '';
	}

	// ---- Delete dialog.
	function fillDelete(trigger) {
		byId('smac-delete-id').value = trigger.getAttribute('data-prompt-id');
		byId('smac-delete-name').textContent = trigger.getAttribute('data-prompt-label') || '';
	}

	// ---- Arguments JSON check.
	var args = byId('prompt_arguments');
	var argsError = byId('prompt_arguments_error');

	function clearArgumentsError() {
		if (args) {
			args.removeAttribute('aria-invalid');
		}
		if (argsError) {
			argsError.hidden = true;
		}
	}

	document.addEventListener('click', function (event) {
		var trigger = event.target.closest('[data-smac-prompt]');
		if (!trigger) {
			return;
		}
		var kind = trigger.getAttribute('data-smac-prompt');
		var id = trigger.getAttribute('data-prompt-id');

		if (kind === 'view') {
			fillView(id);
		} else if (kind === 'edit') {
			setMode('edit', id);
			fillForm(id);
		} else if (kind === 'new') {
			setMode('create', '');
			form.reset();
		} else if (kind === 'delete') {
			fillDelete(trigger);
		}
	});

	if (form) {
		form.addEventListener('submit', function (event) {
			var raw = args.value.trim();
			if (!raw) {
				return;
			}
			try {
				JSON.parse(raw);
			} catch (error) {
				event.preventDefault();
				args.setAttribute('aria-invalid', 'true');
				argsError.hidden = false;
				args.focus();
			}
		});

		args.addEventListener('input', clearArgumentsError);
	}

	// A failed save reopens the form: keep its mode (the server rendered the right action).
	if (formDialog && !formDialog.hidden && byId('smac-prompt-action').value === 'update_prompt') {
		byId('prompt_name').readOnly = true;
	}

	// The example under the Arguments field is a kit [aria-expanded][aria-controls] toggle;
	// only its label changes here.
	var example = byId('show-argument-example');
	if (example) {
		example.addEventListener('click', function () {
			window.setTimeout(function () {
				example.textContent = example.getAttribute('aria-expanded') === 'true'
					? (strings.hideExample || 'Hide example')
					: (strings.showExample || 'Show example');
			}, 0);
		});
	}
})();
