(function() {
	'use strict';

	var filterButtons = document.querySelectorAll('.smac-abilities-filter-btn');
	var cards = document.querySelectorAll('.smac-ability-card');

	filterButtons.forEach(function(btn) {
		btn.addEventListener('click', function() {
			var filter = this.getAttribute('data-filter');

			// Update active button.
			filterButtons.forEach(function(b) { b.classList.remove('active'); });
			this.classList.add('active');

			// Filter cards.
			cards.forEach(function(card) {
				if ('all' === filter || card.getAttribute('data-category') === filter) {
					card.style.display = '';
				} else {
					card.style.display = 'none';
				}
			});
		});
	});
})();
