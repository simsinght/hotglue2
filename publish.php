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
$no_optimize = array(); // files that must not be format-converted (e.g. music covers)

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

		// music-tracks (JSON with audioFile, coverFile, karaokeAudioFile, lrcFile)
		if (preg_match('/^music-tracks:(.+)$/m', $content, $m)) {
			$tracks = json_decode(trim($m[1]), true);
			if (is_array($tracks)) {
				foreach ($tracks as $t) {
					foreach (array('audioFile', 'coverFile', 'karaokeAudioFile', 'lrcFile', 'lyricsVideoFile') as $field) {
						if (!empty($t[$field])) {
							$shared_files[] = $t[$field];
							// Cover files must keep their original format (JS references coverFile by name)
							if ($field === 'coverFile') {
								$no_optimize[] = $t[$field];
							}
						}
					}
				}
			}
		}

		// photostack-images (JSON with file)
		if (preg_match('/^photostack-images:(.+)$/m', $content, $m)) {
			$ps_images = json_decode(trim($m[1]), true);
			if (is_array($ps_images)) {
				foreach ($ps_images as $pi) {
					if (!empty($pi['file'])) {
						$shared_files[] = $pi['file'];
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
//     Also handle JSON-escaped versions (json_encode escapes / to \/)
//     which appear inside data-music-tracks attributes after htmlspecialchars
$json_sentinel = str_replace('/', '\\/', $SENTINEL);
$json_shared_base = str_replace('/', '\\/', $content_relative . '/' . $pagename_short . '/shared/');
foreach ($shared_files as $sf) {
	$encoded_file = rawurlencode($sf);
	// Plain URL
	$pattern = $SENTINEL . $content_relative . '/' . $pagename_short . '/shared/' . $encoded_file;
	$html = str_replace($pattern, 'assets/' . $encoded_file, $html);
	// JSON-escaped URL (inside data attributes that went through json_encode + htmlspecialchars)
	$json_pattern = $json_sentinel . $json_shared_base . str_replace('/', '\\/', $encoded_file);
	$html = str_replace($json_pattern, 'assets\\/' . str_replace('/', '\\/', $encoded_file), $html);
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
// Also strip JSON-escaped version
$html = str_replace($json_sentinel, '', $html);
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
$cached_count = 0;
$expected_assets = array(); // track what belongs in assets/

// Copy CSS files
foreach ($css_map as $src_path => $asset_name) {
	$src = __DIR__ . '/' . $src_path;
	$expected_assets[] = $asset_name;
	if (is_file($src)) {
		$dest = $assets_dir . '/' . $asset_name;
		if (is_file($dest) && filemtime($src) <= filemtime($dest)) {
			$cached_count++;
		} else {
			copy($src, $dest);
		}
		$copied++;
	} else {
		fprintf(STDERR, "Warning: CSS file not found: %s\n", $src);
	}
}

// Copy favicon
if ($favicon_referenced && is_file(__DIR__ . '/img/favicon.ico')) {
	$expected_assets[] = 'favicon.ico';
	$fav_src = __DIR__ . '/img/favicon.ico';
	$fav_dest = $assets_dir . '/favicon.ico';
	if (is_file($fav_dest) && filemtime($fav_src) <= filemtime($fav_dest)) {
		$cached_count++;
	} else {
		copy($fav_src, $fav_dest);
	}
	$copied++;
}

// Copy only referenced shared files (following symlinks), optimizing images
$skipped = 0;
$optimized = 0;
$MAX_DIMENSION = 2048;
$JPEG_QUALITY = 85;

foreach ($shared_files as $sf) {
	$src = $shared_dir . '/' . $sf;
	// Resolve symlinks
	if (is_link($src)) {
		$target = readlink($src);
		if (substr($target, 0, 1) !== '/') {
			$target = dirname($src) . '/' . $target;
		}
		$src = $target;
	}
	if (!is_file($src)) {
		fprintf(STDERR, "Warning: shared file not found: %s\n", $sf);
		continue;
	}

	$src_mtime = filemtime($src);
	$ext = strtolower(pathinfo($sf, PATHINFO_EXTENSION));

	// Cache check: for PNG/BMP that may have been converted to JPG previously
	if (in_array($ext, array('png', 'bmp')) && !in_array($sf, $no_optimize)) {
		$jpg_name = pathinfo($sf, PATHINFO_FILENAME) . '.jpg';
		$jpg_sf = (dirname($sf) !== '.' ? dirname($sf) . '/' : '') . $jpg_name;
		$jpg_dest = $assets_dir . '/' . $jpg_sf;
		if (is_file($jpg_dest) && $src_mtime <= filemtime($jpg_dest)) {
			// Previously converted to JPG — still do HTML rewrite
			$html = str_replace('assets/' . rawurlencode($sf), 'assets/' . rawurlencode($jpg_sf), $html);
			$html = str_replace('assets\\/' . str_replace('/', '\\/', rawurlencode($sf)), 'assets\\/' . str_replace('/', '\\/', rawurlencode($jpg_sf)), $html);
			$expected_assets[] = $jpg_sf;
			$copied++;
			$cached_count++;
			continue;
		}
	}

	// Cache check: dest exists and source unchanged
	$dest_path = $assets_dir . '/' . $sf;
	if (is_file($dest_path) && $src_mtime <= filemtime($dest_path)) {
		$expected_assets[] = $sf;
		$copied++;
		$cached_count++;
		continue;
	}

	$dest_subdir = dirname($assets_dir . '/' . $sf);
	if (!is_dir($dest_subdir)) {
		mkdir($dest_subdir, 0755, true);
	}

	// Check if this is an image we can optimize
	$is_image = in_array($ext, array('png', 'jpg', 'jpeg', 'bmp'));
	$did_optimize = false;

	if ($is_image && !in_array($sf, $no_optimize) && function_exists('imagecreatefrompng')) {
		$size = @getimagesize($src);
		if ($size && ($size[0] > $MAX_DIMENSION || $size[1] > $MAX_DIMENSION || $ext === 'png' || $ext === 'bmp')) {
			$orig_w = $size[0];
			$orig_h = $size[1];
			$orig_size = filesize($src);

			// Load image
			$img = null;
			if ($ext === 'png') $img = @imagecreatefrompng($src);
			elseif ($ext === 'jpg' || $ext === 'jpeg') $img = @imagecreatefromjpeg($src);
			elseif ($ext === 'bmp') $img = @imagecreatefrombmp($src);

			if ($img) {
				// Skip optimization for JPEGs with EXIF orientation
				// (browser handles EXIF natively, GD strips it on re-encode)
				if (($ext === 'jpg' || $ext === 'jpeg') && function_exists('exif_read_data')) {
					$exif = @exif_read_data($src);
					if ($exif && isset($exif['Orientation']) && $exif['Orientation'] != 1) {
						imagedestroy($img);
						copy($src, $assets_dir . '/' . $sf);
						$expected_assets[] = $sf;
						$copied++;
						continue;
					}
				}

				// Resize if needed
				$new_w = $orig_w;
				$new_h = $orig_h;
				if ($orig_w > $MAX_DIMENSION || $orig_h > $MAX_DIMENSION) {
					$ratio = min($MAX_DIMENSION / $orig_w, $MAX_DIMENSION / $orig_h);
					$new_w = (int)round($orig_w * $ratio);
					$new_h = (int)round($orig_h * $ratio);
					$resized = imagecreatetruecolor($new_w, $new_h);
					// Preserve alpha for PNG
					if ($ext === 'png') {
						imagealphablending($resized, false);
						imagesavealpha($resized, true);
					}
					imagecopyresampled($resized, $img, 0, 0, 0, 0, $new_w, $new_h, $orig_w, $orig_h);
					imagedestroy($img);
					$img = $resized;
				}

				// Check if PNG has transparency
				$has_alpha = false;
				if ($ext === 'png') {
					// Quick scan: check if any pixel has alpha < 255
					$check_step = max(1, (int)($new_w * $new_h / 10000)); // sample ~10k pixels
					$pixel_count = $new_w * $new_h;
					for ($i = 0; $i < $pixel_count; $i += $check_step) {
						$px = $i % $new_w;
						$py = (int)($i / $new_w);
						$rgba = imagecolorat($img, $px, $py);
						$alpha = ($rgba >> 24) & 0x7F;
						if ($alpha > 0) { $has_alpha = true; break; }
					}
				}

				// Save: convert to JPEG if no transparency needed
				if ($ext === 'png' && !$has_alpha) {
					// Convert to JPEG — but keep PNG if it's smaller
					$new_filename = pathinfo($sf, PATHINFO_FILENAME) . '.jpg';
					$new_sf = (dirname($sf) !== '.' ? dirname($sf) . '/' : '') . $new_filename;
					imagejpeg($img, $assets_dir . '/' . $new_sf, $JPEG_QUALITY);
					// Also save as PNG to compare
					imagesavealpha($img, true);
					imagepng($img, $assets_dir . '/' . $sf, 6);
					$jpg_size = filesize($assets_dir . '/' . $new_sf);
					$png_size = filesize($assets_dir . '/' . $sf);
					if ($png_size <= $jpg_size) {
						// PNG is smaller — keep it, delete the JPEG
						@unlink($assets_dir . '/' . $new_sf);
						$expected_assets[] = $sf;
						$new_size = $png_size;
						if ($new_w !== $orig_w || $new_size < $orig_size) {
							fprintf(STDERR, "  Resized:   %s (%dx%d, %s) → (%dx%d, %s) [kept PNG]\n",
								$sf, $orig_w, $orig_h, format_bytes($orig_size),
								$new_w, $new_h, format_bytes($new_size));
						}
						$did_optimize = true;
						imagedestroy($img);
						$optimized++;
						$copied++;
						continue;
					}
					// JPEG is smaller — use it, remove PNG
					@unlink($assets_dir . '/' . $sf);
					$expected_assets[] = $new_sf;
					// Update HTML references (plain and JSON-escaped for data attributes)
					$html = str_replace('assets/' . rawurlencode($sf), 'assets/' . rawurlencode($new_sf), $html);
					$html = str_replace('assets\\/' . str_replace('/', '\\/', rawurlencode($sf)), 'assets\\/' . str_replace('/', '\\/', rawurlencode($new_sf)), $html);
					$new_size = filesize($assets_dir . '/' . $new_sf);
					fprintf(STDERR, "  Optimized: %s (%dx%d, %s) → %s (%dx%d, %s)\n",
						$sf, $orig_w, $orig_h, format_bytes($orig_size),
						$new_sf, $new_w, $new_h, format_bytes($new_size));
					$did_optimize = true;
				} elseif ($ext === 'png' && $has_alpha) {
					// Keep as PNG but resized
					imagesavealpha($img, true);
					imagepng($img, $assets_dir . '/' . $sf, 6);
					$expected_assets[] = $sf;
					$new_size = filesize($assets_dir . '/' . $sf);
					if ($new_w !== $orig_w || $new_size < $orig_size) {
						fprintf(STDERR, "  Resized:   %s (%dx%d, %s) → (%dx%d, %s)\n",
							$sf, $orig_w, $orig_h, format_bytes($orig_size),
							$new_w, $new_h, format_bytes($new_size));
					}
					$did_optimize = true;
				} elseif ($orig_w > $MAX_DIMENSION || $orig_h > $MAX_DIMENSION) {
					// JPG/BMP that was resized
					$out_file = $sf;
					if ($ext === 'bmp') {
						$out_file = pathinfo($sf, PATHINFO_FILENAME) . '.jpg';
						$out_sf = (dirname($sf) !== '.' ? dirname($sf) . '/' : '') . $out_file;
						imagejpeg($img, $assets_dir . '/' . $out_sf, $JPEG_QUALITY);
						$expected_assets[] = $out_sf;
						$html = str_replace('assets/' . rawurlencode($sf), 'assets/' . rawurlencode($out_sf), $html);
						$html = str_replace('assets\\/' . str_replace('/', '\\/', rawurlencode($sf)), 'assets\\/' . str_replace('/', '\\/', rawurlencode($out_sf)), $html);
					} else {
						imagejpeg($img, $assets_dir . '/' . $sf, $JPEG_QUALITY);
						$expected_assets[] = $sf;
					}
					$new_size = filesize($assets_dir . '/' . ($ext === 'bmp' ? $out_sf : $sf));
					fprintf(STDERR, "  Resized:   %s (%dx%d, %s) → (%dx%d, %s)\n",
						$sf, $orig_w, $orig_h, format_bytes($orig_size),
						$new_w, $new_h, format_bytes($new_size));
					$did_optimize = true;
				}

				imagedestroy($img);
				if ($did_optimize) {
					$optimized++;
					$copied++;
					continue;
				}
			}
		}
	}

	// No optimization needed/possible — copy as-is
	copy($src, $assets_dir . '/' . $sf);
	$expected_assets[] = $sf;
	$copied++;
}

// Clean up stale files from previous publishes
$stale_removed = 0;
$existing_assets = @scandir($assets_dir);
if ($existing_assets) {
	foreach ($existing_assets as $ef) {
		if ($ef === '.' || $ef === '..') continue;
		if (!in_array($ef, $expected_assets)) {
			@unlink($assets_dir . '/' . $ef);
			$stale_removed++;
		}
	}
}
// Count how many shared files were skipped
if (is_dir($shared_dir)) {
	$all_shared = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($shared_dir, RecursiveDirectoryIterator::FOLLOW_SYMLINKS | RecursiveDirectoryIterator::SKIP_DOTS)
	);
	foreach ($all_shared as $item) {
		if ($item->isFile()) {
			$rel = substr($item->getPathname(), strlen($shared_dir) + 1);
			if (!in_array($rel, $shared_files)) {
				$skipped++;
			}
		}
	}
}

// --- Step 5b: Deduplicate assets (same content, different filenames) ---
$deduplicated = 0;
$hash_to_file = array();
$assets_files = @scandir($assets_dir);
if ($assets_files) {
	foreach ($assets_files as $af) {
		if ($af === '.' || $af === '..') continue;
		$af_path = $assets_dir . '/' . $af;
		if (!is_file($af_path)) continue;
		$hash = md5_file($af_path);
		if (isset($hash_to_file[$hash])) {
			// Duplicate — rewrite HTML references to use the first copy, delete this one
			$canonical = $hash_to_file[$hash];
			$html = str_replace('assets/' . rawurlencode($af), 'assets/' . rawurlencode($canonical), $html);
			$html = str_replace('assets\\/' . str_replace('/', '\\/', rawurlencode($af)), 'assets\\/' . str_replace('/', '\\/', rawurlencode($canonical)), $html);
			@unlink($af_path);
			$deduplicated++;
			fprintf(STDERR, "  Dedup:     %s → %s\n", $af, $canonical);
		} else {
			$hash_to_file[$hash] = $af;
		}
	}
}

// --- Step 6: Write output and report ---

file_put_contents($target_dir . '/index.html', $html);
$html_size = strlen($html);

echo "\nDone!\n";
echo sprintf("  index.html: %s\n", format_bytes($html_size));
echo sprintf("  assets/: %d files\n", $copied);
if ($cached_count > 0) {
	echo sprintf("  Cached: %d unchanged, %d updated\n", $cached_count, $copied - $cached_count);
}
if ($optimized > 0) {
	echo sprintf("  Images optimized: %d (max %dpx, JPEG quality %d)\n", $optimized, $MAX_DIMENSION, $JPEG_QUALITY);
}
if ($stale_removed > 0) {
	echo sprintf("  Removed %d stale file(s) from previous publish\n", $stale_removed);
}
if ($skipped > 0) {
	echo sprintf("  Skipped %d unused shared file(s)\n", $skipped);
}
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
