<?php
/**
 * End-to-end tests against a real WordPress install seeded with the demo site.
 * Every check goes through the WordPress Abilities API, the same path MCP uses.
 *
 * Usage:
 *   php demo/seed-demo-site.php /path/to/wordpress --yes-wipe
 *   php tests/run-integration.php /path/to/wordpress
 *
 * @package SiteGraph
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}
$root = isset( $argv[1] ) ? rtrim( $argv[1], '/\\' ) : '';
if ( ! $root || ! file_exists( $root . '/wp-load.php' ) ) {
	fwrite( STDERR, "Usage: php tests/run-integration.php /path/to/wordpress\n" );
	exit( 1 );
}
$_SERVER['HTTP_HOST']   = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $root . '/wp-load.php';

$failures = 0;
$passes   = 0;

function check( $label, $condition, $detail = '' ) {
	global $failures, $passes;
	if ( $condition ) {
		$passes++;
		echo "  PASS  $label\n";
	} else {
		$failures++;
		echo "  FAIL  $label" . ( $detail ? " â€” $detail" : '' ) . "\n";
	}
}

function run( $ability, $input = null ) {
	$instance = wp_get_ability( $ability );
	if ( ! $instance ) {
		return new WP_Error( 'missing', "Ability $ability is not registered" );
	}
	return $instance->execute( $input );
}

function post_id( $slug ) {
	$post = get_page_by_path( $slug, OBJECT, array( 'post', 'page' ) );
	return $post ? (int) $post->ID : 0;
}

function page_codes( $slug ) {
	$report = run( 'sitegraph/get-page-report', array( 'post_id' => post_id( $slug ) ) );
	return is_wp_error( $report ) ? array() : wp_list_pluck( $report['issues'], 'code' );
}

wp_set_current_user( 1 );

echo "Registration\n";
check( 'plugin loaded', class_exists( 'SiteGraph\\Plugin' ) );
foreach ( SiteGraph\Abilities::names() as $name ) {
	check( "ability $name registered", null !== wp_get_ability( $name ) );
}

echo "Scan\n";
$scan = run( 'sitegraph/scan-site', array( 'restart' => true, 'time_budget_seconds' => 30 ) );
$guard = 0;
while ( ! is_wp_error( $scan ) && ! $scan['done'] && $guard++ < 20 ) {
	$scan = run( 'sitegraph/scan-site', array() );
}
check( 'scan completes', ! is_wp_error( $scan ) && $scan['done'], is_wp_error( $scan ) ? $scan->get_error_message() : '' );
check( 'scan covers 39 pages', ! is_wp_error( $scan ) && 39 === $scan['total'], is_wp_error( $scan ) ? '' : (string) $scan['total'] );

$overview = run( 'sitegraph/get-site-overview' );
check( 'overview without input works', ! is_wp_error( $overview ), is_wp_error( $overview ) ? $overview->get_error_message() : '' );
check( 'overview finds orphan pages', ! is_wp_error( $overview ) && $overview['orphan_pages'] >= 5, is_wp_error( $overview ) ? '' : (string) $overview['orphan_pages'] );
check( 'overview finds 3 broken links', ! is_wp_error( $overview ) && 3 === $overview['broken_links'], is_wp_error( $overview ) ? '' : (string) $overview['broken_links'] );

check( 'waterproof guide is an orphan', in_array( 'orphan', page_codes( 'how-to-waterproof-a-tent' ), true ) );
check( 'campfire tips are thin', in_array( 'thin', page_codes( 'campfire-safety-tips' ), true ) );
check( 'day hikes page has broken links', in_array( 'broken_links', page_codes( 'best-day-hikes-pacific-northwest' ), true ) );
check( 'tent lists cannibalize each other', in_array( 'cannibalization', page_codes( 'lightweight-backpacking-tents-top-picks' ), true ) );
check( 'winter sleep system is stale', in_array( 'stale', page_codes( 'winter-camping-sleep-system' ), true ) );
$topo = run( 'sitegraph/get-page-report', array( 'url' => '/how-to-read-a-topographic-map/' ) );
check( 'page report by URL works', ! is_wp_error( $topo ) && 'How to Read a Topographic Map' === $topo['title'] );
check( 'topo map is 4 clicks deep', ! is_wp_error( $topo ) && 4 === $topo['signals']['click_depth'], is_wp_error( $topo ) ? '' : var_export( $topo['signals']['click_depth'], true ) );
check( 'home page is exempt from orphan check', ! in_array( 'orphan', page_codes( 'home' ), true ) );

echo "Search Console import\n";
$rows   = SiteGraph\Search_Data::parse_csv( file_get_contents( dirname( __DIR__ ) . '/demo/search-console-pages.csv' ) );
$import = run( 'sitegraph/import-search-data', array( 'rows' => $rows, 'period' => 'Demo: last 3 months' ) );
check( 'import matches 35 pages by path', ! is_wp_error( $import ) && 35 === $import['matched'], is_wp_error( $import ) ? $import->get_error_message() : wp_json_encode( $import ) );
check( 'archive URLs are reported as unmatched', ! is_wp_error( $import ) && 2 === $import['unmatched_count'] );
check( 'low CTR detected on tent list', in_array( 'low_ctr', page_codes( 'best-lightweight-backpacking-tents' ), true ) );
check( 'striking distance detected on waterproof guide', in_array( 'striking_distance', page_codes( 'how-to-waterproof-a-tent' ), true ) );
check( 'zero-impression page flagged', in_array( 'no_search_visibility', page_codes( 'how-to-hang-a-bear-bag' ), true ) );

$weak = run( 'sitegraph/list-weak-pages', array( 'limit' => 5 ) );
check( 'weak pages listed weakest first', ! is_wp_error( $weak ) && count( $weak['pages'] ) === 5 && $weak['pages'][0]['score'] <= $weak['pages'][4]['score'] );
$orphans = run( 'sitegraph/list-weak-pages', array( 'issue' => 'orphan', 'max_score' => 100, 'limit' => 50 ) );
check( 'issue filter works', ! is_wp_error( $orphans ) && $orphans['total'] >= 5 && ! array_filter( $orphans['pages'], function ( $p ) { return ! in_array( 'orphan', wp_list_pluck( $p['issues'], 'code' ), true ); } ) );

echo "Action plan\n";
$plan = run( 'sitegraph/get-action-plan', array( 'limit' => 10 ) );
check( 'plan has tasks', ! is_wp_error( $plan ) && count( $plan['tasks'] ) >= 5 );
check( 'top task is high impact', ! is_wp_error( $plan ) && 'high' === $plan['tasks'][0]['impact'], is_wp_error( $plan ) ? '' : $plan['tasks'][0]['id'] );
check( 'orphan task present', ! is_wp_error( $plan ) && in_array( 'link_orphans', wp_list_pluck( $plan['tasks'], 'id' ), true ) );

echo "Link suggestions\n";
$target = post_id( 'how-to-waterproof-a-tent' );
$sugg   = run( 'sitegraph/suggest-internal-links', array( 'target_post_id' => $target ) );
$sources = is_wp_error( $sugg ) ? array() : wp_list_pluck( $sugg['suggestions'], 'source_post_id' );
check( 'suggests the tent guide as a source', in_array( post_id( 'complete-guide-backpacking-tents' ), $sources, true ), wp_json_encode( $sources ) );
check( 'suggestions carry ready operations', ! empty( $sugg['suggestions'][0]['operation']['type'] ) && 'add_internal_link' === $sugg['suggestions'][0]['operation']['type'] );
$ratings = run( 'sitegraph/suggest-internal-links', array( 'target_post_id' => post_id( 'ultralight-cook-systems-compared' ) ) );
check( 'plural/singular anchors are found', ! is_wp_error( $ratings ) && count( $ratings['suggestions'] ) >= 1 );

echo "Changesets: propose â†’ apply â†’ undo â†’ redo\n";
$guide_id  = post_id( 'complete-guide-backpacking-tents' );
$list_id   = post_id( 'best-lightweight-backpacking-tents' );
$original  = get_post_field( 'post_content', $guide_id, 'raw' );
$list_orig = get_post_field( 'post_content', $list_id, 'raw' );
$proposal  = run(
	'sitegraph/propose-changeset',
	array(
		'title'      => 'Link the waterproofing guide',
		'rationale'  => 'It is an orphan with 6,400 impressions at position 11.8.',
		'operations' => array(
			array( 'type' => 'add_internal_link', 'post_id' => $guide_id, 'target_post_id' => $target, 'anchor_text' => 'waterproof a tent' ),
			array( 'type' => 'add_internal_link', 'post_id' => $list_id, 'target_post_id' => $target, 'anchor_text' => 'waterproof a tent' ),
			array( 'type' => 'set_meta_description', 'post_id' => $target, 'value' => 'Fix a leaking tent at home: seam sealing, DWR and rainfly care in one afternoon.' ),
		),
	)
);
check( 'proposal created', ! is_wp_error( $proposal ) && 'proposed' === $proposal['status'], is_wp_error( $proposal ) ? $proposal->get_error_message() : '' );
check( 'proposal does not touch content', get_post_field( 'post_content', $guide_id, 'raw' ) === $original );
check( 'proposal previews the new link', ! is_wp_error( $proposal ) && false !== strpos( $proposal['operations'][0]['preview']['after'], 'how-to-waterproof-a-tent' ) );
$cs = is_wp_error( $proposal ) ? 0 : $proposal['id'];

$bad = run( 'sitegraph/propose-changeset', array( 'title' => 'Bad', 'operations' => array( array( 'type' => 'add_internal_link', 'post_id' => $guide_id, 'target_post_id' => $target, 'anchor_text' => 'phrase that does not exist anywhere' ) ) ) );
check( 'missing anchor is rejected', is_wp_error( $bad ) && 'sitegraph_invalid_operations' === $bad->get_error_code() );

$applied = run( 'sitegraph/apply-changeset', array( 'changeset_id' => $cs ) );
check( 'apply succeeds', ! is_wp_error( $applied ) && 'applied' === $applied['status'], is_wp_error( $applied ) ? $applied->get_error_message() : '' );
$after_content = get_post_field( 'post_content', $guide_id, 'raw' );
check( 'link is in the content', false !== strpos( $after_content, get_permalink( $target ) ) );
check( 'meta description written', '' !== get_post_meta( $target, '_sitegraph_meta_description', true ) );
$report = run( 'sitegraph/get-page-report', array( 'post_id' => $target ) );
check( 'target is no longer an orphan', ! is_wp_error( $report ) && 2 === $report['signals']['inbound_links'] && ! in_array( 'orphan', wp_list_pluck( $report['issues'], 'code' ), true ) );
check( 'baseline recorded for impact', ! is_wp_error( $applied ) && ! empty( $applied['impact'] ) );
$again = run( 'sitegraph/apply-changeset', array( 'changeset_id' => $cs ) );
check( 'cannot apply twice', is_wp_error( $again ) && 'sitegraph_bad_status' === $again->get_error_code() );

$undone = run( 'sitegraph/undo-changeset', array( 'changeset_id' => $cs ) );
check( 'undo succeeds', ! is_wp_error( $undone ) && 'undone' === $undone['status'], is_wp_error( $undone ) ? $undone->get_error_message() : '' );
check( 'undo restores content byte for byte', get_post_field( 'post_content', $guide_id, 'raw' ) === $original && get_post_field( 'post_content', $list_id, 'raw' ) === $list_orig );
check( 'undo removes the meta description', '' === get_post_meta( $target, '_sitegraph_meta_description', true ) || 'Stop leaks for good: seam sealing, DWR treatment and rainfly care, step by step.' === get_post_meta( $target, '_sitegraph_meta_description', true ) );
$report = run( 'sitegraph/get-page-report', array( 'post_id' => $target ) );
check( 'target is an orphan again', ! is_wp_error( $report ) && 0 === $report['signals']['inbound_links'] );

$redone = run( 'sitegraph/redo-changeset', array( 'changeset_id' => $cs ) );
check( 'redo succeeds', ! is_wp_error( $redone ) && 'applied' === $redone['status'], is_wp_error( $redone ) ? $redone->get_error_message() : '' );
check( 'redo restores the change', get_post_field( 'post_content', $guide_id, 'raw' ) === $after_content );
check( 'history records every step', ! is_wp_error( $redone ) && array( 'proposed', 'applied', 'undone', 'redone' ) === wp_list_pluck( $redone['history'], 'action' ) );

echo "Conflict protection\n";
wp_update_post( wp_slash( array( 'ID' => $guide_id, 'post_content' => $after_content . "\n<!-- wp:paragraph -->\n<p>Edited by a person.</p>\n<!-- /wp:paragraph -->" ) ) );
$conflict = run( 'sitegraph/undo-changeset', array( 'changeset_id' => $cs ) );
check( 'undo refuses after a manual edit', is_wp_error( $conflict ) && 'sitegraph_conflict' === $conflict->get_error_code() );
check( 'manual edit is untouched', false !== strpos( get_post_field( 'post_content', $guide_id, 'raw' ), 'Edited by a person.' ) );
$forced = run( 'sitegraph/undo-changeset', array( 'changeset_id' => $cs, 'force' => true ) );
check( 'forced undo restores the original', ! is_wp_error( $forced ) && get_post_field( 'post_content', $guide_id, 'raw' ) === $original );

echo "Stacked changesets\n";
$first  = run( 'sitegraph/propose-changeset', array( 'title' => 'First', 'operations' => array( array( 'type' => 'set_seo_title', 'post_id' => $list_id, 'value' => 'Best Lightweight Tents (2026): 12 Tested on 400 Miles' ) ) ) );
run( 'sitegraph/apply-changeset', array( 'changeset_id' => $first['id'] ) );
$second = run( 'sitegraph/propose-changeset', array( 'title' => 'Second', 'operations' => array( array( 'type' => 'set_seo_title', 'post_id' => $list_id, 'value' => '12 Lightweight Backpacking Tents We Actually Recommend' ) ) ) );
run( 'sitegraph/apply-changeset', array( 'changeset_id' => $second['id'] ) );
$blocked = run( 'sitegraph/undo-changeset', array( 'changeset_id' => $first['id'] ) );
check( 'older changeset cannot be undone under a newer one', is_wp_error( $blocked ) && false !== strpos( $blocked->get_error_message(), '#' . $second['id'] ) );
run( 'sitegraph/undo-changeset', array( 'changeset_id' => $second['id'] ) );
$ok = run( 'sitegraph/undo-changeset', array( 'changeset_id' => $first['id'] ) );
check( 'undo in reverse order works', ! is_wp_error( $ok ) && '' === get_post_meta( $list_id, '_sitegraph_seo_title', true ) );

echo "Discard\n";
$temp      = run( 'sitegraph/propose-changeset', array( 'title' => 'Temp', 'operations' => array( array( 'type' => 'set_post_title', 'post_id' => $list_id, 'value' => 'Temporary title' ) ) ) );
$discarded = run( 'sitegraph/discard-changeset', array( 'changeset_id' => $temp['id'] ) );
check( 'discard works', ! is_wp_error( $discarded ) && 'discarded' === $discarded['status'] );
$late = run( 'sitegraph/apply-changeset', array( 'changeset_id' => $temp['id'] ) );
check( 'discarded changeset cannot be applied', is_wp_error( $late ) );
$listed = run( 'sitegraph/list-changesets', array( 'limit' => 10 ) );
check( 'list-changesets returns history', ! is_wp_error( $listed ) && count( $listed['changesets'] ) >= 4 );

echo "Permissions\n";
$subscriber = get_user_by( 'login', 'sg_subscriber' );
if ( ! $subscriber ) {
	$subscriber = get_user_by( 'id', wp_insert_user( array( 'user_login' => 'sg_subscriber', 'user_pass' => wp_generate_password( 24 ), 'role' => 'subscriber' ) ) );
}
wp_set_current_user( $subscriber->ID );
$denied = run( 'sitegraph/get-site-overview' );
check( 'subscribers are denied', is_wp_error( $denied ) );
$denied = run( 'sitegraph/apply-changeset', array( 'changeset_id' => $cs ) );
check( 'subscribers cannot apply changes', is_wp_error( $denied ) );
wp_set_current_user( 1 );

// Leave the demo in its seeded state for screenshots.
foreach ( array( $cs ) as $id ) {
	$state = SiteGraph\Changesets::get( $id );
	if ( ! is_wp_error( $state ) && 'applied' === $state['status'] ) {
		SiteGraph\Changesets::undo( $id, true );
	}
}

echo "\n$passes passed, $failures failed\n";
exit( $failures ? 1 : 0 );
