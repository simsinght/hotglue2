<?php

/**
 *	module_journal.inc.php
 *	Journal/book page-flipping module for hotglue
 *
 *	Copyright Gottfried Haider, Danja Vasiliev 2010.
 *	This source code is licensed under the GNU General Public License.
 *	See the file COPYING for more details.
 */

@require_once('config.inc.php');
require_once('common.inc.php');
require_once('html.inc.php');
require_once('modules.inc.php');


/**
 *	implements alter_render_early
 *
 *	Renders the journal object with page-flip capability
 */
function journal_alter_render_early($args)
{
	$elem = &$args['elem'];
	$obj = $args['obj'];
	if (!elem_has_class($elem, 'journal')) {
		return false;
	}

	// Get pages data
	$pages = json_decode($obj['journal-pages'] ?? '[]', true);
	if (!is_array($pages)) {
		$pages = array();
	}

	// Start at configured start page (default: 0)
	$current = intval($obj['journal-start-page'] ?? 0);
	if ($current < 0 || $current >= count($pages)) {
		$current = 0;
	}
	elem_attr($elem, 'data-journal-current', $current);
	elem_attr($elem, 'data-journal-start-page', $current);
	elem_attr($elem, 'data-journal-count', count($pages));

	// If no pages, just add a placeholder style
	if (empty($pages)) {
		elem_css($elem, 'background', 'repeating-linear-gradient(45deg, #ddd, #ddd 10px, #eee 10px, #eee 20px)');
		elem_css($elem, 'min-width', '200px');
		elem_css($elem, 'min-height', '150px');
		return true;
	}

	// Get page name for building URLs (like photostack does)
	$a = expl('.', $obj['name']);
	$pagename = $a[0];

	// Build content URL base
	if (CONTENT_DIR[0] === '/') {
		$content_relative = basename(CONTENT_DIR);
	} else {
		$content_relative = CONTENT_DIR;
	}

	// Create the pages container with perspective for 3D
	$container = elem('div');
	elem_add_class($container, 'journal-pages');
	elem_css($container, 'position', 'relative');
	elem_css($container, 'width', '100%');
	elem_css($container, 'height', '100%');
	elem_css($container, 'perspective', '2000px');
	elem_css($container, 'transform-style', 'preserve-3d');

	// Create each page
	foreach ($pages as $index => $page) {
		$foldLine = floatval($page['foldLine'] ?? 50); // percentage from left

		// Build image URL from filename
		$filename = $page['file'] ?? '';
		$imgUrl = base_url() . $content_relative . '/' . $pagename . '/shared/' . rawurlencode($filename);

		// Create page wrapper
		$pageElem = elem('div');
		elem_add_class($pageElem, 'journal-page');
		elem_attr($pageElem, 'data-index', $index);
		elem_attr($pageElem, 'data-fold-line', $foldLine);
		elem_css($pageElem, 'position', 'absolute');
		elem_css($pageElem, 'top', '0');
		elem_css($pageElem, 'left', '0');
		elem_css($pageElem, 'width', '100%');
		elem_css($pageElem, 'height', '100%');
		elem_css($pageElem, 'transform-style', 'preserve-3d');

		$isCover = !empty($page['cover']);

		// Create left half (unfolds when arriving - "back of turned page")
		$leftHalf = elem('div');
		elem_add_class($leftHalf, 'journal-page-left');
		elem_css($leftHalf, 'position', 'absolute');
		elem_css($leftHalf, 'top', '0');
		elem_css($leftHalf, 'left', '0');
		elem_css($leftHalf, 'width', $foldLine . '%');
		elem_css($leftHalf, 'height', '100%');
		elem_css($leftHalf, 'overflow', 'hidden');

		if ($isCover) {
			// Cover pages have an empty left half
		} else {
			$leftImg = elem('img');
			elem_attr($leftImg, 'src', $imgUrl);
			elem_attr($leftImg, 'draggable', 'false');
			elem_css($leftImg, 'position', 'absolute');
			elem_css($leftImg, 'top', '0');
			elem_css($leftImg, 'left', '0');
			elem_css($leftImg, 'width', (100 / ($foldLine / 100)) . '%');
			elem_css($leftImg, 'height', '100%');
			elem_append($leftHalf, $leftImg);
		}
		elem_append($pageElem, $leftHalf);

		// Create right half (folds away when turning - "front of page")
		$rightHalf = elem('div');
		elem_add_class($rightHalf, 'journal-page-right');
		elem_css($rightHalf, 'position', 'absolute');
		elem_css($rightHalf, 'top', '0');
		elem_css($rightHalf, 'left', $foldLine . '%');
		elem_css($rightHalf, 'width', (100 - $foldLine) . '%');
		elem_css($rightHalf, 'height', '100%');
		elem_css($rightHalf, 'overflow', 'hidden');

		$rightImg = elem('img');
		elem_attr($rightImg, 'src', $imgUrl);
		elem_attr($rightImg, 'draggable', 'false');
		elem_css($rightImg, 'position', 'absolute');
		elem_css($rightImg, 'top', '0');
		if ($isCover) {
			elem_css($rightImg, 'left', '0');
			elem_css($rightImg, 'width', '100%');
			elem_css($rightImg, 'height', '100%');
		} else {
			elem_css($rightImg, 'right', '0');
			elem_css($rightImg, 'width', (100 / ((100 - $foldLine) / 100)) . '%');
			elem_css($rightImg, 'height', '100%');
		}
		elem_append($rightHalf, $rightImg);
		elem_append($pageElem, $rightHalf);

		// Set visibility based on current page
		if ($index == $current) {
			elem_css($pageElem, 'z-index', 2);
		} else {
			elem_css($pageElem, 'z-index', 1);
			elem_css($pageElem, 'visibility', 'hidden');
			// Future pages: left half pre-folded (back of page not yet turned)
			if ($index > $current) {
				elem_css($leftHalf, 'transform', 'rotateY(180deg)');
			}
			// Past pages: right half folded away (front of page already turned)
			if ($index < $current) {
				elem_css($rightHalf, 'transform', 'rotateY(-180deg)');
			}
		}

		elem_append($container, $pageElem);
	}

	elem_append($elem, $container);

	// Build alt objects map (page index => array of object names)
	$altMap = array();
	foreach ($pages as $index => $page) {
		$altObjs = $page['altObjects'] ?? array();
		if (!empty($altObjs)) {
			$altMap[$index] = $altObjs;
		}
	}
	if (!empty($altMap)) {
		elem_attr($elem, 'data-alt-objects', json_encode($altMap));
	}

	// Build page objects map (page index => array of object names)
	$pageObjMap = array();
	foreach ($pages as $index => $page) {
		$pageObjs = $page['pageObjects'] ?? array();
		if (!empty($pageObjs)) {
			$pageObjMap[$index] = $pageObjs;
		}
	}
	if (!empty($pageObjMap)) {
		elem_attr($elem, 'data-page-objects', json_encode($pageObjMap));
	}

	// Add ALT toggle button (hidden by default, JS shows it when current page has alt text)
	$altToggle = elem('button');
	elem_add_class($altToggle, 'journal-alt-toggle');
	elem_css($altToggle, 'display', 'none');
	elem_append($altToggle, 'ALT');
	elem_append($elem, $altToggle);

	return true;
}


/**
 *	implements save_state
 */
function journal_save_state($args)
{
	$elem = &$args['elem'];
	$obj = &$args['obj'];

	if (!elem_has_class($elem, 'journal')) {
		return false;
	}

	$obj['type'] = 'journal';
	$obj['module'] = 'journal';

	// Save any modified attributes back to object
	$current = elem_attr($elem, 'data-journal-current');
	if ($current !== NULL) {
		$obj['journal-current'] = intval($current);
	}

	// Extract CSS dimensions/position via alter_save hooks
	invoke_hook('alter_save', array('obj'=>&$obj, 'elem'=>$elem));

	load_modules('glue');
	$ret = save_object($obj);
	if ($ret['#error']) {
		log_msg('error', 'journal_save_state: error saving object');
		return false;
	} else {
		return true;
	}
}


/**
 *	implements render_object
 *
 *	Creates the journal object element
 */
function journal_render_object($args)
{
	$obj = $args['obj'];
	if (!isset($obj['type']) || $obj['type'] != 'journal') {
		return false;
	}

	// Create container element
	$e = elem('div');
	elem_attr($e, 'id', $obj['name']);
	elem_add_class($e, 'journal');
	elem_add_class($e, 'resizable');
	elem_add_class($e, 'object');

	// Invoke hooks to build the element
	invoke_hook_first('alter_render_early', 'journal', array('obj'=>$obj, 'elem'=>&$e, 'edit'=>$args['edit']));
	$html = elem_finalize($e);
	invoke_hook_last('alter_render_late', 'journal', array('obj'=>$obj, 'html'=>&$html, 'elem'=>$e, 'edit'=>$args['edit']));

	return $html;
}


/**
 *	implements render_page_early
 *
 *	Adds CSS and JS for journal
 */
function journal_render_page_early($args)
{
	// Always load CSS
	html_add_css(base_url() . 'modules/journal/journal.css');

	if ($args['edit']) {
		// Always load edit mode JS (for menu registration)
		html_add_js(base_url() . 'modules/journal/journal-edit.js');
	} else {
		// View mode JS (clicking to flip pages)
		html_add_js_inline('
			document.addEventListener("DOMContentLoaded", function() {
				function getAltMap(journal) {
					try { return JSON.parse(journal.getAttribute("data-alt-objects") || "{}"); }
					catch(e) { return {}; }
				}

				function getAltIds(journal, pageIndex) {
					var map = getAltMap(journal);
					return map[pageIndex] || [];
				}

				function showAltObjects(ids) {
					for (var k = 0; k < ids.length; k++) {
						var el = document.getElementById(ids[k]);
						if (el) el.style.display = "";
					}
				}

				function hideAltObjects(ids) {
					for (var k = 0; k < ids.length; k++) {
						var el = document.getElementById(ids[k]);
						if (el) el.style.display = "none";
					}
				}

				function hideAllAltObjects(journal) {
					var map = getAltMap(journal);
					for (var p in map) {
						hideAltObjects(map[p]);
					}
				}

				function getPageObjMap(journal) {
					try { return JSON.parse(journal.getAttribute("data-page-objects") || "{}"); }
					catch(e) { return {}; }
				}

				function getPageObjIds(journal, pageIndex) {
					var map = getPageObjMap(journal);
					return map[pageIndex] || [];
				}

				function showPageObjects(ids) {
					for (var k = 0; k < ids.length; k++) {
						var el = document.getElementById(ids[k]);
						if (el) el.style.display = "";
					}
				}

				function hidePageObjects(ids) {
					for (var k = 0; k < ids.length; k++) {
						var el = document.getElementById(ids[k]);
						if (el) el.style.display = "none";
					}
				}

				function hideAllPageObjects(journal) {
					var map = getPageObjMap(journal);
					for (var p in map) {
						hidePageObjects(map[p]);
					}
				}

				function removeDismissBtn(journal) {
					var existing = journal._altDismissBtn;
					if (existing && existing.parentNode) {
						existing.parentNode.removeChild(existing);
					}
					journal._altDismissBtn = null;
				}

				function createDismissBtn(journal) {
					removeDismissBtn(journal);
					var btn = document.createElement("button");
					btn.className = "journal-alt-dismiss";
					btn.textContent = "\u00d7";
					var rect = journal.getBoundingClientRect();
					var scrollX = window.pageXOffset || document.documentElement.scrollLeft;
					var scrollY = window.pageYOffset || document.documentElement.scrollTop;
					var objZ = parseInt(journal.style.zIndex) || 0;
					document.body.appendChild(btn);
					btn.style.left = (rect.right + scrollX - btn.offsetWidth - 8) + "px";
					btn.style.top = (rect.bottom + scrollY - btn.offsetHeight - 8) + "px";
					btn.style.zIndex = objZ + 2;
					btn.addEventListener("click", function(e) {
						e.stopPropagation();
						dismissAlt(journal);
						updateAltBtn(journal, altShowing);
					});
					journal._altDismissBtn = btn;
				}

				function dismissAlt(journal) {
					hideAllAltObjects(journal);
					removeDismissBtn(journal);
					var btn = journal.querySelector(".journal-alt-toggle");
					if (btn) { btn.textContent = "ALT"; btn.style.display = ""; btn.classList.remove("active"); }
				}

				function updateAltBtn(journal, isAltShowing) {
					var btn = journal.querySelector(".journal-alt-toggle");
					if (!btn) return;
					var current = parseInt(journal.getAttribute("data-journal-current")) || 0;
					var ids = getAltIds(journal, current);
					btn.style.display = (ids.length > 0 && !isAltShowing) ? "" : "none";
				}

				var journals = document.querySelectorAll(".journal");
				for (var i = 0; i < journals.length; i++) {
					(function(journal) {
						journal.style.cursor = "pointer";
						var isAnimating = false;
						var altShowing = false;

						// Hide all alt text objects on load
						hideAllAltObjects(journal);

						// Hide all page objects, then show current page stickers
						hideAllPageObjects(journal);
						var startIdx = parseInt(journal.getAttribute("data-journal-current")) || 0;
						showPageObjects(getPageObjIds(journal, startIdx));

						// ALT toggle button
						var altBtn = journal.querySelector(".journal-alt-toggle");
						if (altBtn) {
							altBtn.addEventListener("click", function(e) {
								e.stopPropagation();
								var current = parseInt(journal.getAttribute("data-journal-current")) || 0;
								var ids = getAltIds(journal, current);
								if (ids.length === 0) return;
								if (!altShowing) {
									showAltObjects(ids);
									altBtn.style.display = "none";
									createDismissBtn(journal);
									altShowing = true;
								} else {
									dismissAlt(journal);
									updateAltBtn(journal, altShowing);
									altShowing = false;
								}
							});
						}

						// Show ALT button only if current page has alt text
						updateAltBtn(journal, altShowing);

						journal.addEventListener("click", function(e) {
							if (isAnimating) return;
							if (e.target.closest(".journal-alt-toggle")) return;
							if (e.target.closest(".journal-alt-dismiss")) return;

							// Hide current page stickers and alt objects before flipping
							var curIdx = parseInt(journal.getAttribute("data-journal-current")) || 0;
							hidePageObjects(getPageObjIds(journal, curIdx));
							if (altShowing) {
								hideAltObjects(getAltIds(journal, curIdx));
							}

							var rect = journal.getBoundingClientRect();
							var clickX = e.clientX - rect.left;
							var width = rect.width;
							var clickPercent = (clickX / width) * 100;

							var current = parseInt(journal.getAttribute("data-journal-current")) || 0;
							var count = parseInt(journal.getAttribute("data-journal-count")) || 1;

							// Get current page fold line
							var currentPage = journal.querySelector(".journal-page[data-index=\"" + current + "\"]");
							var foldLine = currentPage ? parseFloat(currentPage.getAttribute("data-fold-line")) || 50 : 50;

							var next;
							if (clickPercent > foldLine && current < count - 1) {
								// Click on right side - go forward
								next = current + 1;
								isAnimating = true;

								var nextPage = journal.querySelector(".journal-page[data-index=\"" + next + "\"]");

								var cr = currentPage.querySelector(".journal-page-right");
								var nl = nextPage ? nextPage.querySelector(".journal-page-left") : null;

								// Pin starting transforms with transition disabled
								if (nl) { nl.style.transition = "none"; nl.style.transform = "rotateY(180deg)"; }
								if (cr) { cr.style.transition = "none"; cr.style.transform = "rotateY(0deg)"; }
								if (nextPage) { nextPage.style.visibility = "visible"; nextPage.style.zIndex = 1; }
								currentPage.style.zIndex = 2;

								// Force paint at starting position
								if (cr) cr.offsetHeight;
								if (nl) nl.offsetHeight;

								// Re-enable transition, then animate
								if (cr) { cr.style.transition = ""; cr.style.transform = "rotateY(-180deg)"; }
								if (nl) { nl.style.transition = ""; nl.style.transform = "rotateY(0deg)"; }
								setTimeout(function() {
									currentPage.style.visibility = "hidden";
									if (nextPage) nextPage.style.zIndex = 2;
									journal.setAttribute("data-journal-current", next);
									isAnimating = false;
									showPageObjects(getPageObjIds(journal, next));
									if (altShowing) {
										var newIds = getAltIds(journal, next);
										if (newIds.length > 0) {
											showAltObjects(newIds);
										} else {
											dismissAlt(journal);
											altShowing = false;
										}
									}
									updateAltBtn(journal, altShowing);
								}, 600);

							} else if (clickPercent <= foldLine && current > 0) {
								// Click on left side - go back
								next = current - 1;
								isAnimating = true;

								var prevPage = journal.querySelector(".journal-page[data-index=\"" + next + "\"]");

								var cl = currentPage.querySelector(".journal-page-left");
								var pr = prevPage ? prevPage.querySelector(".journal-page-right") : null;

								// Pin starting transforms with transition disabled
								if (cl) { cl.style.transition = "none"; cl.style.transform = "rotateY(0deg)"; }
								if (pr) { pr.style.transition = "none"; pr.style.transform = "rotateY(-180deg)"; }
								if (prevPage) { prevPage.style.visibility = "visible"; prevPage.style.zIndex = 2; }
								currentPage.style.zIndex = 1;

								// Force paint at starting position
								if (cl) cl.offsetHeight;
								if (pr) pr.offsetHeight;

								// Re-enable transition, then animate
								if (cl) { cl.style.transition = ""; cl.style.transform = "rotateY(180deg)"; }
								if (pr) { pr.style.transition = ""; pr.style.transform = "rotateY(0deg)"; }
								setTimeout(function() {
									currentPage.style.visibility = "hidden";
									journal.setAttribute("data-journal-current", next);
									isAnimating = false;
									showPageObjects(getPageObjIds(journal, next));
									if (altShowing) {
										var newIds = getAltIds(journal, next);
										if (newIds.length > 0) {
											showAltObjects(newIds);
										} else {
											dismissAlt(journal);
											altShowing = false;
										}
									}
									updateAltBtn(journal, altShowing);
								}, 600);
							}
						});
					})(journals[i]);
				}
			});
		', 5, 'journal');
	}
}


/**
 *	implements alter_render_late
 *
 *	Handle journal-specific rendering in editor mode
 */
function journal_alter_render_late($args)
{
	$elem = &$args['elem'];
	$obj = $args['obj'];

	if (!elem_has_class($elem, 'journal')) {
		return false;
	}

	// Additional edit-mode setup if needed
	return true;
}


// ============================================================================
// Backend services
// ============================================================================

/**
 *	Create a new journal from an uploaded image
 */
function journal_upload($args)
{
	load_modules('glue');

	if (empty($args['page'])) {
		return response('Required argument "page" is missing', 400);
	}

	// Handle file upload
	if (empty($_FILES['file'])) {
		return response('No file uploaded', 400);
	}

	$file = $_FILES['file'];
	if ($file['error'] !== UPLOAD_ERR_OK) {
		return response('File upload error', 400);
	}

	// Get image dimensions
	$size = @getimagesize($file['tmp_name']);
	if ($size === false) {
		return response('Invalid image file', 400);
	}

	$width = $size[0];
	$height = $size[1];

	// Get just the page name (without revision suffix like .head)
	$a = expl('.', $args['page']);
	$pagename = $a[0];

	// Move file to content directory (in shared/ like other modules)
	$shared_dir = CONTENT_DIR . '/' . $pagename . '/shared';
	if (!is_dir($shared_dir)) {
		@mkdir($shared_dir, 0777, true);
	}

	$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
	if (!in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp'))) {
		$ext = 'jpg';
	}

	$filename = time() . '_' . rand(1000, 9999) . '.' . $ext;
	$dest = $shared_dir . '/' . $filename;

	if (!move_uploaded_file($file['tmp_name'], $dest)) {
		return response('Failed to save uploaded file', 500);
	}

	// Create image data - store just the filename, build URL at render time
	$img = array(
		'file' => $filename,
		'foldLine' => 50
	);

	if (!empty($args['name'])) {
		// Add to existing journal
		$obj = load_object(array('name' => $args['name']));
		if ($obj['#error']) {
			return response('Journal not found', 404);
		}
		$obj = $obj['#data'];

		$pages = json_decode($obj['journal-pages'] ?? '[]', true);
		if (!is_array($pages)) {
			$pages = array();
		}
		$pages[] = $img;

		// Update the object with new pages array
		$obj['journal-pages'] = json_encode($pages);

		$ret = save_object($obj);
		if ($ret['#error']) {
			return $ret;
		}

		// Render and return HTML
		$ret = render_object(array('name' => $obj['name'], 'edit' => true));
		if ($ret['#error']) {
			return $ret;
		}

		return response($ret['#data']);
	} else {
		// Create new journal object
		$obj = create_object($args);
		if ($obj['#error']) {
			return $obj;
		}

		$name = $obj['#data']['name'];

		$obj = array();
		$obj['name'] = $name;
		$obj['type'] = 'journal';
		$obj['module'] = 'journal';
		$obj['journal-pages'] = json_encode(array($img));
		$obj['journal-current'] = 0;

		// Set initial size based on image
		if ($width > 0 && $height > 0) {
			$maxWidth = 600;
			if ($width > $maxWidth) {
				$scale = $maxWidth / $width;
				$width = $maxWidth;
				$height = round($height * $scale);
			}
			$obj['object-width'] = $width . 'px';
			$obj['object-height'] = $height . 'px';
		}

		// Set position if provided
		if (!empty($args['x'])) {
			$obj['object-left'] = $args['x'];
		}
		if (!empty($args['y'])) {
			$obj['object-top'] = $args['y'];
		}

		$ret = save_object($obj);
		if ($ret['#error']) {
			return $ret;
		}

		// Render and return HTML
		$ret = render_object(array('name' => $obj['name'], 'edit' => true));
		if ($ret['#error']) {
			return $ret;
		}

		return response($ret['#data']);
	}
}

register_service('journal.upload', 'journal_upload', array('auth'=>true));


/**
 *	Get journal data
 */
function journal_get_data($args)
{
	load_modules('glue');

	if (empty($args['name'])) {
		return response('Required argument "name" is missing', 400);
	}

	$obj = load_object(array('name' => $args['name']));
	if ($obj['#error']) {
		return response('Journal not found', 404);
	}
	$obj = $obj['#data'];

	$pages = json_decode($obj['journal-pages'] ?? '[]', true);
	$current = intval($obj['journal-current'] ?? 0);

	// Build full URLs for each page
	$a = expl('.', $obj['name']);
	$pagename = $a[0];

	if (CONTENT_DIR[0] === '/') {
		$content_relative = basename(CONTENT_DIR);
	} else {
		$content_relative = CONTENT_DIR;
	}

	foreach ($pages as &$page) {
		if (!empty($page['file'])) {
			$page['src'] = base_url() . $content_relative . '/' . $pagename . '/shared/' . rawurlencode($page['file']);
		}
	}

	$startPage = intval($obj['journal-start-page'] ?? 0);

	return response(array(
		'pages' => $pages,
		'current' => $current,
		'startPage' => $startPage
	));
}

register_service('journal.get_data', 'journal_get_data', array('auth'=>true));


/**
 *	Update pages array
 */
function journal_update_pages($args)
{
	load_modules('glue');

	if (empty($args['name'])) {
		return response('Required argument "name" is missing', 400);
	}

	if (!isset($args['pages'])) {
		return response('Required argument "pages" is missing', 400);
	}

	$pages = json_decode($args['pages'], true);
	if (!is_array($pages)) {
		return response('Invalid pages data', 400);
	}

	$obj = load_object(array('name' => $args['name']));
	if ($obj['#error']) {
		return response('Journal not found', 404);
	}
	$obj = $obj['#data'];

	$obj['journal-pages'] = json_encode($pages);

	return save_object($obj);
}

register_service('journal.update_pages', 'journal_update_pages', array('auth'=>true));


/**
 *	Set the start page for a journal
 */
function journal_set_start_page($args)
{
	load_modules('glue');

	if (empty($args['name'])) {
		return response('Required argument "name" is missing', 400);
	}

	if (!isset($args['startPage'])) {
		return response('Required argument "startPage" is missing', 400);
	}

	$obj = load_object(array('name' => $args['name']));
	if ($obj['#error']) {
		return response('Journal not found', 404);
	}
	$obj = $obj['#data'];

	$obj['journal-start-page'] = intval($args['startPage']);

	return save_object($obj);
}

register_service('journal.set_start_page', 'journal_set_start_page', array('auth'=>true));


/**
 *	Remove a page from the journal
 */
function journal_remove_page($args)
{
	load_modules('glue');

	if (empty($args['name'])) {
		return response('Required argument "name" is missing', 400);
	}

	if (!isset($args['index'])) {
		return response('Required argument "index" is missing', 400);
	}

	$index = intval($args['index']);

	$obj = load_object(array('name' => $args['name']));
	if ($obj['#error']) {
		return response('Journal not found', 404);
	}
	$obj = $obj['#data'];

	$pages = json_decode($obj['journal-pages'] ?? '[]', true);
	if (!is_array($pages) || $index < 0 || $index >= count($pages)) {
		return response('Invalid page index', 400);
	}

	array_splice($pages, $index, 1);

	$obj['journal-pages'] = json_encode($pages);

	return save_object($obj);
}

register_service('journal.remove_page', 'journal_remove_page', array('auth'=>true));


/**
 *	Create empty journal
 */
function journal_create($args)
{
	load_modules('glue');

	if (empty($args['page'])) {
		return response('Required argument "page" is missing', 400);
	}

	// Create new object
	$obj = create_object(array('page' => $args['page']));
	if ($obj['#error']) {
		return $obj;
	}
	$obj = $obj['#data'];

	$obj['type'] = 'journal';
	$obj['module'] = 'journal';
	$obj['journal-pages'] = '[]';
	$obj['journal-current'] = '0';

	// Set position if provided
	if (!empty($args['x'])) {
		$obj['object-left'] = $args['x'];
	}
	if (!empty($args['y'])) {
		$obj['object-top'] = $args['y'];
	}

	// Set default size
	$obj['object-width'] = '400px';
	$obj['object-height'] = '300px';

	$ret = save_object($obj);
	if ($ret['#error']) {
		return $ret;
	}

	// Render and return HTML
	$ret = render_object(array('name' => $obj['name'], 'edit' => true));
	if ($ret['#error']) {
		return $ret;
	}

	return response($ret['#data']);
}

register_service('journal.create', 'journal_create', array('auth'=>true));

?>
