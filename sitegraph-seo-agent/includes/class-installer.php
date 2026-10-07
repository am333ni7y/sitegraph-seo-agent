<?php
/**
 * Database tables and upgrades.
 *
 * @package SiteGraph
 */

namespace SiteGraph;

defined( 'ABSPATH' ) || exit;

class Installer {

	/**
	 * Returns the full table name for one of the plugin tables.
	 *
	 * @param string $name One of: links, pages, search, changesets.
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'sitegraph_' . $name;
	}

	public static function activate() {
		self::create_tables();
	}

	public static function maybe_upgrade() {
		if ( get_option( 'sitegraph_db_version' ) !== SITEGRAPH_DB_VERSION ) {
			self::create_tables();
		}
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$links   = self::table( 'links' );
		$pages   = self::table( 'pages' );
		$search  = self::table( 'search' );
		$changes = self::table( 'changesets' );

		dbDelta(
			"CREATE TABLE $links (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				source_id bigint(20) unsigned NOT NULL,
				target_id bigint(20) unsigned NOT NULL DEFAULT 0,
				target_url text NOT NULL,
				anchor varchar(255) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'ok',
				PRIMARY KEY  (id),
				KEY source_id (source_id),
				KEY target_id (target_id)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE $pages (
				post_id bigint(20) unsigned NOT NULL,
				post_type varchar(20) NOT NULL DEFAULT '',
				url text NOT NULL,
				title text NOT NULL,
				word_count int(11) NOT NULL DEFAULT 0,
				inbound int(11) NOT NULL DEFAULT 0,
				outbound int(11) NOT NULL DEFAULT 0,
				broken_out int(11) NOT NULL DEFAULT 0,
				depth int(11) DEFAULT NULL,
				has_meta_desc tinyint(1) NOT NULL DEFAULT 0,
				modified_gmt datetime DEFAULT NULL,
				published_gmt datetime DEFAULT NULL,
				score int(11) NOT NULL DEFAULT 100,
				issues longtext,
				scanned_at datetime DEFAULT NULL,
				PRIMARY KEY  (post_id),
				KEY score (score)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE $search (
				post_id bigint(20) unsigned NOT NULL,
				url text NOT NULL,
				clicks int(11) NOT NULL DEFAULT 0,
				impressions int(11) NOT NULL DEFAULT 0,
				ctr float NOT NULL DEFAULT 0,
				position float NOT NULL DEFAULT 0,
				period varchar(64) NOT NULL DEFAULT '',
				imported_at datetime DEFAULT NULL,
				PRIMARY KEY  (post_id)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE $changes (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				title varchar(255) NOT NULL DEFAULT '',
				rationale text,
				status varchar(20) NOT NULL DEFAULT 'proposed',
				operations longtext,
				fields longtext,
				baseline longtext,
				history longtext,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime DEFAULT NULL,
				updated_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY status (status)
			) $charset;"
		);

		update_option( 'sitegraph_db_version', SITEGRAPH_DB_VERSION );
	}
}
