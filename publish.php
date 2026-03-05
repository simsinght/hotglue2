<?php

/*
 *	publish.php
 *	Export a hotglue page as a self-contained static site
 *
 *	Usage: php publish.php <page-name> <target-directory>
 *	Example: php publish.php thread-up /Volumes/web/thread-up
 */

if (php_sapi_name() !== 'cli') {
	die("This script must be run from the command line.\n");
}

if ($argc < 3) {
	fprintf(STDERR, "Usage: php publish.php <page-name> <target-directory>\n");
	fprintf(STDERR, "Example: php publish.php thread-up /Volumes/web/thread-up\n");
	exit(1);
}

$page_name = $argv[1];
$target_dir = rtrim($argv[2], '/');

// --- Step 1: Bootstrap CLI environment ---

$SENTINEL = 'http://__HOTGLUE_PUBLISH__/';

// Define BASE_URL before anything else (all @define calls will be no-ops)
define('BASE_URL', $SENTINEL);

// Fake server variables for the rendering pipeline
$_SERVER['HTTP_HOST'] = '__HOTGLUE_PUBLISH__';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['QUERY_STRING'] = '';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// Suppress PHP warnings/notices during rendering (noisy log.inc.php etc.)
error_reporting(E_ERROR | E_PARSE);

chdir(__DIR__);

// Load the framework
@require_once('config.inc.php');
require_once('common.inc.php');
require_once('html.inc.php');
require_once('modules.inc.php');
require_once('util.inc.php');

// Canonicalize page name
page_canonical($page_name);

if (!page_exists($page_name)) {
	fprintf(STDERR, "Error: page '%s' does not exist in %s\n", $page_name, CONTENT_DIR);
	exit(1);
}

echo "Publishing page: $page_name\n";

// Load modules (same as controller_show)
load_modules('glue');

// --- Step 2: Render the page ---

default_html(false);
$cache_page = true;
render_page(array('page' => $page_name, 'edit' => false));
$html = html_finalize($cache_page);

// --- Step 3: Build object map (object name → shared filename) ---

$page_parts = expl('.', $page_name);
$pagename_short = $page_parts[0];
$page_dir = CONTENT_DIR . '/' . str_replace('.', '/', $page_name);
$shared_dir = CONTENT_DIR . '/' . $pagename_short . '/shared';

$object_map = array(); // urlencode(obj_name) => filename in shared/
$shared_files = array(); // all filenames to copy

$files = @scandir($page_dir);
if ($files) {
	foreach ($files as $f) {
		if ($f === '.' || $f === '..' || $f === 'shared') continue;
		$fn = $page_dir . '/' . $f;
		if (!is_file($fn)) continue;

		$content = @file_get_contents($fn);
		if ($content === false) continue;

		$obj_name = $page_name . '.' . $f;

		// image-file / image-resized-file
		if (preg_match('/^image-file:(.+)$/m', $content, $m)) {
			$shared_files[] = trim($m[1]);
		}
		if (preg_match('/^image-resized-file:(.+)$/m', $content, $m)) {
			// Resized files are served when the object name is requested
			$object_map[urlencode($obj_name)] = trim($m[1]);
			$shared_files[] = trim($m[1]);
		} elseif (preg_match('/^image-file:(.+)$/m', $content, $m)) {
			$object_map[urlencode($obj_name)] = trim($m[1]);
		}

		// video-file
		if (preg_match('/^video-file:(.+)$/m', $content, $m)) {
			$object_map[urlencode($obj_name)] = trim($m[1]);
			$shared_files[] = trim($m[1]);
		}

		// page-background-file
		if (preg_match('/^page-background-file:(.+)$/m', $content, $m)) {
			$object_map[urlencode($obj_name)] = trim($m[1]);
			$shared_files[] = trim($m[1]);
		}

		// music-tracks (JSON with audioFile, coverFile, karaokeAudioFile)
		if (preg_match('/^music-tracks:(.+)$/m', $content, $m)) {
			$tracks = json_decode(trim($m[1]), true);
			if (is_array($tracks)) {
				foreach ($tracks as $t) {
					foreach (array('audioFile', 'coverFile', 'karaokeAudioFile') as $field) {
						if (!empty($t[$field])) {
							$shared_files[] = $t[$field];
						}
					}
				}
			}
		}

		// journal-pages (JSON with file)
		if (preg_match('/^journal-pages:(.+)$/m', $content, $m)) {
			$pages = json_decode(trim($m[1]), true);
			if (is_array($pages)) {
				foreach ($pages as $p) {
					if (!empty($p['file'])) {
						$shared_files[] = $p['file'];
					}
				}
			}
		}
	}
}

$shared_files = array_unique($shared_files);

// --- Step 4: Rewrite URLs (order matters — most specific first) ---

// Determine CONTENT_DIR relative path as used by music/journal modules
if (CONTENT_DIR[0] === '/') {
	$content_relative = basename(CONTENT_DIR);
} else {
	$content_relative = CONTENT_DIR;
}

// 4a. Content-dir shared URLs (music/journal pattern):
//     SENTINEL + "local-content/PAGE/shared/RAWENCODED_FILE" → assets/FILE
foreach ($shared_files as $sf) {
	$pattern = $SENTINEL . $content_relative . '/' . $pagename_short . '/shared/' . rawurlencode($sf);
	$html = str_replace($pattern, 'assets/' . rawurlencode($sf), $html);
}

// 4b. Object-name URLs (image/video/page-bg pattern):
//     SENTINEL + urlencode("page.head.12345") → assets/FILE
foreach ($object_map as $encoded_name => $filename) {
	// SHORT_URLS: base_url() + urlencode(name)
	$pattern = $SENTINEL . $encoded_name;
	$html = str_replace($pattern, 'assets/' . rawurlencode($filename), $html);
	// Non-short URLs: base_url() + '?' + urlencode(name)
	$pattern_q = $SENTINEL . '?' . $encoded_name;
	$html = str_replace($pattern_q, 'assets/' . rawurlencode($filename), $html);
}

// 4c. CSS framework files
$css_map = array();
if (USE_MIN_FILES) {
	$css_map['css/reset.min.css'] = 'reset.min.css';
} else {
	$css_map['css/reset.css'] = 'reset.css';
}
$css_map['css/main.css'] = 'main.css';

// Module CSS files that might be referenced
$module_css = array(
	'modules/image/image.css' => 'image.css',
	'modules/video/video.css' => 'video.css',
	'modules/music/music.css' => 'music.css',
	'modules/photostack/photostack.css' => 'photostack.css',
	'modules/journal/journal.css' => 'journal.css',
	'modules/download/download.css' => 'download.css',
	'modules/user_code/user_code.css' => 'user_code.css',
);

foreach ($module_css as $src => $dest) {
	if (strpos($html, $SENTINEL . $src) !== false) {
		$css_map[$src] = $dest;
	}
}

foreach ($css_map as $src_path => $asset_name) {
	$html = str_replace($SENTINEL . $src_path, 'assets/' . $asset_name, $html);
}

// 4d. Favicon
$favicon_referenced = false;
if (strpos($html, $SENTINEL . 'img/favicon.ico') !== false) {
	$html = str_replace($SENTINEL . 'img/favicon.ico', 'assets/favicon.ico', $html);
	$favicon_referenced = true;
}

// 4e. Strip any remaining JS references (jQuery, glue.js — not needed in view mode)
// Remove entire <script> tags that reference the sentinel
$html = preg_replace('/<script[^>]*src="' . preg_quote($SENTINEL, '/') . '[^"]*"[^>]*><\/script>\s*/', '', $html);

// 4f. Strip any remaining sentinel references as catch-all
// (e.g., in inline JS vars like $.glue.base_url)
$remaining_before = substr_count($html, '__HOTGLUE_PUBLISH__');
$html = str_replace($SENTINEL, '', $html);
$remaining_after = substr_count($html, '__HOTGLUE_PUBLISH__');

// --- Step 5: Copy assets to target ---

// Create target directories
if (!is_dir($target_dir)) {
	if (!mkdir($target_dir, 0755, true)) {
		fprintf(STDERR, "Error: cannot create target directory '%s'\n", $target_dir);
		exit(1);
	}
}

$assets_dir = $target_dir . '/assets';
if (!is_dir($assets_dir)) {
	mkdir($assets_dir, 0755, true);
}

$copied = 0;

// Copy CSS files
foreach ($css_map as $src_path => $asset_name) {
	$src = __DIR__ . '/' . $src_path;
	if (is_file($src)) {
		copy($src, $assets_dir . '/' . $asset_name);
		$copied++;
	} else {
		fprintf(STDERR, "Warning: CSS file not found: %s\n", $src);
	}
}

// Copy favicon
if ($favicon_referenced && is_file(__DIR__ . '/img/favicon.ico')) {
	copy(__DIR__ . '/img/favicon.ico', $assets_dir . '/favicon.ico');
	$copied++;
}

// Copy shared files (following symlinks)
if (is_dir($shared_dir)) {
	$iter = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($shared_dir, RecursiveDirectoryIterator::FOLLOW_SYMLINKS | RecursiveDirectoryIterator::SKIP_DOTS),
		RecursiveIteratorIterator::SELF_FIRST
	);
	foreach ($iter as $item) {
		// Get relative path from shared dir
		$rel = substr($item->getPathname(), strlen($shared_dir) + 1);
		$dest = $assets_dir . '/' . $rel;

		if ($item->isDir()) {
			if (!is_dir($dest)) {
				mkdir($dest, 0755, true);
			}
		} elseif ($item->isFile()) {
			$dest_subdir = dirname($dest);
			if (!is_dir($dest_subdir)) {
				mkdir($dest_subdir, 0755, true);
			}
			copy($item->getPathname(), $dest);
			$copied++;
		}
	}
}

// --- Step 6: Write output and report ---

file_put_contents($target_dir . '/index.html', $html);
$html_size = strlen($html);

echo "\nDone!\n";
echo sprintf("  index.html: %s\n", format_bytes($html_size));
echo sprintf("  assets/: %d files copied\n", $copied);

if ($remaining_before > 0) {
	echo sprintf("  Sentinel URLs rewritten: %d catch-all replacements\n", $remaining_before);
}
if ($remaining_after > 0) {
	fprintf(STDERR, "\n  WARNING: %d sentinel references remain in output!\n", $remaining_after);
	// Show context of remaining references
	$pos = 0;
	while (($pos = strpos($html, '__HOTGLUE_PUBLISH__', $pos)) !== false) {
		$start = max(0, $pos - 40);
		$end = min(strlen($html), $pos + 60);
		fprintf(STDERR, "    ...%s...\n", substr($html, $start, $end - $start));
		$pos += 19;
	}
}

echo sprintf("\nTarget: %s\n", realpath($target_dir));


function format_bytes($bytes) {
	if ($bytes >= 1048576) {
		return sprintf('%.1f MB', $bytes / 1048576);
	} elseif ($bytes >= 1024) {
		return sprintf('%.1f KB', $bytes / 1024);
	}
	return $bytes . ' bytes';
}
