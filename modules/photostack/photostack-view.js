/**
 *	modules/photostack/photostack-view.js
 *	View mode code for photostack objects - click to cycle through images
 */

function getClickAreas(obj, layerIndex) {
	var raw = obj.attr('data-layer-clickareas');
	if (!raw) return [];
	try {
		var map = JSON.parse(raw);
		return map[layerIndex] || [];
	} catch (e) {
		return [];
	}
}

function hideAllClickAreas(obj) {
	obj.find('.photostack-click-area').css('display', 'none');
}

function showClickAreas(obj, layerIndex) {
	obj.find('.photostack-click-area[data-layer="' + layerIndex + '"]').css('display', 'block');
}

$(document).ready(function() {
	// Initialize click areas for each photostack
	$('.photostack').each(function() {
		var obj = $(this);
		hideAllClickAreas(obj);
		showClickAreas(obj, parseInt(obj.attr('data-photostack-current')) || 0);
	});

	$('.photostack').click(function(e) {
		var obj = $(this);
		var current = parseInt(obj.attr('data-photostack-current')) || 0;
		var count = parseInt(obj.attr('data-photostack-count')) || 1;

		// Don't do anything if there's only one or no images
		if (count <= 1) {
			return;
		}

		// Check if click areas are defined for the current layer
		var areas = getClickAreas(obj, current);
		if (areas.length > 0) {
			var offset = obj.offset();
			var stackW = obj.outerWidth();
			var stackH = obj.outerHeight();
			var clickX = e.pageX - offset.left;
			var clickY = e.pageY - offset.top;
			var inArea = false;
			for (var i = 0; i < areas.length; i++) {
				var a = areas[i];
				var aLeft = (a.x / 100) * stackW;
				var aTop = (a.y / 100) * stackH;
				var aRight = aLeft + (a.w / 100) * stackW;
				var aBottom = aTop + (a.h / 100) * stackH;
				if (clickX >= aLeft && clickX <= aRight && clickY >= aTop && clickY <= aBottom) {
					inArea = true;
					break;
				}
			}
			if (!inArea) {
				return;
			}
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

		// Update click area visibility for the new layer
		hideAllClickAreas(obj);
		showClickAreas(obj, next);
	});
});
