/**
 *	modules/journal/journal-edit.js
 *	Frontend code for journal objects in edit mode
 */

$.glue.journal = function() {
	var pagesData = null;
	var currentJournal = null;

	return {
		/**
		 *	Add a page to the journal
		 */
		addPage: function(obj) {
			var self = this;
			var input = $('<input type="file" accept="image/*" style="display:none">');
			$('body').append(input);

			$(input).bind('change', function(e) {
				var file = e.target.files[0];
				if (!file) {
					$(input).remove();
					return;
				}

				var formData = new FormData();
				formData.append('file', file);
				formData.append('method', JSON.stringify('journal.upload'));
				formData.append('name', JSON.stringify($(obj).attr('id')));
				formData.append('page', JSON.stringify($.glue.page));

				$.ajax({
					url: $.glue.base_url + 'json.php',
					type: 'POST',
					data: formData,
					processData: false,
					contentType: false,
					success: function(data) {
						self.refresh(obj);
					},
					error: function() {
						$.glue.error('Failed to upload image');
					}
				});

				$(input).remove();
			});

			$(input).click();
		},

		/**
		 *	Refresh a journal object
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
		 *	Flip to next page
		 */
		nextPage: function(obj) {
			var self = this;
			var current = parseInt($(obj).attr('data-journal-current')) || 0;
			var count = parseInt($(obj).attr('data-journal-count')) || 1;

			if (current >= count - 1) return;

			var next = current + 1;

			var currentPage = $(obj).find('.journal-page[data-index="' + current + '"]');
			var nextPage = $(obj).find('.journal-page[data-index="' + next + '"]');

			var curRight = currentPage.find('.journal-page-right');
			var nextLeft = nextPage.find('.journal-page-left');

			// Pin starting transforms with transition disabled
			nextLeft.css('transition', 'none').css('transform', 'rotateY(180deg)');
			curRight.css('transition', 'none').css('transform', 'rotateY(0deg)');
			nextPage.css({ 'visibility': 'visible', 'z-index': 1 });
			currentPage.css('z-index', 2);

			// Force paint at starting position
			curRight[0].offsetHeight;
			nextLeft[0].offsetHeight;

			// Re-enable transition, then animate
			curRight.css('transition', '').addClass('is-flipping').css('transform', 'rotateY(-180deg)');
			nextLeft.css('transition', '').addClass('is-flipping').css('transform', 'rotateY(0deg)');

			setTimeout(function() {
				currentPage.find('.journal-page-right').removeClass('is-flipping');
				nextPage.find('.journal-page-left').removeClass('is-flipping');
				currentPage.css('visibility', 'hidden');
				nextPage.css('z-index', 2);
				$(obj).attr('data-journal-current', next);
				self.updateAltBtn(obj);
			}, 600);
		},

		/**
		 *	Flip to previous page
		 */
		prevPage: function(obj) {
			var self = this;
			var current = parseInt($(obj).attr('data-journal-current')) || 0;

			if (current <= 0) return;

			var prev = current - 1;

			var currentPage = $(obj).find('.journal-page[data-index="' + current + '"]');
			var prevPage = $(obj).find('.journal-page[data-index="' + prev + '"]');

			var curLeft = currentPage.find('.journal-page-left');
			var prevRight = prevPage.find('.journal-page-right');

			// Pin starting transforms with transition disabled
			curLeft.css('transition', 'none').css('transform', 'rotateY(0deg)');
			prevRight.css('transition', 'none').css('transform', 'rotateY(-180deg)');
			prevPage.css({ 'visibility': 'visible', 'z-index': 2 });
			currentPage.css('z-index', 1);

			// Force paint at starting position
			curLeft[0].offsetHeight;
			prevRight[0].offsetHeight;

			// Re-enable transition, then animate
			curLeft.css('transition', '').addClass('is-flipping').css('transform', 'rotateY(180deg)');
			prevRight.css('transition', '').addClass('is-flipping').css('transform', 'rotateY(0deg)');

			setTimeout(function() {
				currentPage.find('.journal-page-left').removeClass('is-flipping');
				prevPage.find('.journal-page-right').removeClass('is-flipping');
				currentPage.css('visibility', 'hidden');
				$(obj).attr('data-journal-current', prev);
				self.updateAltBtn(obj);
			}, 600);
		},

		/**
		 *	Reset to first page
		 */
		reset: function(obj) {
			$(obj).attr('data-journal-current', 0);

			$(obj).find('.journal-page').each(function() {
				var index = parseInt($(this).attr('data-index'));
				if (index === 0) {
					$(this).css({ 'visibility': 'visible', 'z-index': 2 });
					$(this).find('.journal-page-left').css('transform', '');
					$(this).find('.journal-page-right').css('transform', '');
				} else {
					$(this).css({ 'visibility': 'hidden', 'z-index': 1 });
					$(this).find('.journal-page-left').css('transform', 'rotateY(180deg)');
					$(this).find('.journal-page-right').css('transform', '');
				}
			});

			this.dismissAlt(obj);
			this.updateAltBtn(obj);
		},

		/**
		 *	Manage pages dialog
		 */
		managePages: function(obj) {
			var self = this;

			$.glue.backend({
				method: 'journal.get_data',
				name: $(obj).attr('id')
			}, function(data) {
				if (!data) {
					$.glue.error('Failed to load journal data');
					return;
				}

				var pages = data['pages'] || [];

				var html = '<div class="journal-manage-dialog" style="background:#fff;padding:20px;border-radius:5px;min-width:400px;max-height:80vh;overflow-y:auto;">';
				html += '<h3 style="margin-top:0;">Manage Journal Pages</h3>';
				html += '<div class="journal-pages-list" style="margin-bottom:15px;">';

				for (var i = 0; i < pages.length; i++) {
					var page = pages[i];
					var isCover = page.cover ? true : false;
					html += '<div class="journal-page-item" data-index="' + i + '" style="display:flex;align-items:center;padding:8px;margin-bottom:8px;background:#f5f5f5;border-radius:4px;">';
					html += '<img src="' + page.src + '" style="width:80px;height:60px;object-fit:cover;margin-right:10px;border-radius:3px;">';
					html += '<span style="flex:1;font-size:14px;">Page ' + i + '</span>';
					html += '<label style="flex:0 0 auto;font-size:12px;color:#666;margin-left:8px;cursor:pointer;"><input type="checkbox" class="journal-cover-toggle" data-index="' + i + '"' + (isCover ? ' checked' : '') + '> Cover</label>';
					html += '<button class="journal-edit-page" data-index="' + i + '" style="margin-left:8px;cursor:pointer;">Edit</button>';
					html += '<button class="journal-move-up" data-index="' + i + '" style="margin-left:4px;cursor:pointer;"' + (i === 0 ? ' disabled' : '') + '>&uarr;</button>';
					html += '<button class="journal-move-down" data-index="' + i + '" style="margin-left:4px;cursor:pointer;"' + (i === pages.length - 1 ? ' disabled' : '') + '>&darr;</button>';
					html += '<button class="journal-remove-page" data-index="' + i + '" style="margin-left:4px;cursor:pointer;color:red;">&times;</button>';
					html += '</div>';
				}

				if (pages.length === 0) {
					html += '<p style="color:#888;font-style:italic;">No pages yet. Drag images onto the journal to add pages.</p>';
				}

				html += '</div>';
				html += '<div style="text-align:right;">';
				html += '<button class="journal-manage-close" style="padding:8px 16px;cursor:pointer;">Close</button>';
				html += '</div>';
				html += '</div>';

				var overlay = $('<div class="journal-overlay" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:10000;"></div>');
				var dialog = $(html);
				$(overlay).append(dialog);
				$('body').append(overlay);

				// Stop propagation on dialog
				$(dialog).bind('mousedown click', function(e) {
					e.stopPropagation();
				});

				$(dialog).find('.journal-manage-close').click(function(e) {
					e.stopPropagation();
					$(overlay).remove();
				});

				$(dialog).find('.journal-remove-page').click(function(e) {
					e.stopPropagation();
					var index = parseInt($(this).attr('data-index'));
					if (confirm('Remove page ' + index + '?')) {
						$.glue.backend({
							method: 'journal.remove_page',
							name: $(obj).attr('id'),
							index: index
						}, function() {
							$(overlay).remove();
							self.refresh(obj);
						});
					}
				});

				$(dialog).find('.journal-edit-page').click(function(e) {
					e.stopPropagation();
					var index = parseInt($(this).attr('data-index'));
					$(overlay).remove();
					self.editPage(obj, index);
				});

				$(dialog).find('.journal-move-up').click(function(e) {
					e.stopPropagation();
					var index = parseInt($(this).attr('data-index'));
					if (index > 0) {
						var temp = pages[index];
						pages[index] = pages[index - 1];
						pages[index - 1] = temp;
						// Swap DOM items
						var items = $(dialog).find('.journal-page-item');
						$(items[index]).insertBefore($(items[index - 1]));
						// Save and refresh object behind the dialog
						$.glue.backend({
							method: 'journal.update_pages',
							name: $(obj).attr('id'),
							pages: JSON.stringify(pages)
						}, function() {
							self.refresh(obj);
						});
					}
				});

				$(dialog).find('.journal-move-down').click(function(e) {
					e.stopPropagation();
					var index = parseInt($(this).attr('data-index'));
					if (index < pages.length - 1) {
						var temp = pages[index];
						pages[index] = pages[index + 1];
						pages[index + 1] = temp;
						// Swap DOM items
						var items = $(dialog).find('.journal-page-item');
						$(items[index]).insertAfter($(items[index + 1]));
						// Save and refresh object behind the dialog
						$.glue.backend({
							method: 'journal.update_pages',
							name: $(obj).attr('id'),
							pages: JSON.stringify(pages)
						}, function() {
							self.refresh(obj);
						});
					}
				});

				$(dialog).find('.journal-cover-toggle').change(function(e) {
					e.stopPropagation();
					var index = parseInt($(this).attr('data-index'));
					pages[index].cover = $(this).is(':checked') ? true : false;
					$.glue.backend({
						method: 'journal.update_pages',
						name: $(obj).attr('id'),
						pages: JSON.stringify(pages)
					}, function() {
						self.refresh(obj);
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
		 *	Get the alt objects map from the data attribute
		 */
		_getAltMap: function(obj) {
			try { return JSON.parse($(obj).attr('data-alt-objects') || '{}'); }
			catch(e) { return {}; }
		},

		/**
		 *	Get alt object IDs for a specific page
		 */
		_getAltIds: function(obj, pageIndex) {
			var map = this._getAltMap(obj);
			return map[pageIndex] || [];
		},

		/**
		 *	Rebuild the data-alt-objects attribute from pages data and update button
		 */
		_syncAltAttr: function(obj, pages) {
			var altMap = {};
			for (var i = 0; i < pages.length; i++) {
				var ao = pages[i].altObjects || [];
				if (ao.length > 0) {
					altMap[i] = ao;
				}
			}
			var json = JSON.stringify(altMap);
			$(obj).attr('data-alt-objects', json === '{}' ? '' : json);
			this.updateAltBtn(obj);
		},

		/**
		 *	Show/hide ALT button based on whether current page has alt text objects
		 */
		updateAltBtn: function(obj) {
			var current = parseInt($(obj).attr('data-journal-current')) || 0;
			var ids = this._getAltIds(obj, current);
			var btn = $(obj).find('.journal-alt-toggle');
			if (ids.length > 0) {
				btn.show();
			} else {
				btn.hide();
			}
		},

		/**
		 *	Position the floating dismiss button over a journal's bottom-right
		 */
		_positionDismiss: function(obj, dismissBtn) {
			var pos = $(obj).offset();
			var w = $(obj).outerWidth();
			var h = $(obj).outerHeight();
			var objZ = parseInt($(obj).css('z-index')) || 0;
			$(dismissBtn).css({
				'left': (pos.left + w - $(dismissBtn).outerWidth() - 8) + 'px',
				'top': (pos.top + h - $(dismissBtn).outerHeight() - 8) + 'px',
				'z-index': objZ + 2
			});
		},

		/**
		 *	Dismiss (hide) all alt text objects for this journal
		 */
		dismissAlt: function(obj) {
			var map = this._getAltMap(obj);
			for (var p in map) {
				var ids = map[p];
				for (var i = 0; i < ids.length; i++) {
					var el = document.getElementById(ids[i]);
					if (el) $(el).hide();
				}
			}
			$(obj).data('altShowing', false);
			// Remove floating dismiss, show in-journal ALT button
			var dismissBtn = $(obj).data('altDismissBtn');
			if (dismissBtn) {
				$(dismissBtn).remove();
				$(obj).data('altDismissBtn', null);
			}
			$(obj).find('.journal-alt-toggle').show().text('ALT').removeClass('active');
		},

		/**
		 *	Toggle alt text objects for current page
		 */
		toggleAlt: function(obj) {
			var self = this;
			var current = parseInt($(obj).attr('data-journal-current')) || 0;
			var ids = this._getAltIds(obj, current);
			if (ids.length === 0) return;
			var showing = $(obj).data('altShowing');
			if (showing) {
				this.dismissAlt(obj);
				this.updateAltBtn(obj);
			} else {
				for (var i = 0; i < ids.length; i++) {
					var el = document.getElementById(ids[i]);
					if (el) $(el).show();
				}
				$(obj).data('altShowing', true);
				// Hide in-journal button, create floating dismiss
				$(obj).find('.journal-alt-toggle').hide();
				var dismissBtn = $('<button class="journal-alt-dismiss">\u00d7</button>');
				$('body').append(dismissBtn);
				self._positionDismiss(obj, dismissBtn);
				$(obj).data('altDismissBtn', dismissBtn);
				$(dismissBtn).bind('click', function(e) {
					e.stopPropagation();
					self.dismissAlt(obj);
					self.updateAltBtn(obj);
				});
			}
		},

		/**
		 *	Edit alt text objects for current page
		 */
		editAltText: function(obj) {
			var self = this;

			$.glue.backend({
				method: 'journal.get_data',
				name: $(obj).attr('id')
			}, function(data) {
				if (!data) {
					$.glue.error('Failed to load journal data');
					return;
				}

				var pages = data['pages'] || [];
				var current = parseInt($(obj).attr('data-journal-current')) || 0;
				if (current < 0 || current >= pages.length) {
					$.glue.error('No page selected');
					return;
				}

				var page = pages[current];
				var altObjects = page.altObjects || [];

				var html = '<div class="journal-alt-dialog" style="background:#fff;padding:20px;border-radius:5px;min-width:400px;max-height:80vh;overflow-y:auto;">';
				html += '<h3 style="margin-top:0;">Alt Text Objects - Page ' + current + '</h3>';
				html += '<div class="journal-alt-list" style="margin-bottom:15px;">';

				for (var i = 0; i < altObjects.length; i++) {
					var objName = altObjects[i];
					var textEl = $(document.getElementById(objName));
					var preview = textEl.length ? (textEl.find('.glue-text-render').text() || textEl.text() || objName).substring(0, 60) : objName;
					html += '<div class="journal-alt-item" data-obj-name="' + objName + '" style="display:flex;align-items:center;padding:8px;margin-bottom:8px;background:#f5f5f5;border-radius:4px;">';
					html += '<span style="flex:1;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + preview + '</span>';
					html += '<button class="journal-alt-detach" data-obj-name="' + objName + '" style="margin-left:8px;cursor:pointer;color:red;">Delete</button>';
					html += '</div>';
				}

				if (altObjects.length === 0) {
					html += '<p style="color:#888;font-style:italic;">No text objects attached to this page.</p>';
				}

				html += '</div>';
				html += '<div style="display:flex;justify-content:space-between;">';
				html += '<div>';
				html += '<button class="journal-alt-create" style="padding:8px 16px;cursor:pointer;">+ New Text Block</button>';
				html += '</div>';
				html += '<button class="journal-alt-close" style="padding:8px 16px;cursor:pointer;">Close</button>';
				html += '</div>';
				html += '</div>';

				var overlay = $('<div class="journal-overlay" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:10000;"></div>');
				var dialog = $(html);
				$(overlay).append(dialog);
				$('body').append(overlay);

				$(dialog).bind('mousedown', function(e) {
					e.stopPropagation();
				});

				// Create new text object and attach it
				$(overlay).delegate('.journal-alt-create', 'click', function(e) {
					e.stopPropagation();
					$.glue.backend({ method: 'glue.create_object', 'page': $.glue.page }, function(newData) {
						var newName = newData['name'];
						// Create a text element positioned within the journal
						var journalPos = $(obj).offset();
						var journalW = $(obj).width();
						var journalH = $(obj).height();
						var objZ = parseInt($(obj).css('z-index')) || 0;
						var elemW = Math.min(200, journalW - 40);
						var elemH = 80;
						var elem = $('<div class="text resizable object" style="position: absolute;"><textarea class="glue-text-input" style="display: none; height: 100%; width: 100%;"></textarea><div class="glue-text-render" style="height: 100%; width: 100%;"></div></div>');
						$(elem).attr('id', newName);
						$(elem).css({
							'width': elemW + 'px',
							'height': elemH + 'px',
							'left': (journalPos.left + (journalW - elemW) / 2) + 'px',
							'top': (journalPos.top + (journalH - elemH) / 2) + 'px',
							'z-index': objZ + 1,
							'background-color': 'rgba(255, 255, 255, 0.9)'
						});
						$('body').append(elem);
						$.glue.object.register(elem);
						$.glue.object.save(elem);

						// Add to altObjects and save
						altObjects.push(newName);
						pages[current].altObjects = altObjects;
						$.glue.backend({
							method: 'journal.update_pages',
							name: $(obj).attr('id'),
							pages: JSON.stringify(pages)
						}, function() {
							$(overlay).remove();
							self._syncAltAttr(obj, pages);
							// Show alt objects via toggle (creates floating dismiss)
							if (!$(obj).data('altShowing')) {
								self.toggleAlt(obj);
							}
							// Focus the new text block for editing
							var newEl = document.getElementById(newName);
							if (newEl) {
								$.glue.sel.select($(newEl));
								$(newEl).find('.glue-text-input').show().focus();
								$(newEl).find('.glue-text-render').hide();
								$(newEl).addClass('glue-text-editing');
							}
						});
					});
				});

				// Delete alt text object
				$(overlay).delegate('.journal-alt-detach', 'click', function(e) {
					e.stopPropagation();
					var detachName = $(this).attr('data-obj-name');
					// Remove from altObjects array
					for (var j = altObjects.length - 1; j >= 0; j--) {
						if (altObjects[j] === detachName) {
							altObjects.splice(j, 1);
						}
					}
					pages[current].altObjects = altObjects;

					// Delete the text object from DOM and backend
					var detEl = document.getElementById(detachName);
					if (detEl) {
						$.glue.object.unregister($(detEl));
						$(detEl).remove();
						$.glue.backend({ method: 'glue.delete_object', name: detachName });
					}

					// Save updated pages and remove the list item
					$(this).closest('.journal-alt-item').remove();
					if ($(dialog).find('.journal-alt-item').length === 0) {
						$(dialog).find('.journal-alt-list').html('<p style="color:#888;font-style:italic;">No text objects attached to this page.</p>');
					}
					$.glue.backend({
						method: 'journal.update_pages',
						name: $(obj).attr('id'),
						pages: JSON.stringify(pages)
					}, function() {
						self._syncAltAttr(obj, pages);
					});
				});

				// Close
				$(overlay).delegate('.journal-alt-close', 'click', function(e) {
					e.stopPropagation();
					$(overlay).remove();
				});

				$(overlay).click(function(e) {
					if (e.target === this) {
						$(overlay).remove();
					}
				});
			});
		},

		/**
		 *	Edit a single page
		 */
		editPage: function(obj, index) {
			var self = this;

			$.glue.backend({
				method: 'journal.get_data',
				name: $(obj).attr('id')
			}, function(data) {
				if (!data) {
					$.glue.error('Failed to load journal data');
					return;
				}

				var pages = data['pages'] || [];
				if (index < 0 || index >= pages.length) return;

				var page = pages[index];

				var html = '<div class="journal-edit-dialog" style="background:#fff;padding:20px;border-radius:5px;min-width:350px;">';
				html += '<h3 style="margin-top:0;">Edit Page ' + index + '</h3>';

				// Preview with fold line indicator
				html += '<div style="position:relative;margin-bottom:15px;">';
				html += '<img src="' + page.src + '" style="width:100%;max-width:400px;border-radius:4px;">';
				html += '<div class="fold-line-preview" style="position:absolute;top:0;bottom:0;width:2px;background:red;left:' + (page.foldLine || 50) + '%;"></div>';
				html += '</div>';

				html += '<div style="margin-bottom:12px;">';
				html += '<label style="display:block;margin-bottom:4px;">Fold Line Position (% from left)</label>';
				html += '<input type="range" class="journal-fold-line" value="' + (page.foldLine || 50) + '" min="20" max="80" style="width:100%;">';
				html += '<input type="number" class="journal-fold-line-num" value="' + (page.foldLine || 50) + '" min="20" max="80" style="width:60px;margin-left:10px;">';
				html += '</div>';

				html += '<div style="text-align:right;">';
				html += '<button class="journal-edit-cancel" style="padding:8px 16px;cursor:pointer;margin-right:8px;">Cancel</button>';
				html += '<button class="journal-edit-save" style="padding:8px 16px;cursor:pointer;">Save</button>';
				html += '</div>';
				html += '</div>';

				var overlay = $('<div class="journal-overlay" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:10000;"></div>');
				var dialog = $(html);
				$(overlay).append(dialog);
				$('body').append(overlay);

				// Stop propagation
				$(dialog).bind('mousedown click', function(e) {
					e.stopPropagation();
				});

				// Sync range and number inputs
				var rangeInput = $(dialog).find('.journal-fold-line');
				var numInput = $(dialog).find('.journal-fold-line-num');
				var foldPreview = $(dialog).find('.fold-line-preview');

				rangeInput.bind('input', function() {
					numInput.val($(this).val());
					foldPreview.css('left', $(this).val() + '%');
				});

				numInput.bind('input', function() {
					var val = Math.max(20, Math.min(80, parseInt($(this).val()) || 50));
					rangeInput.val(val);
					foldPreview.css('left', val + '%');
				});

				$(dialog).find('.journal-edit-cancel').click(function(e) {
					e.stopPropagation();
					$(overlay).remove();
				});

				$(dialog).find('.journal-edit-save').click(function(e) {
					e.stopPropagation();
					page.foldLine = parseInt(rangeInput.val()) || 50;

					$.glue.backend({
						method: 'journal.update_pages',
						name: $(obj).attr('id'),
						pages: JSON.stringify(pages)
					}, function() {
						$(overlay).remove();
						self.refresh(obj);
					});
				});

				$(overlay).click(function(e) {
					if (e.target === this) {
						$(overlay).remove();
					}
				});
			});
		}
	};
}();


// Click handler for flipping in edit mode
$('.journal').live('click', function(e) {
	if ($(this).hasClass('ui-draggable-dragging')) return;
	if ($(e.target).closest('.journal-overlay').length > 0) return;

	// Handle ALT toggle button click
	if ($(e.target).closest('.journal-alt-toggle').length > 0) {
		$.glue.journal.toggleAlt(this);
		return;
	}

	// Auto-dismiss alt text objects before flipping
	$.glue.journal.dismissAlt(this);
	$.glue.journal.updateAltBtn(this);

	var rect = this.getBoundingClientRect();
	var clickX = e.clientX - rect.left;
	var width = rect.width;
	var clickPercent = (clickX / width) * 100;

	var current = parseInt($(this).attr('data-journal-current')) || 0;
	var count = parseInt($(this).attr('data-journal-count')) || 1;

	// Get current page fold line
	var currentPage = $(this).find('.journal-page[data-index="' + current + '"]');
	var foldLine = currentPage.length ? parseFloat(currentPage.attr('data-fold-line')) || 50 : 50;

	if (clickPercent > foldLine) {
		$.glue.journal.nextPage(this);
	} else {
		$.glue.journal.prevPage(this);
	}
});


// Click outside to deselect
$(document).bind('click', function(e) {
	var selectedJournal = $('.journal.glue-selected');
	if (selectedJournal.length === 0) return;

	var target = $(e.target);
	if (target.closest('.journal').length === 0 &&
		target.closest('.glue-ui').length === 0 &&
		target.closest('.journal-overlay').length === 0) {
		$.glue.sel.none();
	}
});


$(document).ready(function() {
	// Hide all alt text objects on initial load
	$('.journal').each(function() {
		$.glue.journal.dismissAlt(this);
		$.glue.journal.updateAltBtn(this);
	});

	//
	// Register context menu items
	//
	var elem;

	// Previous page button
	elem = $('<img src="' + $.glue.base_url + 'modules/journal/journal-prev.png" alt="btn" title="previous page" width="32" height="32">');
	$(elem).bind('click', function(e) {
		var obj = $(this).data('owner');
		$.glue.journal.prevPage(obj);
	});
	$.glue.contextmenu.register('journal', 'journal-prev', elem);

	// Next page button
	elem = $('<img src="' + $.glue.base_url + 'modules/journal/journal-next.png" alt="btn" title="next page" width="32" height="32">');
	$(elem).bind('click', function(e) {
		var obj = $(this).data('owner');
		$.glue.journal.nextPage(obj);
	});
	$.glue.contextmenu.register('journal', 'journal-next', elem);

	// Reset button
	elem = $('<img src="' + $.glue.base_url + 'modules/journal/journal-reset.png" alt="btn" title="reset to first page" width="32" height="32">');
	$(elem).bind('click', function(e) {
		var obj = $(this).data('owner');
		$.glue.journal.reset(obj);
	});
	$.glue.contextmenu.register('journal', 'journal-reset', elem);

	// Manage pages button
	elem = $('<img src="' + $.glue.base_url + 'modules/journal/journal-manage.png" alt="btn" title="manage pages" width="32" height="32">');
	$(elem).bind('click', function(e) {
		var obj = $(this).data('owner');
		$.glue.journal.managePages(obj);
	});
	$.glue.contextmenu.register('journal', 'journal-manage', elem);

	// Add page button (opens file picker)
	elem = $('<img src="' + $.glue.base_url + 'modules/journal/journal-add.png" alt="btn" title="add page" width="32" height="32">');
	$(elem).bind('click', function(e) {
		var obj = $(this).data('owner');
		$.glue.journal.addPage(obj);
	});
	$.glue.contextmenu.register('journal', 'journal-add', elem);

	// Edit alt text button
	elem = $('<img src="' + $.glue.base_url + 'modules/journal/journal-alt.png" alt="btn" title="edit alt text" width="32" height="32">');
	$(elem).bind('click', function(e) {
		var obj = $(this).data('owner');
		$.glue.journal.editAltText(obj);
	});
	$.glue.contextmenu.register('journal', 'journal-alt', elem);


	//
	// Register menu item to create new journal
	//
	elem = $('<img src="' + $.glue.base_url + 'modules/journal/journal-new.png" alt="btn" title="create new journal" width="32" height="32">');
	$(elem).bind('click', function(e) {
		var clickX = e.pageX;
		var clickY = e.pageY;
		$.glue.menu.hide();

		$.glue.backend({
			method: 'journal.create',
			page: $.glue.page,
			x: clickX + 'px',
			y: clickY + 'px'
		}, function(html) {
			if (html) {
				var newElem = $(html);
				$('body').append(newElem);
				$.glue.object.register(newElem);
				$.glue.sel.select(newElem);
			}
		});
	});
	$.glue.menu.register('new', elem);
});
