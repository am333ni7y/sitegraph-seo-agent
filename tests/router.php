<?php
// Router for `php -S` so pretty permalinks and /wp-json/ work locally.
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$file = __DIR__ . $path;
if ( $path !== '/' && file_exists( $file ) && ! is_dir( $file ) ) {
	return false;
}
if ( is_dir( $file ) && file_exists( rtrim( $file, '/' ) . '/index.php' ) ) {
	$_SERVER['SCRIPT_NAME'] = rtrim( $path, '/' ) . '/index.php';
	require rtrim( $file, '/' ) . '/index.php';
	return;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';
