/**
 *	modules/transform/transform.js
 *	Frontend code for general object properties
 *
 *	Copyright Gottfried Haider, Danja Vasiliev 2010.
 *	This source code is licensed under the GNU General Public License.
 *	See the file COPYING for more details.
 */

$(document).ready(function() {
	//
	// register menu items
	//
	var elem;
	elem = $('<img src="'+$.glue.base_url+'modules/transform/transform-flip.png" alt="btn" title="flip object horizontally" width="32" height="32">');
	$(elem).bind('click', function(e) {
		var obj = $(this).data('owner');

		// Parse current transform to preserve rotation
		var currentRotation = 0;
		var currentFlip = 1;
		var transform = $(obj).css('transform') || $(obj).css('-webkit-transform') || $(obj).css('-moz-transform') || '';

		if (transform && transform !== 'none') {
			var values = transform.match(/matrix\(([^)]+)\)/);
			if (values && values[1]) {
				var parts = values[1].split(',');
				if (parts.length >= 4) {
					var a = parseFloat(parts[0]);
					var b = parseFloat(parts[1]);
					// Extract rotation and flip from matrix
					var scaleX = Math.sqrt(a*a + b*b);
					currentFlip = (a < 0 && b < 0) || (a < 0 && Math.abs(b) < 0.01) ? -1 : 1;
					currentRotation = Math.round(Math.atan2(b, a) * (180/Math.PI));
				}
			}
		}

		// Toggle flip
		var newFlip = (currentFlip === -1) ? 1 : -1;

		// Build combined transform
		var transformValue = 'scaleX(' + newFlip + ') rotate(' + currentRotation + 'deg)';
		$(obj).css('transform', transformValue);
		$(obj).css('-webkit-transform', transformValue);
		$(obj).css('-moz-transform', transformValue);

		$.glue.object.save(obj);
	});
	$.glue.contextmenu.register('object', 'object-transform-flip', elem, 5);

	// rotation control - drag up/down to rotate
	elem = $('<img src="'+$.glue.base_url+'modules/transform/transform-rotate.png" alt="btn" title="rotate object (drag up/down)" width="32" height="32">');
	$(elem).bind('mousedown', function(e) {
		var obj = $(this).data('owner');

		// Parse current transform to preserve flip
		var currentRotation = 0;
		var currentFlip = 1;
		var transform = $(obj).css('transform') || $(obj).css('-webkit-transform') || $(obj).css('-moz-transform');

		if (transform && transform !== 'none') {
			var values = transform.match(/matrix\(([^)]+)\)/);
			if (values && values[1]) {
				var parts = values[1].split(',');
				if (parts.length >= 4) {
					var a = parseFloat(parts[0]);
					var b = parseFloat(parts[1]);
					// Extract rotation and flip from matrix
					currentFlip = (a < 0 && b < 0) || (a < 0 && Math.abs(b) < 0.01) ? -1 : 1;
					currentRotation = Math.round(Math.atan2(b, a) * (180/Math.PI));
				}
			}
		}

		$.glue.slider(e, function(x, y) {
			// Rotate by dragging vertically - 1 pixel = 1 degree
			var rotation = currentRotation + y;
			// Build combined transform preserving flip
			var transformValue = 'scaleX(' + currentFlip + ') rotate(' + rotation + 'deg)';
			$(obj).css('transform', transformValue);
			$(obj).css('-webkit-transform', transformValue);
			$(obj).css('-moz-transform', transformValue);
		}, function(x, y) {
			// Save when done
			$.glue.object.save(obj);
		});
		return false;
	});
	$.glue.contextmenu.register('object', 'object-transform-rotate', elem, 6);

});
