/**
 *	modules/photostack/photostack-edit.js
 *	Frontend code for photostack objects
 *
 *	Copyright Gottfried Haider, Danja Vasiliev 2010.
 *	This source code is licensed under the GNU General Public License.
 *	See the file COPYING for more details.
 */

$.glue.photostack = function() {
	var focusedLayer = null;
	var focusedStack = null;
	var focusedIndex = null;
	var imagesData = null;
	var rotateBtn = null;

	return {
		/**
		 *	Get the focused layer index
		 */
		getFocusedIndex: function() {
			return focusedIndex;
		},

		/**
		 *	Set reference to rotate button
		 */
		setRotateBtn: function(btn) {
			rotateBtn = btn;
		},

		/**
		 *	Enable/disable rotate button styling
		 */
		showRotateBtn: function(enabled) {
			if (rotateBtn) {
				if (enabled) {
					$(rotateBtn).css({'background': '#fda', 'cursor': 'ns-resize', 'opacity': '1'});
				} else {
					$(rotateBtn).css({'background': '#ccc', 'cursor': 'not-allowed', 'opacity': '0.5'});
				}
			}
		},

		/**
		 *	Get the current rotation of the focused layer
		 */
		getLayerRotation: function() {
			if (!focusedLayer || focusedIndex === null || !imagesData) return 0;
			return imagesData[focusedIndex].rotation || 0;
		},

		/**
		 *	Set the rotation of the focused layer
		 */
		setLayerRotation: function(rotation) {
			if (!focusedLayer || !focusedStack || focusedIndex === null) return;

			// Update CSS
			focusedLayer.css('transform', 'rotate(' + rotation + 'deg)');

			// Update data
			imagesData[focusedIndex].rotation = rotation;
		},

		/**
		 *	Save rotation after drag ends
		 */
		saveRotation: function() {
			if (!focusedStack || !imagesData) return;
			this.saveImagesData(focusedStack);
		},

		/**
		 *	Focus a specific layer for editing
		 */
		focusLayer: function(obj, index) {
			var self = this;

			// Unfocus any previous layer
			this.unfocusLayer();

			focusedStack = obj;
			focusedIndex = index;

			// Load images data
			$.glue.backend({
				method: 'photostack.get_data',
				name: $(obj).attr('id')
			}, function(data) {
				if (!data) return;
				imagesData = data['images'] || [];

				var layer = $(obj).find('.photostack-layer[data-index="' + index + '"]');
				if (layer.length === 0) return;

				focusedLayer = layer;

				// Make layer visible and interactive
				layer.css('opacity', '1');
				layer.css('pointer-events', 'auto');
				layer.addClass('photostack-layer-focused');

				// Show rotate button
				$.glue.photostack.showRotateBtn(true);

				// Store container dimensions for percentage calculations
				var containerWidth = $(obj).width();
				var containerHeight = $(obj).height();

				// Make the layer draggable
				layer.draggable({
					start: function(e, ui) {
						// Prevent photostack from being dragged
						e.stopPropagation();
					},
					stop: function(e, ui) {
						// Calculate percentage offset
						var offsetX = (ui.position.left / containerWidth) * 100;
						var offsetY = (ui.position.top / containerHeight) * 100;

						// Update to percentage-based position
						layer.css('left', offsetX + '%');
						layer.css('top', offsetY + '%');

						// Save
						if (imagesData && imagesData[index]) {
							imagesData[index].offsetX = Math.round(offsetX * 10) / 10;
							imagesData[index].offsetY = Math.round(offsetY * 10) / 10;
							self.saveImagesData(obj);
						}
					}
				});

				// Make the layer resizable
				layer.resizable({
					handles: 'se',
					start: function(e, ui) {
						e.stopPropagation();
					},
					stop: function(e, ui) {
						// Calculate percentage scale (use width as reference)
						var scale = (ui.size.width / containerWidth) * 100;

						// Update to percentage-based size
						layer.css('width', scale + '%');
						layer.css('height', scale + '%');

						// Save
						if (imagesData && imagesData[index]) {
							imagesData[index].scale = Math.round(scale * 10) / 10;
							self.saveImagesData(obj);
						}
					}
				});
			});
		},

		/**
		 *	Unfocus the current layer
		 */
		unfocusLayer: function() {
			if (!focusedLayer) return;

			var layer = focusedLayer;
			var obj = focusedStack;
			var idx = focusedIndex;

			// Remove draggable/resizable
			if (layer.data('ui-draggable')) {
				layer.draggable('destroy');
			}
			if (layer.data('ui-resizable')) {
				layer.resizable('destroy');
			}

			layer.removeClass('photostack-layer-focused');

			// Restore visibility based on current state
			var current = parseInt($(obj).attr('data-photostack-current')) || 0;
			var index = parseInt(layer.attr('data-index'));
			var imgData = imagesData ? imagesData[index] : null;

			if (index < current) {
				// Passed layer - use fade
				var fade = imgData ? (imgData.fade !== undefined ? imgData.fade : 100) : 100;
				if (fade == 0) {
					layer.css('opacity', '0');
					layer.css('pointer-events', 'none');
				} else {
					layer.css('opacity', fade / 100);
					layer.css('pointer-events', '');
				}
			} else if (index == current) {
				// Current top layer
				layer.css('opacity', '1');
				layer.css('pointer-events', '');
			} else {
				// Not yet revealed - use ghost
				var ghost = imgData ? (imgData.ghost || 0) : 0;
				if (ghost > 0) {
					layer.css('opacity', ghost / 100);
				} else {
					layer.css('opacity', '0');
				}
				layer.css('pointer-events', 'none');
			}

			focusedLayer = null;
			focusedStack = null;
			focusedIndex = null;
			imagesData = null;

			// Hide rotate button
			$.glue.photostack.showRotateBtn(false);
		},

		/**
		 *	Check if a layer is focused
		 */
		hasFocusedLayer: function() {
			return focusedLayer !== null;
		},

		/**
		 *	Save images data to backend
		 */
		saveImagesData: function(obj) {
			if (!imagesData) return;

			$.glue.backend({
				method: 'photostack.update_images',
				name: $(obj).attr('id'),
				images: JSON.stringify(imagesData)
			});
		},

		/**
		 *	Get layer objects map from data attribute
		 */
		_getLayerObjMap: function(obj) {
			try { return JSON.parse($(obj).attr('data-layer-objects') || '{}'); }
			catch(e) { return {}; }
		},

		/**
		 *	Get object IDs for a specific layer
		 */
		_getLayerObjIds: function(obj, layerIndex) {
			var map = this._getLayerObjMap(obj);
			return map[layerIndex] || [];
		},

		/**
		 *	Sync data-layer-objects attribute from images data
		 */
		_syncLayerObjAttr: function(obj, images) {
			var map = {};
			for (var i = 0; i < images.length; i++) {
				var lo = images[i].layerObjects || [];
				if (lo.length > 0) {
					map[i] = lo;
				}
			}
			var json = JSON.stringify(map);
			$(obj).attr('data-layer-objects', json === '{}' ? '' : json);
		},

		/**
		 *	Show layer objects for a specific layer, hiding all others
		 */
		_showLayerObjectsForLayer: function(obj, layerIndex) {
			var map = this._getLayerObjMap(obj);
			// Deselect and hide all layer objects
			for (var p in map) {
				var ids = map[p];
				for (var i = 0; i < ids.length; i++) {
					var el = document.getElementById(ids[i]);
					if (el) {
						if ($(el).hasClass('glue-selected')) {
							$.glue.sel.deselect($(el));
						}
						$(el).hide();
					}
				}
			}
			// Show target layer's objects
			var targetIds = map[layerIndex] || [];
			for (var i = 0; i < targetIds.length; i++) {
				var el = document.getElementById(targetIds[i]);
				if (el) $(el).show();
			}
		},

		/**
		 *	Add a sticker to the current layer
		 */
		addSticker: function(obj) {
			var self = this;
			var current = parseInt($(obj).attr('data-photostack-current')) || 0;
			var stackPos = $(obj).offset();
			var stackW = $(obj).outerWidth();
			var stackH = $(obj).outerHeight();
			var objZ = parseInt($(obj).css('z-index')) || 0;

			// Center of stack in viewport coordinates
			var centerX = stackPos.left + stackW / 2 - $(window).scrollLeft();
			var centerY = stackPos.top + stackH / 2 - $(window).scrollTop();

			// Watch for new objects added to body
			var observer = new MutationObserver(function(mutations) {
				for (var m = 0; m < mutations.length; m++) {
					var added = mutations[m].addedNodes;
					for (var n = 0; n < added.length; n++) {
						var node = added[n];
						if (node.nodeType === 1 && $(node).hasClass('object')) {
							observer.disconnect();
							clearTimeout(safetyTimeout);
							// Reposition centered on stack
							var elW = $(node).outerWidth() || 100;
							var elH = $(node).outerHeight() || 80;
							$(node).css({
								'left': (stackPos.left + (stackW - elW) / 2) + 'px',
								'top': (stackPos.top + (stackH - elH) / 2) + 'px',
								'z-index': objZ + 1
							});
							$.glue.object.save($(node));
							self._addStickerToLayer(obj, current, $(node).attr('id'));
							return;
						}
					}
				}
			});

			observer.observe(document.body, { childList: true });

			// Safety timeout: disconnect observer if user cancels
			var safetyTimeout = setTimeout(function() {
				observer.disconnect();
			}, 60000);

			// Show the standard "new" menu centered on stack
			$.glue.menu.show('new', centerX, centerY);
		},

		/**
		 *	Add a sticker object ID to a specific layer's layerObjects
		 */
		_addStickerToLayer: function(obj, layerIndex, objId) {
			var self = this;
			$.glue.backend({
				method: 'photostack.get_data',
				name: $(obj).attr('id')
			}, function(data) {
				if (!data) return;
				var images = data['images'] || [];
				if (layerIndex < 0 || layerIndex >= images.length) return;

				if (!images[layerIndex].layerObjects) {
					images[layerIndex].layerObjects = [];
				}
				images[layerIndex].layerObjects.push(objId);

				$.glue.backend({
					method: 'photostack.update_images',
					name: $(obj).attr('id'),
					images: JSON.stringify(images)
				}, function() {
					self._syncLayerObjAttr(obj, images);
				});
			});
		},

		/**
		 *	Advance to the next image in the stack
		 */
		advance: function(obj) {
			var current = parseInt($(obj).attr('data-photostack-current')) || 0;
			var count = parseInt($(obj).attr('data-photostack-count')) || 1;

			var next = (current + 1) % count;
			$(obj).attr('data-photostack-current', next);

			// Update layer visibility
			$(obj).find('.photostack-layer').each(function() {
				var index = parseInt($(this).attr('data-index'));
				var ghost = parseInt($(this).attr('data-ghost')) || 0;
				var fade = parseInt($(this).attr('data-fade'));
				if (isNaN(fade)) fade = 100;

				if (index < next) {
					// Passed layer - use fade
					$(this).removeClass('photostack-ghost');
					if (fade == 0) {
						$(this).css('opacity', '0');
						$(this).css('pointer-events', 'none');
					} else {
						$(this).css('opacity', fade / 100);
						$(this).css('pointer-events', '');
					}
				} else if (index == next) {
					// Current top layer
					$(this).css('opacity', '1');
					$(this).css('pointer-events', '');
					$(this).removeClass('photostack-ghost');
				} else {
					// Not yet revealed - use ghost
					if (ghost > 0) {
						$(this).css('opacity', ghost / 100);
						$(this).addClass('photostack-ghost');
						$(this).css('pointer-events', 'none');
					} else {
						$(this).css('opacity', '0');
						$(this).css('pointer-events', 'none');
					}
				}
			});

			this._showLayerObjectsForLayer(obj, next);
		},

		/**
		 *	Reset stack to show only the first image
		 */
		reset: function(obj) {
			$(obj).attr('data-photostack-current', 0);

			$(obj).find('.photostack-layer').each(function() {
				var index = parseInt($(this).attr('data-index'));
				var ghost = parseInt($(this).attr('data-ghost')) || 0;

				if (index === 0) {
					// Current top layer
					$(this).css('opacity', '1');
					$(this).css('pointer-events', '');
					$(this).removeClass('photostack-ghost');
				} else {
					// Not yet revealed - use ghost
					if (ghost > 0) {
						$(this).css('opacity', ghost / 100);
						$(this).addClass('photostack-ghost');
						$(this).css('pointer-events', 'none');
					} else {
						$(this).css('opacity', '0');
						$(this).css('pointer-events', 'none');
					}
				}
			});

			this._showLayerObjectsForLayer(obj, 0);
		},

		/**
		 *	Add images to the stack
		 */
		addImages: function(obj) {
			var input = $('<input type="file" multiple accept="image/*" style="display:none">');
			$('body').append(input);

			$(input).bind('change', function(e) {
				var files = e.target.files;
				if (files.length === 0) {
					$(input).remove();
					return;
				}

				var uploadNext = function(index) {
					if (index >= files.length) {
						$.glue.photostack.refresh(obj);
						return;
					}
					$.glue.photostack.uploadFile(obj, files[index], function() {
						uploadNext(index + 1);
					});
				};

				uploadNext(0);
				$(input).remove();
			});

			$(input).click();
		},

		/**
		 *	Upload a single file
		 */
		uploadFile: function(obj, file, callback) {
			var xhr = new XMLHttpRequest();
			xhr.onreadystatechange = function() {
				if (xhr.readyState == 4 && callback) {
					callback();
				}
			};

			xhr.open('POST', $.glue.base_url + 'json.php', true);
			var formData = new FormData();
			formData.append('method', JSON.stringify('glue.upload_files'));
			formData.append('page', JSON.stringify($.glue.page));
			formData.append('preferred_module', JSON.stringify('photostack'));
			formData.append('photostack_target', JSON.stringify($(obj).attr('id')));
			formData.append('user_file0', file);
			xhr.send(formData);
		},

		/**
		 *	Refresh the photostack
		 */
		refresh: function(obj) {
			var objId = $(obj).attr('id');
			$.glue.backend({
				method: 'glue.render_object',
				name: objId,
				edit: true
			}, function(html) {
				if (html) {
					var newObj = $(html);
					$(obj).replaceWith(newObj);
					$.glue.object.register(newObj);
				}
			});
		},

		/**
		 *	Open the manage stack dialog
		 */
		manageStack: function(obj) {
			$.glue.backend({
				method: 'photostack.get_data',
				name: $(obj).attr('id')
			}, function(data) {
				if (!data) {
					$.glue.error('Failed to load photostack data');
					return;
				}

				var images = data['images'] || [];
				var current = data['current'] || 0;

				var html = '<div class="photostack-manage-dialog" style="background:#fff;padding:20px;border-radius:5px;max-width:500px;max-height:400px;overflow-y:auto;">';
				html += '<h3 style="margin-top:0;">Manage Stack (' + images.length + ' images)</h3>';
				html += '<div class="photostack-manage-list" style="margin-bottom:15px;">';

				for (var i = 0; i < images.length; i++) {
					var img = images[i];
					var isRevealed = i <= current;
					html += '<div class="photostack-manage-item" data-index="' + i + '" style="display:flex;align-items:center;padding:8px;margin:4px 0;background:' + (isRevealed ? '#e8f5e9' : '#f5f5f5') + ';border-radius:3px;">';
					html += '<span style="flex:0 0 30px;font-weight:bold;">#' + i + '</span>';
					// Up/down reorder buttons
					html += '<button class="photostack-move-up" data-index="' + i + '" style="margin-right:2px;cursor:pointer;padding:2px 6px;"' + (i === 0 ? ' disabled' : '') + '>↑</button>';
					html += '<button class="photostack-move-down" data-index="' + i + '" style="margin-right:8px;cursor:pointer;padding:2px 6px;"' + (i === images.length - 1 ? ' disabled' : '') + '>↓</button>';
					html += '<span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + img.file + '</span>';
					html += '<span style="flex:0 0 60px;text-align:right;color:#666;font-size:12px;">' + (img.scale || 100) + '%</span>';
					if (img.rotation != 0) {
						html += '<span style="flex:0 0 50px;text-align:right;color:#666;font-size:12px;">' + img.rotation + '°</span>';
					}
					html += '<button class="photostack-edit-layer" data-index="' + i + '" style="margin-left:8px;cursor:pointer;">Edit</button>';
					html += '<button class="photostack-remove-layer" data-index="' + i + '" style="margin-left:4px;cursor:pointer;color:red;">×</button>';
					html += '</div>';
				}

				html += '</div>';
				html += '<div style="text-align:right;">';
				html += '<button class="photostack-manage-close" style="padding:8px 16px;cursor:pointer;">Close</button>';
				html += '</div>';
				html += '</div>';

				var overlay = $('<div class="photostack-overlay" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:10000;"></div>');
				var dialog = $(html);
				$(overlay).append(dialog);
				$('body').append(overlay);

				// Stop propagation on dialog to prevent interference
				$(dialog).bind('mousedown click', function(e) {
					e.stopPropagation();
				});

				$(dialog).find('.photostack-manage-close').click(function(e) {
					e.stopPropagation();
					$(overlay).remove();
				});

				$(dialog).find('.photostack-remove-layer').click(function(e) {
					e.stopPropagation();
					var index = parseInt($(this).attr('data-index'));
					if (confirm('Remove image #' + index + '?')) {
						// Delete sticker objects bound to this layer
						var stickerIds = (images[index] && images[index].layerObjects) || [];
						for (var si = 0; si < stickerIds.length; si++) {
							var stickerEl = document.getElementById(stickerIds[si]);
							if (stickerEl) {
								$.glue.object.unregister($(stickerEl));
								$(stickerEl).remove();
								$.glue.backend({ method: 'glue.delete_object', name: stickerIds[si] });
							}
						}
						$.glue.backend({
							method: 'photostack.remove_image',
							name: $(obj).attr('id'),
							index: index
						}, function() {
							$(overlay).remove();
							$.glue.photostack.refresh(obj);
						});
					}
				});

				$(dialog).find('.photostack-edit-layer').click(function(e) {
					e.stopPropagation();
					var index = parseInt($(this).attr('data-index'));
					$(overlay).remove();
					$.glue.photostack.editLayer(obj, index);
				});

				$(dialog).find('.photostack-move-up').click(function(e) {
					e.stopPropagation();
					var index = parseInt($(this).attr('data-index'));
					if (index > 0) {
						// Swap with previous
						var temp = images[index];
						images[index] = images[index - 1];
						images[index - 1] = temp;
						// Save and refresh dialog
						$.glue.backend({
							method: 'photostack.update_images',
							name: $(obj).attr('id'),
							images: JSON.stringify(images)
						}, function() {
							$(overlay).remove();
							$.glue.photostack.refresh(obj);
							// Reopen dialog after a short delay
							setTimeout(function() {
								$.glue.photostack.manageStack(obj);
							}, 300);
						});
					}
				});

				$(dialog).find('.photostack-move-down').click(function(e) {
					e.stopPropagation();
					var index = parseInt($(this).attr('data-index'));
					if (index < images.length - 1) {
						// Swap with next
						var temp = images[index];
						images[index] = images[index + 1];
						images[index + 1] = temp;
						// Save and refresh dialog
						$.glue.backend({
							method: 'photostack.update_images',
							name: $(obj).attr('id'),
							images: JSON.stringify(images)
						}, function() {
							$(overlay).remove();
							$.glue.photostack.refresh(obj);
							// Reopen dialog after a short delay
							setTimeout(function() {
								$.glue.photostack.manageStack(obj);
							}, 300);
						});
					}
				});

				$(overlay).click(function(e) {
					if (e.target === this) {
						$(overlay).remove();
					}
				});
			});
		},

		/**
		 *	Edit a specific layer's properties
		 */
		editLayer: function(obj, index) {
			$.glue.backend({
				method: 'photostack.get_data',
				name: $(obj).attr('id')
			}, function(data) {
				if (!data) {
					$.glue.error('Failed to load photostack data');
					return;
				}

				var images = data['images'] || [];
				if (index < 0 || index >= images.length) return;

				var img = images[index];

				var html = '<div class="photostack-edit-dialog" style="background:#fff;padding:20px;border-radius:5px;min-width:320px;max-height:80vh;overflow-y:auto;">';
				html += '<h3 style="margin-top:0;">Edit Layer #' + index + '</h3>';
				html += '<div style="margin-bottom:12px;">';
				html += '<label style="display:block;margin-bottom:4px;">Offset X (%)</label>';
				html += '<input type="number" class="photostack-offsetx" value="' + (img.offsetX || 0) + '" style="width:100%;padding:4px;box-sizing:border-box;">';
				html += '</div>';
				html += '<div style="margin-bottom:12px;">';
				html += '<label style="display:block;margin-bottom:4px;">Offset Y (%)</label>';
				html += '<input type="number" class="photostack-offsety" value="' + (img.offsetY || 0) + '" style="width:100%;padding:4px;box-sizing:border-box;">';
				html += '</div>';
				html += '<div style="margin-bottom:12px;">';
				html += '<label style="display:block;margin-bottom:4px;">Scale (%)</label>';
				html += '<input type="number" class="photostack-scale" value="' + (img.scale || 100) + '" min="1" max="500" style="width:100%;padding:4px;box-sizing:border-box;">';
				html += '</div>';
				html += '<div style="margin-bottom:12px;">';
				html += '<label style="display:block;margin-bottom:4px;">Rotation (degrees)</label>';
				html += '<input type="number" class="photostack-rotation" value="' + (img.rotation || 0) + '" style="width:100%;padding:4px;box-sizing:border-box;">';
				html += '</div>';
				html += '<div style="margin-bottom:12px;">';
				html += '<label style="display:block;margin-bottom:4px;">Ghost opacity (0-100, preview before revealed)</label>';
				html += '<input type="number" class="photostack-ghost-input" value="' + (img.ghost || 0) + '" min="0" max="100" style="width:100%;padding:8px;box-sizing:border-box;font-size:14px;">';
				html += '</div>';
				html += '<div style="margin-bottom:12px;">';
				html += '<label style="display:block;margin-bottom:4px;">Fade opacity (0-100, after layer is passed)</label>';
				html += '<input type="number" class="photostack-fade-input" value="' + (img.fade !== undefined ? img.fade : 100) + '" min="0" max="100" style="width:100%;padding:8px;box-sizing:border-box;font-size:14px;">';
				html += '</div>';
				html += '<div style="text-align:right;">';
				html += '<button class="photostack-edit-cancel" style="padding:8px 16px;cursor:pointer;margin-right:8px;">Cancel</button>';
				html += '<button class="photostack-edit-save" style="padding:8px 16px;cursor:pointer;">Save</button>';
				html += '</div>';
				html += '</div>';

				var overlay = $('<div class="photostack-overlay" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:10000;"></div>');
				var dialog = $(html);
				$(overlay).append(dialog);
				$('body').append(overlay);

				// Stop propagation on dialog to prevent interference with inputs
				$(dialog).bind('mousedown click', function(e) {
					e.stopPropagation();
				});

				$(dialog).find('.photostack-edit-cancel').click(function(e) {
					e.stopPropagation();
					$(overlay).remove();
				});

				$(dialog).find('.photostack-edit-save').click(function(e) {
					e.stopPropagation();
					img.offsetX = parseFloat($(dialog).find('.photostack-offsetx').val()) || 0;
					img.offsetY = parseFloat($(dialog).find('.photostack-offsety').val()) || 0;
					img.scale = parseFloat($(dialog).find('.photostack-scale').val()) || 100;
					img.rotation = parseFloat($(dialog).find('.photostack-rotation').val()) || 0;
					img.ghost = parseInt($(dialog).find('.photostack-ghost-input').val()) || 0;
					img.fade = parseInt($(dialog).find('.photostack-fade-input').val());
					if (isNaN(img.fade)) img.fade = 100;

					$.glue.backend({
						method: 'photostack.update_images',
						name: $(obj).attr('id'),
						images: JSON.stringify(images)
					}, function() {
						$(overlay).remove();
						$.glue.photostack.refresh(obj);
					});
				});

				$(overlay).click(function(e) {
					if (e.target === this) {
						$(overlay).remove();
					}
				});
			});
		},

		/**
		 *	Update layer buttons in context menu
		 */
		updateLayerButtons: function(obj, container) {
			container.empty();

			if (!obj || !$(obj).hasClass('photostack')) {
				return;
			}

			var count = parseInt($(obj).attr('data-photostack-count')) || 0;

			for (var i = 0; i < count; i++) {
				(function(index) {
					var btn = $('<span class="photostack-layer-btn" data-index="' + index + '">' + index + '</span>');
					btn.css({
						'display': 'inline-block',
						'width': '20px',
						'height': '20px',
						'line-height': '20px',
						'text-align': 'center',
						'background': '#ddd',
						'border-radius': '3px',
						'margin': '2px',
						'cursor': 'pointer',
						'font-size': '12px',
						'font-weight': 'bold'
					});
					btn.bind('click', function(e) {
						e.stopPropagation();
						// Toggle focus on this layer
						if ($.glue.photostack.hasFocusedLayer()) {
							$.glue.photostack.unfocusLayer();
						}
						$.glue.photostack.focusLayer(obj, index);
						// Update button states
						$('.photostack-layer-btn').css('background', '#ddd');
						$(this).css('background', '#88f');
					});
					container.append(btn);
				})(i);
			}
		}
	};
}();


// Click handler - advance through stack (only if no layer is focused)
$('.photostack').live('click', function(e) {
	if ($(this).hasClass('ui-draggable-dragging')) return;
	if ($(e.target).closest('.photostack-overlay').length > 0) return;
	if ($(e.target).hasClass('photostack-layer-focused')) return;
	if ($(e.target).closest('.ui-resizable-handle').length > 0) return;

	// If we have a focused layer, clicking the stack background unfocuses it
	if ($.glue.photostack.hasFocusedLayer()) {
		$.glue.photostack.unfocusLayer();
		$('.photostack-layer-btn').css('background', '#ddd');
		return;
	}

	// Hide current layer stickers before advancing
	var cur = parseInt($(this).attr('data-photostack-current')) || 0;
	var curIds = $.glue.photostack._getLayerObjIds(this, cur);
	for (var si = 0; si < curIds.length; si++) {
		var sel = document.getElementById(curIds[si]);
		if (sel && $(sel).hasClass('glue-selected')) {
			$.glue.sel.deselect($(sel));
		}
	}
	$.glue.photostack.advance(this);
});

// Escape to unfocus layer
$(document).bind('keydown', function(e) {
	if (e.keyCode === 27 && $.glue.photostack.hasFocusedLayer()) {
		$.glue.photostack.unfocusLayer();
		$('.photostack-layer-btn').css('background', '#ddd');
	}
});

// Click outside to deselect photostack (handles cases where body click doesn't work)
$(document).bind('click', function(e) {
	// Only act if we have a selected photostack
	var selectedStack = $('.photostack.glue-selected');
	if (selectedStack.length === 0) return;

	// Check if click is outside the photostack and outside the context menu
	var target = $(e.target);
	if (target.closest('.photostack').length === 0 &&
		target.closest('.glue-ui').length === 0 &&
		target.closest('.photostack-overlay').length === 0) {
		// Unfocus any focused layer first
		if ($.glue.photostack.hasFocusedLayer()) {
			$.glue.photostack.unfocusLayer();
			$('.photostack-layer-btn').css('background', '#ddd');
		}
		// Deselect the photostack
		$.glue.sel.none();
	}
});


$(document).ready(function() {
	//
	// Register context menu items
	//
	var elem;

	// Add images button
	elem = $('<img src="'+$.glue.base_url+'modules/photostack/photostack-add.png" alt="btn" title="add images to stack" width="32" height="32">');
	$(elem).bind('click', function(e) {
		var obj = $(this).data('owner');
		$.glue.photostack.addImages(obj);
	});
	$.glue.contextmenu.register('photostack', 'photostack-add', elem);

	// Manage stack button
	elem = $('<img src="'+$.glue.base_url+'modules/photostack/photostack-manage.png" alt="btn" title="manage stack layers" width="32" height="32">');
	$(elem).bind('click', function(e) {
		var obj = $(this).data('owner');
		$.glue.photostack.manageStack(obj);
	});
	$.glue.contextmenu.register('photostack', 'photostack-manage', elem);

	// Reset stack button
	elem = $('<img src="'+$.glue.base_url+'modules/photostack/photostack-reset.png" alt="btn" title="reset stack to first image" width="32" height="32">');
	$(elem).bind('click', function(e) {
		var obj = $(this).data('owner');
		$.glue.photostack.reset(obj);
	});
	$.glue.contextmenu.register('photostack', 'photostack-reset', elem);

	// Add sticker button
	elem = $('<img src="'+$.glue.base_url+'modules/photostack/photostack-sticker.png" alt="btn" title="add sticker to current layer" width="32" height="32">');
	$(elem).bind('click', function(e) {
		var obj = $(this).data('owner');
		$.glue.photostack.addSticker(obj);
	});
	$.glue.contextmenu.register('photostack', 'photostack-sticker', elem);

	// Rotate focused layer button (drag up/down to rotate)
	elem = $('<div class="photostack-rotate-btn" style="width:24px;height:24px;line-height:24px;text-align:center;background:#ccc;border-radius:4px;font-size:14px;cursor:not-allowed;opacity:0.5;" title="rotate focused layer (drag up/down) - select a layer first">↻</div>');
	$(elem).bind('glue-menu-activate', function() {
		// Store reference when menu activates
		$.glue.photostack.setRotateBtn(this);
		// Update appearance based on focus state
		if ($.glue.photostack.hasFocusedLayer()) {
			$(this).css({'background': '#fda', 'cursor': 'ns-resize', 'opacity': '1'});
		} else {
			$(this).css({'background': '#ccc', 'cursor': 'not-allowed', 'opacity': '0.5'});
		}
	});
	$(elem).bind('mousedown', function(e) {
		if (!$.glue.photostack.hasFocusedLayer()) return false;

		// Capture starting rotation
		var startRotation = $.glue.photostack.getLayerRotation();

		$.glue.slider(e, function(x, y) {
			// Rotate by dragging vertically - y is cumulative offset from start
			$.glue.photostack.setLayerRotation(startRotation + y);
		}, function(x, y) {
			// Save when done
			$.glue.photostack.saveRotation();
		});
		return false;
	});
	$.glue.contextmenu.register('photostack', 'photostack-rotate-layer', elem, 11);

	// Layer buttons (one per layer, appear after reset button)
	for (var layerIdx = 0; layerIdx < 10; layerIdx++) {
		(function(idx) {
			var layerBtn = $('<div class="photostack-layer-btn" style="display:none;width:24px;height:24px;line-height:24px;text-align:center;background:#ccc;border-radius:4px;font-size:12px;font-weight:bold;cursor:pointer;">' + idx + '</div>');
			$(layerBtn).bind('glue-menu-activate', function() {
				var obj = $(this).data('owner');
				if (obj && $(obj).hasClass('photostack')) {
					var count = parseInt($(obj).attr('data-photostack-count')) || 0;
					if (idx < count) {
						$(this).css('display', 'block');
					} else {
						$(this).css('display', 'none');
					}
				} else {
					$(this).css('display', 'none');
				}
			});
			$(layerBtn).bind('glue-menu-deactivate', function() {
				$(this).css('display', 'none');
				$(this).css('background', '#ccc');
			});
			$(layerBtn).bind('click', function(e) {
				e.stopPropagation();
				var obj = $(this).data('owner');
				if ($.glue.photostack.hasFocusedLayer()) {
					$.glue.photostack.unfocusLayer();
				}
				$.glue.photostack.focusLayer(obj, idx);
				// Update all layer button states
				$('.photostack-layer-btn').css('background', '#ccc');
				$(this).css('background', '#88f');
			});
			$.glue.contextmenu.register('photostack', 'photostack-layer-' + idx, layerBtn, 11 + idx);
		})(layerIdx);
	}

	//
	// Register menu item to create new photostack
	//
	elem = $('<img src="'+$.glue.base_url+'modules/photostack/photostack-add.png" alt="btn" title="create new photo stack" width="32" height="32">');
	$(elem).bind('click', function(e) {
		var clickX = e.pageX;
		var clickY = e.pageY;
		$.glue.menu.hide();

		$.glue.backend({ method: 'glue.create_object', page: $.glue.page }, function(data) {
			if (!data || !data['name']) {
				$.glue.error('Error creating object');
				return;
			}

			var objName = data['name'];
			var obj = $('<div class="photostack resizable object" style="position: absolute;"></div>');
			$(obj).attr('id', objName);
			$(obj).css('width', '200px');
			$(obj).css('height', '200px');
			$(obj).css('left', (clickX - 100) + 'px');
			$(obj).css('top', (clickY - 100) + 'px');
			$(obj).css('background-color', 'rgba(200, 200, 200, 0.3)');
			$(obj).css('border', '2px dashed #999');
			$(obj).attr('data-photostack-current', '0');
			$(obj).attr('data-photostack-count', '0');

			$('body').append(obj);
			$.glue.object.register(obj);

			$.glue.backend({
				method: 'glue.update_object',
				name: objName,
				'type': 'photostack',
				'module': 'photostack',
				'photostack-images': '[]',
				'photostack-current': '0'
			});

			$.glue.object.save(obj);
			$.glue.sel.select(obj);
			$.glue.photostack.addImages(obj);
		});
	});
	$.glue.menu.register('new', elem);

	// Show layer 0 stickers for each photostack on load
	$('.photostack').each(function() {
		$.glue.photostack._showLayerObjectsForLayer(this, 0);
	});

	// Cleanup: remove deleted objects from layerObjects
	$('.object').live('glue-unregister', function() {
		var deletedId = $(this).attr('id');
		if (!deletedId) return;
		$('.photostack').each(function() {
			var stack = this;
			var layerObjMap = $.glue.photostack._getLayerObjMap(stack);
			var found = false;

			for (var p in layerObjMap) {
				var arr = layerObjMap[p];
				var idx = $.inArray(deletedId, arr);
				if (idx !== -1) {
					found = true;
					break;
				}
			}

			if (!found) return;

			// Fetch images, remove the deleted ID, save
			$.glue.backend({
				method: 'photostack.get_data',
				name: $(stack).attr('id')
			}, function(data) {
				if (!data) return;
				var images = data['images'] || [];
				var changed = false;
				for (var i = 0; i < images.length; i++) {
					var lo = images[i].layerObjects || [];
					var loIdx = $.inArray(deletedId, lo);
					if (loIdx !== -1) {
						lo.splice(loIdx, 1);
						images[i].layerObjects = lo;
						changed = true;
					}
				}
				if (changed) {
					$.glue.backend({
						method: 'photostack.update_images',
						name: $(stack).attr('id'),
						images: JSON.stringify(images)
					}, function() {
						$.glue.photostack._syncLayerObjAttr(stack, images);
					});
				}
			});
		});
	});
});
