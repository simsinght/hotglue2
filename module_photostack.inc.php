<?php

/*
 *	module_photostack.inc.php
 *	Module for displaying stacked images with click-through behavior
 *
 *	Copyright Gottfried Haider, Danja Vasiliev 2010.
 *	This source code is licensed under the GNU General Public License.
 *	See the file COPYING for more details.
 */

@require_once('config.inc.php');
require_once('html.inc.php');
require_once('modules.inc.php');
require_once('util.inc.php');


/**
 *	implements alter_render_early
 *
 *	Renders image layers from the photostack-images JSON
 */
function photostack_alter_render_early($args)
{
	$elem = &$args['elem'];
	$obj = $args['obj'];
	if (!elem_has_class($elem, 'photostack')) {
		return false;
	}

	// Get the images array
	$images = json_decode($obj['photostack-images'] ?? '[]', true);
	if (!is_array($images)) {
		$images = array();
	}

	// Always start at first image on page load
	$current = 0;
	elem_attr($elem, 'data-photostack-current', $current);
	elem_attr($elem, 'data-photostack-count', count($images));

	// If no images, just add a placeholder style so the object is visible
	if (count($images) == 0) {
		elem_css($elem, 'background-color', 'rgba(200, 200, 200, 0.3)');
		elem_css($elem, 'border', '2px dashed #999');
		return true;
	}

	// Get page name for building URLs
	$a = expl('.', $obj['name']);
	$pagename = $a[0];

	// Render each layer
	foreach ($images as $index => $img) {
		$layer = elem('div');
		elem_add_class($layer, 'photostack-layer');
		elem_attr($layer, 'data-index', $index);

		// Build image URL - serve directly from content directory
		// If CONTENT_DIR is absolute, extract just the folder name relative to base
		if (CONTENT_DIR[0] === '/') {
			// Get the last component of the path (e.g., 'local-content' from '/path/to/local-content')
			$content_relative = basename(CONTENT_DIR);
		} else {
			$content_relative = CONTENT_DIR;
		}
		$url = base_url().$content_relative.'/'.$pagename.'/shared/'.rawurlencode($img['file']);

		elem_css($layer, 'background-image', 'url('.$url.')');
		elem_css($layer, 'background-size', 'contain');
		elem_css($layer, 'background-repeat', 'no-repeat');
		elem_css($layer, 'background-position', 'center');
		elem_css($layer, 'position', 'absolute');

		// Apply positioning from image data
		$offsetX = floatval($img['offsetX'] ?? 0);
		$offsetY = floatval($img['offsetY'] ?? 0);
		$scale = floatval($img['scale'] ?? 100);
		$rotation = floatval($img['rotation'] ?? 0);
		$ghost = intval($img['ghost'] ?? 0);
		$fade = intval($img['fade'] ?? 100); // default 100 = fully visible when passed

		// Position and size relative to container
		elem_css($layer, 'left', $offsetX.'%');
		elem_css($layer, 'top', $offsetY.'%');
		elem_css($layer, 'width', $scale.'%');
		elem_css($layer, 'height', $scale.'%');

		// Apply rotation if set
		if ($rotation != 0) {
			elem_css($layer, 'transform', 'rotate('.$rotation.'deg)');
		}

		// Store ghost/fade values as data attributes for JS
		elem_attr($layer, 'data-ghost', $ghost);
		elem_attr($layer, 'data-fade', $fade);

		// Determine visibility based on current index
		if ($index < $current) {
			// This layer has been passed (newer layers on top)
			if ($fade == 0) {
				elem_css($layer, 'opacity', '0');
				elem_css($layer, 'pointer-events', 'none');
			} else {
				elem_css($layer, 'opacity', ($fade / 100));
			}
		} else if ($index == $current) {
			// This layer is currently on top
			elem_css($layer, 'opacity', '1');
		} else {
			// This layer is not yet revealed
			if ($ghost > 0) {
				// Show as ghost
				elem_add_class($layer, 'photostack-ghost');
				elem_css($layer, 'opacity', ($ghost / 100));
			} else {
				// Hide completely
				elem_css($layer, 'opacity', '0');
				elem_css($layer, 'pointer-events', 'none');
			}
		}

		elem_append($elem, $layer);
	}

	// Render click area divs for each layer
	$clickAreasMap = array();
	foreach ($images as $index => $img) {
		$areas = $img['clickAreas'] ?? array();
		if (!is_array($areas) || count($areas) == 0) {
			continue;
		}
		$clickAreasMap[$index] = $areas;
		foreach ($areas as $areaIndex => $area) {
			$areaDiv = elem('div');
			elem_add_class($areaDiv, 'photostack-click-area');
			elem_attr($areaDiv, 'data-layer', $index);
			elem_attr($areaDiv, 'data-area-index', $areaIndex);
			$x = floatval($area['x'] ?? 0);
			$y = floatval($area['y'] ?? 0);
			$w = floatval($area['w'] ?? 0);
			$h = floatval($area['h'] ?? 0);
			elem_css($areaDiv, 'position', 'absolute');
			elem_css($areaDiv, 'left', $x.'%');
			elem_css($areaDiv, 'top', $y.'%');
			elem_css($areaDiv, 'width', $w.'%');
			elem_css($areaDiv, 'height', $h.'%');
			elem_css($areaDiv, 'display', 'none');
			elem_append($elem, $areaDiv);
		}
	}
	if (!empty($clickAreasMap)) {
		elem_attr($elem, 'data-layer-clickareas', json_encode($clickAreasMap));
	}

	// Build layer objects map (layer index => array of object names)
	$layerObjMap = array();
	foreach ($images as $index => $img) {
		$layerObjs = $img['layerObjects'] ?? array();
		if (!empty($layerObjs)) {
			$layerObjMap[$index] = $layerObjs;
		}
	}
	if (!empty($layerObjMap)) {
		elem_attr($elem, 'data-layer-objects', json_encode($layerObjMap));
	}

	return true;
}


/**
 *	implements alter_save
 *
 *	Extracts photostack properties from the element
 */
function photostack_alter_save($args)
{
	$elem = $args['elem'];
	$obj = &$args['obj'];

	if (!elem_has_class($elem, 'photostack')) {
		return false;
	}

	// Get current index from data attribute
	$current = elem_attr($elem, 'data-photostack-current');
	if ($current !== NULL) {
		$obj['photostack-current'] = intval($current);
	}

	return true;
}


/**
 *	implements has_reference
 *
 *	Check if this object references a specific file
 */
function photostack_has_reference($args)
{
	$obj = $args['obj'];
	if (!isset($obj['type']) || $obj['type'] != 'photostack') {
		return false;
	}

	// Check if the file is in our images array
	$images = json_decode($obj['photostack-images'] ?? '[]', true);
	if (is_array($images)) {
		foreach ($images as $img) {
			if (!empty($img['file']) && $img['file'] == $args['file']) {
				return true;
			}
		}
	}

	return false;
}


/**
 *	implements delete_object
 *
 *	Cleans up all image files when photostack is deleted
 */
function photostack_delete_object($args)
{
	$obj = $args['obj'];
	if (!isset($obj['type']) || $obj['type'] != 'photostack') {
		return false;
	}

	load_modules('glue');

	// Get all images and delete them
	$images = json_decode($obj['photostack-images'] ?? '[]', true);
	if (is_array($images)) {
		$a = expl('.', $obj['name']);
		foreach ($images as $img) {
			if (!empty($img['file'])) {
				delete_upload(array('pagename'=>$a[0], 'file'=>$img['file'], 'max_cnt'=>1));
			}
		}
	}

	return true;
}


/**
 *	implements render_object
 *
 *	Creates the basic photostack container element
 */
function photostack_render_object($args)
{
	$obj = $args['obj'];
	if (!isset($obj['type']) || $obj['type'] != 'photostack') {
		return false;
	}

	$e = elem('div');
	elem_attr($e, 'id', $obj['name']);
	elem_add_class($e, 'photostack');
	elem_add_class($e, 'resizable');
	elem_add_class($e, 'object');

	// Invoke hooks to build the element
	invoke_hook_first('alter_render_early', 'photostack', array('obj'=>$obj, 'elem'=>&$e, 'edit'=>$args['edit']));
	$html = elem_finalize($e);
	invoke_hook_last('alter_render_late', 'photostack', array('obj'=>$obj, 'html'=>&$html, 'elem'=>$e, 'edit'=>$args['edit']));

	return $html;
}


/**
 *	implements render_page_early
 *
 *	Loads JS and CSS for photostack module
 */
function photostack_render_page_early($args)
{
	// Always load CSS
	html_add_css(base_url().'modules/photostack/photostack.css');

	if ($args['edit']) {
		// Full editing JS
		if (USE_MIN_FILES) {
			html_add_js(base_url().'modules/photostack/photostack-edit.min.js');
		} else {
			html_add_js(base_url().'modules/photostack/photostack-edit.js');
		}
	} else {
		// View mode - inline JS for cycling through images (no jQuery dependency)
		html_add_js_inline('
			document.addEventListener("DOMContentLoaded", function() {
				function getLayerObjMap(stack) {
					try { return JSON.parse(stack.getAttribute("data-layer-objects") || "{}"); }
					catch(e) { return {}; }
				}
				function getLayerObjIds(stack, layerIndex) {
					var map = getLayerObjMap(stack);
					return map[layerIndex] || [];
				}
				function showLayerObjects(ids) {
					for (var k = 0; k < ids.length; k++) {
						var el = document.getElementById(ids[k]);
						if (el) el.style.display = "";
					}
				}
				function hideLayerObjects(ids) {
					for (var k = 0; k < ids.length; k++) {
						var el = document.getElementById(ids[k]);
						if (el) el.style.display = "none";
					}
				}
				function hideAllLayerObjects(stack) {
					var map = getLayerObjMap(stack);
					for (var p in map) {
						hideLayerObjects(map[p]);
					}
				}
				var stacks = document.querySelectorAll(".photostack");
				for (var i = 0; i < stacks.length; i++) {
					(function(stack) {
						hideAllLayerObjects(stack);
						showLayerObjects(getLayerObjIds(stack, 0));
						stack.style.cursor = "pointer";
						stack.addEventListener("click", function(e) {
							var current = parseInt(stack.getAttribute("data-photostack-current")) || 0;
							var count = parseInt(stack.getAttribute("data-photostack-count")) || 1;
							if (count <= 1) return;
							var next = (current + 1) % count;
							hideLayerObjects(getLayerObjIds(stack, current));
							stack.setAttribute("data-photostack-current", next);
							var layers = stack.querySelectorAll(".photostack-layer");
							for (var j = 0; j < layers.length; j++) {
								var layer = layers[j];
								var index = parseInt(layer.getAttribute("data-index"));
								var ghost = parseInt(layer.getAttribute("data-ghost")) || 0;
								var fade = parseInt(layer.getAttribute("data-fade"));
								if (isNaN(fade)) fade = 100;

								if (index < next) {
									// Passed layer - use fade
									if (fade == 0) {
										layer.style.opacity = "0";
										layer.style.pointerEvents = "none";
									} else {
										layer.style.opacity = (fade / 100).toString();
										layer.style.pointerEvents = "";
									}
								} else if (index == next) {
									// Current top layer
									layer.style.opacity = "1";
									layer.style.pointerEvents = "";
								} else {
									// Not yet revealed - use ghost
									if (ghost > 0) {
										layer.style.opacity = (ghost / 100).toString();
										layer.style.pointerEvents = "none";
									} else {
										layer.style.opacity = "0";
										layer.style.pointerEvents = "none";
									}
								}
							}
							showLayerObjects(getLayerObjIds(stack, next));
						});
					})(stacks[i]);
				}
			});
		', 5, 'photostack');
	}
}


/**
 *	implements save_state
 *
 *	Saves the photostack object
 */
function photostack_save_state($args)
{
	$elem = $args['elem'];
	$obj = $args['obj'];

	// Only handle if photostack is the main class
	if (get_first_item(elem_classes($elem)) != 'photostack') {
		return false;
	}

	$obj['type'] = 'photostack';
	$obj['module'] = 'photostack';

	// Invoke alter_save hooks
	invoke_hook('alter_save', array('obj'=>&$obj, 'elem'=>$elem));

	// Save the object
	load_modules('glue');
	$ret = save_object($obj);
	if ($ret['#error']) {
		log_msg('error', 'photostack_save_state: save_object returned '.quot($ret['#data']));
		return false;
	}

	return true;
}


/**
 *	implements upload
 *
 *	Adds uploaded image to an existing photostack
 */
function photostack_upload($args)
{
	// Check if this is an image file
	if (!in_array($args['mime'], array('image/jpeg', 'image/png', 'image/gif'))) {
		$ext = filext($args['file']);
		if (!in_array($ext, array('jpg', 'jpeg', 'png', 'gif'))) {
			return false;
		}
	}

	// Check if we have a target photostack
	if (empty($args['photostack_target'])) {
		return false;
	}

	load_modules('glue');

	$target = $args['photostack_target'];
	$file = $args['file'];
	$mime = $args['mime'] ?? '';

	// Get just the first part of the page name (without revision)
	$a = expl('.', $args['page']);
	$pagename = $a[0];

	// Get image dimensions
	$width = 0;
	$height = 0;
	$fn = CONTENT_DIR.'/'.$pagename.'/shared/'.$file;
	$size = @getimagesize($fn);
	if ($size !== false) {
		$width = $size[0];
		$height = $size[1];
	}

	// Create image entry
	$img = array(
		'file' => $file,
		'mime' => $mime,
		'width' => $width,
		'height' => $height,
		'offsetX' => 0,
		'offsetY' => 0,
		'scale' => 100,
		'rotation' => 0,
		'ghost' => 0,
		'fade' => 100
	);

	// Load target object
	$obj = load_object(array('name' => $target));
	if ($obj['#error']) {
		log_msg('error', 'photostack_upload: could not load target object '.quot($target));
		return false;
	}
	$obj = $obj['#data'];

	// Add image to array
	$images = json_decode($obj['photostack-images'] ?? '[]', true);
	if (!is_array($images)) {
		$images = array();
	}
	$images[] = $img;
	$obj['photostack-images'] = json_encode($images);

	$ret = save_object($obj);
	if ($ret['#error']) {
		log_msg('error', 'photostack_upload: could not save object '.quot($target));
		return false;
	}

	// Render and return HTML
	$ret = render_object(array('name' => $obj['name'], 'edit' => true));
	if ($ret['#error']) {
		return false;
	}

	return $ret['#data'];
}


/**
 *	Add an image to an existing photostack or create a new one
 *
 *	@param array $args arguments
 *		key 'name' object name (optional, creates new if not provided)
 *		key 'page' page name
 *		key 'file' filename in shared directory
 *		key 'mime' mime type
 *		key 'width' image width
 *		key 'height' image height
 *	@return array response
 */
function photostack_add_image($args)
{
	load_modules('glue');

	if (empty($args['page'])) {
		return response('Required argument "page" is missing', 400);
	}
	if (empty($args['file'])) {
		return response('Required argument "file" is missing', 400);
	}

	$pagename = $args['page'];
	$file = $args['file'];
	$mime = $args['mime'] ?? '';
	$width = intval($args['width'] ?? 0);
	$height = intval($args['height'] ?? 0);

	// Try to get dimensions if not provided
	if ($width == 0 || $height == 0) {
		$fn = CONTENT_DIR.'/'.$pagename.'/shared/'.$file;
		$size = @getimagesize($fn);
		if ($size !== false) {
			$width = $size[0];
			$height = $size[1];
		}
	}

	// Create image entry
	$img = array(
		'file' => $file,
		'mime' => $mime,
		'width' => $width,
		'height' => $height,
		'offsetX' => 0,
		'offsetY' => 0,
		'scale' => 100,
		'rotation' => 0,
		'ghost' => 0,
		'fade' => 100
	);

	if (!empty($args['name'])) {
		// Add to existing photostack
		$obj = load_object(array('name' => $args['name']));
		if ($obj['#error']) {
			return $obj;
		}
		$obj = $obj['#data'];

		$images = json_decode($obj['photostack-images'] ?? '[]', true);
		if (!is_array($images)) {
			$images = array();
		}
		$images[] = $img;
		$obj['photostack-images'] = json_encode($images);

		$ret = save_object($obj);
		if ($ret['#error']) {
			return $ret;
		}

		return response(array(
			'name' => $obj['name'],
			'images' => $images,
			'index' => count($images) - 1
		));
	} else {
		// Create new photostack
		$obj = create_object(array('page' => $pagename));
		if ($obj['#error']) {
			return $obj;
		}
		$obj = $obj['#data'];

		$obj['type'] = 'photostack';
		$obj['module'] = 'photostack';
		$obj['photostack-images'] = json_encode(array($img));
		$obj['photostack-current'] = 0;

		// Set initial size based on first image
		if ($width > 0 && $height > 0) {
			$obj['object-width'] = $width.'px';
			$obj['object-height'] = $height.'px';
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

register_service('photostack.add_image', 'photostack_add_image', array('auth'=>true));


/**
 *	Update the current index of a photostack
 *
 *	@param array $args arguments
 *		key 'name' object name
 *		key 'current' new current index
 *	@return array response
 */
/**
 *	Update the images array of a photostack
 *
 *	@param array $args arguments
 *		key 'name' object name
 *		key 'images' JSON string of images array
 *	@return array response
 */
function photostack_update_images($args)
{
	load_modules('glue');

	if (empty($args['name'])) {
		return response('Required argument "name" is missing', 400);
	}
	if (!isset($args['images'])) {
		return response('Required argument "images" is missing', 400);
	}

	// Validate JSON
	$images = json_decode($args['images'], true);
	if (!is_array($images)) {
		return response('Invalid images JSON', 400);
	}

	$ret = update_object(array(
		'name' => $args['name'],
		'photostack-images' => $args['images']
	));

	return $ret;
}

register_service('photostack.update_images', 'photostack_update_images', array('auth'=>true));


/**
 *	Remove an image from a photostack
 *
 *	@param array $args arguments
 *		key 'name' object name
 *		key 'index' index of image to remove
 *		key 'delete_file' whether to delete the file (default true)
 *	@return array response
 */
function photostack_remove_image($args)
{
	load_modules('glue');

	if (empty($args['name'])) {
		return response('Required argument "name" is missing', 400);
	}
	if (!isset($args['index'])) {
		return response('Required argument "index" is missing', 400);
	}

	$index = intval($args['index']);
	$delete_file = isset($args['delete_file']) ? ($args['delete_file'] !== 'false' && $args['delete_file'] !== false) : true;

	// Load object
	$obj = load_object(array('name' => $args['name']));
	if ($obj['#error']) {
		return $obj;
	}
	$obj = $obj['#data'];

	$images = json_decode($obj['photostack-images'] ?? '[]', true);
	if (!is_array($images) || $index < 0 || $index >= count($images)) {
		return response('Invalid index', 400);
	}

	// Delete file if requested
	if ($delete_file && !empty($images[$index]['file'])) {
		$a = expl('.', $args['name']);
		delete_upload(array('pagename'=>$a[0], 'file'=>$images[$index]['file'], 'max_cnt'=>1));
	}

	// Remove from array
	array_splice($images, $index, 1);

	// Adjust current index if needed
	$current = intval($obj['photostack-current'] ?? 0);
	if ($current >= count($images)) {
		$current = max(0, count($images) - 1);
	}

	// Save
	$ret = update_object(array(
		'name' => $args['name'],
		'photostack-images' => json_encode($images),
		'photostack-current' => $current
	));

	return $ret;
}

register_service('photostack.remove_image', 'photostack_remove_image', array('auth'=>true));


/**
 *	Get photostack data
 *
 *	@param array $args arguments
 *		key 'name' object name
 *	@return array response with images and current index
 */
function photostack_get_data($args)
{
	load_modules('glue');

	if (empty($args['name'])) {
		return response('Required argument "name" is missing', 400);
	}

	$obj = load_object(array('name' => $args['name']));
	if ($obj['#error']) {
		return $obj;
	}
	$obj = $obj['#data'];

	$images = json_decode($obj['photostack-images'] ?? '[]', true);
	$current = intval($obj['photostack-current'] ?? 0);

	return response(array(
		'images' => $images,
		'current' => $current
	));
}

register_service('photostack.get_data', 'photostack_get_data', array('auth'=>true));


/**
 *	Create a new empty photostack
 *
 *	@param array $args arguments
 *		key 'page' page name
 *		key 'x' x position (optional)
 *		key 'y' y position (optional)
 *	@return array response with rendered HTML
 */
function photostack_create($args)
{
	load_modules('glue');
	log_msg('info', 'photostack_create: starting with args '.var_export($args, true));

	if (empty($args['page'])) {
		log_msg('error', 'photostack_create: page is missing');
		return response('Required argument "page" is missing', 400);
	}

	// Create new object
	log_msg('info', 'photostack_create: calling create_object for page '.$args['page']);
	$obj = create_object(array('page' => $args['page']));
	if ($obj['#error']) {
		log_msg('error', 'photostack_create: create_object failed: '.$obj['#data']);
		return response('create_object failed: ' . $obj['#data'], 500);
	}
	$obj = $obj['#data'];
	log_msg('info', 'photostack_create: created object '.$obj['name']);

	$obj['type'] = 'photostack';
	$obj['module'] = 'photostack';
	$obj['photostack-images'] = '[]';
	$obj['photostack-current'] = '0';

	// Set position if provided
	if (!empty($args['x'])) {
		$obj['object-left'] = intval($args['x']).'px';
	}
	if (!empty($args['y'])) {
		$obj['object-top'] = intval($args['y']).'px';
	}

	// Set default size
	$obj['object-width'] = '200px';
	$obj['object-height'] = '200px';

	log_msg('info', 'photostack_create: saving object');
	$ret = save_object($obj);
	if ($ret['#error']) {
		log_msg('error', 'photostack_create: save_object failed: '.$ret['#data']);
		return response('save_object failed: ' . $ret['#data'], 500);
	}
	log_msg('info', 'photostack_create: object saved');

	// Render and return HTML
	log_msg('info', 'photostack_create: rendering object '.$obj['name']);
	$ret = render_object(array('name' => $obj['name'], 'edit' => true));
	if ($ret['#error']) {
		log_msg('error', 'photostack_create: render_object failed: '.$ret['#data']);
		return response('render_object failed: ' . $ret['#data'], 500);
	}

	if (empty($ret['#data'])) {
		log_msg('error', 'photostack_create: render_object returned empty HTML');
		return response('render_object returned empty HTML for ' . $obj['name'], 500);
	}

	log_msg('info', 'photostack_create: success, returning HTML');
	return response($ret['#data']);
}

register_service('photostack.create', 'photostack_create', array('auth'=>true));


/**
 *	implements snapshot_symlink
 *
 *	Copies referenced files when creating a snapshot
 */
function photostack_snapshot_symlink($args)
{
	$obj = $args['obj'];
	if (!isset($obj['type']) || $obj['type'] != 'photostack') {
		return false;
	}

	$dest_dir = CONTENT_DIR.'/'.get_first_item(expl('.', $obj['name'])).'/shared';
	$src_dir = CONTENT_DIR.'/'.get_first_item(expl('.', $args['origin'])).'/shared';

	$images = json_decode($obj['photostack-images'] ?? '[]', true);
	if (!is_array($images)) {
		return true;
	}

	$modified = false;
	foreach ($images as &$img) {
		if (empty($img['file'])) {
			continue;
		}

		$src_file = $src_dir.'/'.$img['file'];
		if (($f = dir_has_same_file($dest_dir, $src_file)) !== false) {
			$img['file'] = $f;
			$modified = true;
		} else {
			// Copy file
			$dest_file = $dest_dir.'/'.unique_filename($dest_dir, $src_file);
			$m = umask(0111);
			if (!(@copy($src_file, $dest_file))) {
				umask($m);
				log_msg('error', 'photostack_snapshot_symlink: error copying referenced file '.quot($src_file).' to '.quot($dest_file));
				continue;
			}
			umask($m);
			$img['file'] = basename($dest_file);
			$modified = true;
			log_msg('info', 'photostack_snapshot_symlink: copied referenced file to '.quot($dest_file));
		}
	}

	if ($modified) {
		$obj['photostack-images'] = json_encode($images);
		$ret = save_object($obj);
		if ($ret['#error']) {
			log_msg('error', 'photostack_snapshot_symlink: error saving object '.quot($obj['name']));
			return false;
		}
	}

	return true;
}
