<?php
/**
 * wp-admin screens: overview, weak pages, page report, action plan, link graph,
 * changes (with apply / undo / redo) and agent connection.
 *
 * @package SiteGraph
 */

namespace SiteGraph;

defined( 'ABSPATH' ) || exit;

// SiteGraph keeps its link index, page scores, search data and changesets in its own
// tables. They change on every scan or edit, so they are queried directly, not cached.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class Admin {

	const SLUG = 'sitegraph';

	const TABS = array(
		'overview' => 'Overview',
		'pages'    => 'Weak pages',
		'plan'     => 'Action plan',
		'graph'    => 'Link graph',
		'changes'  => 'Changes',
		'connect'  => 'Connect an agent',
	);

	const OPERATION_LABELS = array(
		'add_internal_link'    => 'New link',
		'replace_text'         => 'Text edit',
		'set_post_title'       => 'Post title',
		'set_seo_title'        => 'SEO title',
		'set_meta_description' => 'Meta description',
	);

	const AUTOMATION_LABELS = array(
		'agent'    => 'Agent can do it',
		'assisted' => 'Agent drafts, you decide',
		'decision' => 'Needs your decision',
	);

	const WP_NEEDS_URL = 'https://wp-needs.com/';
	const AUTHOR_URL   = 'https://aminzahed.ir/';
	const DOCS_URL     = 'https://github.com/am333ni7y/sitegraph-seo-agent#readme';
	const SUPPORT_URL  = 'https://github.com/am333ni7y/sitegraph-seo-agent/issues';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SITEGRAPH_FILE ), array( __CLASS__, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
		foreach ( array( 'scan', 'import', 'changeset', 'connect', 'propose_links' ) as $action ) {
			add_action( 'admin_post_sitegraph_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
	}

	public static function menu() {
		add_menu_page( 'SiteGraph SEO', 'SiteGraph SEO', Abilities::capability(), self::SLUG, array( __CLASS__, 'render' ), 'dashicons-networking', 58 );
	}

	public static function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG === $hook ) {
			wp_enqueue_style( 'sitegraph-admin', SITEGRAPH_URL . 'assets/admin.css', array(), SITEGRAPH_VERSION );
		}
	}

	private static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * External link tagged so the destination site can see it came from the plugin.
	 */
	private static function campaign_url( $url, $content ) {
		return add_query_arg(
			array(
				'utm_source'   => 'sitegraph-seo-agent',
				'utm_medium'   => 'wp-admin',
				'utm_campaign' => 'plugin',
				'utm_content'  => $content,
			),
			$url
		);
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open SiteGraph', 'sitegraph-seo-agent' ) . '</a>' );
		return $links;
	}

	public static function row_meta( $links, $file ) {
		if ( plugin_basename( SITEGRAPH_FILE ) !== $file ) {
			return $links;
		}
		$links[] = '<a href="' . esc_url( self::DOCS_URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'Documentation', 'sitegraph-seo-agent' ) . '</a>';
		$links[] = '<a href="' . esc_url( self::SUPPORT_URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'Support', 'sitegraph-seo-agent' ) . '</a>';
		$links[] = '<a href="' . esc_url( self::campaign_url( self::WP_NEEDS_URL, 'plugins-screen' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'WP Needs', 'sitegraph-seo-agent' ) . '</a>';
		return $links;
	}

	/* ------------------------------------------------------------------ */
	/* Form handlers                                                       */
	/* ------------------------------------------------------------------ */

	private static function require_capability() {
		if ( ! Abilities::can_use() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'sitegraph-seo-agent' ), 403 );
		}
	}

	private static function notice( $type, $message ) {
		set_transient( 'sitegraph_notice_' . get_current_user_id(), array( $type, $message ), 60 );
	}

	private static function back( $args ) {
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	public static function handle_scan() {
		self::require_capability();
		check_admin_referer( 'sitegraph_scan' );
		$deadline = microtime( true ) + 40;
		$result   = Link_Index::scan( 20, true );
		while ( ! $result['done'] && microtime( true ) < $deadline ) {
			$result = Link_Index::scan( 20 );
		}
		self::notice( $result['done'] ? 'success' : 'warning', $result['message'] );
		self::back( array( 'tab' => 'overview' ) );
	}

	public static function handle_import() {
		self::require_capability();
		check_admin_referer( 'sitegraph_import' );
		$file = isset( $_FILES['sitegraph_csv']['tmp_name'] ) ? $_FILES['sitegraph_csv']['tmp_name'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! $file || ! is_uploaded_file( $file ) ) {
			self::notice( 'error', 'Choose the Pages CSV exported from Search Console.' );
			self::back( array( 'tab' => 'overview' ) );
		}
		$rows = Search_Data::parse_csv( file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( is_wp_error( $rows ) ) {
			self::notice( 'error', $rows->get_error_message() );
			self::back( array( 'tab' => 'overview' ) );
		}
		$period = isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : '';
		$result = Search_Data::import( $rows, 'replace', $period );
		self::notice( 'success', sprintf( 'Imported search data for %d pages (%d rows did not match a published page).', $result['matched'], $result['unmatched_count'] ) );
		self::back( array( 'tab' => 'overview' ) );
	}

	public static function handle_changeset() {
		self::require_capability();
		check_admin_referer( 'sitegraph_changeset' );
		$id   = isset( $_POST['changeset_id'] ) ? absint( $_POST['changeset_id'] ) : 0;
		$verb = isset( $_POST['verb'] ) ? sanitize_key( $_POST['verb'] ) : '';
		$map  = array(
			'apply'   => 'apply',
			'undo'    => 'undo',
			'redo'    => 'redo',
			'discard' => 'discard',
		);
		if ( ! isset( $map[ $verb ] ) ) {
			self::back( array( 'tab' => 'changes' ) );
		}
		$force  = ! empty( $_POST['force'] );
		$result = 'discard' === $verb ? Changesets::discard( $id ) : call_user_func( array( Changesets::class, $map[ $verb ] ), $id, $force );
		if ( is_wp_error( $result ) ) {
			self::notice( 'error', $result->get_error_message() );
		} else {
			$done = array(
				'apply'   => 'applied',
				'undo'    => 'undone',
				'redo'    => 'redone',
				'discard' => 'discarded',
			);
			self::notice( 'success', sprintf( 'Changeset #%d %s.', $id, $done[ $verb ] ) );
		}
		self::back(
			array(
				'tab'       => 'changes',
				'changeset' => $id,
			)
		);
	}

	public static function handle_propose_links() {
		self::require_capability();
		check_admin_referer( 'sitegraph_propose_links' );
		$target  = isset( $_POST['target_post_id'] ) ? absint( $_POST['target_post_id'] ) : 0;
		$picks   = isset( $_POST['links'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['links'] ) ) : array();
		$ops     = array();
		foreach ( $picks as $pick ) {
			$parts = explode( '|', $pick, 2 );
			if ( 2 === count( $parts ) ) {
				$ops[] = array(
					'type'           => 'add_internal_link',
					'post_id'        => (int) $parts[0],
					'target_post_id' => $target,
					'anchor_text'    => $parts[1],
				);
			}
		}
		if ( empty( $ops ) ) {
			self::notice( 'error', 'Select at least one suggested link.' );
			self::back(
				array(
					'tab'     => 'page',
					'post_id' => $target,
				)
			);
		}
		$title  = sprintf( 'Add %d internal link%s to “%s”', count( $ops ), 1 === count( $ops ) ? '' : 's', wp_strip_all_tags( get_the_title( $target ) ) );
		$result = Changesets::propose( $title, 'Created from link suggestions in wp-admin.', $ops );
		if ( is_wp_error( $result ) ) {
			self::notice( 'error', $result->get_error_message() );
			self::back(
				array(
					'tab'     => 'page',
					'post_id' => $target,
				)
			);
		}
		self::notice( 'success', 'Changeset proposed. Review the diff below, then apply it.' );
		self::back(
			array(
				'tab'       => 'changes',
				'changeset' => $result['id'],
			)
		);
	}

	public static function handle_connect() {
		self::require_capability();
		check_admin_referer( 'sitegraph_connect' );
		if ( ! wp_is_application_passwords_available_for_user( wp_get_current_user() ) ) {
			self::notice( 'error', 'Application Passwords are not available on this site. They require HTTPS, or a local environment.' );
			self::back( array( 'tab' => 'connect' ) );
		}
		$created = \WP_Application_Passwords::create_new_application_password(
			get_current_user_id(),
			array( 'name' => 'SiteGraph agent ' . gmdate( 'Y-m-d H:i' ) )
		);
		if ( is_wp_error( $created ) ) {
			self::notice( 'error', $created->get_error_message() );
			self::back( array( 'tab' => 'connect' ) );
		}
		// Shown once on the next page load, like WordPress's own Application Passwords screen.
		set_transient( 'sitegraph_connection_' . get_current_user_id(), $created[0], 120 );
		self::back( array( 'tab' => 'connect' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Rendering helpers                                                   */
	/* ------------------------------------------------------------------ */

	private static function score_pill( $score ) {
		$grade = Page_Analyzer::grade( $score );
		return '<span class="sg-score sg-grade-' . esc_attr( $grade ) . '">' . (int) $score . '</span>';
	}

	private static function impact_badge( $impact ) {
		return '<span class="sg-badge sg-impact-' . esc_attr( $impact ) . '">' . esc_html( ucfirst( $impact ) ) . ' impact</span>';
	}

	private static function issue_chips( $issues, $max = 4 ) {
		$html = '';
		foreach ( array_slice( $issues, 0, $max ) as $issue ) {
			$html .= '<span class="sg-chip sg-impact-' . esc_attr( $issue['impact'] ) . '" title="' . esc_attr( $issue['detail'] ) . '">' . esc_html( $issue['label'] ) . '</span>';
		}
		if ( count( $issues ) > $max ) {
			$html .= '<span class="sg-chip sg-more">+' . ( count( $issues ) - $max ) . '</span>';
		}
		return $html;
	}

	private static function status_badge( $status ) {
		return '<span class="sg-status sg-status-' . esc_attr( $status ) . '">' . esc_html( ucfirst( $status ) ) . '</span>';
	}

	private static function button_form( $action, $fields, $label, $class = 'button' ) {
		$html  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sg-inline-form">';
		$html .= '<input type="hidden" name="action" value="sitegraph_' . esc_attr( $action ) . '" />';
		$html .= wp_nonce_field( 'sitegraph_' . $action, '_wpnonce', true, false );
		foreach ( $fields as $name => $value ) {
			$html .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
		}
		$html .= '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
		return $html;
	}

	/**
	 * Word-level diff of two short strings (longest common subsequence of tokens).
	 *
	 * @return array{0:string, 1:string} Escaped HTML for before and after.
	 */
	private static function diff( $before, $after ) {
		$split = function ( $text ) {
			return preg_split( '/(<[^>]*>|\s+|[.,;:!?()"“”])/u', (string) $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		};
		$a  = $split( $before );
		$b  = $split( $after );
		$na = count( $a );
		$nb = count( $b );
		if ( $na * $nb > 250000 ) {
			return array( esc_html( $before ), esc_html( $after ) );
		}

		$lcs = array_fill( 0, $na + 1, array_fill( 0, $nb + 1, 0 ) );
		for ( $i = $na - 1; $i >= 0; $i-- ) {
			for ( $j = $nb - 1; $j >= 0; $j-- ) {
				$lcs[ $i ][ $j ] = $a[ $i ] === $b[ $j ] ? $lcs[ $i + 1 ][ $j + 1 ] + 1 : max( $lcs[ $i + 1 ][ $j ], $lcs[ $i ][ $j + 1 ] );
			}
		}

		// Mostly rewritten (typical for titles and descriptions): show it as one replacement.
		if ( $lcs[0][0] < 0.5 * max( $na, $nb ) ) {
			return array(
				'' === (string) $before ? '' : '<del>' . esc_html( $before ) . '</del>',
				'' === (string) $after ? '' : '<ins>' . esc_html( $after ) . '</ins>',
			);
		}

		$left  = '';
		$right = '';
		$i     = 0;
		$j     = 0;
		while ( $i < $na || $j < $nb ) {
			if ( $i < $na && $j < $nb && $a[ $i ] === $b[ $j ] ) {
				$left  .= esc_html( $a[ $i ] );
				$right .= esc_html( $b[ $j ] );
				$i++;
				$j++;
			} elseif ( $j < $nb && ( $i >= $na || $lcs[ $i ][ $j + 1 ] >= $lcs[ $i + 1 ][ $j ] ) ) {
				$right .= '<ins>' . esc_html( $b[ $j ] ) . '</ins>';
				$j++;
			} else {
				$left .= '<del>' . esc_html( $a[ $i ] ) . '</del>';
				$i++;
			}
		}
		$tidy = function ( $html ) {
			return preg_replace( array( '#</ins><ins>#', '#</del><del>#' ), '', $html );
		};
		return array( $tidy( $left ), $tidy( $right ) );
	}

	/**
	 * Text without tags, keeping the leading and trailing space that separates it from the link.
	 */
	private static function plain( $html ) {
		$text = wp_strip_all_tags( $html );
		$lead = preg_match( '/^\s/u', $html ) ? ' ' : '';
		$tail = preg_match( '/\s$/u', $html ) ? ' ' : '';
		return $lead . $text . $tail;
	}

	/**
	 * Before/after for a new link: the anchor text is highlighted, and the link
	 * is rendered as a link with its destination, rather than as raw HTML.
	 */
	private static function link_preview( $before, $after, $anchor ) {
		$mark = function ( $text ) use ( $anchor ) {
			$escaped = esc_html( $text );
			$needle  = esc_html( $anchor );
			$pos     = '' === $needle ? false : stripos( $escaped, $needle );
			return false === $pos ? $escaped : substr( $escaped, 0, $pos ) . '<mark>' . substr( $escaped, $pos, strlen( $needle ) ) . '</mark>' . substr( $escaped, $pos + strlen( $needle ) );
		};
		if ( ! preg_match( '#^(.*?)<a href="([^"]*)">(.*?)</a>(.*)$#su', $after, $m ) ) {
			return self::diff( $before, $after );
		}
		$path = wp_parse_url( html_entity_decode( $m[2] ), PHP_URL_PATH );
		return array(
			$mark( $before ),
			esc_html( self::plain( $m[1] ) ) . '<ins class="sg-new-link">' . esc_html( $m[3] ) . '</ins><span class="sg-link-target">→ ' . esc_html( $path ? $path : $m[2] ) . '</span>' . esc_html( self::plain( $m[4] ) ),
		);
	}

	/**
	 * SVG coordinate with one decimal and a dot separator, whatever the locale.
	 */
	private static function coord( $value ) {
		return number_format( (float) $value, 1, '.', '' );
	}

	private static function logo() {
		return '<svg class="sg-logo" viewBox="0 0 32 32" aria-hidden="true"><circle cx="16" cy="16" r="15" fill="#1d2b53"/><g stroke="#8fb4ff" stroke-width="1.6"><line x1="16" y1="16" x2="8" y2="9"/><line x1="16" y1="16" x2="24" y2="10"/><line x1="16" y1="16" x2="10" y2="24"/><line x1="16" y1="16" x2="23" y2="23"/><line x1="24" y1="10" x2="23" y2="23"/></g><circle cx="16" cy="16" r="3.4" fill="#ffffff"/><circle cx="8" cy="9" r="2.3" fill="#4ade80"/><circle cx="24" cy="10" r="2.3" fill="#4ade80"/><circle cx="10" cy="24" r="2.3" fill="#fbbf24"/><circle cx="23" cy="23" r="2.3" fill="#f87171"/></svg>';
	}

	/* ------------------------------------------------------------------ */
	/* Page                                                                */
	/* ------------------------------------------------------------------ */

	public static function render() {
		if ( ! Abilities::can_use() ) {
			return;
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( self::TABS[ $tab ] ) && 'page' !== $tab ) {
			$tab = 'overview';
		}
		$last  = (int) get_option( Link_Index::LAST_SCAN_OPTION );
		$state = Link_Index::scan_state();

		echo '<div class="wrap sg-wrap">';
		echo '<div class="sg-header">' . self::logo(); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="sg-header-text"><h1>SiteGraph SEO</h1><p>' . esc_html( get_bloginfo( 'name' ) ) . ' · ';
		if ( $last ) {
			/* translators: %s: human time difference */
			echo esc_html( sprintf( 'Last scan %s ago', human_time_diff( $last ) ) );
		} elseif ( $state && empty( $state['done'] ) ) {
			echo esc_html( sprintf( 'Scan in progress (%d of %d pages)', $state['processed'], $state['total'] ) );
		} else {
			echo 'Not scanned yet';
		}
		echo '</p></div><div class="sg-header-actions">';
		echo self::button_form( 'scan', array(), $last ? 'Re-scan site' : 'Scan site', 'button button-primary' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</div></div>';

		$notice = get_transient( 'sitegraph_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'sitegraph_notice_' . get_current_user_id() );
			echo '<div class="notice notice-' . esc_attr( $notice[0] ) . ' sg-notice"><p>' . nl2br( esc_html( $notice[1] ) ) . '</p></div>';
		}

		echo '<nav class="sg-tabs">';
		foreach ( self::TABS as $key => $label ) {
			$active = ( $key === $tab || ( 'page' === $tab && 'pages' === $key ) ) ? ' sg-tab-active' : '';
			echo '<a class="sg-tab' . esc_attr( $active ) . '" href="' . esc_url( self::url( array( 'tab' => $key ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav><div class="sg-body">';

		if ( ! $last && ! in_array( $tab, array( 'connect', 'changes' ), true ) ) {
			self::render_empty();
		} else {
			call_user_func( array( __CLASS__, 'render_' . $tab ) );
		}
		echo '</div>';
		self::render_credits();
		echo '</div>';
	}

	private static function render_credits() {
		/**
		 * Filters whether the credits line is shown at the bottom of SiteGraph's own admin screen.
		 *
		 * @param bool $show Whether to show the credits line. Default true.
		 */
		if ( ! apply_filters( 'sitegraph_show_credits', true ) ) {
			return;
		}
		echo '<p class="sg-credits">';
		echo esc_html( 'SiteGraph SEO Agent ' . SITEGRAPH_VERSION ) . ' · ';
		/* translators: %s: author name linked to the author's website */
		printf( esc_html__( 'Made by %s', 'sitegraph-seo-agent' ), '<a href="' . esc_url( self::campaign_url( self::AUTHOR_URL, 'credits' ) ) . '" target="_blank" rel="noopener">AMEEEN ZED</a>' );
		echo ' · ';
		/* translators: %s: "WP Needs" linked to wp-needs.com */
		printf( esc_html__( 'Part of %s — WordPress plugins, themes and support', 'sitegraph-seo-agent' ), '<a href="' . esc_url( self::campaign_url( self::WP_NEEDS_URL, 'credits' ) ) . '" target="_blank" rel="noopener">WP Needs</a>' );
		echo ' · <a href="' . esc_url( self::DOCS_URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'Documentation', 'sitegraph-seo-agent' ) . '</a>';
		echo '</p>';
	}

	private static function render_empty() {
		echo '<div class="sg-card sg-empty"><h2>Find out which pages are holding your site back</h2>';
		echo '<p>SiteGraph reads every published page straight from your database, maps how they link to each other, and scores each page from 0 to 100. Scanning does not change any content.</p>';
		echo self::button_form( 'scan', array(), 'Run the first scan', 'button button-primary button-hero' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</div>';
	}

	private static function render_overview() {
		$o    = Page_Analyzer::overview();
		$plan = Action_Plan::build( 4 );
		$avg  = null === $o['average_score'] ? 0 : $o['average_score'];
		$weak = $o['grades']['critical'] + $o['grades']['weak'];
		$sd   = $o['search_data'];

		echo '<div class="sg-kpis">';
		echo '<div class="sg-card sg-kpi"><div class="sg-kpi-label">Average page score</div><div class="sg-kpi-value">' . self::score_pill( $avg ) . '<small>/ 100</small></div><div class="sg-kpi-sub">' . (int) $o['pages_analyzed'] . ' pages analyzed</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="sg-card sg-kpi"><div class="sg-kpi-label">Weak pages</div><div class="sg-kpi-value sg-text-weak">' . (int) $weak . '</div><div class="sg-kpi-sub">' . (int) $o['grades']['critical'] . ' critical · ' . (int) $o['grades']['weak'] . ' weak</div></div>';
		echo '<div class="sg-card sg-kpi"><div class="sg-kpi-label">Orphan pages</div><div class="sg-kpi-value">' . (int) $o['orphan_pages'] . '</div><div class="sg-kpi-sub">No internal links point to them</div></div>';
		echo '<div class="sg-card sg-kpi"><div class="sg-kpi-label">Broken internal links</div><div class="sg-kpi-value">' . (int) $o['broken_links'] . '</div><div class="sg-kpi-sub">Links to pages that do not exist</div></div>';
		echo '<div class="sg-card sg-kpi"><div class="sg-kpi-label">Search Console data</div><div class="sg-kpi-value sg-kpi-small">' . ( $sd ? esc_html( number_format_i18n( $sd['rows'] ) ) . ' pages' : 'Not imported' ) . '</div><div class="sg-kpi-sub">' . ( $sd ? esc_html( $sd['period'] ? $sd['period'] : 'Imported ' . human_time_diff( $sd['imported_at'] ) . ' ago' ) : 'Import it to rank by real demand' ) . '</div></div>';
		echo '</div>';

		$total = max( 1, $o['pages_analyzed'] );
		echo '<div class="sg-card"><div class="sg-card-head"><h2>Score distribution</h2></div><div class="sg-dist">';
		foreach ( array( 'critical', 'weak', 'fair', 'good' ) as $grade ) {
			$count = $o['grades'][ $grade ];
			if ( $count ) {
				echo '<div class="sg-dist-seg sg-grade-bg-' . esc_attr( $grade ) . '" style="flex:' . (int) $count . '" title="' . esc_attr( ucfirst( $grade ) . ': ' . $count ) . '">' . (int) $count . '</div>';
			}
		}
		echo '</div><div class="sg-legend"><span class="sg-dot sg-grade-bg-critical"></span>Critical (0–39) <span class="sg-dot sg-grade-bg-weak"></span>Weak (40–59) <span class="sg-dot sg-grade-bg-fair"></span>Fair (60–79) <span class="sg-dot sg-grade-bg-good"></span>Good (80–100)</div></div>';
		unset( $total );

		echo '<div class="sg-grid-2">';
		echo '<div class="sg-card"><div class="sg-card-head"><h2>Top actions</h2><a href="' . esc_url( self::url( array( 'tab' => 'plan' ) ) ) . '">Full action plan →</a></div><ol class="sg-task-list">';
		foreach ( $plan['tasks'] as $task ) {
			echo '<li><div class="sg-task-title">' . esc_html( $task['title'] ) . '</div><div class="sg-task-meta">' . self::impact_badge( $task['impact'] ) . '<span class="sg-badge sg-auto-' . esc_attr( $task['automation'] ) . '">' . esc_html( self::AUTOMATION_LABELS[ $task['automation'] ] ) . '</span><span class="sg-muted">~' . (int) $task['effort_minutes'] . ' min</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
			if ( null !== $task['impressions_at_stake'] && $task['impressions_at_stake'] > 0 ) {
				echo '<span class="sg-muted">' . esc_html( number_format_i18n( $task['impressions_at_stake'] ) ) . ' impressions at stake</span>';
			}
			echo '</div></li>';
		}
		echo '</ol></div>';

		echo '<div class="sg-card"><div class="sg-card-head"><h2>Weakest pages</h2><a href="' . esc_url( self::url( array( 'tab' => 'pages' ) ) ) . '">All weak pages →</a></div><table class="sg-table sg-compact"><tbody>';
		foreach ( $o['weakest_pages'] as $page ) {
			echo '<tr><td class="sg-col-score">' . self::score_pill( $page['score'] ) . '</td><td><a href="' . esc_url( self::url( array( 'tab' => 'page', 'post_id' => $page['post_id'] ) ) ) . '">' . esc_html( $page['title'] ) . '</a><div class="sg-chips">' . self::issue_chips( $page['issues'], 3 ) . '</div></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table></div></div>';

		echo '<div class="sg-grid-2"><div class="sg-card"><div class="sg-card-head"><h2>Most common issues</h2></div><table class="sg-table sg-compact"><tbody>';
		foreach ( array_slice( $o['issues'], 0, 8 ) as $issue ) {
			echo '<tr><td><a href="' . esc_url( self::url( array( 'tab' => 'pages', 'issue' => $issue['code'], 'max_score' => 100 ) ) ) . '">' . esc_html( $issue['label'] ) . '</a></td><td>' . self::impact_badge( $issue['impact'] ) . '</td><td class="sg-num">' . (int) $issue['pages'] . ' pages</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table></div>';

		echo '<div class="sg-card"><div class="sg-card-head"><h2>Import Search Console data</h2></div>';
		echo '<p>In Search Console open <strong>Performance → Search results</strong>, choose a date range, click <strong>Export → Download CSV</strong> and upload <code>Pages.csv</code> from the zip. Rows are matched by URL path, so a production export works on staging too.</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sg-import">';
		echo '<input type="hidden" name="action" value="sitegraph_import" />';
		wp_nonce_field( 'sitegraph_import' );
		echo '<input type="file" name="sitegraph_csv" accept=".csv,text/csv" required /> <input type="text" name="period" placeholder="Period, e.g. Last 3 months" /> <button class="button">Import</button></form>';
		echo '<p class="sg-muted">Agents can push the same data with the <code>import-search-data</code> tool, for example from a Search Console connector.</p></div></div>';
	}

	private static function render_pages() {
		// Read-only filter and paging parameters from the URL.
		$issue     = isset( $_GET['issue'] ) ? sanitize_key( $_GET['issue'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$max_score = isset( $_GET['max_score'] ) ? absint( $_GET['max_score'] ) : 59; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged     = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page = 25;
		$result   = Page_Analyzer::list_pages(
			array(
				'max_score' => $max_score,
				'issue'     => isset( Page_Analyzer::ISSUES[ $issue ] ) ? $issue : '',
				'limit'     => $per_page,
				'offset'    => ( $paged - 1 ) * $per_page,
			)
		);

		echo '<div class="sg-card"><form method="get" class="sg-filters"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" /><input type="hidden" name="tab" value="pages" />';
		echo '<label>Show <select name="max_score"><option value="59"' . selected( $max_score, 59, false ) . '>Weak and critical pages</option><option value="39"' . selected( $max_score, 39, false ) . '>Critical pages only</option><option value="100"' . selected( $max_score, 100, false ) . '>All pages</option></select></label> ';
		echo '<label>with issue <select name="issue"><option value="">Any issue</option>';
		foreach ( Page_Analyzer::ISSUES as $code => $def ) {
			echo '<option value="' . esc_attr( $code ) . '"' . selected( $issue, $code, false ) . '>' . esc_html( $def['label'] ) . '</option>';
		}
		echo '</select></label> <button class="button">Filter</button><span class="sg-muted sg-count">' . (int) $result['total'] . ' pages</span></form>';

		echo '<table class="sg-table"><thead><tr><th>Score</th><th>Page</th><th>Why it is weak</th><th class="sg-num">Links in</th><th class="sg-num">Depth</th><th class="sg-num">Words</th><th class="sg-num">Impr.</th><th class="sg-num">Pos.</th></tr></thead><tbody>';
		foreach ( $result['pages'] as $page ) {
			$s = $page['search'];
			echo '<tr><td class="sg-col-score">' . self::score_pill( $page['score'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td class="sg-col-page"><a class="sg-page-link" href="' . esc_url( self::url( array( 'tab' => 'page', 'post_id' => $page['post_id'] ) ) ) . '">' . esc_html( $page['title'] ) . '</a><div class="sg-muted sg-url">' . esc_html( wp_parse_url( $page['url'], PHP_URL_PATH ) ) . '</div></td>';
			echo '<td><div class="sg-chips">' . self::issue_chips( $page['issues'] ) . '</div></td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td class="sg-num">' . (int) $page['signals']['inbound_links'] . '</td>';
			echo '<td class="sg-num">' . ( null === $page['signals']['click_depth'] ? '—' : (int) $page['signals']['click_depth'] ) . '</td>';
			echo '<td class="sg-num">' . esc_html( number_format_i18n( $page['signals']['word_count'] ) ) . '</td>';
			echo '<td class="sg-num">' . ( $s ? esc_html( number_format_i18n( $s['impressions'] ) ) : '—' ) . '</td>';
			echo '<td class="sg-num">' . ( $s && $s['position'] ? esc_html( number_format_i18n( $s['position'], 1 ) ) : '—' ) . '</td></tr>';
		}
		if ( empty( $result['pages'] ) ) {
			echo '<tr><td colspan="8" class="sg-muted">No pages match. Nice.</td></tr>';
		}
		echo '</tbody></table>';

		$pages = (int) ceil( $result['total'] / $per_page );
		if ( $pages > 1 ) {
			echo '<div class="sg-pager">';
			for ( $i = 1; $i <= $pages; $i++ ) {
				echo $i === $paged ? '<span class="sg-page-current">' . (int) $i . '</span>' : '<a href="' . esc_url( self::url( array( 'tab' => 'pages', 'issue' => $issue, 'max_score' => $max_score, 'paged' => $i ) ) ) . '">' . (int) $i . '</a>';
			}
			echo '</div>';
		}
		echo '</div>';
	}

	private static function render_page() {
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter.
		$report  = Abilities::page_report( array( 'post_id' => $post_id ) );
		if ( is_wp_error( $report ) ) {
			echo '<div class="sg-card"><p>' . esc_html( $report->get_error_message() ) . '</p></div>';
			return;
		}
		$signals = $report['signals'];
		$s       = $report['search'];

		echo '<div class="sg-card sg-report-head">' . self::score_pill( $report['score'] ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div><h2>' . esc_html( $report['title'] ) . '</h2><div class="sg-muted">' . esc_html( $report['url'] ) . ' · <a href="' . esc_url( $report['url'] ) . '" target="_blank" rel="noopener">View</a> · <a href="' . esc_url( $report['edit_url'] ) . '">Edit</a></div></div></div>';

		echo '<div class="sg-grid-2"><div class="sg-card"><div class="sg-card-head"><h2>Why this page is weak</h2></div>';
		if ( empty( $report['issues'] ) ) {
			echo '<p class="sg-muted">No issues found.</p>';
		}
		echo '<ul class="sg-issues">';
		foreach ( $report['issues'] as $issue ) {
			echo '<li>' . self::impact_badge( $issue['impact'] ) . '<strong>' . esc_html( $issue['label'] ) . '</strong> <span class="sg-muted">' . esc_html( $issue['detail'] ) . '</span><div class="sg-fix">Fix: ' . esc_html( $issue['fix'] ) . '</div></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</ul></div>';

		echo '<div class="sg-card"><div class="sg-card-head"><h2>Signals</h2></div><dl class="sg-signals">';
		$rows = array(
			'Internal links in'  => $signals['inbound_links'],
			'Internal links out' => $signals['outbound_links'],
			'Click depth'        => null === $signals['click_depth'] ? 'Unreachable' : $signals['click_depth'],
			'Words'              => number_format_i18n( $signals['word_count'] ),
			'Broken links'       => $signals['broken_links'],
			'Meta description'   => $signals['has_meta_description'] ? 'Yes' : 'Missing',
			'Impressions'        => $s ? number_format_i18n( $s['impressions'] ) : '—',
			'Clicks'             => $s ? number_format_i18n( $s['clicks'] ) : '—',
			'CTR'                => $s ? number_format_i18n( $s['ctr'] * 100, 1 ) . '%' : '—',
			'Avg. position'      => $s ? number_format_i18n( $s['position'], 1 ) : '—',
		);
		foreach ( $rows as $label => $value ) {
			echo '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( (string) $value ) . '</dd></div>';
		}
		echo '</dl></div></div>';

		$suggestions = Link_Suggester::suggest( $post_id, 6 );
		echo '<div class="sg-card"><div class="sg-card-head"><h2>Pages that could link here</h2><span class="sg-muted">Existing sentences that already mention this topic</span></div>';
		if ( empty( $suggestions['suggestions'] ) ) {
			echo '<p class="sg-muted">' . esc_html( $suggestions['note'] ) . '</p>';
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sitegraph_propose_links" /><input type="hidden" name="target_post_id" value="' . (int) $post_id . '" />';
			wp_nonce_field( 'sitegraph_propose_links' );
			echo '<table class="sg-table"><thead><tr><th></th><th>From page</th><th>Anchor and context</th></tr></thead><tbody>';
			foreach ( $suggestions['suggestions'] as $index => $sug ) {
				$context = esc_html( $sug['context'] );
				$anchor  = esc_html( $sug['anchor_text'] );
				$context = preg_replace( '/' . preg_quote( $anchor, '/' ) . '/', '<mark>' . $anchor . '</mark>', $context, 1 );
				echo '<tr><td><input type="checkbox" name="links[]" value="' . esc_attr( $sug['source_post_id'] . '|' . $sug['anchor_text'] ) . '"' . checked( $index < 3, true, false ) . ' /></td><td><strong>' . esc_html( $sug['source_title'] ) . '</strong>' . ( $sug['same_category'] ? '<div class="sg-muted">Same category</div>' : '' ) . '</td><td class="sg-context">' . $context . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</tbody></table><p><button class="button button-primary">Propose changeset with selected links</button> <span class="sg-muted">Nothing changes until you apply it.</span></p></form>';
		}
		echo '</div>';

		echo '<div class="sg-grid-2"><div class="sg-card"><div class="sg-card-head"><h2>Linked from (' . count( $report['inbound_links'] ) . ')</h2></div><ul class="sg-linklist">';
		foreach ( $report['inbound_links'] as $link ) {
			echo '<li><a href="' . esc_url( self::url( array( 'tab' => 'page', 'post_id' => $link['from_post_id'] ) ) ) . '">' . esc_html( $link['from_title'] ) . '</a> <span class="sg-muted">“' . esc_html( $link['anchor'] ) . '”</span></li>';
		}
		echo '</ul></div><div class="sg-card"><div class="sg-card-head"><h2>Links out (' . count( $report['outbound_links'] ) . ')</h2></div><ul class="sg-linklist">';
		foreach ( $report['outbound_links'] as $link ) {
			$broken = 'broken' === $link['status'];
			echo '<li' . ( $broken ? ' class="sg-broken"' : '' ) . '>' . ( $broken ? '<span class="sg-badge sg-impact-high">Broken</span> ' : '' ) . esc_html( $link['url'] ) . ' <span class="sg-muted">“' . esc_html( $link['anchor'] ) . '”</span></li>';
		}
		echo '</ul></div></div>';
	}

	private static function render_plan() {
		$plan = Action_Plan::build( 12 );
		if ( $plan['note'] ) {
			echo '<div class="notice notice-info inline sg-notice"><p>' . esc_html( $plan['note'] ) . '</p></div>';
		}
		foreach ( $plan['tasks'] as $task ) {
			echo '<div class="sg-card sg-task"><div class="sg-task-rank">' . (int) $task['rank'] . '</div><div class="sg-task-body">';
			echo '<div class="sg-card-head"><h2>' . esc_html( $task['title'] ) . '</h2><div class="sg-task-meta">' . self::impact_badge( $task['impact'] ) . '<span class="sg-badge sg-auto-' . esc_attr( $task['automation'] ) . '">' . esc_html( self::AUTOMATION_LABELS[ $task['automation'] ] ) . '</span><span class="sg-muted">~' . (int) $task['effort_minutes'] . ' min</span></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<p>' . esc_html( $task['why'] ) . '</p><table class="sg-table sg-compact"><tbody>';
			foreach ( array_slice( $task['pages'], 0, 5 ) as $page ) {
				echo '<tr><td class="sg-col-score">' . self::score_pill( $page['score'] ) . '</td><td><a href="' . esc_url( self::url( array( 'tab' => 'page', 'post_id' => $page['post_id'] ) ) ) . '">' . esc_html( $page['title'] ) . '</a></td><td class="sg-muted">' . esc_html( $page['detail'] ) . '</td><td class="sg-num">' . ( $page['impressions'] ? esc_html( number_format_i18n( $page['impressions'] ) ) . ' impr.' : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</tbody></table>';
			if ( $task['pages_affected'] > 5 ) {
				echo '<p class="sg-muted">…and ' . (int) ( $task['pages_affected'] - 5 ) . ' more.</p>';
			}
			echo '<div class="sg-prompt"><span>Ask your agent</span><code>' . esc_html( $task['suggested_prompt'] ) . '</code></div>';
			echo '</div></div>';
		}
	}

	private static function render_graph() {
		global $wpdb;
		$pages_table = Installer::table( 'pages' );
		$links_table = Installer::table( 'links' );
		$nodes = $wpdb->get_results( $wpdb->prepare( 'SELECT post_id, title, inbound, depth, score FROM %i ORDER BY inbound DESC LIMIT 400', $pages_table ) );
		$edges = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT source_id, target_id FROM %i WHERE status = 'ok' AND target_id > 0", $links_table ) );

		$size   = 760;
		$center = $size / 2;
		$rings  = array();
		foreach ( $nodes as $node ) {
			$ring             = null === $node->depth ? 6 : min( 5, (int) $node->depth );
			$rings[ $ring ][] = $node;
		}
		ksort( $rings );
		$pos = array();
		foreach ( $rings as $ring => $members ) {
			$radius = 0 === $ring ? 0 : 58 * $ring + ( 6 === $ring ? 20 : 0 );
			$count  = count( $members );
			foreach ( $members as $i => $node ) {
				$angle                         = ( 2 * M_PI * $i / max( 1, $count ) ) + $ring * 0.6;
				$pos[ (int) $node->post_id ] = array( $center + $radius * cos( $angle ), $center + $radius * sin( $angle ), $node );
			}
		}

		echo '<div class="sg-card"><div class="sg-card-head"><h2>Internal link graph</h2><span class="sg-muted">Rings show click depth from the home page. The outer dashed ring holds pages no link path reaches. Larger dots have more internal links.</span></div>';
		echo '<svg class="sg-graph" viewBox="0 0 ' . (int) $size . ' ' . (int) $size . '" role="img" aria-label="Internal link graph">';
		for ( $r = 1; $r <= 5; $r++ ) {
			echo '<circle cx="' . (int) $center . '" cy="' . (int) $center . '" r="' . (int) ( 58 * $r ) . '" class="sg-ring"/><text x="' . (int) ( $center + 4 ) . '" y="' . (int) ( $center - 58 * $r + 12 ) . '" class="sg-ring-label">' . (int) $r . ( 5 === $r ? '+' : '' ) . ' click' . ( 1 === $r ? '' : 's' ) . '</text>';
		}
		echo '<circle cx="' . (int) $center . '" cy="' . (int) $center . '" r="' . (int) ( 58 * 6 + 20 ) . '" class="sg-ring sg-ring-unreachable"/>';
		foreach ( $edges as $edge ) {
			$a = (int) $edge->source_id;
			$b = (int) $edge->target_id;
			if ( isset( $pos[ $a ], $pos[ $b ] ) ) {
				printf( '<line x1="%s" y1="%s" x2="%s" y2="%s" class="sg-edge"/>', esc_attr( self::coord( $pos[ $a ][0] ) ), esc_attr( self::coord( $pos[ $a ][1] ) ), esc_attr( self::coord( $pos[ $b ][0] ) ), esc_attr( self::coord( $pos[ $b ][1] ) ) );
			}
		}
		echo '<text x="' . (int) $center . '" y="' . (int) ( $center + 26 ) . '" class="sg-home-label" text-anchor="middle">Home</text>';
		foreach ( $pos as $id => $p ) {
			$node   = $p[2];
			$radius = 5 + min( 11, sqrt( (int) $node->inbound ) * 2.4 );
			printf(
				'<a href="%s"><circle cx="%s" cy="%s" r="%s" class="sg-node sg-grade-fill-%s"><title>%s</title></circle></a>',
				esc_url( self::url( array( 'tab' => 'page', 'post_id' => $id ) ) ),
				esc_attr( self::coord( $p[0] ) ),
				esc_attr( self::coord( $p[1] ) ),
				esc_attr( self::coord( $radius ) ),
				esc_attr( Page_Analyzer::grade( (int) $node->score ) ),
				esc_html( sprintf( '%s — score %d, %d links in, depth %s', $node->title, $node->score, $node->inbound, null === $node->depth ? 'unreachable' : $node->depth ) )
			);
		}
		echo '</svg><div class="sg-legend"><span class="sg-dot sg-grade-bg-critical"></span>Critical <span class="sg-dot sg-grade-bg-weak"></span>Weak <span class="sg-dot sg-grade-bg-fair"></span>Fair <span class="sg-dot sg-grade-bg-good"></span>Good</div></div>';
	}

	private static function render_changes() {
		$id = isset( $_GET['changeset'] ) ? absint( $_GET['changeset'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter.
		if ( $id ) {
			self::render_changeset( $id );
			return;
		}
		$list = Changesets::list_changesets( '', 50 );
		echo '<div class="sg-card"><div class="sg-card-head"><h2>Changes</h2><span class="sg-muted">Every content change made through SiteGraph — by an agent or from this screen — with undo and redo.</span></div>';
		if ( empty( $list ) ) {
			echo '<p class="sg-muted">No changes yet. Ask your agent to fix something from the action plan, or propose links from a page report.</p></div>';
			return;
		}
		echo '<table class="sg-table"><thead><tr><th>#</th><th>Change</th><th>Status</th><th class="sg-num">Operations</th><th class="sg-num">Pages</th><th>By</th><th>When</th><th></th></tr></thead><tbody>';
		foreach ( $list as $row ) {
			echo '<tr><td>' . (int) $row['id'] . '</td><td><a href="' . esc_url( self::url( array( 'tab' => 'changes', 'changeset' => $row['id'] ) ) ) . '">' . esc_html( $row['title'] ) . '</a></td><td>' . self::status_badge( $row['status'] ) . '</td><td class="sg-num">' . (int) $row['operations'] . '</td><td class="sg-num">' . (int) $row['posts'] . '</td><td>' . esc_html( (string) $row['created_by'] ) . '</td><td class="sg-muted">' . esc_html( human_time_diff( strtotime( $row['updated_at'] . ' UTC' ) ) ) . ' ago</td><td class="sg-actions">'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo self::changeset_buttons( $row['id'], $row['available_actions'] ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function changeset_buttons( $id, $actions, $force = false ) {
		$labels = array(
			'apply'   => array( 'Apply', 'button button-primary' ),
			'undo'    => array( 'Undo', 'button' ),
			'redo'    => array( 'Redo', 'button' ),
			'discard' => array( 'Discard', 'button button-link-delete' ),
		);
		$html   = '';
		foreach ( $actions as $action ) {
			$fields = array(
				'changeset_id' => $id,
				'verb'         => $action,
			);
			if ( $force ) {
				$fields['force'] = 1;
			}
			$html .= self::button_form( 'changeset', $fields, ( $force ? 'Force ' . strtolower( $labels[ $action ][0] ) : $labels[ $action ][0] ), $labels[ $action ][1] );
		}
		return $html;
	}

	private static function render_changeset( $id ) {
		$cs = Changesets::get( $id );
		if ( is_wp_error( $cs ) ) {
			echo '<div class="sg-card"><p>' . esc_html( $cs->get_error_message() ) . '</p></div>';
			return;
		}
		echo '<p><a href="' . esc_url( self::url( array( 'tab' => 'changes' ) ) ) . '">← All changes</a></p>';
		echo '<div class="sg-card"><div class="sg-card-head"><h2>#' . (int) $cs['id'] . ' ' . esc_html( $cs['title'] ) . ' ' . self::status_badge( $cs['status'] ) . '</h2><div class="sg-actions">' . self::changeset_buttons( $cs['id'], $cs['available_actions'] ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $cs['rationale'] ) {
			echo '<p>' . esc_html( $cs['rationale'] ) . '</p>';
		}
		echo '<p class="sg-muted">Proposed by ' . esc_html( (string) $cs['created_by'] ) . ' · ' . count( $cs['operations'] ) . ' operation(s) on ' . count( $cs['affected_posts'] ) . ' page(s)</p>';

		foreach ( $cs['operations'] as $op ) {
			if ( 'add_internal_link' === $op['type'] ) {
				list( $before, $after ) = self::link_preview( $op['preview']['before'], $op['preview']['after'], isset( $op['params']['anchor_text'] ) ? $op['params']['anchor_text'] : '' );
			} else {
				list( $before, $after ) = self::diff( $op['preview']['before'], $op['preview']['after'] );
			}
			echo '<div class="sg-op"><div class="sg-op-title"><span class="sg-badge sg-op-type">' . esc_html( isset( self::OPERATION_LABELS[ $op['type'] ] ) ? self::OPERATION_LABELS[ $op['type'] ] : $op['type'] ) . '</span> ' . esc_html( $op['summary'] ) . '</div>';
			echo '<div class="sg-diff"><div class="sg-diff-before"><span class="sg-diff-label">Before</span>' . ( '' === $op['preview']['before'] ? '<em class="sg-muted">(empty)</em>' : $before ) . '</div><div class="sg-diff-after"><span class="sg-diff-label">After</span>' . $after . '</div></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</div>';

		echo '<div class="sg-grid-2"><div class="sg-card"><div class="sg-card-head"><h2>History</h2></div><ul class="sg-history">';
		foreach ( array_reverse( $cs['history'] ) as $event ) {
			echo '<li>' . self::status_badge( $event['action'] ) . ' by ' . esc_html( $event['user'] ) . ' <span class="sg-muted">' . esc_html( human_time_diff( strtotime( $event['at'] ) ) ) . ' ago' . ( ! empty( $event['forced'] ) ? ' · forced' : '' ) . '</span>' . ( ! empty( $event['error'] ) ? '<div class="sg-broken">' . esc_html( $event['error'] ) . '</div>' : '' ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</ul></div>';

		echo '<div class="sg-card"><div class="sg-card-head"><h2>Impact</h2></div>';
		if ( empty( $cs['impact'] ) ) {
			echo '<p class="sg-muted">Impact is tracked once the changeset is applied.</p>';
		} else {
			echo '<table class="sg-table sg-compact"><thead><tr><th>Page</th><th class="sg-num">Score then → now</th><th class="sg-num">Impressions then → now</th></tr></thead><tbody>';
			foreach ( $cs['impact'] as $row ) {
				$then = $row['before']['search'];
				$now  = $row['now']['search'];
				echo '<tr><td>' . esc_html( $row['title'] ) . '</td><td class="sg-num">' . esc_html( (string) $row['before']['score'] ) . ' → ' . esc_html( (string) $row['now']['score'] ) . '</td><td class="sg-num">' . ( $then ? esc_html( number_format_i18n( $then['impressions'] ) ) : '—' ) . ' → ' . ( $now ? esc_html( number_format_i18n( $now['impressions'] ) ) : '—' ) . '</td></tr>';
			}
			echo '</tbody></table><p class="sg-muted">' . esc_html( (string) $cs['impact_note'] ) . '</p>';
		}
		echo '</div></div>';
	}

	private static function render_connect() {
		$endpoint   = rest_url( 'sitegraph/v1/mcp' );
		$connection = get_transient( 'sitegraph_connection_' . get_current_user_id() );
		if ( $connection ) {
			delete_transient( 'sitegraph_connection_' . get_current_user_id() );
		}

		echo '<div class="sg-grid-2"><div class="sg-card"><div class="sg-card-head"><h2>Requirements</h2></div><ul class="sg-checks">';
		foreach ( Plugin::checks() as $check ) {
			echo '<li class="' . ( $check['ok'] ? 'sg-ok' : 'sg-fail' ) . '"><span class="dashicons ' . ( $check['ok'] ? 'dashicons-yes-alt' : 'dashicons-warning' ) . '"></span><div><strong>' . esc_html( $check['label'] ) . '</strong><div class="sg-muted">' . esc_html( $check['detail'] ) . '</div></div></li>';
		}
		echo '</ul></div>';

		echo '<div class="sg-card"><div class="sg-card-head"><h2>MCP endpoint</h2></div><p><code class="sg-code-block">' . esc_html( $endpoint ) . '</code></p>';
		echo '<p>The agent acts as <strong>your WordPress user</strong>, with your permissions. Reads and proposals change nothing; applying, undoing and redoing are marked as destructive so MCP clients ask for confirmation first.</p>';
		echo self::button_form( 'connect', array(), 'Generate a connection', 'button button-primary' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p class="sg-muted">Creates an Application Password named “SiteGraph agent” that you can revoke any time under Users → Profile.</p></div></div>';

		if ( $connection ) {
			$user  = wp_get_current_user()->user_login;
			$token = base64_encode( $user . ':' . $connection ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			echo '<div class="sg-card sg-secret"><div class="sg-card-head"><h2>Your connection (shown once)</h2></div>';
			echo '<p><strong>With the SiteGraph SEO plugin for Claude:</strong> paste these two values when Claude Code asks for them.</p>';
			echo '<p>MCP URL</p><pre class="sg-code-block">' . esc_html( $endpoint ) . '</pre>';
			echo '<p>Access token</p><pre class="sg-code-block">' . esc_html( $token ) . '</pre>';
			echo '<p><strong>Without the plugin:</strong> add the server yourself.</p>';
			echo '<pre class="sg-code-block">claude mcp add --transport http sitegraph ' . esc_html( $endpoint ) . ' --header "Authorization: Basic ' . esc_html( $token ) . '"</pre>';
			echo '<p class="sg-muted">Treat the token like a password. If it leaks, revoke the Application Password.</p></div>';
		}

		echo '<div class="sg-card"><div class="sg-card-head"><h2>Install the SiteGraph SEO plugin for Claude Code</h2></div>';
		echo '<p>It connects Claude to this site and adds skills for the audit → plan → propose → review → apply workflow, including when to stop and ask you. Claude Code asks for the MCP URL and access token above when you enable it.</p>';
		echo '<pre class="sg-code-block">/plugin marketplace add am333ni7y/sitegraph-seo-agent' . "\n" . '/plugin install sitegraph-seo@sitegraph</pre>';
		echo '<p>Then ask: <code>Audit my site with SiteGraph and tell me the five changes that would help most.</code></p></div>';
	}
}
