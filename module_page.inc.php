<?php

/*
 *	module_page.inc.php
 *	Module for managing pages
 *
 *	Copyright Gottfried Haider, Danja Vasiliev 2010.
 *	This source code is licensed under the GNU General Public License.
 *	See the file COPYING for more details.
 */

@require_once('config.inc.php');
require_once('common.inc.php');
require_once('html.inc.php');
require_once('util.inc.php');


// module_image.inc.php has more information on what's going on inside modules 
// (they can be easier than that one though)


/**
 *	clear the page's current background image
 *
 *	@param array $args arguments
 *		key 'page' is the page (i.e. page.rev)
 *	@return array response
 */
function page_clear_background_img($args)
{
	if (!isset($args['page'])) {
		return response('Required argument "page" missing', 400);
	}
	if (!page_exists($args['page'])) {
		return response('Page '.quot($args['page']).' does not exist', 400);
	}
	
	load_modules('glue');
	$obj = load_object(array('name'=>$args['page'].'.page'));
	if ($obj['#error']) {
		// page object does not exist, hence no background image to clear
		return response(true);
	} else {
		$obj = $obj['#data'];
	}
	
	if (!empty($obj['page-background-file'])) {
		// delete file
		delete_upload(array('pagename'=>get_first_item(expl('.', $args['page'])), 'file'=>$obj['page-background-file'], 'max_cnt'=>1));
		// and remove attributes
		return object_remove_attr(array('name'=>$obj['name'], 'attr'=>array('page-background-file', 'page-background-mime')));
	} else {
		return response(true);
	}
}

register_service('page.clear_background_img', 'page_clear_background_img', array('auth'=>true));


function page_delete_page($args)
{
	$page = $args['page'];
	
	// check if there is a page object
	$obj = load_object(array('name'=>$page.'.page'));
	if ($obj['#error']) {
		return false;
	} else {
		$obj = $obj['#data'];
	}
	// check if there is a background-image
	if (!empty($obj['page-background-file'])) {
		// delete it
		delete_upload(array('pagename'=>get_first_item(expl('.', $page)), 'file'=>$obj['page-background-file'], 'max_cnt'=>1));
		return true;
	} else {
		return false;
	}
}


function page_has_reference($args)
{
	$obj = $args['obj'];
	$parts = expl('.', $obj['name']);
	if (array_pop($parts) != 'page') {
		return false;
	}
	
	if (!empty($obj['page-background-file']) && $obj['page-background-file'] == $args['file']) {
		return true;
	} else {
		return false;
	}
}


/**
 *	get the current grid size
 *
 *	@param array $args arguments
 *	@return array response
 *		'x', 'y' the grid size
 */
function page_get_grid($args)
{
	if (($s = @file_get_contents(CONTENT_DIR.'/grid')) !== false) {
		$a = expl(' ', $s);
		return response(array('x'=>intval($a[0]), 'y'=>intval($a[1])));
	} else {
		return response(array('x'=>PAGE_DEFAULT_GRID_X, 'y'=>PAGE_DEFAULT_GRID_Y));
	}
}

register_service('page.get_grid', 'page_get_grid');


function page_render_object($args)
{
	$obj = $args['obj'];
	$a = expl('.', $obj['name']);
	if ($a[2] != 'page') {
		return false;
	}
	
	// background-attachment
	if (!empty($obj['page-background-attachment'])) {
		html_css('background-attachment', $obj['page-background-attachment']);
	}
	// background-color
	if (!empty($obj['page-background-color'])) {
		html_css('background-color', $obj['page-background-color']);
	}
	// background-image
	if (!empty($obj['page-background-file'])) {
		if (SHORT_URLS) {
			html_css('background-image', 'url('.base_url().htmlspecialchars(urlencode($obj['name']), ENT_NOQUOTES, 'UTF-8').')');
		} else {
			html_css('background-image', 'url('.base_url().'?'.htmlspecialchars(urlencode($obj['name']), ENT_NOQUOTES, 'UTF-8').')');
		}
	}
	// background-image-position
	if (!empty($obj['page-background-image-position'])) {
		html_css('background-position', $obj['page-background-image-position']);
	}
	// set the html title
	if (isset($obj['page-title'])) {
		html_title($obj['page-title']);
	}
}


function page_render_page_early($args)
{
	if ($args['edit']) {
		if (USE_MIN_FILES) {
			html_add_js(base_url().'modules/page/page-edit.min.js');
		} else {
			html_add_js(base_url().'modules/page/page-edit.js');
		}
		html_add_css(base_url().'modules/page/page-edit.css');
		
		// set default grid
		$grid = page_get_grid(array());
		$grid = $grid['#data'];
		html_add_js_var('$.glue.conf.page.default_grid_x', $grid['x']);
		html_add_js_var('$.glue.conf.page.default_grid_y', $grid['y']);
				
		// set guides
		$guide = expl(' ', PAGE_GUIDES_X);
		for ($i=0; $i < count($guide); $i++) {
			$guide[$i] = intval(trim($guide[$i]));
		}
		html_add_js_var('$.glue.conf.page.guides_x', $guide);
		$guide = expl(' ', PAGE_GUIDES_Y);
		for ($i=0; $i < count($guide); $i++) {
			$guide[$i] = intval(trim($guide[$i]));
		}
		html_add_js_var('$.glue.conf.page.guides_y', $guide);
	}

	// set the html title to the page name by default
	html_title(page_short($args['page']));
}


function page_serve_resource($args)
{
	$obj = $args['obj'];
	$parts = expl('.', $obj['name']);
	if (array_pop($parts) != 'page') {
		return false;
	}
	$pn = get_first_item(expl('.', $obj['name']));
	
	if (!empty($obj['page-background-file'])) {
		$fn = CONTENT_DIR.'/'.$pn.'/shared/'.$obj['page-background-file'];
		if (isset($obj['page-background-mime'])) {
			$mime = $obj['page-background-mime'];
		} else {
			$mime = '';
		}
		serve_file($fn, false, $mime);
	}
	
	// if everything fails
	return false;
}


/**
 *	get the current grid size
 *
 *	@param array $args arguments
 *		key 'x', 'y' is the grid size
 *	@return array response
 *		true if successful
 */
function page_set_grid($args)
{
	if (($x = @intval($args['x'])) == 0) {
		return response('Required argument "x" missing or invalid', 400);
	}
	if (($y = @intval($args['y'])) == 0) {
		return response('Required argument "y" missing or invalid', 400);
	}
	
	$m = umask(0111);
	if (!@file_put_contents(CONTENT_DIR.'/grid', $x.' '.$y)) {
		umask($m);
		return response('Error saving to global grid file', 500);
	} else {
		umask($m);
		return response(true);
	}
}

register_service('page.set_grid', 'page_set_grid', array('auth'=>true));


function page_render_page_late($args)
{
	// In view mode, wrap all objects in a content-container div with
	// explicit dimensions so mobile browsers know the content bounds
	// (enables proper pinch-zoom, prevents back-swipe on min zoom)
	if ($args['edit']) {
		return;
	}

	// Scan object files to compute content bounds and find view anchors
	$page_dir = CONTENT_DIR.'/'.str_replace('.', '/', $args['page']);
	$maxR = 0;
	$maxB = 0;

	// View anchor positions
	$tl_left = null;
	$tl_top = null;
	$br_right = null;

	$files = @scandir($page_dir);
	if ($files) {
		foreach ($files as $f) {
			if ($f == '.' || $f == '..' || $f == 'page' || $f == 'shared' || $f == 'userhead') {
				continue;
			}
			$fn = $page_dir.'/'.$f;
			if (!is_file($fn)) {
				continue;
			}

			$content = @file_get_contents($fn);
			if ($content === false) {
				continue;
			}

			$left = 0; $top = 0; $width = 0; $height = 0;
			if (preg_match('/^object-left:(.+)$/m', $content, $m)) {
				$left = floatval(trim($m[1]));
			}
			if (preg_match('/^object-top:(.+)$/m', $content, $m)) {
				$top = floatval(trim($m[1]));
			}
			if (preg_match('/^object-width:(.+)$/m', $content, $m)) {
				$width = floatval(trim($m[1]));
			}
			if (preg_match('/^object-height:(.+)$/m', $content, $m)) {
				$height = floatval(trim($m[1]));
			}

			$r = $left + $width;
			$b = $top + $height;
			if ($r > $maxR) $maxR = $r;
			if ($b > $maxB) $maxB = $b;

			// Check for view-anchor property
			if (preg_match('/^view-anchor:(.+)$/m', $content, $m)) {
				$anchor = trim($m[1]);
				if ($anchor === 'top-left') {
					$tl_left = round($left);
					$tl_top = round($top);
				} else if ($anchor === 'bottom-right') {
					$br_right = round($left + $width);
				}
			}
		}
	}

	$maxR = ceil($maxR) + 50;
	$maxB = ceil($maxB) + 50;

	// Always use device-width viewport (iOS pinch-zoom works reliably with this)
	html_add_head_inline('<meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=0.1, maximum-scale=10">', 0);

	// Set zoom and scroll based on anchors
	if ($tl_left !== null && $br_right !== null) {
		$vp_width = $br_right - $tl_left;
		if ($vp_width > 0) {
			// Hide body until zoom + scroll are applied (prevents flash)
			html_add_css_inline('body { opacity: 0; }', 0);
			// Use CSS zoom on body to fit anchor span to screen width.
			// Viewport stays at scale=1 so pinch-out has full 10x range (1.0 → 0.1).
			$code = 'document.addEventListener("DOMContentLoaded", function() {';
			$code .= 'var z = screen.width / '.$vp_width.';';
			$code .= 'if (z < 1) document.body.style.zoom = z; else z = 1;';
			$code .= 'window.scrollTo(Math.round('.$tl_left.' * z), Math.round('.$tl_top.' * z));';
			$code .= 'document.body.style.opacity = "1";';
			$code .= '});';
			html_add_js_inline($code, 10, 'view anchor zoom and scroll');
		}
	} else if ($tl_left !== null) {
		// Only top-left anchor: just scroll, no zoom
		$code = 'document.addEventListener("DOMContentLoaded", function() { window.scrollTo('.$tl_left.', '.$tl_top.'); });';
		html_add_js_inline($code, 10, 'scroll to view anchor');
	}

	// Wrap body contents in a content-container div
	$bdy = &body();
	$container = elem('div');
	elem_add_class($container, 'content-container');
	elem_css($container, 'position', 'relative');
	elem_css($container, 'width', $maxR.'px');
	elem_css($container, 'height', $maxB.'px');
	elem_css($container, 'min-height', '100vh');

	if (isset($bdy['val'])) {
		$container['val'] = $bdy['val'];
		$bdy['val'] = array($container);
	}
}


function page_upload($args)
{
	// only handle the file if the frontend wants us to
	if (empty($args['preferred_module']) || $args['preferred_module'] != 'page') {
		return false;
	}
	// check if supported file
	if (!in_array($args['mime'], array('image/jpeg', 'image/png', 'image/gif')) || ($args['mime'] == '' && !in_array(filext($args['file']), array('jpg', 'jpeg', 'png', 'gif')))) {
		return false;
	}
	
	// check if there is already a background-image and delete it
	$obj = load_object(array('name'=>$args['page'].'.page'));
	if (!$obj['#error']) {
		$obj = $obj['#data'];
		if (!empty($obj['page-background-file'])) {
			delete_upload(array('pagename'=>get_first_item(expl('.', $args['page'])), 'file'=>$obj['page-background-file'], 'max_cnt'=>1));
		}
	}
	
	// set as background-image in page object
	$obj = array();
	$obj['name'] = $args['page'].'.page';
	$obj['page-background-file'] = $args['file'];
	$obj['page-background-mime'] = $args['mime'];
	
	// update page object
	load_modules('glue');
	$ret = update_object($obj);
	if ($ret['#error']) {
		log_msg('page_upload: error updating page object: '.quot($ret['#data']));
		return false;
	} else {
		// we don't actually render the object here, but signal the 
		// frontend that everything went okay
		return true;
	}
}
