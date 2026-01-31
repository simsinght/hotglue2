<?php
/**
 * Router script for PHP built-in server
 * This mimics the .htaccess rewrite rules for hotglue
 *
 * Usage: php -S localhost:8080 router.php
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = urldecode($uri);

// Special handling for json endpoint
if (preg_match('#^/json/?$#', $uri)) {
    require 'json.php';
    return true;
}

// Get the real file path
$file = __DIR__ . $uri;

// If the request is for an actual file or directory, serve it directly
if ($uri !== '/' && file_exists($file)) {
    // Block access to specific files
    $basename = basename($file);
    if (in_array($basename, ['COPYING', 'INSTALL', 'README'])) {
        http_response_code(403);
        echo 'Access denied';
        return true;
    }

    // Let PHP's built-in server handle the file
    return false;
}

// Otherwise, rewrite to index.php with the path as query string
// This mimics: RewriteRule ^(.*)$ index.php?$1

// Get the original query string from REQUEST_URI (e.g., "edit" from "/housewarming?edit")
$original_query = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);

// Build the new query string: path + original query params
$path_part = ltrim($uri, '/');
if (!empty($original_query)) {
    $_SERVER['QUERY_STRING'] = $path_part . '/' . $original_query;
} else {
    $_SERVER['QUERY_STRING'] = $path_part;
}

// Parse into $_GET
$_GET = [];
if (!empty($_SERVER['QUERY_STRING'])) {
    parse_str($_SERVER['QUERY_STRING'], $_GET);
}

require 'index.php';
return true;
