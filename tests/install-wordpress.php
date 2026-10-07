<?php
/**
 * Installs a throwaway WordPress for tests: runs the installer with a random
 * admin password, enables pretty permalinks, and activates MCP Adapter and
 * SiteGraph SEO Agent. Expects wp-config.php to exist already.
 *
 * Usage: php tests/install-wordpress.php /path/to/wordpress
 *
 * @package SiteGraph
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}
$root = isset( $argv[1] ) ? rtrim( $argv[1], '/\\' ) : '';
if ( ! $root || ! file_exists( $root . '/wp-config.php' ) ) {
	fwrite( STDERR, "Usage: php tests/install-wordpress.php /path/to/wordpress (wp-config.php must exist)\n" );
	exit( 1 );
}

define( 'WP_INSTALLING', true );
$_SERVER['HTTP_HOST']   = 'localhost:8080';
$_SERVER['REQUEST_URI'] = '/';
require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

if ( ! is_blog_installed() ) {
	wp_install( 'SiteGraph test site', 'admin', 'admin@example.test', false, '', wp_generate_password( 24 ) );
}
update_option( 'permalink_structure', '/%postname%/' );

foreach ( array( 'mcp-adapter/mcp-adapter.php', 'sitegraph-seo-agent/sitegraph-seo-agent.php' ) as $plugin ) {
	if ( ! file_exists( WP_PLUGIN_DIR . '/' . $plugin ) ) {
		fwrite( STDERR, "Missing plugin: $plugin\n" );
		exit( 1 );
	}
	$result = activate_plugin( $plugin );
	if ( is_wp_error( $result ) ) {
		fwrite( STDERR, "Could not activate $plugin: " . $result->get_error_message() . "\n" );
		exit( 1 );
	}
}

echo 'WordPress ' . get_bloginfo( 'version' ) . " installed with MCP Adapter and SiteGraph SEO Agent.\n";
