/**
 *	modules/object/object-edit.js
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
	elem = $('<img src="'+$.glue.base_url+'modules/object/object-clone.png" alt="btn" title="clone object" width="32" height="32">');
	$(elem).bind('click', function(e) {
		var obj = $(this).data('owner');
		$.glue.backend({ method: 'glue.clone_object', name: $(obj).attr('id') }, function(data) {
			// deselect current object
			$.glue.sel.none();
			var clone = $(obj).clone();
			// set new id
			$(clone).attr('id', data);
			// move object a bit
			$(clone).css('left', ($(obj).position().left+$.glue.grid.x())+'px');
			$(clone).css('top', ($(obj).position().top+$.glue.grid.y())+'px');
			// add to dom and register
			$('body').append(clone);
			$(clone).trigger('glue-pre-clone');
			$.glue.object.register(clone);
			// select new object
			$.glue.sel.select(clone);
			$.glue.object.save(clone);
		});
	});
	$.glue.contextmenu.register('object', 'object-clone', elem, 1);
	
	elem = $('<img src="'+$.glue.base_url+'modules/object/object-transparency.png" alt="btn" title="change transparency" width="32" height="32">');
	$(elem).bind('glue-menu-activate', function(e) {
		var obj = $(this).data('owner');
		var opacity = parseFloat($(obj).css('opacity'))*100;
		var tip = 'change transparency ('+opacity.toFixed(0)+'%)';
		$(this).attr('title', tip);
	});
	$(elem).bind('mousedown', function(e) {
		var that = this;
		var obj = $(this).data('owner');
		$.glue.slider(e, function(x, y) {
			if (x < -15) {
				x = 1-(Math.abs(x)-15)/300;
			} else if (x < 15) {
				// dead zone
				x = 1;
			} else {
				x = 1-(Math.abs(x)-15)/300;
			}
			if (x < 0) {
				x = 0;
			}
			$(obj).css('opacity', x);
		}, function(x, y) {
			$.glue.object.save(obj);
			// update tooltip (see above)
			$(that).trigger('glue-menu-activate');
		});
		return false;
	});
	$.glue.contextmenu.register('object', 'object-transparency', elem, 2);
	
	elem = $('<img src="'+$.glue.base_url+'modules/object/object-zindex.png" alt="btn" title="bring object to foreground or background" width="32" height="32">');
	$(elem).bind('mousedown', function(e) {
		var obj = $(this).data('owner');
		var old_z = parseInt($(obj).css('z-index'));
		$.glue.slider(e, function(x, y) {
			if (x < -15) {
				$.glue.stack.to_bottom($(obj));
			} else if (x < 15) {
				// dead zone
				var z = parseInt($(obj).css('z-index'));
				if (z !== old_z) {
					if (!isNaN(old_z)) {
						$(obj).css('z-index', old_z);
					} else {
						$(obj).css('z-index', '');
					}
					// DEBUG
					//console.log('set z-index to '+old_z);
				}
			} else {
				$.glue.stack.to_top($(obj));
			}
		}, function(x, y) {
			$.glue.object.save(obj);
			$.glue.stack.compress();
		});
		return false;
	});
	$.glue.contextmenu.register('object', 'object-zindex', elem, 3);
	
	elem = $('<img src="'+$.glue.base_url+'modules/object/object-link.png" alt="btn" title="make the object a link" width="32" height="32">');
	$(elem).bind('click', function(e) {
		var obj = $(this).data('owner');
		// get link
		$.glue.backend({ method: 'glue.load_object', name: $(obj).attr('id') }, function(data) {
			if (data['#error']) {
				$.glue.error(data['#error']);
			} else {
				var old_link = '';
				if (data['#data']['object-link'] !== undefined) {
					old_link = data['#data']['object-link'];
				}
				var old_target = '';
				if (data['#data']['object-target'] !== undefined) {
					old_target = data['#data']['object-target'];
				}
				old_linkdata = (old_target == '') ? old_link : old_link + ' ' + old_target;
				var linkdata = prompt('Enter link (e.g. http://hotglue.me or pagename or anchor name).\nTo add target specify its name after a space (e.g. http://hotglue.me _blank)', old_linkdata);
				if (linkdata === null || linkdata == old_link + ' ' + old_target) {
					return;
				}
				t = linkdata.split(' '); // if there is no space split() returns the string
				link = t[0];
				target = t[1];
				
				if (link == undefined) {
					$.glue.backend({ method: 'glue.object_remove_attr', name: $(obj).attr('id'), attr: 'object-link' });
				} else {
					// set link
					$.glue.backend({ method: 'glue.update_object', name: $(obj).attr('id'), 'object-link': link });
					if (target !== undefined) {
						// set target
						$.glue.backend({ method: 'glue.update_object', name: $(obj).attr('id'), 'object-target': target });
					}
				}
				if (old_target !== '' && (target == '' || target == undefined)) {
					// delete target
					$.glue.backend({ method: 'glue.object_remove_attr', name: $(obj).attr('id'), attr: 'object-target' });
				}
			}
		}, false);
	});
	$.glue.contextmenu.register('object', 'object-link', elem);

	elem = $('<img src="'+$.glue.base_url+'modules/object/object-target.png" alt="btn" title="object name & view anchor" width="32" height="32">');
	$(elem).bind('click', function(e) {
		var obj = $(this).data('owner');
		var objId = $(obj).attr('id');
		var name = objId.split('.').pop();
		var fullName = $.glue.page+'.'+name;

		// Load current object data to check view-anchor value
		$.glue.backend({ method: 'glue.load_object', name: objId }, function(data) {
			var currentAnchor = '';
			if (!data['#error'] && data['#data'] && data['#data']['view-anchor']) {
				currentAnchor = data['#data']['view-anchor'];
			}

			var html = '<div style="background:#fff;padding:20px;border-radius:5px;min-width:320px;">';
			html += '<div style="margin-bottom:14px;">';
			html += '<label style="display:block;margin-bottom:4px;font-size:13px;color:#666;">Object name</label>';
			html += '<input type="text" class="anchor-obj-name" value="'+fullName+'" readonly style="width:100%;padding:6px;box-sizing:border-box;background:#f5f5f5;border:1px solid #ccc;font-family:monospace;cursor:text;">';
			html += '</div>';
			html += '<div style="margin-bottom:14px;">';
			html += '<label style="display:block;margin-bottom:6px;font-size:13px;color:#666;">View anchor</label>';
			html += '<div style="display:flex;gap:6px;">';

			var roles = [
				{ value: 'top-left', label: 'Top-left' },
				{ value: 'bottom-right', label: 'Bottom-right' },
				{ value: '', label: 'None' }
			];
			for (var i = 0; i < roles.length; i++) {
				var active = (currentAnchor === roles[i].value);
				var bg = active ? '#333' : '#eee';
				var fg = active ? '#fff' : '#333';
				html += '<button class="anchor-role-btn" data-role="'+roles[i].value+'" style="flex:1;padding:8px 4px;cursor:pointer;border:1px solid #ccc;border-radius:3px;background:'+bg+';color:'+fg+';font-size:13px;">'+roles[i].label+'</button>';
			}

			html += '</div></div>';
			html += '<div style="text-align:right;">';
			html += '<button class="anchor-done-btn" style="padding:8px 16px;cursor:pointer;">Done</button>';
			html += '</div></div>';

			var overlay = $('<div style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:10000;"></div>');
			var dialog = $(html);
			$(overlay).append(dialog);
			$('body').append(overlay);

			$(dialog).bind('mousedown click', function(e) { e.stopPropagation(); });

			// Click name input to select all text
			$(dialog).find('.anchor-obj-name').click(function() { this.select(); });

			// Toggle buttons
			$(dialog).find('.anchor-role-btn').click(function(e) {
				e.stopPropagation();
				var role = $(this).attr('data-role');

				// Update button styles
				$(dialog).find('.anchor-role-btn').each(function() {
					var isActive = ($(this).attr('data-role') === role);
					$(this).css('background', isActive ? '#333' : '#eee');
					$(this).css('color', isActive ? '#fff' : '#333');
				});

				// Save or remove the attribute
				if (role === '') {
					$.glue.backend({ method: 'glue.object_remove_attr', name: objId, attr: 'view-anchor' });
				} else {
					$.glue.backend({ method: 'glue.update_object', name: objId, 'view-anchor': role });
				}
				currentAnchor = role;
			});

			// Done button
			$(dialog).find('.anchor-done-btn').click(function(e) {
				e.stopPropagation();
				$(overlay).remove();
			});

			// Click outside to close
			$(overlay).click(function(e) {
				if (e.target === this) $(overlay).remove();
			});
		}, false);
	});
	$.glue.contextmenu.register('object', 'object-target', elem);
	
	elem = $('<img src="'+$.glue.base_url+'modules/object/object-symlink.png" alt="btn" title="make this object appear on all pages" width="32" height="32">');
	$(elem).bind('click', function(e) {
		var obj = $(this).data('owner');
		$.glue.backend({ method: 'glue.object_make_symlink', name: $(obj).attr('id') });
	});
	$.glue.contextmenu.register('object', 'object-symlink', elem);
	
	elem = $('<img src="'+$.glue.base_url+'modules/object/object-delete.png" alt="btn" title="delete object" width="32" height="32">');
	$(elem).bind('click', function(e) {
		var obj = $(this).data('owner');
		var id = $(obj).attr('id');
		$.glue.object.unregister($(obj));
		$(obj).remove();
		// delete in backend as well
		$.glue.backend({ method: 'glue.delete_object', name: id });
		// update canvas
		$.glue.canvas.update();
	});
	$.glue.contextmenu.register('object', 'object-delete', elem, 20);
});
