<?php
/**
 * Builds the internal link graph straight from the database: every internal
 * link in published content, inbound counts, and click depth from the home page.
 *
 * @package SiteGraph
 */

namespace SiteGraph;

defined( 'ABSPATH' ) || exit;

// SiteGraph keeps its link index, page scores, search data and changesets in its own
// tables. They change on every scan or edit, so they are queried directly, not cached.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class Link_Index {

	const STATE_OPTION     = 'sitegraph_scan_state';
	const LAST_SCAN_OPTION = 'sitegraph_last_scan';
	const DIRTY_OPTION     = 'sitegraph_needs_refresh';
	const BATCH_SIZE       = 50;

	/** @var array<string, int|string>|null */
	private static $path_map = null;

	/** @var bool */
	private static $suspended = false;

	/**
	 * Post types that count as pages of the site.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$types = get_post_types( array( 'public' => true ) );
		unset( $types['attachment'] );
		return array_values( apply_filters( 'sitegraph_post_types', array_values( $types ) ) );
	}

	/**
	 * Pauses the save_post listener while SiteGraph writes content itself.
	 */
	public static function suspend( $suspended ) {
		self::$suspended = (bool) $suspended;
	}

	public static function has_scanned() {
		return (bool) get_option( self::LAST_SCAN_OPTION );
	}

	public static function scan_state() {
		$state = get_option( self::STATE_OPTION );
		return is_array( $state ) ? $state : null;
	}

	public static function count_indexable() {
		global $wpdb;
		$types        = self::post_types();
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is a list of %s placeholders, one per post type.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_status = 'publish' AND post_type IN ($placeholders)", array_merge( array( $wpdb->posts ), $types ) ) );
	}

	/**
	 * Indexes published content in batches until the time budget runs out.
	 * Call again with the same arguments until `done` is true.
	 *
	 * @param int  $time_budget Seconds to spend in this call.
	 * @param bool $restart     Discard any partial scan and start over.
	 */
	public static function scan( $time_budget = 20, $restart = false ) {
		global $wpdb;
		$links = Installer::table( 'links' );
		$pages = Installer::table( 'pages' );

		$state = self::scan_state();
		if ( $restart || null === $state || ! empty( $state['done'] ) ) {
			$state = array(
				'cursor'     => 0,
				'processed'  => 0,
				'total'      => self::count_indexable(),
				'started_at' => time(),
				'done'       => false,
			);
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $links ) );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $pages ) );
			self::flush_path_map();
		}

		$types        = self::post_types();
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$deadline     = microtime( true ) + max( 3, (int) $time_budget );
		$this_call    = 0;

		while ( true ) {
			$ids = $wpdb->get_col(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the replacements are passed as one array.
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a list of %s placeholders, one per post type.
					"SELECT ID FROM %i WHERE post_status = 'publish' AND post_type IN ($placeholders) AND ID > %d ORDER BY ID ASC LIMIT %d",
					array_merge( array( $wpdb->posts ), $types, array( $state['cursor'], self::BATCH_SIZE ) )
				)
			);

			if ( empty( $ids ) ) {
				self::refresh();
				$state['done']        = true;
				$state['finished_at'] = time();
				break;
			}

			foreach ( $ids as $id ) {
				self::index_post( (int) $id );
				$state['cursor'] = (int) $id;
				$state['processed']++;
				$this_call++;
			}

			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}

		update_option( self::STATE_OPTION, $state, false );

		return array(
			'done'               => (bool) $state['done'],
			'processed_total'    => (int) $state['processed'],
			'processed_now'      => $this_call,
			'total'              => (int) $state['total'],
			'message'            => $state['done']
				? sprintf( 'Scan complete: %d pages analyzed.', $state['processed'] )
				: sprintf( 'Scanned %d of %d pages. Call scan-site again to continue.', $state['processed'], $state['total'] ),
		);
	}

	/**
	 * Re-indexes the outgoing links and page facts of one post.
	 */
	public static function index_post( $post_id ) {
		global $wpdb;
		$links_table = Installer::table( 'links' );
		$pages_table = Installer::table( 'pages' );

		$post = get_post( $post_id );
		$wpdb->delete( $links_table, array( 'source_id' => $post_id ), array( '%d' ) );

		if ( ! $post || 'publish' !== $post->post_status || ! in_array( $post->post_type, self::post_types(), true ) ) {
			$wpdb->delete( $pages_table, array( 'post_id' => $post_id ), array( '%d' ) );
			return;
		}

		$url     = get_permalink( $post );
		$targets = array();
		$broken  = 0;

		foreach ( self::extract_links( $post->post_content ) as $link ) {
			$resolved = self::resolve( $link['href'], $url );
			if ( in_array( $resolved['status'], array( 'external', 'ignored' ), true ) ) {
				continue;
			}
			if ( 'ok' === $resolved['status'] && $resolved['target_id'] === (int) $post_id ) {
				continue;
			}
			$wpdb->insert(
				$links_table,
				array(
					'source_id'  => $post_id,
					'target_id'  => $resolved['target_id'],
					'target_url' => $resolved['url'],
					'anchor'     => mb_substr( $link['anchor'], 0, 250 ),
					'status'     => $resolved['status'],
				),
				array( '%d', '%d', '%s', '%s', '%s' )
			);
			if ( 'ok' === $resolved['status'] && $resolved['target_id'] > 0 ) {
				$targets[ $resolved['target_id'] ] = true;
			} elseif ( 'broken' === $resolved['status'] ) {
				$broken++;
			}
		}

		$wpdb->replace(
			$pages_table,
			array(
				'post_id'       => $post_id,
				'post_type'     => $post->post_type,
				'url'           => $url,
				'title'         => wp_strip_all_tags( $post->post_title ),
				'word_count'    => Content_Editor::word_count( $post->post_content ),
				'inbound'       => 0,
				'outbound'      => count( $targets ),
				'broken_out'    => $broken,
				'has_meta_desc' => '' !== Seo_Meta::get_description( $post_id ) ? 1 : 0,
				'modified_gmt'  => $post->post_modified_gmt,
				'published_gmt' => $post->post_date_gmt,
				'score'         => 100,
				'issues'        => '[]',
				'scanned_at'    => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Recomputes graph-wide numbers: inbound counts, outbound counts, broken links,
	 * click depth and page scores.
	 */
	public static function refresh() {
		global $wpdb;
		$links = Installer::table( 'links' );
		$pages = Installer::table( 'pages' );

		// Links to content that is no longer published are broken now.
		$live = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM %i', $pages ) ) );
		$live = array_flip( $live );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, source_id, target_id, status FROM %i WHERE status IN ('ok','broken')", $links ) );

		$inbound  = array();
		$outbound = array();
		$broken   = array();
		foreach ( $rows as $row ) {
			$source = (int) $row->source_id;
			$target = (int) $row->target_id;
			if ( 'ok' === $row->status && $target > 0 && ! isset( $live[ $target ] ) ) {
				$wpdb->update( $links, array( 'status' => 'broken' ), array( 'id' => $row->id ) );
				$row->status = 'broken';
			}
			if ( 'ok' === $row->status && $target > 0 ) {
				$inbound[ $target ][ $source ]  = true;
				$outbound[ $source ][ $target ] = true;
			} elseif ( 'broken' === $row->status ) {
				$broken[ $source ] = ( isset( $broken[ $source ] ) ? $broken[ $source ] : 0 ) + 1;
			}
		}

		$depths = self::compute_depths();

		foreach ( array_keys( $live ) as $post_id ) {
			$wpdb->update(
				$pages,
				array(
					'inbound'    => isset( $inbound[ $post_id ] ) ? count( $inbound[ $post_id ] ) : 0,
					'outbound'   => isset( $outbound[ $post_id ] ) ? count( $outbound[ $post_id ] ) : 0,
					'broken_out' => isset( $broken[ $post_id ] ) ? $broken[ $post_id ] : 0,
					'depth'      => isset( $depths[ $post_id ] ) ? $depths[ $post_id ] : null,
				),
				array( 'post_id' => $post_id )
			);
		}

		Page_Analyzer::score_all();
		update_option( self::LAST_SCAN_OPTION, time(), false );
		delete_option( self::DIRTY_OPTION );
	}

	/**
	 * Refreshes graph numbers if content changed since the last refresh.
	 */
	public static function ensure_fresh() {
		if ( get_option( self::DIRTY_OPTION ) && self::has_scanned() ) {
			self::refresh();
		}
	}

	public static function on_save_post( $post_id ) {
		if ( self::$suspended || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! self::has_scanned() ) {
			return;
		}
		self::flush_path_map();
		self::index_post( (int) $post_id );
		update_option( self::DIRTY_OPTION, 1, false );
	}

	/**
	 * @return array<int, array{href:string, anchor:string}>
	 */
	public static function extract_links( $html ) {
		$links = array();
		if ( preg_match_all( '/<a\s[^>]*?href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$links[] = array(
					'href'   => $m[2],
					'anchor' => trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $m[3] ) ) ),
				);
			}
		}
		return $links;
	}

	/**
	 * Classifies a link and maps it to a post when it points inside the site.
	 *
	 * @return array{status:string, target_id:int, url:string}
	 */
	public static function resolve( $href, $base_url ) {
		$href    = trim( html_entity_decode( $href, ENT_QUOTES, 'UTF-8' ) );
		$ignored = array(
			'status'    => 'ignored',
			'target_id' => 0,
			'url'       => $href,
		);
		if ( '' === $href || '#' === $href[0] || preg_match( '#^(mailto|tel|javascript|sms|data):#i', $href ) ) {
			return $ignored;
		}

		$absolute = \WP_Http::make_absolute_url( $href, $base_url );
		$parts    = wp_parse_url( $absolute );
		if ( ! $parts || empty( $parts['host'] ) ) {
			return $ignored;
		}
		if ( ! self::is_own_host( $parts['host'] ) ) {
			return array(
				'status'    => 'external',
				'target_id' => 0,
				'url'       => $absolute,
			);
		}

		$path = isset( $parts['path'] ) ? $parts['path'] : '/';
		if ( preg_match( '#^/(wp-content|wp-admin|wp-includes|wp-json)/#', $path ) || preg_match( '#\.(jpe?g|png|gif|webp|avif|svg|pdf|zip|mp4|mp3|css|js|xml|txt)$#i', $path ) ) {
			return $ignored;
		}

		if ( isset( $parts['query'] ) && preg_match( '/(?:^|&)(?:p|page_id)=(\d+)/', $parts['query'], $qm ) ) {
			$id = (int) $qm[1];
			return 'publish' === get_post_status( $id )
				? array( 'status' => 'ok', 'target_id' => $id, 'url' => self::normalize_path( $path ) )
				: array( 'status' => 'broken', 'target_id' => 0, 'url' => $absolute );
		}

		$key = self::normalize_path( $path );
		$map = self::path_map();
		if ( isset( $map[ $key ] ) ) {
			$node = $map[ $key ];
			if ( is_int( $node ) ) {
				return array( 'status' => 'ok', 'target_id' => $node, 'url' => $key );
			}
			return array( 'status' => 'archive', 'target_id' => 0, 'url' => $key );
		}

		$id = url_to_postid( $absolute );
		if ( $id && 'publish' === get_post_status( $id ) && in_array( get_post_type( $id ), self::post_types(), true ) ) {
			return array( 'status' => 'ok', 'target_id' => (int) $id, 'url' => $key );
		}

		if ( preg_match( '#^/(author|search|feed|comments|page)(/|$)|^/\d{4}(/\d{2}){0,2}$|/feed$|/page/\d+$#', $key ) ) {
			return array( 'status' => 'archive', 'target_id' => 0, 'url' => $key );
		}

		return array( 'status' => 'broken', 'target_id' => 0, 'url' => $absolute );
	}

	public static function normalize_path( $path ) {
		$path = mb_strtolower( rawurldecode( (string) $path ) );
		$path = '/' . trim( $path, '/' );
		return '/' === $path ? '/' : $path;
	}

	private static function is_own_host( $host ) {
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		$trim = function ( $value ) {
			return preg_replace( '/^www\./i', '', strtolower( (string) $value ) );
		};
		return $trim( $host ) === $trim( $home );
	}

	public static function flush_path_map() {
		self::$path_map = null;
		delete_transient( 'sitegraph_path_map' );
	}

	/**
	 * Normalized URL path => post ID (int) or archive node ("term:taxonomy:id" / "home").
	 *
	 * @return array<string, int|string>
	 */
	public static function path_map() {
		if ( null !== self::$path_map ) {
			return self::$path_map;
		}
		$cached = get_transient( 'sitegraph_path_map' );
		if ( is_array( $cached ) ) {
			self::$path_map = $cached;
			return $cached;
		}

		$map = array( '/' => 'home' );
		$ids = get_posts(
			array(
				'post_type'        => self::post_types(),
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
			)
		);
		foreach ( $ids as $id ) {
			$map[ self::normalize_path( wp_parse_url( get_permalink( $id ), PHP_URL_PATH ) ) ] = (int) $id;
		}
		$front = self::front_page_id();
		if ( $front ) {
			$map['/'] = $front;
		}

		foreach ( get_taxonomies( array( 'public' => true ) ) as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'number'     => 5000,
				)
			);
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$link = get_term_link( $term );
				if ( ! is_wp_error( $link ) ) {
					$map[ self::normalize_path( wp_parse_url( $link, PHP_URL_PATH ) ) ] = 'term:' . $taxonomy . ':' . $term->term_id;
				}
			}
		}

		set_transient( 'sitegraph_path_map', $map, 10 * MINUTE_IN_SECONDS );
		self::$path_map = $map;
		return $map;
	}

	public static function front_page_id() {
		if ( 'page' === get_option( 'show_on_front' ) ) {
			$id = (int) get_option( 'page_on_front' );
			return 'publish' === get_post_status( $id ) ? $id : 0;
		}
		return 0;
	}

	/**
	 * Clicks needed to reach each post from the home page, following content links,
	 * navigation menus and paginated archives (blog index and term archives).
	 *
	 * @return array<int, int> post ID => depth.
	 */
	public static function compute_depths() {
		global $wpdb;
		$links = Installer::table( 'links' );
		$map   = self::path_map();
		$adj   = array();

		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT source_id, target_id, target_url, status FROM %i WHERE status IN ('ok','archive')", $links ) ) as $row ) {
			if ( 'ok' === $row->status ) {
				$adj[ (string) (int) $row->source_id ][] = array( (string) (int) $row->target_id, 1 );
			} elseif ( isset( $map[ $row->target_url ] ) && is_string( $map[ $row->target_url ] ) && 0 === strpos( $map[ $row->target_url ], 'term:' ) ) {
				$adj[ (string) (int) $row->source_id ][] = array( $map[ $row->target_url ], 1 );
			}
		}

		$per_page = max( 1, (int) get_option( 'posts_per_page', 10 ) );
		$front    = self::front_page_id();
		if ( $front ) {
			$adj['home'][] = array( (string) $front, 0 );
			$blog          = (int) get_option( 'page_for_posts' );
			if ( $blog ) {
				$adj[ (string) $blog ][] = array( 'blog', 0 );
			}
		} else {
			$adj['home'][] = array( 'blog', 0 );
		}
		foreach ( self::menu_targets() as $node ) {
			$adj['home'][] = array( (string) $node, 1 );
		}

		// Themes print term links (usually categories) on every single post.
		$template_taxonomies = apply_filters( 'sitegraph_template_term_links', array( 'category' ) );
		if ( $template_taxonomies ) {
			$placeholders = implode( ',', array_fill( 0, count( $template_taxonomies ), '%s' ) );
			$rows = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the replacements are passed as one array.
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a list of %s placeholders, one per taxonomy.
					"SELECT tr.object_id, tt.taxonomy, tt.term_id FROM %i tr INNER JOIN %i tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy IN ($placeholders)",
					array_merge( array( $wpdb->term_relationships, $wpdb->term_taxonomy ), $template_taxonomies )
				)
			);
			foreach ( $rows as $row ) {
				$adj[ (string) (int) $row->object_id ][] = array( 'term:' . $row->taxonomy . ':' . (int) $row->term_id, 1 );
			}
		}

		$dist  = array( 'home' => 0 );
		$done  = array();
		$queue = new \SplPriorityQueue();
		$queue->insert( 'home', 0 );

		while ( ! $queue->isEmpty() ) {
			$node = (string) $queue->extract();
			if ( isset( $done[ $node ] ) ) {
				continue;
			}
			$done[ $node ] = true;
			$base          = $dist[ $node ];
			$next = isset( $adj[ $node ] ) ? $adj[ $node ] : array();

			if ( 'blog' === $node ) {
				$next = array_merge( $next, self::listing_edges( 'post', null, $per_page ) );
			} elseif ( 0 === strpos( $node, 'term:' ) ) {
				$parts = explode( ':', $node );
				$next  = array_merge( $next, self::listing_edges( null, array( $parts[1], (int) $parts[2] ), $per_page ) );
			}

			foreach ( $next as $edge ) {
				list( $to, $weight ) = $edge;
				$candidate           = $base + $weight;
				if ( ! isset( $dist[ $to ] ) || $candidate < $dist[ $to ] ) {
					$dist[ $to ] = $candidate;
					$queue->insert( $to, -$candidate );
				}
			}
		}

		$depths = array();
		foreach ( $dist as $node => $depth ) {
			if ( is_numeric( $node ) ) {
				$depths[ (int) $node ] = (int) $depth;
			}
		}
		if ( $front ) {
			$depths[ $front ] = 0;
		}
		return $depths;
	}

	/**
	 * Edges from a paginated listing to the posts on it. A post on page N of the
	 * listing is N clicks away from the listing itself.
	 */
	private static function listing_edges( $post_type, $term, $per_page ) {
		$args = array(
			'post_type'        => $post_type ? $post_type : self::post_types(),
			'post_status'      => 'publish',
			'numberposts'      => -1,
			'fields'           => 'ids',
			'orderby'          => 'date',
			'order'            => 'DESC',
		);
		if ( $term ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => $term[0],
					'terms'    => $term[1],
				),
			);
		}
		$edges = array();
		foreach ( get_posts( $args ) as $index => $id ) {
			$edges[] = array( (string) (int) $id, 1 + (int) floor( $index / $per_page ) );
		}
		return $edges;
	}

	/**
	 * Nodes linked from site-wide navigation (classic menus, block navigation,
	 * or the page list the navigation block falls back to).
	 *
	 * @return array<int, int|string>
	 */
	public static function menu_targets() {
		$targets = array();
		$home    = home_url( '/' );

		foreach ( array_unique( array_values( get_nav_menu_locations() ) ) as $menu_id ) {
			$items = wp_get_nav_menu_items( $menu_id );
			foreach ( (array) $items as $item ) {
				if ( 'post_type' === $item->type ) {
					$targets[] = (int) $item->object_id;
				} elseif ( 'taxonomy' === $item->type ) {
					$targets[] = 'term:' . $item->object . ':' . (int) $item->object_id;
				} else {
					$node = self::url_node( $item->url, $home );
					if ( null !== $node ) {
						$targets[] = $node;
					}
				}
			}
		}

		$navigations = get_posts(
			array(
				'post_type'        => 'wp_navigation',
				'post_status'      => 'publish',
				'numberposts'      => 20,
			)
		);
		foreach ( $navigations as $navigation ) {
			self::collect_navigation_blocks( parse_blocks( $navigation->post_content ), $targets, $home );
		}

		if ( empty( $targets ) && function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			$targets = self::top_level_pages();
		}

		return array_values( array_unique( $targets, SORT_REGULAR ) );
	}

	private static function collect_navigation_blocks( $blocks, &$targets, $home ) {
		foreach ( $blocks as $block ) {
			$name  = isset( $block['blockName'] ) ? $block['blockName'] : '';
			$attrs = isset( $block['attrs'] ) ? $block['attrs'] : array();
			if ( in_array( $name, array( 'core/navigation-link', 'core/navigation-submenu' ), true ) ) {
				$kind = isset( $attrs['kind'] ) ? $attrs['kind'] : '';
				$id   = isset( $attrs['id'] ) ? (int) $attrs['id'] : 0;
				if ( 'post-type' === $kind && $id ) {
					$targets[] = $id;
				} elseif ( 'taxonomy' === $kind && $id ) {
					$taxonomy  = ( isset( $attrs['type'] ) && 'tag' === $attrs['type'] ) ? 'post_tag' : ( isset( $attrs['type'] ) ? $attrs['type'] : 'category' );
					$targets[] = 'term:' . $taxonomy . ':' . $id;
				} elseif ( ! empty( $attrs['url'] ) ) {
					$node = self::url_node( $attrs['url'], $home );
					if ( null !== $node ) {
						$targets[] = $node;
					}
				}
			} elseif ( 'core/page-list' === $name ) {
				$targets = array_merge( $targets, self::top_level_pages() );
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				self::collect_navigation_blocks( $block['innerBlocks'], $targets, $home );
			}
		}
	}

	private static function url_node( $url, $base ) {
		$resolved = self::resolve( $url, $base );
		if ( 'ok' === $resolved['status'] ) {
			return $resolved['target_id'];
		}
		$map = self::path_map();
		if ( 'archive' === $resolved['status'] && isset( $map[ $resolved['url'] ] ) && is_string( $map[ $resolved['url'] ] ) && 'home' !== $map[ $resolved['url'] ] ) {
			return $map[ $resolved['url'] ];
		}
		return null;
	}

	private static function top_level_pages() {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'        => 'page',
					'post_status'      => 'publish',
					'post_parent'      => 0,
					'numberposts'      => 50,
					'fields'           => 'ids',
				)
			)
		);
	}
}
