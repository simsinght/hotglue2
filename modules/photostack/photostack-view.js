/**
 *	modules/photostack/photostack-view.js
 *	View mode code for photostack objects - click to cycle through images
 */

$(document).ready(function() {
	$('.photostack').click(function(e) {
		var obj = $(this);
		var current = parseInt(obj.attr('data-photostack-current')) || 0;
		var count = parseInt(obj.attr('data-photostack-count')) || 1;

		// Don't do anything if there's only one or no images
		if (count <= 1) {
			return;
		}

		// Advance to next, wrapping at the end back to 0
		var next = (current + 1) % count;

		obj.attr('data-photostack-current', next);

		// Update layer visibility
		obj.find('.photostack-layer').each(function() {
			var index = parseInt($(this).attr('data-index'));
			if (index <= next) {
				$(this).css('opacity', '1');
				$(this).css('pointer-events', '');
				$(this).removeClass('photostack-ghost');
			} else {
				// Check for ghost opacity
				var ghost = $(this).data('ghost') || 0;
				if (ghost > 0) {
					$(this).css('opacity', ghost / 100);
					$(this).addClass('photostack-ghost');
				} else {
					$(this).css('opacity', '0');
					$(this).css('pointer-events', 'none');
				}
			}
		});
	});
});
