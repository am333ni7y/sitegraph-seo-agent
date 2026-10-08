<?php
/**
 * Registers SiteGraph's tools with the WordPress Abilities API and exposes them
 * as a dedicated MCP server through the official MCP Adapter.
 *
 * Endpoint: /wp-json/sitegraph/v1/mcp
 *
 * @package SiteGraph
 */

namespace SiteGraph;

use WP_Error;

defined( 'ABSPATH' ) || exit;

// SiteGraph keeps its link index, page scores, search data and changesets in its own
// tables. They change on every scan or edit, so they are queried directly, not cached.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class Abilities {

	const CATEGORY = 'sitegraph-seo';

	/**
	 * Capability needed to read reports and propose changes. Editors and admins have it.
	 */
	public static function capability() {
		return apply_filters( 'sitegraph_capability', 'edit_others_posts' );
	}

	public static function can_use() {
		return current_user_can( self::capability() );
	}

	public static function register_category() {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => 'SiteGraph SEO',
				'description' => 'Internal link graph, weak-page analysis, SEO action plans and reversible content changesets.',
			)
		);
	}

	/**
	 * Ability names in the order agents usually need them.
	 *
	 * @return string[]
	 */
	public static function names() {
		return array_map(
			function ( $definition ) {
				return $definition['name'];
			},
			self::definitions()
		);
	}

	public static function register() {
		foreach ( self::definitions() as $definition ) {
			$name = $definition['name'];
			unset( $definition['name'] );
			$annotations = $definition['annotations'];
			unset( $definition['annotations'] );

			$definition['category']            = self::CATEGORY;
			$definition['permission_callback'] = array( __CLASS__, 'can_use' );
			$definition['meta']                = array(
				'show_in_rest' => true,
				'annotations'  => $annotations,
				'mcp'          => array(
					'public' => true,
					'type'   => 'tool',
				),
			);
			wp_register_ability( $name, $definition );
		}
	}

	/**
	 * A dedicated MCP server that lists every SiteGraph tool directly.
	 */
	public static function register_mcp_server( $adapter ) {
		$adapter->create_server(
			'sitegraph-seo-agent',
			'sitegraph/v1',
			'mcp',
			'SiteGraph SEO Agent',
			'Finds weak pages, explains why they are weak, builds a prioritized SEO action plan and applies fixes as reviewable changesets with undo and redo.',
			SITEGRAPH_VERSION,
			array( \WP\MCP\Transport\HttpTransport::class ),
			\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
			\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
			self::names(),
			array(),
			array(),
			array( __CLASS__, 'can_use' )
		);
	}

	private static function int_prop( $description, $minimum = 0, $maximum = null ) {
		$prop = array(
			'type'        => 'integer',
			'description' => $description,
			'minimum'     => $minimum,
		);
		if ( null !== $maximum ) {
			$prop['maximum'] = $maximum;
		}
		return $prop;
	}

	private static function changeset_id_schema( $with_force ) {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'changeset_id' => self::int_prop( 'ID of the changeset.', 1 ),
			),
			'required'   => array( 'changeset_id' ),
		);
		if ( $with_force ) {
			$schema['properties']['force'] = array(
				'type'        => 'boolean',
				'description' => 'Overwrite fields that were edited since this changeset last ran. Only use after the user explicitly confirms.',
			);
		}
		return $schema;
	}

	private static function definitions() {
		$issue_codes = array_keys( Page_Analyzer::ISSUES );

		return array(
			array(
				'name'             => 'sitegraph/scan-site',
				'label'            => 'Scan site',
				'description'      => 'Builds or rebuilds the internal link graph and scores every published page. Reads content directly from the database (no crawling). Large sites are scanned in steps: call again until "done" is true. Run this first, and again after large content changes.',
				'input_schema'     => array(
					'type'       => 'object',
					'default'    => new \stdClass(),
					'properties' => array(
						'restart'             => array(
							'type'        => 'boolean',
							'description' => 'Discard a partial or previous scan and start from scratch.',
						),
						'time_budget_seconds' => self::int_prop( 'How long this call may run before returning (default 20).', 3, 60 ),
					),
				),
				'execute_callback' => function ( $input ) {
					$input = (array) $input;
					return Link_Index::scan(
						isset( $input['time_budget_seconds'] ) ? (int) $input['time_budget_seconds'] : 20,
						! empty( $input['restart'] )
					);
				},
				'annotations'      => array(
					'title'       => 'Scan site',
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
			array(
				'name'             => 'sitegraph/get-site-overview',
				'label'            => 'Get site overview',
				'description'      => 'Site-wide SEO health: pages analyzed, average page score, how many pages are critical/weak/fair/good, orphan pages, broken links, the most common issues, whether Search Console data is loaded, and the five weakest pages.',
				'input_schema'     => array(
					'type'       => 'object',
					'default'    => new \stdClass(),
				),
				'execute_callback' => function () {
					return self::require_scan() ?: Page_Analyzer::overview();
				},
				'annotations'      => self::read_only( 'Get site overview' ),
			),
			array(
				'name'             => 'sitegraph/list-weak-pages',
				'label'            => 'List weak pages',
				'description'      => 'Pages ranked from weakest to strongest (score 0–100), each with the reasons it is weak, the suggested fix, its link and content signals, and search data if imported. Ties are ordered by search impressions so weak pages with real demand come first.',
				'input_schema'     => array(
					'type'       => 'object',
					'default'    => new \stdClass(),
					'properties' => array(
						'max_score' => self::int_prop( 'Only pages scoring at or below this. Default 59 (weak and critical). Use 100 for all pages.', 0, 100 ),
						'issue'     => array(
							'type'        => 'string',
							'enum'        => $issue_codes,
							'description' => 'Only pages with this issue.',
						),
						'post_type' => array(
							'type'        => 'string',
							'description' => 'Only this post type, e.g. "post" or "page".',
						),
						'limit'     => self::int_prop( 'Maximum pages to return (default 20).', 1, 100 ),
						'offset'    => self::int_prop( 'Skip this many pages, for paging.' ),
					),
				),
				'execute_callback' => function ( $input ) {
					$input = (array) $input;
					return self::require_scan() ?: Page_Analyzer::list_pages(
						array(
							'max_score' => isset( $input['max_score'] ) ? (int) $input['max_score'] : 59,
							'issue'     => isset( $input['issue'] ) ? (string) $input['issue'] : '',
							'post_type' => isset( $input['post_type'] ) ? (string) $input['post_type'] : '',
							'limit'     => isset( $input['limit'] ) ? (int) $input['limit'] : 20,
							'offset'    => isset( $input['offset'] ) ? (int) $input['offset'] : 0,
						)
					);
				},
				'annotations'      => self::read_only( 'List weak pages' ),
			),
			array(
				'name'             => 'sitegraph/get-page-report',
				'label'            => 'Get page report',
				'description'      => 'Everything about one page: score, issues with fixes, inbound links (which pages link here and with what anchor), outbound and broken links, click depth, word count and search performance. Pass post_id or url.',
				'input_schema'     => array(
					'type'       => 'object',
					'default'    => new \stdClass(),
					'properties' => array(
						'post_id' => self::int_prop( 'Post ID.', 1 ),
						'url'     => array(
							'type'        => 'string',
							'description' => 'Page URL or path, as an alternative to post_id.',
						),
					),
				),
				'execute_callback' => array( __CLASS__, 'page_report' ),
				'annotations'      => self::read_only( 'Get page report' ),
			),
			array(
				'name'             => 'sitegraph/get-action-plan',
				'label'            => 'Get action plan',
				'description'      => 'A prioritized to-do list for the site. Each task says what to do, why it matters, which pages, the estimated effort, whether an agent can do it (automation: agent, assisted or decision), the tool steps, and a prompt the user can give an agent.',
				'input_schema'     => array(
					'type'       => 'object',
					'default'    => new \stdClass(),
					'properties' => array(
						'limit' => self::int_prop( 'Maximum tasks (default 8).', 1, 20 ),
					),
				),
				'execute_callback' => function ( $input ) {
					$input = (array) $input;
					return self::require_scan() ?: Action_Plan::build( isset( $input['limit'] ) ? (int) $input['limit'] : 8 );
				},
				'annotations'      => self::read_only( 'Get action plan' ),
			),
			array(
				'name'             => 'sitegraph/suggest-internal-links',
				'label'            => 'Suggest internal links',
				'description'      => 'For a target page, finds other pages that already mention its topic in linkable text and returns ready-to-use add_internal_link operations with the exact anchor and surrounding sentence. Strong, related pages are ranked first.',
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'target_post_id' => self::int_prop( 'The page that should receive links.', 1 ),
						'limit'          => self::int_prop( 'Maximum suggestions (default 8).', 1, 30 ),
					),
					'required'   => array( 'target_post_id' ),
				),
				'execute_callback' => function ( $input ) {
					$input = (array) $input;
					if ( 'publish' !== get_post_status( (int) $input['target_post_id'] ) ) {
						return new WP_Error( 'sitegraph_bad_target', 'target_post_id must be a published post.' );
					}
					return self::require_scan() ?: Link_Suggester::suggest( (int) $input['target_post_id'], isset( $input['limit'] ) ? (int) $input['limit'] : 8 );
				},
				'annotations'      => self::read_only( 'Suggest internal links' ),
			),
			array(
				'name'             => 'sitegraph/import-search-data',
				'label'            => 'Import search data',
				'description'      => 'Stores page-level Google Search Console data (clicks, impressions, CTR, position) so scores and the action plan reflect real search demand. Rows are matched to posts by URL path, so data from the live domain also works on staging. Use the Search Console "Pages" report.',
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'rows'   => array(
							'type'        => 'array',
							'description' => 'One row per page.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'url'         => array( 'type' => 'string' ),
									'clicks'      => array( 'type' => array( 'number', 'string' ) ),
									'impressions' => array( 'type' => array( 'number', 'string' ) ),
									'ctr'         => array(
										'type'        => array( 'number', 'string' ),
										'description' => 'A fraction such as 0.042, or a percentage string such as "4.2%".',
									),
									'position'    => array( 'type' => array( 'number', 'string' ) ),
								),
								'required'   => array( 'url', 'impressions' ),
							),
						),
						'mode'   => array(
							'type'        => 'string',
							'enum'        => array( 'replace', 'merge' ),
							'description' => 'replace (default) clears previous data; merge updates matching pages only.',
						),
						'period' => array(
							'type'        => 'string',
							'description' => 'Date range label, e.g. "2026-07-01 to 2026-09-30".',
						),
					),
					'required'   => array( 'rows' ),
				),
				'execute_callback' => function ( $input ) {
					$input = (array) $input;
					return Search_Data::import( (array) $input['rows'], isset( $input['mode'] ) ? $input['mode'] : 'replace', isset( $input['period'] ) ? $input['period'] : '' );
				},
				'annotations'      => array(
					'title'       => 'Import search data',
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
			array(
				'name'             => 'sitegraph/propose-changeset',
				'label'            => 'Propose changeset',
				'description'      => 'Validates a group of content changes against the live content and saves them as a proposal with a before/after preview. Nothing on the site changes yet: show the preview to the user, then call apply-changeset. Operation types: add_internal_link (post_id, target_post_id, anchor_text — the anchor must already appear in the post), replace_text (post_id, find, replace, all), set_post_title, set_seo_title, set_meta_description (post_id, value). SEO title and description are written to the active SEO plugin (Yoast, Rank Math, SEOPress) or SiteGraph\'s own output.',
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'title'      => array(
							'type'        => 'string',
							'description' => 'Short summary, e.g. "Link 4 orphan tent guides".',
						),
						'rationale'  => array(
							'type'        => 'string',
							'description' => 'Why these changes help, in one or two sentences.',
						),
						'operations' => array(
							'type'     => 'array',
							'minItems' => 1,
							'maxItems' => Changesets::MAX_OPERATIONS,
							'items'    => array(
								'type'       => 'object',
								'properties' => array(
									'type'           => array(
										'type' => 'string',
										'enum' => Changesets::OPERATION_TYPES,
									),
									'post_id'        => array(
										'type'        => 'integer',
										'description' => 'The post being edited.',
									),
									'target_post_id' => array(
										'type'        => 'integer',
										'description' => 'add_internal_link: the page to link to.',
									),
									'anchor_text'    => array(
										'type'        => 'string',
										'description' => 'add_internal_link: existing text in the post to turn into the link.',
									),
									'find'           => array(
										'type'        => 'string',
										'description' => 'replace_text: exact HTML/text to find.',
									),
									'replace'        => array(
										'type'        => 'string',
										'description' => 'replace_text: replacement HTML/text.',
									),
									'all'            => array(
										'type'        => 'boolean',
										'description' => 'replace_text: replace every occurrence instead of the first.',
									),
									'value'          => array(
										'type'        => 'string',
										'description' => 'set_post_title / set_seo_title / set_meta_description: the new value.',
									),
								),
								'required'   => array( 'type', 'post_id' ),
							),
						),
					),
					'required'   => array( 'title', 'operations' ),
				),
				'execute_callback' => function ( $input ) {
					$input = (array) $input;
					return Changesets::propose( (string) $input['title'], isset( $input['rationale'] ) ? (string) $input['rationale'] : '', (array) $input['operations'] );
				},
				'annotations'      => array(
					'title'       => 'Propose changeset',
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
			array(
				'name'             => 'sitegraph/apply-changeset',
				'label'            => 'Apply changeset',
				'description'      => 'Applies a proposed changeset to the live site in one step. Refuses if the affected content changed since the proposal (unless force is set). Every applied changeset can be reverted with undo-changeset. Only call after the user has approved the preview.',
				'input_schema'     => self::changeset_id_schema( true ),
				'execute_callback' => function ( $input ) {
					$input = (array) $input;
					return Changesets::apply( (int) $input['changeset_id'], ! empty( $input['force'] ) );
				},
				'annotations'      => array(
					'title'       => 'Apply changeset',
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
			),
			array(
				'name'             => 'sitegraph/undo-changeset',
				'label'            => 'Undo changeset',
				'description'      => 'Reverts an applied changeset, restoring every field to its exact previous value. Refuses if a field was edited after the changeset was applied (it names the newer changeset), so newer work is never lost silently.',
				'input_schema'     => self::changeset_id_schema( true ),
				'execute_callback' => function ( $input ) {
					$input = (array) $input;
					return Changesets::undo( (int) $input['changeset_id'], ! empty( $input['force'] ) );
				},
				'annotations'      => array(
					'title'       => 'Undo changeset',
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
			),
			array(
				'name'             => 'sitegraph/redo-changeset',
				'label'            => 'Redo changeset',
				'description'      => 'Re-applies a changeset that was undone, with the same conflict protection as undo.',
				'input_schema'     => self::changeset_id_schema( true ),
				'execute_callback' => function ( $input ) {
					$input = (array) $input;
					return Changesets::redo( (int) $input['changeset_id'], ! empty( $input['force'] ) );
				},
				'annotations'      => array(
					'title'       => 'Redo changeset',
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
			),
			array(
				'name'             => 'sitegraph/discard-changeset',
				'label'            => 'Discard changeset',
				'description'      => 'Discards a proposed changeset that should not be applied. Applied changesets cannot be discarded; undo them instead.',
				'input_schema'     => self::changeset_id_schema( false ),
				'execute_callback' => function ( $input ) {
					$input = (array) $input;
					return Changesets::discard( (int) $input['changeset_id'] );
				},
				'annotations'      => array(
					'title'       => 'Discard changeset',
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
			array(
				'name'             => 'sitegraph/list-changesets',
				'label'            => 'List changesets',
				'description'      => 'Recent changesets with their status (proposed, applied, undone, discarded) and the actions available for each. Use it to answer "what did the agent change?".',
				'input_schema'     => array(
					'type'       => 'object',
					'default'    => new \stdClass(),
					'properties' => array(
						'status' => array(
							'type' => 'string',
							'enum' => array( 'proposed', 'applied', 'undone', 'discarded' ),
						),
						'limit'  => self::int_prop( 'Maximum changesets (default 20).', 1, 100 ),
					),
				),
				'execute_callback' => function ( $input ) {
					$input = (array) $input;
					return array(
						'changesets' => Changesets::list_changesets( isset( $input['status'] ) ? $input['status'] : '', isset( $input['limit'] ) ? (int) $input['limit'] : 20 ),
					);
				},
				'annotations'      => self::read_only( 'List changesets' ),
			),
			array(
				'name'             => 'sitegraph/get-changeset',
				'label'            => 'Get changeset',
				'description'      => 'One changeset in full: each operation with a before/after preview, affected pages, history (who applied or undid it and when), available actions, and impact (score and search metrics at apply time versus now).',
				'input_schema'     => self::changeset_id_schema( false ),
				'execute_callback' => function ( $input ) {
					$input = (array) $input;
					return Changesets::get( (int) $input['changeset_id'] );
				},
				'annotations'      => self::read_only( 'Get changeset' ),
			),
		);
	}

	private static function read_only( $title ) {
		return array(
			'title'       => $title,
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * Error for tools that need a scan first, or false when a scan exists.
	 *
	 * @return WP_Error|false
	 */
	private static function require_scan() {
		if ( Link_Index::has_scanned() ) {
			return false;
		}
		return new WP_Error( 'sitegraph_not_scanned', 'The site has not been scanned yet. Call sitegraph/scan-site until it reports done, then try again.' );
	}

	public static function page_report( $input ) {
		global $wpdb;
		$input = (array) $input;
		$error = self::require_scan();
		if ( $error ) {
			return $error;
		}

		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		if ( ! $post_id && ! empty( $input['url'] ) ) {
			$path    = Link_Index::normalize_path( (string) wp_parse_url( (string) $input['url'], PHP_URL_PATH ) );
			$map     = Link_Index::path_map();
			$post_id = ( isset( $map[ $path ] ) && is_int( $map[ $path ] ) ) ? $map[ $path ] : 0;
		}
		$page = $post_id ? Page_Analyzer::get_page( $post_id ) : null;
		if ( ! $page ) {
			return new WP_Error( 'sitegraph_page_not_found', 'No analyzed page matches that post_id or url. It may be unpublished, or the site needs a new scan.' );
		}

		$links = Installer::table( 'links' );
		$inbound = $wpdb->get_results( $wpdb->prepare( "SELECT source_id, anchor FROM %i WHERE target_id = %d AND status = 'ok'", $links, $post_id ) );
		$out     = $wpdb->get_results( $wpdb->prepare( 'SELECT target_id, target_url, anchor, status FROM %i WHERE source_id = %d', $links, $post_id ) );

		$page['inbound_links'] = array_map(
			function ( $row ) {
				return array(
					'from_post_id' => (int) $row->source_id,
					'from_title'   => wp_strip_all_tags( get_the_title( (int) $row->source_id ) ),
					'anchor'       => $row->anchor,
				);
			},
			$inbound
		);
		$page['outbound_links'] = array_map(
			function ( $row ) {
				return array(
					'to_post_id' => (int) $row->target_id ?: null,
					'url'        => $row->target_url,
					'anchor'     => $row->anchor,
					'status'     => $row->status,
				);
			},
			$out
		);
		$page['seo'] = array(
			'plugin'           => Seo_Meta::provider_label(),
			'seo_title'        => Seo_Meta::get( $post_id, 'title' ),
			'meta_description' => Seo_Meta::get_description( $post_id ),
			'focus_keyword'    => Seo_Meta::get_focus_keyword( $post_id ),
		);
		$page['edit_url'] = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
		return $page;
	}
}
