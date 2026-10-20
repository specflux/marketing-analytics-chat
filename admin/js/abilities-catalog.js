/**
 * Abilities screen.
 *
 * The kit's [data-aui-filterbox] hides non-matching rows ([data-kind] and the
 * search box). This adds the rest: per-group counts, hiding groups with no
 * matches, opening matching groups while a search is active, and a live status.
 */
(function () {
	'use strict';

	var box = document.querySelector('[data-aui-filterbox]');
	var list = document.getElementById('smac-ability-list');
	if (!box || !list) {
		return;
	}

	var search = box.querySelector('[data-aui-search]');
	var none = document.getElementById('smac-ability-none');
	var status = document.getElementById('smac-ability-status');
	var clear = document.getElementById('smac-ability-clear');
	var showTiers = list.getAttribute('data-show-tiers') === '1';
	var total = parseInt(list.getAttribute('data-total'), 10) || 0;

	// Mirror of Abilities_Catalog::group_meta(); the singular and plural come from the server.
	var strings = (window.specfluxMacAbilities && window.specfluxMacAbilities.strings) || {};

	function format(template, values) {
		return String(template).replace(/%(\d)\$d|%d/g, function (match, index) {
			return values[index ? parseInt(index, 10) - 1 : 0];
		});
	}

	function meta(shown, all, free, pro) {
		var text = shown === all
			? format(all === 1 ? strings.abilityOne : strings.abilityMany, [all])
			: format(strings.shownOf, [shown, all]);
		if (showTiers) {
			text += ' · ' + format(strings.tierSplit, [free, pro]);
		}
		return text;
	}

	function update() {
		var query = search ? search.value.trim() : '';
		var shownTotal = 0;

		list.querySelectorAll('.aui-group').forEach(function (group) {
			var rows = group.querySelectorAll('.aui-item');
			var visible = group.querySelectorAll('.aui-item:not([hidden])');
			var free = group.querySelectorAll('.aui-item[data-kind="free"]:not([hidden])').length;
			var pro = group.querySelectorAll('.aui-item[data-kind="pro"]:not([hidden])').length;
			var label = group.querySelector('[data-meta]');
			var toggle = group.querySelector('.aui-group-toggle');
			var body = group.querySelector('.aui-group-b');

			shownTotal += visible.length;
			group.hidden = visible.length === 0;
			if (label) {
				label.textContent = meta(visible.length, rows.length, free, pro);
			}

			// While searching, open the groups that have matches so they are not hidden behind a collapse.
			if (query && visible.length && toggle && body) {
				toggle.setAttribute('aria-expanded', 'true');
				body.hidden = false;
			}
		});

		if (none) {
			none.hidden = shownTotal !== 0;
		}
		if (status && strings.status) {
			status.textContent = format(strings.status, [shownTotal, total]);
		}
	}

	// The kit filters on the same events; wait a tick so its row state is final.
	function later() {
		window.setTimeout(update, 0);
	}

	box.addEventListener('input', later);
	box.addEventListener('click', function (event) {
		if (event.target.closest('[data-aui-filter] button')) {
			later();
		}
	});

	if (clear && search) {
		clear.addEventListener('click', function () {
			search.value = '';
			search.dispatchEvent(new Event('input', { bubbles: true }));
			search.focus();
		});
	}

	update();
	if (status) {
		status.textContent = '';
	}
})();
