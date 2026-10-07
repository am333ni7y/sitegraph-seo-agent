<?php
/**
 * Turns page issues into a short, prioritized list of tasks: what to do, why,
 * on which pages, how long it takes, and whether an agent can do it for you.
 *
 * @package SiteGraph
 */

namespace SiteGraph;

defined( 'ABSPATH' ) || exit;

class Action_Plan {

	const IMPACT_WEIGHT = array(
		'high'   => 3,
		'medium' => 2,
		'low'    => 1,
	);

	/**
	 * Task definitions, keyed by task ID. Each task groups pages with one or more issue codes.
	 */
	private static function definitions() {
		return array(
			'link_orphans'          => array(
				'codes'      => array( 'orphan', 'unreachable' ),
				'title'      => 'Link to %d orphan page(s)',
				'why'        => 'Search engines and readers can only find these pages through the sitemap or archives. A few contextual links from related pages pass authority and help them rank.',
				'impact'     => 'high',
				'minutes'    => 3,
				'automation' => 'agent',
				'steps'      => 'suggest-internal-links for each page → propose-changeset with the best add_internal_link operations → review the diff → apply-changeset.',
				'prompt'     => 'Find the orphan pages on my site and propose internal links to them from related pages. Show me the changes before applying anything.',
			),
			'fix_broken_links'      => array(
				'codes'      => array( 'broken_links' ),
				'title'      => 'Fix broken internal links on %d page(s)',
				'why'        => 'Broken links waste crawl budget, leak link equity and frustrate readers.',
				'impact'     => 'high',
				'minutes'    => 2,
				'automation' => 'agent',
				'steps'      => 'get-page-report to see each broken URL → choose the live page it should point to → propose-changeset with replace_text → apply-changeset.',
				'prompt'     => 'Fix the broken internal links on my site. For each one, suggest the live page it should point to and show me the changes first.',
			),
			'rewrite_snippets'      => array(
				'codes'      => array( 'low_ctr' ),
				'title'      => 'Rewrite search snippets for %d page(s) with low CTR',
				'why'        => 'These pages already rank on page one but get far fewer clicks than results in the same position. A better title and description is the fastest traffic win.',
				'impact'     => 'high',
				'minutes'    => 4,
				'automation' => 'agent',
				'steps'      => 'get-page-report for search data → draft a new SEO title (≤ 60 chars) and meta description (140–155 chars) → propose-changeset with set_seo_title and set_meta_description → apply-changeset.',
				'prompt'     => 'Rewrite the SEO titles and meta descriptions of my low-CTR pages. Give me two options for each and apply the ones I pick.',
			),
			'push_striking_distance' => array(
				'codes'      => array( 'striking_distance' ),
				'title'      => 'Push %d page(s) from page 2 into the top 10',
				'why'        => 'Pages ranking at positions 8–20 are close to page one. Extra internal links with descriptive anchors are often enough to move them up.',
				'impact'     => 'high',
				'minutes'    => 5,
				'automation' => 'agent',
				'steps'      => 'suggest-internal-links for each page → propose-changeset with 2–3 links from strong pages → apply-changeset → re-import Search Console data in 2–4 weeks to measure.',
				'prompt'     => 'Help my striking-distance pages reach page one: add internal links from my strongest related pages and tell me what else to improve.',
			),
			'resolve_cannibalization' => array(
				'codes'      => array( 'cannibalization' ),
				'title'      => 'Resolve %d page(s) competing for the same topic',
				'why'        => 'When two pages target the same query, they split clicks and links and often both rank lower.',
				'impact'     => 'medium',
				'minutes'    => 15,
				'automation' => 'decision',
				'steps'      => 'Compare the pages (get-page-report for both) → decide to differentiate or merge → if merging, keep the stronger page and redirect the weaker one.',
				'prompt'     => 'Compare the pages on my site that compete for the same topic and recommend whether to merge or differentiate each pair.',
			),
			'strengthen_links'      => array(
				'codes'      => array( 'weak_inbound', 'deep' ),
				'title'      => 'Strengthen internal links to %d page(s)',
				'why'        => 'These pages have a single internal link or sit four or more clicks from the home page, so they receive little authority.',
				'impact'     => 'medium',
				'minutes'    => 3,
				'automation' => 'agent',
				'steps'      => 'suggest-internal-links → propose-changeset → apply-changeset.',
				'prompt'     => 'Add internal links to my pages that are buried deep or only linked once.',
			),
			'expand_thin_content'   => array(
				'codes'      => array( 'thin' ),
				'title'      => 'Expand or merge %d thin page(s)',
				'why'        => 'Very short pages rarely satisfy the query and can drag down how search engines judge the site.',
				'impact'     => 'medium',
				'minutes'    => 30,
				'automation' => 'assisted',
				'steps'      => 'Decide per page: expand, merge into a related page, or noindex. An agent can draft the new sections for your review.',
				'prompt'     => 'Review my thin pages and tell me which to expand, merge or remove. Draft an outline for the ones worth expanding.',
			),
			'add_meta_descriptions' => array(
				'codes'      => array( 'no_meta_description' ),
				'title'      => 'Write meta descriptions for %d page(s)',
				'why'        => 'Without a description, search engines pick a random snippet, which usually converts worse.',
				'impact'     => 'low',
				'minutes'    => 1,
				'automation' => 'agent',
				'steps'      => 'Read each page → propose-changeset with set_meta_description → apply-changeset.',
				'prompt'     => 'Write meta descriptions for my pages that are missing one, then show me the changeset before applying.',
			),
			'connect_dead_ends'     => array(
				'codes'      => array( 'dead_end' ),
				'title'      => 'Add onward links to %d dead-end page(s)',
				'why'        => 'Pages that link nowhere end the visit and stop authority from flowing to related pages.',
				'impact'     => 'low',
				'minutes'    => 2,
				'automation' => 'agent',
				'steps'      => 'For each dead end, pick 2–3 related pages and link to them with add_internal_link or replace_text.',
				'prompt'     => 'Add links from my dead-end pages to related articles.',
			),
			'refresh_stale'         => array(
				'codes'      => array( 'stale', 'no_search_visibility' ),
				'title'      => 'Refresh or retire %d stale or invisible page(s)',
				'why'        => 'Old content with no search visibility either needs an update or should be consolidated.',
				'impact'     => 'low',
				'minutes'    => 20,
				'automation' => 'assisted',
				'steps'      => 'Review each page; update facts and dates, or merge it into a stronger page.',
				'prompt'     => 'Which of my stale or invisible pages are worth refreshing, and what should change in each?',
			),
		);
	}

	/**
	 * @param int $limit Maximum number of tasks.
	 */
	public static function build( $limit = 8 ) {
		Link_Index::ensure_fresh();
		$all    = Page_Analyzer::list_pages(
			array(
				'max_score' => 100,
				'limit'     => 5000,
			)
		);
		$search = Search_Data::has_data();
		$tasks  = array();

		foreach ( self::definitions() as $id => $def ) {
			$pages = array();
			$reach = 0;
			foreach ( $all['pages'] as $page ) {
				$codes = wp_list_pluck( $page['issues'], 'code' );
				$hit   = array_values( array_intersect( $def['codes'], $codes ) );
				if ( empty( $hit ) ) {
					continue;
				}
				$impressions = $page['search'] ? $page['search']['impressions'] : 0;
				$reach      += $impressions;
				$details     = array();
				foreach ( $page['issues'] as $issue ) {
					if ( in_array( $issue['code'], $def['codes'], true ) ) {
						$details[] = $issue['detail'];
					}
				}
				$pages[] = array(
					'post_id'     => $page['post_id'],
					'title'       => $page['title'],
					'url'         => $page['url'],
					'score'       => $page['score'],
					'impressions' => $impressions,
					'detail'      => implode( ' ', $details ),
				);
			}
			if ( empty( $pages ) ) {
				continue;
			}

			usort(
				$pages,
				function ( $a, $b ) {
					return ( $b['impressions'] - $a['impressions'] ) ?: ( $a['score'] - $b['score'] );
				}
			);

			$count    = count( $pages );
			$minutes  = $def['minutes'] * $count;
			$weight   = self::IMPACT_WEIGHT[ $def['impact'] ];
			$priority = $weight * 100 * ( 1 + log10( 1 + $reach ) ) * ( 1 + log10( $count ) ) / ( 1 + log10( 1 + $minutes ) );

			$tasks[] = array(
				'id'                => $id,
				'title'             => sprintf( $def['title'], $count ),
				'why'               => $def['why'],
				'impact'            => $def['impact'],
				'effort_minutes'    => $minutes,
				'automation'        => $def['automation'],
				'pages_affected'    => $count,
				'impressions_at_stake' => $search ? $reach : null,
				'pages'             => array_slice( $pages, 0, 10 ),
				'how'               => $def['steps'],
				'suggested_prompt'  => $def['prompt'],
				'priority'          => (int) round( $priority ),
			);
		}

		usort(
			$tasks,
			function ( $a, $b ) {
				return $b['priority'] - $a['priority'];
			}
		);
		foreach ( $tasks as $index => &$task ) {
			$task['rank'] = $index + 1;
		}
		unset( $task );

		return array(
			'generated_at'   => gmdate( 'c' ),
			'has_search_data' => $search,
			'note'           => $search ? null : 'Import Search Console data (import-search-data) to rank tasks by real search demand and unlock CTR and striking-distance checks.',
			'tasks'          => array_slice( $tasks, 0, max( 1, (int) $limit ) ),
		);
	}
}
