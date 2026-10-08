<?php
/**
 * Finds sentences on other pages that already mention a page's topic, so a
 * link can be added in place without writing new copy.
 *
 * @package SiteGraph
 */

namespace SiteGraph;

defined( 'ABSPATH' ) || exit;

// SiteGraph keeps its link index, page scores, search data and changesets in its own
// tables. They change on every scan or edit, so they are queried directly, not cached.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class Link_Suggester {

	const MAX_SOURCES = 600;

	/**
	 * Anchor phrases for a target page, best first: focus keyword, tag names,
	 * then word runs taken from the title.
	 *
	 * @return string[]
	 */
	public static function phrases( $post_id ) {
		$phrases = array();
		$keyword = Seo_Meta::get_focus_keyword( $post_id );
		if ( '' !== $keyword ) {
			$phrases[] = $keyword;
		}
		$tags = wp_get_post_terms( $post_id, 'post_tag', array( 'fields' => 'names' ) );
		foreach ( is_wp_error( $tags ) ? array() : $tags as $tag ) {
			if ( count( preg_split( '/\s+/u', trim( $tag ) ) ) >= 2 ) {
				$phrases[] = $tag;
			}
		}

		$title = wp_strip_all_tags( get_the_title( $post_id ) );
		$title = preg_replace( '/\b(19|20)\d{2}\b/', '', html_entity_decode( $title, ENT_QUOTES, 'UTF-8' ) );
		$words = preg_split( '/[^\p{L}\p{N}\'’-]+/u', $title, -1, PREG_SPLIT_NO_EMPTY );
		$stop  = array_merge( Page_Analyzer::STOPWORDS, array( 'a', 'an', 'of', 'to', 'in', 'on', 'at', 'is', 'it', 'or', 'by', 'do', 'vs' ) );
		$count = count( $words );
		$runs  = array();
		for ( $size = min( 5, $count ); $size >= 2; $size-- ) {
			for ( $start = 0; $start + $size <= $count; $start++ ) {
				$run   = array_slice( $words, $start, $size );
				$first = mb_strtolower( $run[0] );
				$last  = mb_strtolower( $run[ $size - 1 ] );
				if ( in_array( $first, $stop, true ) || in_array( $last, $stop, true ) ) {
					continue;
				}
				$runs[] = implode( ' ', $run );
			}
		}

		// Title runs must contain a word that is distinctive for this page. "sleeping bag"
		// appears in many titles and would make a vague anchor; "temperature ratings" does not.
		$frequency = self::title_word_frequency();
		$limit     = max( 2, (int) ceil( count( $frequency ? $frequency['__pages'] : array() ) * 0.05 ) );
		$runs      = array_values(
			array_filter(
				$runs,
				function ( $run ) use ( $frequency, $limit ) {
					foreach ( Page_Analyzer::title_tokens( $run ) as $token ) {
						$df = isset( $frequency['words'][ $token ] ) ? $frequency['words'][ $token ] : 0;
						if ( $df <= $limit ) {
							return true;
						}
					}
					return false;
				}
			)
		);

		$phrases = array_merge( $phrases, $runs );
		$unique  = array();
		foreach ( $phrases as $phrase ) {
			$key = mb_strtolower( trim( $phrase ) );
			if ( '' !== $key && ! isset( $unique[ $key ] ) ) {
				$unique[ $key ] = trim( $phrase );
			}
		}
		return array_slice( array_values( $unique ), 0, 15 );
	}

	/**
	 * How many page titles contain each (normalized) word.
	 *
	 * @return array{words: array<string, int>, __pages: int[]}
	 */
	private static function title_word_frequency() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		global $wpdb;
		$table = Installer::table( 'pages' );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT post_id, title FROM %i', $table ) );
		$words = array();
		foreach ( $rows as $row ) {
			foreach ( Page_Analyzer::title_tokens( $row->title ) as $token ) {
				$words[ $token ] = ( isset( $words[ $token ] ) ? $words[ $token ] : 0 ) + 1;
			}
		}
		$cache = array(
			'words'   => $words,
			'__pages' => wp_list_pluck( $rows, 'post_id' ),
		);
		return $cache;
	}

	/**
	 * Pages that could link to $target_id, each with the exact anchor text and
	 * the sentence it sits in.
	 */
	public static function suggest( $target_id, $limit = 8 ) {
		global $wpdb;
		Link_Index::ensure_fresh();
		$pages = Installer::table( 'pages' );
		$links = Installer::table( 'links' );

		$phrases = self::phrases( $target_id );
		$already = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT source_id FROM %i WHERE target_id = %d AND status = 'ok'", $links, $target_id ) ) );
		$sources = $wpdb->get_results( $wpdb->prepare( 'SELECT post_id, title, url, inbound, score FROM %i WHERE post_id != %d ORDER BY inbound DESC, score DESC LIMIT %d', $pages, $target_id, self::MAX_SOURCES ) );

		$already     = array_flip( $already );
		$target_cats = wp_get_post_terms( $target_id, 'category', array( 'fields' => 'ids' ) );
		$target_cats = is_wp_error( $target_cats ) ? array() : $target_cats;
		$search      = Search_Data::all();
		$suggestions = array();

		foreach ( $sources as $source ) {
			$source_id = (int) $source->post_id;
			if ( isset( $already[ $source_id ] ) ) {
				continue;
			}
			$content = get_post_field( 'post_content', $source_id, 'raw' );
			foreach ( $phrases as $rank => $phrase ) {
				$found = Content_Editor::find_linkable( $content, $phrase );
				if ( null === $found ) {
					continue;
				}
				$source_cats = wp_get_post_terms( $source_id, 'category', array( 'fields' => 'ids' ) );
				$shared      = is_wp_error( $source_cats ) ? 0 : count( array_intersect( $target_cats, $source_cats ) );
				$impressions = isset( $search[ $source_id ] ) ? $search[ $source_id ]['impressions'] : 0;
				$relevance   = 40 - $rank * 2 + str_word_count( $phrase ) * 6 + ( $shared ? 15 : 0 ) + min( 20, (int) $source->inbound * 2 ) + min( 15, (int) round( log10( 1 + $impressions ) * 4 ) );

				$suggestions[] = array(
					'source_post_id' => $source_id,
					'source_title'   => $source->title,
					'source_url'     => $source->url,
					'source_score'   => (int) $source->score,
					'anchor_text'    => $found['match'],
					'context'        => wp_strip_all_tags( $found['context'] ),
					'same_category'  => $shared > 0,
					'relevance'      => $relevance,
					'operation'      => array(
						'type'           => 'add_internal_link',
						'post_id'        => $source_id,
						'target_post_id' => (int) $target_id,
						'anchor_text'    => $found['match'],
					),
				);
				break;
			}
		}

		usort(
			$suggestions,
			function ( $a, $b ) {
				return $b['relevance'] - $a['relevance'];
			}
		);

		return array(
			'target_post_id' => (int) $target_id,
			'target_title'   => wp_strip_all_tags( get_the_title( $target_id ) ),
			'target_url'     => get_permalink( $target_id ),
			'phrases_tried'  => $phrases,
			'suggestions'    => array_slice( $suggestions, 0, max( 1, (int) $limit ) ),
			'note'           => empty( $suggestions )
				? 'No page mentions this topic in linkable text yet. Propose a replace_text operation that adds one natural sentence with the link to a closely related page, and show it to the user before applying.'
				: 'Each suggestion includes a ready-made add_internal_link operation. Pick the most natural ones (at most one new link per source page) and pass them to propose-changeset.',
		);
	}
}
