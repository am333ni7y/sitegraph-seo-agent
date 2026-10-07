<?php
/**
 * Puts the seeded demo site into the state shown in the README screenshots:
 * scanned, Search Console data imported, and a few changesets in different states.
 * Runs everything through the Abilities API, exactly as an agent would.
 *
 * Usage: php demo/demo-changesets.php /path/to/wordpress
 *
 * @package SiteGraph
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}
$root = isset( $argv[1] ) ? rtrim( $argv[1], '/\\' ) : '';
if ( ! $root || ! file_exists( $root . '/wp-load.php' ) ) {
	fwrite( STDERR, "Usage: php demo/demo-changesets.php /path/to/wordpress\n" );
	exit( 1 );
}
$_SERVER['HTTP_HOST']   = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $root . '/wp-load.php';
wp_set_current_user( 1 );

function demo_run( $ability, $input = null ) {
	$result = wp_get_ability( $ability )->execute( $input );
	if ( is_wp_error( $result ) ) {
		fwrite( STDERR, "$ability failed: " . $result->get_error_message() . "\n" );
		exit( 1 );
	}
	return $result;
}

function demo_id( $slug ) {
	return (int) get_page_by_path( $slug, OBJECT, array( 'post', 'page' ) )->ID;
}

do {
	$scan = demo_run( 'sitegraph/scan-site', array( 'time_budget_seconds' => 30 ) );
} while ( ! $scan['done'] );
echo $scan['message'] . "\n";

$rows   = SiteGraph\Search_Data::parse_csv( file_get_contents( __DIR__ . '/search-console-pages.csv' ) );
$import = demo_run( 'sitegraph/import-search-data', array( 'rows' => $rows, 'period' => 'Jul 1 – Sep 30, 2026' ) );
echo "Imported search data for {$import['matched']} pages\n";

// 1. Applied: link the orphan waterproofing guide from the pages that mention it.
$target = demo_id( 'how-to-waterproof-a-tent' );
$ops    = array();
foreach ( demo_run( 'sitegraph/suggest-internal-links', array( 'target_post_id' => $target, 'limit' => 3 ) )['suggestions'] as $suggestion ) {
	$ops[] = $suggestion['operation'];
}
$links = demo_run(
	'sitegraph/propose-changeset',
	array(
		'title'      => 'Link the orphan waterproofing guide from 2 related pages',
		'rationale'  => 'How to Waterproof a Tent has 6,400 impressions at position 11.8 but no internal links. Two strong tent pages already mention the topic.',
		'operations' => $ops,
	)
);
demo_run( 'sitegraph/apply-changeset', array( 'changeset_id' => $links['id'] ) );

// 2. Applied: fix the broken links on the day hikes page.
$hikes   = demo_id( 'best-day-hikes-pacific-northwest' );
$content = get_post_field( 'post_content', $hikes, 'raw' );
preg_match( '#<a href="[^"]*best-day-hikes-2023-update/">2023 update</a>#', $content, $first );
preg_match( '#<a href="[^"]*trail-maps/old-loop-trail/">old loop trail map</a>#', $content, $second );
$broken = demo_run(
	'sitegraph/propose-changeset',
	array(
		'title'      => 'Fix 2 broken links on the day hikes page',
		'rationale'  => 'Both links point to pages that were removed. Point readers to the live hiking guide instead.',
		'operations' => array(
			array( 'type' => 'replace_text', 'post_id' => $hikes, 'find' => $first[0], 'replace' => '<a href="' . get_permalink( demo_id( 'hiking-guide' ) ) . '">hiking guide</a>' ),
			array( 'type' => 'replace_text', 'post_id' => $hikes, 'find' => $second[0], 'replace' => 'trail maps' ),
		),
	)
);
demo_run( 'sitegraph/apply-changeset', array( 'changeset_id' => $broken['id'] ) );

// 3. Applied, then undone: a snippet rewrite the editor decided against.
$list    = demo_id( 'best-lightweight-backpacking-tents' );
$snippet = demo_run(
	'sitegraph/propose-changeset',
	array(
		'title'      => 'Rewrite the search snippet for Best Lightweight Backpacking Tents',
		'rationale'  => 'CTR is 1.4% at position 3.2, where results usually get about 10%.',
		'operations' => array(
			array( 'type' => 'set_seo_title', 'post_id' => $list, 'value' => '12 Lightweight Tents Tested on 400 Trail Miles (2026)' ),
			array( 'type' => 'set_meta_description', 'post_id' => $list, 'value' => 'We pitched 12 lightweight backpacking tents in rain, wind and on rock. See which ones are worth the weight, from ultralight shelters to roomy two-person domes.' ),
		),
	)
);
demo_run( 'sitegraph/apply-changeset', array( 'changeset_id' => $snippet['id'] ) );
demo_run( 'sitegraph/undo-changeset', array( 'changeset_id' => $snippet['id'] ) );

// 4. Proposed and waiting for review: links to the temperature ratings explainer.
$ratings = demo_id( 'sleeping-bag-temperature-ratings-explained' );
$ops     = array();
foreach ( demo_run( 'sitegraph/suggest-internal-links', array( 'target_post_id' => $ratings, 'limit' => 3 ) )['suggestions'] as $suggestion ) {
	$ops[] = $suggestion['operation'];
}
$pending = demo_run(
	'sitegraph/propose-changeset',
	array(
		'title'      => 'Link 3 sleep guides to the temperature ratings explainer',
		'rationale'  => 'The explainer ranks at 9.6 with 8,800 impressions and has no internal links. Three sleep guides already use the phrase.',
		'operations' => $ops,
	)
);

echo "Changesets: #{$links['id']} applied, #{$broken['id']} applied, #{$snippet['id']} undone, #{$pending['id']} proposed\n";
