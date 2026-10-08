<?php
/**
 * Removes SiteGraph tables and options. Post content and SEO meta written by
 * applied changesets stay as they are.
 *
 * @package SiteGraph
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
foreach ( array( 'links', 'pages', 'search', 'changesets' ) as $sitegraph_table ) {
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'sitegraph_' . $sitegraph_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- removing the plugin's own tables on uninstall.
}
foreach ( array( 'sitegraph_db_version', 'sitegraph_scan_state', 'sitegraph_last_scan', 'sitegraph_needs_refresh', 'sitegraph_search_meta' ) as $sitegraph_option ) {
	delete_option( $sitegraph_option );
}
delete_transient( 'sitegraph_path_map' );
