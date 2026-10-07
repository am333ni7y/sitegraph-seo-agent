<?php
/**
 * Page-level search performance (clicks, impressions, CTR, position) imported
 * from a Google Search Console export or pushed by an agent.
 *
 * @package SiteGraph
 */

namespace SiteGraph;

use WP_Error;

defined( 'ABSPATH' ) || exit;

class Search_Data {

	/**
	 * Stores search rows, matching each URL to a post by its path. Matching by
	 * path means a production export also works on a staging copy of the site.
	 *
	 * @param array  $rows   Each row: url, clicks, impressions, ctr, position.
	 * @param string $mode   'replace' clears existing data first; 'merge' upserts.
	 * @param string $period Free-text label such as "Last 3 months".
	 */
	public static function import( array $rows, $mode = 'replace', $period = '' ) {
		global $wpdb;
		$table = Installer::table( 'search' );

		if ( 'replace' === $mode ) {
			$wpdb->query( "DELETE FROM $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$map       = Link_Index::path_map();
		$matched   = 0;
		$unmatched = array();
		$now       = current_time( 'mysql', true );

		foreach ( $rows as $row ) {
			$url  = isset( $row['url'] ) ? trim( (string) $row['url'] ) : '';
			$path = Link_Index::normalize_path( (string) wp_parse_url( $url, PHP_URL_PATH ) );
			$id   = ( isset( $map[ $path ] ) && is_int( $map[ $path ] ) ) ? $map[ $path ] : 0;
			if ( ! $id ) {
				if ( count( $unmatched ) < 25 ) {
					$unmatched[] = $url;
				}
				continue;
			}
			$raw_ctr = isset( $row['ctr'] ) ? $row['ctr'] : 0;
			$ctr     = self::parse_number( $raw_ctr );
			if ( ( is_string( $raw_ctr ) && false !== strpos( $raw_ctr, '%' ) ) || $ctr > 1 ) {
				$ctr = $ctr / 100; // "4.2%" (CSV export) means 4.2 percent; 0.042 (API) is already a fraction.
			}
			$impressions = (int) self::parse_number( isset( $row['impressions'] ) ? $row['impressions'] : 0 );
			$clicks      = (int) self::parse_number( isset( $row['clicks'] ) ? $row['clicks'] : 0 );
			if ( ! isset( $row['ctr'] ) && $impressions > 0 ) {
				$ctr = $clicks / $impressions;
			}
			$wpdb->replace(
				$table,
				array(
					'post_id'     => $id,
					'url'         => $url,
					'clicks'      => $clicks,
					'impressions' => $impressions,
					'ctr'         => $ctr,
					'position'    => self::parse_number( isset( $row['position'] ) ? $row['position'] : 0 ),
					'period'      => mb_substr( (string) $period, 0, 64 ),
					'imported_at' => $now,
				),
				array( '%d', '%s', '%d', '%d', '%f', '%f', '%s', '%s' )
			);
			$matched++;
		}

		update_option(
			'sitegraph_search_meta',
			array(
				'period'      => $period,
				'imported_at' => time(),
				'rows'        => self::count(),
			),
			false
		);

		if ( Link_Index::has_scanned() ) {
			Page_Analyzer::score_all();
		}

		return array(
			'matched'         => $matched,
			'unmatched_count' => count( $rows ) - $matched,
			'unmatched'       => $unmatched,
			'stored_rows'     => self::count(),
		);
	}

	/**
	 * Parses the "Pages" CSV from Search Console (Performance > Export), or any CSV
	 * with url/page, clicks, impressions, ctr and position columns.
	 *
	 * @return array|WP_Error
	 */
	public static function parse_csv( $contents ) {
		$contents = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $contents );
		$lines    = preg_split( '/\r\n|\n|\r/', trim( $contents ) );
		if ( count( $lines ) < 2 ) {
			return new WP_Error( 'sitegraph_csv_empty', 'The CSV file has no data rows.' );
		}

		$header  = array_map( 'strtolower', array_map( 'trim', str_getcsv( array_shift( $lines ) ) ) );
		$columns = array();
		foreach ( $header as $index => $name ) {
			if ( preg_match( '/^(top pages|pages?|url|address|landing page)$/', $name ) ) {
				$columns['url'] = $index;
			} elseif ( 'clicks' === $name ) {
				$columns['clicks'] = $index;
			} elseif ( 'impressions' === $name ) {
				$columns['impressions'] = $index;
			} elseif ( in_array( $name, array( 'ctr', 'url ctr' ), true ) ) {
				$columns['ctr'] = $index;
			} elseif ( in_array( $name, array( 'position', 'avg. position', 'average position' ), true ) ) {
				$columns['position'] = $index;
			}
		}
		if ( ! isset( $columns['url'], $columns['impressions'] ) ) {
			return new WP_Error( 'sitegraph_csv_columns', 'Could not find the page URL and Impressions columns. Export the "Pages" tab from Search Console > Performance.' );
		}

		$rows = array();
		foreach ( $lines as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$cells = str_getcsv( $line );
			$row   = array();
			foreach ( $columns as $key => $index ) {
				$value = isset( $cells[ $index ] ) ? trim( $cells[ $index ] ) : '';
				if ( 'url' === $key ) {
					$row[ $key ] = $value;
				} elseif ( 'ctr' === $key ) {
					// Search Console exports "4.2%"; store the fraction 0.042.
					$number      = self::parse_number( $value );
					$row[ $key ] = ( false !== strpos( $value, '%' ) || $number > 1 ) ? $number / 100 : $number;
				} else {
					$row[ $key ] = self::parse_number( $value );
				}
			}
			$rows[] = $row;
		}
		return $rows;
	}

	private static function parse_number( $value ) {
		if ( is_int( $value ) || is_float( $value ) ) {
			return $value;
		}
		$value = str_replace( array( ',', '%', ' ' ), '', (string) $value );
		return is_numeric( $value ) ? (float) $value : 0;
	}

	public static function count() {
		global $wpdb;
		$table = Installer::table( 'search' );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function has_data() {
		return self::count() > 0;
	}

	public static function meta() {
		$meta = get_option( 'sitegraph_search_meta' );
		return is_array( $meta ) ? $meta : null;
	}

	/**
	 * @return array<int, array{clicks:int, impressions:int, ctr:float, position:float}>
	 */
	public static function all() {
		global $wpdb;
		$table = Installer::table( 'search' );
		$out   = array();
		foreach ( $wpdb->get_results( "SELECT post_id, clicks, impressions, ctr, position FROM $table" ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$out[ (int) $row->post_id ] = self::format_row( $row );
		}
		return $out;
	}

	public static function get( $post_id ) {
		global $wpdb;
		$table = Installer::table( 'search' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT clicks, impressions, ctr, position FROM $table WHERE post_id = %d", $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::format_row( $row ) : null;
	}

	private static function format_row( $row ) {
		return array(
			'clicks'      => (int) $row->clicks,
			'impressions' => (int) $row->impressions,
			'ctr'         => round( (float) $row->ctr, 4 ),
			'position'    => round( (float) $row->position, 1 ),
		);
	}
}
