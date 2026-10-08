<?php
/**
 * Scores every page from 0 (weakest) to 100 (strongest) and explains why.
 *
 * @package SiteGraph
 */

namespace SiteGraph;

defined( 'ABSPATH' ) || exit;

// SiteGraph keeps its link index, page scores, search data and changesets in its own
// tables. They change on every scan or edit, so they are queried directly, not cached.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class Page_Analyzer {

	/**
	 * Issue catalog. `penalty` is subtracted from 100; `operation` is the changeset
	 * operation an agent can use to fix it (null means it needs a human decision).
	 */
	const ISSUES = array(
		'orphan'               => array(
			'label'     => 'Orphan page',
			'penalty'   => 30,
			'impact'    => 'high',
			'fix'       => 'Add contextual links to it from related, stronger pages.',
			'operation' => 'add_internal_link',
		),
		'unreachable'          => array(
			'label'     => 'Not reachable by clicking',
			'penalty'   => 15,
			'impact'    => 'high',
			'fix'       => 'No chain of links leads here from the home page. Link to it from a page that is reachable.',
			'operation' => 'add_internal_link',
		),
		'broken_links'         => array(
			'label'     => 'Broken internal links',
			'penalty'   => 10,
			'impact'    => 'high',
			'fix'       => 'Point each broken link at a live page or remove it.',
			'operation' => 'replace_text',
		),
		'low_ctr'              => array(
			'label'     => 'Low click-through rate',
			'penalty'   => 12,
			'impact'    => 'high',
			'fix'       => 'Rewrite the SEO title and meta description so the result wins the click.',
			'operation' => 'set_seo_title',
		),
		'striking_distance'    => array(
			'label'     => 'Striking distance (page 2)',
			'penalty'   => 6,
			'impact'    => 'high',
			'fix'       => 'Add internal links with descriptive anchors and refresh the content to move into the top 10.',
			'operation' => 'add_internal_link',
		),
		'thin'                 => array(
			'label'     => 'Thin content',
			'penalty'   => 20,
			'impact'    => 'medium',
			'fix'       => 'Expand it with what searchers need, or merge it into a stronger page.',
			'operation' => null,
		),
		'no_search_visibility' => array(
			'label'     => 'No search impressions',
			'penalty'   => 15,
			'impact'    => 'medium',
			'fix'       => 'Check that it is indexed, give it a clear target query, link to it, or consolidate it.',
			'operation' => null,
		),
		'cannibalization'      => array(
			'label'     => 'Competes with another page',
			'penalty'   => 10,
			'impact'    => 'medium',
			'fix'       => 'Differentiate the pages, or merge them and redirect the weaker one.',
			'operation' => null,
		),
		'weak_inbound'         => array(
			'label'     => 'Only one internal link',
			'penalty'   => 10,
			'impact'    => 'medium',
			'fix'       => 'Add two or three more contextual links from related pages.',
			'operation' => 'add_internal_link',
		),
		'deep'                 => array(
			'label'     => 'Buried deep',
			'penalty'   => 12,
			'impact'    => 'medium',
			'fix'       => 'Link to it from a hub page so it is at most three clicks from the home page.',
			'operation' => 'add_internal_link',
		),
		'short'                => array(
			'label'     => 'Short content',
			'penalty'   => 8,
			'impact'    => 'low',
			'fix'       => 'Check whether the page fully answers the query; add depth where it does not.',
			'operation' => null,
		),
		'dead_end'             => array(
			'label'     => 'No internal links out',
			'penalty'   => 6,
			'impact'    => 'low',
			'fix'       => 'Link to two or three related pages so readers and crawlers can continue.',
			'operation' => 'add_internal_link',
		),
		'no_meta_description'  => array(
			'label'     => 'Missing meta description',
			'penalty'   => 6,
			'impact'    => 'low',
			'fix'       => 'Write a 140–155 character description that matches the search intent.',
			'operation' => 'set_meta_description',
		),
		'title_length'         => array(
			'label'     => 'Title length',
			'penalty'   => 4,
			'impact'    => 'low',
			'fix'       => 'Keep the SEO title between 30 and 60 characters.',
			'operation' => 'set_seo_title',
		),
		'stale'                => array(
			'label'     => 'Not updated in 18+ months',
			'penalty'   => 8,
			'impact'    => 'low',
			'fix'       => 'Refresh facts, prices, dates and examples.',
			'operation' => null,
		),
	);

	/**
	 * Typical organic CTR by rounded position, used to spot titles that underperform.
	 */
	const EXPECTED_CTR = array( 1 => 0.28, 2 => 0.15, 3 => 0.10, 4 => 0.07, 5 => 0.05, 6 => 0.04, 7 => 0.03, 8 => 0.025, 9 => 0.02, 10 => 0.018 );

	const STOPWORDS = array( 'the', 'and', 'for', 'with', 'your', 'you', 'how', 'what', 'why', 'when', 'are', 'from', 'this', 'that', 'best', 'top', 'guide', 'tips', 'vs', 'into', 'our', 'can', 'all', 'new', 'review', 'reviews', 'complete' );

	public static function grade( $score ) {
		if ( $score < 40 ) {
			return 'critical';
		}
		if ( $score < 60 ) {
			return 'weak';
		}
		if ( $score < 80 ) {
			return 'fair';
		}
		return 'good';
	}

	/**
	 * Pages that are expected to be short or link-light (home, blog index, privacy).
	 *
	 * @return array<int, true>
	 */
	private static function exempt_ids() {
		$ids = array_filter(
			array(
				Link_Index::front_page_id(),
				(int) get_option( 'page_for_posts' ),
				(int) get_option( 'wp_page_for_privacy_policy' ),
			)
		);
		return array_fill_keys( apply_filters( 'sitegraph_exempt_post_ids', $ids ), true );
	}

	public static function score_all() {
		global $wpdb;
		$table = Installer::table( 'pages' );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT post_id, post_type, title, word_count, inbound, outbound, broken_out, depth, has_meta_desc, modified_gmt, published_gmt FROM %i', $table ) );

		$search     = Search_Data::all();
		$has_search = ! empty( $search );
		$exempt     = self::exempt_ids();
		$similar    = self::find_cannibalization( $rows );
		$now        = time();

		foreach ( $rows as $row ) {
			$id        = (int) $row->post_id;
			$is_exempt = isset( $exempt[ $id ] );
			$s         = isset( $search[ $id ] ) ? $search[ $id ] : null;
			$issues    = array();
			$inbound   = (int) $row->inbound;
			$words     = (int) $row->word_count;

			if ( ! $is_exempt ) {
				if ( 0 === $inbound ) {
					$issues[] = self::issue( 'orphan', 'No other page on the site links here.' );
				} elseif ( 1 === $inbound ) {
					$issues[] = self::issue( 'weak_inbound', 'Only one page links here.' );
				}
				if ( null === $row->depth ) {
					$issues[] = self::issue( 'unreachable', 'No path of links or menus leads here from the home page.' );
				} elseif ( (int) $row->depth >= 4 ) {
					$issues[] = self::issue( 'deep', sprintf( '%d clicks from the home page.', (int) $row->depth ) );
				}
				$thin_limit = 'page' === $row->post_type ? 150 : 300;
				if ( $words < $thin_limit ) {
					$issues[] = self::issue( 'thin', sprintf( '%d words.', $words ) );
				} elseif ( 'page' !== $row->post_type && $words < 600 ) {
					$issues[] = self::issue( 'short', sprintf( '%d words.', $words ) );
				}
				if ( 0 === (int) $row->outbound ) {
					$issues[] = self::issue( 'dead_end', 'The content links to no other page on the site.' );
				}
			}

			if ( (int) $row->broken_out > 0 ) {
				$issues[] = self::issue( 'broken_links', sprintf( '%d link(s) point to pages that do not exist.', (int) $row->broken_out ) );
			}
			if ( ! (int) $row->has_meta_desc ) {
				$issues[] = self::issue( 'no_meta_description', 'No meta description is set.' );
			}

			$seo_title = Seo_Meta::get( $id, 'title' );
			$title     = ( '' !== $seo_title && false === strpos( $seo_title, '%%' ) ) ? $seo_title : $row->title;
			$length    = mb_strlen( $title );
			if ( $length > 65 || $length < 20 ) {
				$issues[] = self::issue( 'title_length', sprintf( 'The title is %d characters.', $length ) );
			}

			$modified = $row->modified_gmt ? strtotime( $row->modified_gmt . ' UTC' ) : 0;
			if ( 'post' === $row->post_type && $modified && $now - $modified > 540 * DAY_IN_SECONDS ) {
				$issues[] = self::issue( 'stale', sprintf( 'Last updated %s.', gmdate( 'F Y', $modified ) ) );
			}

			if ( $has_search ) {
				$published = $row->published_gmt ? strtotime( $row->published_gmt . ' UTC' ) : 0;
				if ( $s ) {
					$position = (float) $s['position'];
					$expected = self::expected_ctr( $position );
					// The home page is skipped: brand and navigational queries distort its CTR.
					if ( $s['impressions'] >= 500 && $position <= 10 && $s['ctr'] < $expected * 0.5 && Link_Index::front_page_id() !== $id ) {
						$issues[] = self::issue( 'low_ctr', sprintf( 'CTR %.1f%% at average position %.1f; results there typically get about %.0f%%.', $s['ctr'] * 100, $position, $expected * 100 ) );
					}
					if ( $position >= 8 && $position <= 20 && $s['impressions'] >= 50 ) {
						$issues[] = self::issue( 'striking_distance', sprintf( 'Average position %.1f with %s impressions.', $position, number_format_i18n( $s['impressions'] ) ) );
					}
					if ( 0 === $s['impressions'] && ! $is_exempt ) {
						$issues[] = self::issue( 'no_search_visibility', 'Zero impressions in the imported period.' );
					}
				} elseif ( ! $is_exempt && $published && $now - $published > 90 * DAY_IN_SECONDS ) {
					$issues[] = self::issue( 'no_search_visibility', 'No impressions in the imported Search Console data.' );
				}
			}

			if ( isset( $similar[ $id ] ) ) {
				$other    = $similar[ $id ];
				$issue    = self::issue( 'cannibalization', sprintf( 'Targets the same topic as “%s” (#%d).', $other['title'], $other['id'] ) );
				$issue['related_post_id'] = $other['id'];
				$issues[] = $issue;
			}

			// Most damaging issues first, so short lists show what matters.
			usort(
				$issues,
				function ( $a, $b ) {
					return self::ISSUES[ $b['code'] ]['penalty'] - self::ISSUES[ $a['code'] ]['penalty'];
				}
			);
			$penalty = 0;
			foreach ( $issues as $issue ) {
				$penalty += self::ISSUES[ $issue['code'] ]['penalty'];
			}

			$wpdb->update(
				$table,
				array(
					'score'  => max( 0, 100 - $penalty ),
					'issues' => wp_json_encode( $issues ),
				),
				array( 'post_id' => $id )
			);
		}
	}

	private static function issue( $code, $detail ) {
		$def = self::ISSUES[ $code ];
		return array(
			'code'          => $code,
			'label'         => $def['label'],
			'impact'        => $def['impact'],
			'detail'        => $detail,
			'fix'           => $def['fix'],
			'fix_operation' => $def['operation'],
		);
	}

	public static function expected_ctr( $position ) {
		$rounded = (int) max( 1, round( $position ) );
		return $rounded > 10 ? 0.01 : self::EXPECTED_CTR[ $rounded ];
	}

	/**
	 * Lowercased, de-pluralized title words without stopwords or years.
	 */
	public static function title_tokens( $title ) {
		$words  = preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( $title ), -1, PREG_SPLIT_NO_EMPTY );
		$tokens = array();
		foreach ( $words as $word ) {
			if ( mb_strlen( $word ) < 3 || in_array( $word, self::STOPWORDS, true ) || preg_match( '/^\d{4}$/', $word ) ) {
				continue;
			}
			if ( mb_strlen( $word ) > 4 && 's' === mb_substr( $word, -1 ) && 'ss' !== mb_substr( $word, -2 ) ) {
				$word = mb_substr( $word, 0, -1 );
			}
			$tokens[ $word ] = true;
		}
		return array_keys( $tokens );
	}

	/**
	 * Pairs of posts whose titles (or focus keywords) target the same topic.
	 * Each post maps to its closest competitor.
	 */
	private static function find_cannibalization( $rows ) {
		$candidates = array();
		foreach ( $rows as $row ) {
			if ( 'post' !== $row->post_type ) {
				continue;
			}
			$candidates[] = array(
				'id'      => (int) $row->post_id,
				'title'   => $row->title,
				'tokens'  => self::title_tokens( $row->title ),
				'keyword' => mb_strtolower( Seo_Meta::get_focus_keyword( (int) $row->post_id ) ),
			);
		}
		if ( count( $candidates ) > 3000 ) {
			return array();
		}

		$best  = array();
		$count = count( $candidates );
		for ( $i = 0; $i < $count; $i++ ) {
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$a = $candidates[ $i ];
				$b = $candidates[ $j ];
				if ( '' !== $a['keyword'] && $a['keyword'] === $b['keyword'] ) {
					$similarity = 1.0;
				} else {
					$shared = count( array_intersect( $a['tokens'], $b['tokens'] ) );
					$union  = count( array_unique( array_merge( $a['tokens'], $b['tokens'] ) ) );
					if ( $shared < 2 || 0 === $union ) {
						continue;
					}
					$similarity = $shared / $union;
					// Two shared words is common between a guide and a list on the same
					// subject; only treat it as competition when the titles are near-identical.
					if ( 2 === $shared && $similarity < 0.8 ) {
						continue;
					}
				}
				if ( $similarity < 0.5 ) {
					continue;
				}
				foreach ( array( array( $a, $b ), array( $b, $a ) ) as $pair ) {
					if ( ! isset( $best[ $pair[0]['id'] ] ) || $best[ $pair[0]['id'] ]['similarity'] < $similarity ) {
						$best[ $pair[0]['id'] ] = array(
							'id'         => $pair[1]['id'],
							'title'      => $pair[1]['title'],
							'similarity' => $similarity,
						);
					}
				}
			}
		}
		return $best;
	}

	/**
	 * Formats a stored page row for API and UI output.
	 */
	public static function format( $row, $search = null ) {
		$id     = (int) $row->post_id;
		$score  = (int) $row->score;
		$issues = json_decode( (string) $row->issues, true );
		return array(
			'post_id'   => $id,
			'title'     => $row->title,
			'url'       => $row->url,
			'post_type' => $row->post_type,
			'score'     => $score,
			'grade'     => self::grade( $score ),
			'issues'    => is_array( $issues ) ? $issues : array(),
			'signals'   => array(
				'inbound_links'        => (int) $row->inbound,
				'outbound_links'       => (int) $row->outbound,
				'click_depth'          => null === $row->depth ? null : (int) $row->depth,
				'word_count'           => (int) $row->word_count,
				'broken_links'         => (int) $row->broken_out,
				'has_meta_description' => (bool) $row->has_meta_desc,
				'last_modified'        => $row->modified_gmt,
			),
			'search'    => $search,
		);
	}

	public static function get_page( $post_id ) {
		global $wpdb;
		Link_Index::ensure_fresh();
		$table = Installer::table( 'pages' );
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d', $table, $post_id ) );
		return $row ? self::format( $row, Search_Data::get( $post_id ) ) : null;
	}

	/**
	 * Weak pages, weakest first. Ties are broken by search impressions, so the
	 * weak pages with the most demand come first.
	 */
	public static function list_pages( $args = array() ) {
		global $wpdb;
		Link_Index::ensure_fresh();
		$args = wp_parse_args(
			$args,
			array(
				'max_score' => 59,
				'issue'     => '',
				'post_type' => '',
				'limit'     => 20,
				'offset'    => 0,
			)
		);

		$table  = Installer::table( 'pages' );
		$search = Installer::table( 'search' );
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT p.*, s.clicks, s.impressions, s.ctr, s.position FROM %i p LEFT JOIN %i s ON s.post_id = p.post_id WHERE p.score <= %d ORDER BY p.score ASC, s.impressions DESC, p.post_id ASC', $table, $search, (int) $args['max_score'] ) );

		if ( '' !== $args['post_type'] ) {
			$type = $args['post_type'];
			$rows = array_values(
				array_filter(
					$rows,
					function ( $row ) use ( $type ) {
						return $row->post_type === $type;
					}
				)
			);
		}

		if ( '' !== $args['issue'] ) {
			$code = $args['issue'];
			$rows = array_values(
				array_filter(
					$rows,
					function ( $row ) use ( $code ) {
						return in_array( $code, wp_list_pluck( (array) json_decode( (string) $row->issues, true ), 'code' ), true );
					}
				)
			);
		}
		$total = count( $rows );
		$rows  = array_slice( $rows, max( 0, (int) $args['offset'] ), max( 1, min( 5000, (int) $args['limit'] ) ) );

		$pages = array();
		foreach ( $rows as $row ) {
			$s       = null === $row->impressions ? null : array(
				'clicks'      => (int) $row->clicks,
				'impressions' => (int) $row->impressions,
				'ctr'         => round( (float) $row->ctr, 4 ),
				'position'    => round( (float) $row->position, 1 ),
			);
			$pages[] = self::format( $row, $s );
		}
		return array(
			'total' => $total,
			'pages' => $pages,
		);
	}

	public static function overview() {
		global $wpdb;
		Link_Index::ensure_fresh();
		$table = Installer::table( 'pages' );

		$stats = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS pages, AVG(score) AS avg_score, SUM(CASE WHEN score < 40 THEN 1 ELSE 0 END) AS critical, SUM(CASE WHEN score >= 40 AND score < 60 THEN 1 ELSE 0 END) AS weak, SUM(CASE WHEN score >= 60 AND score < 80 THEN 1 ELSE 0 END) AS fair, SUM(CASE WHEN score >= 80 THEN 1 ELSE 0 END) AS good, SUM(broken_out) AS broken FROM %i', $table ) );
		$rows  = $wpdb->get_col( $wpdb->prepare( 'SELECT issues FROM %i', $table ) );

		$issue_counts = array();
		foreach ( $rows as $json ) {
			foreach ( (array) json_decode( (string) $json, true ) as $issue ) {
				$code                  = $issue['code'];
				$issue_counts[ $code ] = ( isset( $issue_counts[ $code ] ) ? $issue_counts[ $code ] : 0 ) + 1;
			}
		}
		arsort( $issue_counts );

		$issue_summary = array();
		foreach ( $issue_counts as $code => $count ) {
			$issue_summary[] = array(
				'code'   => $code,
				'label'  => self::ISSUES[ $code ]['label'],
				'impact' => self::ISSUES[ $code ]['impact'],
				'pages'  => $count,
			);
		}

		$weakest = self::list_pages(
			array(
				'max_score' => 100,
				'limit'     => 5,
			)
		);

		$last_scan = (int) get_option( Link_Index::LAST_SCAN_OPTION );
		return array(
			'site'                 => get_bloginfo( 'name' ),
			'scanned'              => $last_scan > 0,
			'last_scan'            => $last_scan ? gmdate( 'c', $last_scan ) : null,
			'pages_analyzed'       => (int) $stats->pages,
			'average_score'        => null === $stats->avg_score ? null : (int) round( (float) $stats->avg_score ),
			'grades'               => array(
				'critical' => (int) $stats->critical,
				'weak'     => (int) $stats->weak,
				'fair'     => (int) $stats->fair,
				'good'     => (int) $stats->good,
			),
			'orphan_pages'         => isset( $issue_counts['orphan'] ) ? $issue_counts['orphan'] : 0,
			'broken_links'         => (int) $stats->broken,
			'issues'               => $issue_summary,
			'search_data'          => Search_Data::meta(),
			'seo_plugin'           => Seo_Meta::provider_label(),
			'weakest_pages'        => $weakest['pages'],
		);
	}
}
