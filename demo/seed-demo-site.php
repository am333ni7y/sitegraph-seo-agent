<?php
/**
 * Seeds a local WordPress site with the "Northwind Outdoor Journal" demo:
 * 32 posts and 7 pages with realistic SEO problems (orphans, thin content,
 * broken links, cannibalization, low CTR, buried pages).
 *
 * Usage (local or development sites only — it deletes existing posts and pages):
 *   php demo/seed-demo-site.php /path/to/wordpress --yes-wipe
 *
 * Then import demo/search-console-pages.csv on the SiteGraph SEO screen, or let
 * tests/run-integration.php do it.
 *
 * @package SiteGraph
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}
$root = isset( $argv[1] ) ? rtrim( $argv[1], '/\\' ) : '';
if ( ! $root || ! file_exists( $root . '/wp-load.php' ) || ! in_array( '--yes-wipe', $argv, true ) ) {
	fwrite( STDERR, "Usage: php seed-demo-site.php /path/to/wordpress --yes-wipe\n" );
	exit( 1 );
}
$_SERVER['HTTP_HOST']   = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/post.php';

if ( ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
	fwrite( STDERR, "Refusing to run: WP_ENVIRONMENT_TYPE must be 'local' or 'development'.\n" );
	exit( 1 );
}

wp_set_current_user( 1 );
mt_srand( 42 );

// ---------------------------------------------------------------- wipe
foreach ( get_posts( array( 'post_type' => array( 'post', 'page', 'wp_navigation' ), 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) {
	wp_delete_post( $id, true );
}

update_option( 'blogname', 'Northwind Outdoor Journal' );
update_option( 'blogdescription', 'Gear reviews, trail guides and camping know-how' );
update_option( 'permalink_structure', '/%postname%/' );
update_option( 'posts_per_page', 10 );

// ---------------------------------------------------------------- categories
$categories = array(
	'tents'    => 'Tents & Shelter',
	'sleep'    => 'Sleep Systems',
	'kitchen'  => 'Camp Kitchen',
	'hiking'   => 'Hiking & Trails',
	'footwear' => 'Footwear',
	'skills'   => 'Skills & Safety',
);
$cat_ids = array();
foreach ( $categories as $slug => $name ) {
	$term = term_exists( $slug, 'category' );
	if ( ! $term ) {
		$term = wp_insert_term( $name, 'category', array( 'slug' => $slug ) );
	}
	$cat_ids[ $slug ] = (int) $term['term_id'];
}

// ---------------------------------------------------------------- copy helpers
$filler = array(
	'Weight matters, but so does how a piece of gear behaves after a week of hard use.',
	'We tested every option on the same routes so the differences come from the gear, not the weather.',
	'Most readers will be happy with a mid-range option; the premium models mostly save grams.',
	'If you are new to backpacking, start with what you already own and upgrade the item that annoys you most.',
	'Pack volume is easy to overlook until you try to fit a week of food into a small bag.',
	'A short shakedown trip close to home is the cheapest way to find problems before a big trip.',
	'Durability is hard to judge in a store, so read the warranty and look at how seams are finished.',
	'Cold, wet conditions expose weak points faster than anything else, which is why we test in the shoulder seasons.',
	'Price is not a reliable signal of quality in this category; some of the best performers were mid-priced.',
	'Think about how you will use it on the third day of a trip, when you are tired and it is getting dark.',
	'Simple designs tend to fail less and are easier to repair in the field.',
	'Always check the packed size as well as the weight, because bulk is what fills your pack.',
	'Our testers range from weekend campers to thru-hikers, and their priorities rarely match.',
	'Small details like zipper pulls and stuff sacks make a bigger difference than spec sheets suggest.',
	'Weather forecasts in the mountains change quickly, so plan for the conditions you might get, not the ones you expect.',
	'Practice at home first, so you are not reading instructions by headlamp in the rain.',
	'Many problems people blame on gear are really fit problems, so try things on with the layers you will wear.',
	'Rental programs are a good way to try expensive gear before you commit.',
	'Keep a short list of what worked and what did not after every trip; it makes the next purchase easier.',
	'Local outfitters often know the conditions on nearby trails better than any review.',
	'Moisture management matters as much as raw warmth when the temperature drops below freezing.',
	'Spending a few minutes on maintenance after each trip doubles the life of most outdoor gear.',
	'A lighter pack makes long days more enjoyable and reduces strain on knees and feet.',
	'Look for gear with replaceable parts, because the cheapest repair is the one you can do yourself.',
	'The right choice depends on where you camp, how far you walk, and how much comfort you want at night.',
	'Testing in real conditions consistently surfaced issues that lab numbers did not predict.',
	'When in doubt, choose the option that is easier to use with cold hands and gloves on.',
	'Storage between trips affects performance, so keep insulation lofted and fabrics clean and dry.',
);

/**
 * Builds block markup: specific sentences first, then filler until the target word count.
 * [[slug|anchor]] becomes a link once all posts exist.
 */
function northwind_body( $sections, $words, $filler ) {
	$html  = '';
	$count = 0;
	foreach ( $sections as $section ) {
		if ( isset( $section['h'] ) ) {
			$html .= "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . $section['h'] . "</h2>\n<!-- /wp:heading -->\n\n";
		}
		$text   = $section['p'];
		$count += str_word_count( wp_strip_all_tags( preg_replace( '/\[\[[^|]+\|([^\]]+)\]\]/', '$1', $text ) ) );
		$html  .= "<!-- wp:paragraph -->\n<p>" . $text . "</p>\n<!-- /wp:paragraph -->\n\n";
	}
	$headings = array( 'What we look for', 'How we tested', 'Common mistakes', 'Our recommendation', 'Care and maintenance', 'Frequently asked questions' );
	$h        = 0;
	while ( $count < $words ) {
		if ( 0 === $h % 2 && isset( $headings[ $h / 2 ] ) ) {
			$html .= "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . $headings[ $h / 2 ] . "</h2>\n<!-- /wp:heading -->\n\n";
		}
		$h++;
		$sentences = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$sentences[] = $filler[ mt_rand( 0, count( $filler ) - 1 ) ];
		}
		$paragraph = implode( ' ', $sentences );
		$count    += str_word_count( $paragraph );
		$html     .= "<!-- wp:paragraph -->\n<p>" . $paragraph . "</p>\n<!-- /wp:paragraph -->\n\n";
	}
	return $html;
}

// ---------------------------------------------------------------- content plan
// slug => [title, category, target words, sections, date, modified?, meta description?]
$posts = array(
	'complete-guide-backpacking-tents'       => array( 'The Complete Guide to Backpacking Tents', 'tents', 1600, array(
		array( 'p' => 'Choosing a backpacking tent comes down to weight, weather protection and how much space you need at the end of a long day. This guide walks through every decision, from single-wall shelters to freestanding domes, and links to our hands-on tests.' ),
		array( 'h' => 'Freestanding or trekking-pole tents?', 'p' => 'Freestanding tents pitch anywhere, including rock slabs and tent platforms, while trekking-pole shelters save weight by using the poles you already carry. Our list of the [[best-lightweight-backpacking-tents|best lightweight backpacking tents]] covers both styles.' ),
		array( 'h' => 'Weather protection', 'p' => 'Even the best fabric eventually wets out. If you need to waterproof a tent that has started to leak, seam sealing and a fresh DWR coating usually bring it back. A good rainfly, taut guylines and a sensible campsite matter more than the hydrostatic head number on the box. In exposed places, learn [[how-to-pitch-a-tent-in-wind|how to pitch a tent in strong wind]] before you need to.' ),
		array( 'h' => 'Floor protection', 'p' => 'Whether you need a ground sheet depends on the floor fabric and the terrain. We tested this in detail in [[tent-footprint-do-you-need-one|do you really need a tent footprint]].' ),
		array( 'h' => 'Sleeping comfort', 'p' => 'A tent is only half of a good night. Pair it with a pad that suits the season; our [[sleeping-pads-r-value-guide|sleeping pad R-value guide]] explains what the numbers mean.' ),
	), '2024-03-14', null, 'Everything you need to choose a backpacking tent: weight, weather protection, floor space and the trade-offs that matter on the trail.' ),
	'best-lightweight-backpacking-tents'     => array( 'Best Lightweight Backpacking Tents of 2026', 'tents', 1400, array(
		array( 'p' => 'We carried twelve lightweight backpacking tents on more than 400 trail miles to find the ones worth their weight. Every tent was pitched in rain, wind and on rocky ground.' ),
		array( 'h' => 'How we chose', 'p' => 'If you are just starting out, read [[complete-guide-backpacking-tents|the complete guide to backpacking tents]] first; it explains the trade-offs behind these picks. We favored tents that pitch fast, ventilate well and survive a season of abuse.' ),
		array( 'h' => 'Keeping it dry', 'p' => 'Lightweight fabrics are thin, so learning to waterproof a tent and care for the rainfly pays off quickly. We also note which tents ship with factory seam taping.' ),
	), '2025-05-02', null, 'Our top lightweight backpacking tents, tested over 400 trail miles.' ),
	'lightweight-backpacking-tents-top-picks' => array( 'Lightweight Backpacking Tents: Our Top Picks', 'tents', 700, array(
		array( 'p' => 'A shorter list of lightweight backpacking tents for readers who just want our top picks without the long reviews. These are the shelters our editors actually take on their own trips.' ),
	), '2023-08-19', null, null ),
	'how-to-waterproof-a-tent'               => array( 'How to Waterproof a Tent: Seam Sealing, DWR and Rainfly Care', 'tents', 1100, array(
		array( 'p' => 'A leaking tent can ruin a trip, but most leaks come from three places: failed seam tape, worn DWR on the rainfly, and a floor that has lost its coating. Here is how to fix each one at home in an afternoon.' ),
		array( 'h' => 'Seam sealing', 'p' => 'Set the tent up in dry weather, clean the seams with rubbing alcohol and apply a thin bead of sealant. Silicone fabrics need silicone sealant; polyurethane fabrics need a PU sealer.' ),
		array( 'h' => 'Restoring DWR', 'p' => 'Wash the rainfly with a technical cleaner, then spray on a fresh DWR treatment while it is still damp. Let it cure fully before packing it away.' ),
	), '2024-10-07', null, 'Stop leaks for good: seam sealing, DWR treatment and rainfly care, step by step.' ),
	'tent-footprint-do-you-need-one'         => array( 'Do You Really Need a Tent Footprint?', 'tents', 800, array(
		array( 'p' => 'Footprints add weight and cost, yet many hikers carry one out of habit. We dragged tent floors across granite, sand and forest duff to see when a footprint really helps.' ),
		array( 'p' => 'For a deeper look at floor fabrics and denier, see [[complete-guide-backpacking-tents|our backpacking tent guide]].' ),
	), '2024-06-21', null, 'When a tent footprint is worth the weight, and when it is not.' ),
	'how-to-pitch-a-tent-in-wind'            => array( 'How to Pitch a Tent in Strong Wind', 'tents', 900, array(
		array( 'p' => 'Wind is the fastest way to snap a pole or lose a rainfly. Orient the narrow end into the wind, stake the windward corners first and add guylines before the gusts arrive.' ),
	), '2024-09-12', null, 'Pitch your tent safely in strong wind: orientation, staking order and guylines.' ),
	'how-to-choose-a-sleeping-bag'           => array( 'How to Choose a Sleeping Bag', 'sleep', 1500, array(
		array( 'p' => 'The right sleeping bag depends on the coldest night you expect, how warm you sleep and how much weight you are willing to carry. This guide covers shapes, fills and temperature ratings in plain language.' ),
		array( 'h' => 'Insulation', 'p' => 'Down is lighter and packs smaller; synthetic keeps working when damp. We compared both in [[down-vs-synthetic-insulation|down vs synthetic insulation]].' ),
		array( 'h' => 'Temperature ratings', 'p' => 'Manufacturers publish comfort and limit temperature ratings, but they assume you use a decent pad and wear a base layer. Read the comfort number, not the limit, when you shop.' ),
		array( 'h' => 'Do not forget the pad', 'p' => 'A warm bag on a cold pad still sleeps cold. Check the [[sleeping-pads-r-value-guide|R-value of your sleeping pad]] before blaming the bag.' ),
	), '2024-01-30', null, 'Pick the right sleeping bag for your trips: temperature, fill, shape and weight explained.' ),
	'sleeping-bag-temperature-ratings-explained' => array( 'Sleeping Bag Temperature Ratings Explained', 'sleep', 1000, array(
		array( 'p' => 'Comfort, limit and extreme ratings come from a standardized lab test with a heated mannequin. Here is what each number means and how to adjust it for the way you sleep.' ),
		array( 'h' => 'Comfort vs limit', 'p' => 'The comfort rating is the temperature at which a typical cold sleeper stays comfortable. The limit rating is where a warm sleeper curls up and stays safe, but not comfortable.' ),
	), '2024-11-03', null, 'What comfort, limit and extreme sleeping bag ratings really mean.' ),
	'down-vs-synthetic-insulation'           => array( 'Down vs Synthetic Insulation: Which Is Warmer When Wet?', 'sleep', 1200, array(
		array( 'p' => 'We soaked down and synthetic jackets and sleeping bags, then measured how much warmth each kept. The results surprised us, especially for treated down.' ),
		array( 'p' => 'Lab temperature ratings do not account for moisture, which is why insulation type matters on wet trips.' ),
	), '2024-02-11', null, 'We soaked down and synthetic insulation to see which stays warmer when wet.' ),
	'sleeping-pads-r-value-guide'            => array( 'Sleeping Pad R-Values: A Practical Guide', 'sleep', 900, array(
		array( 'p' => 'R-value measures how well a pad resists heat loss to the ground. Since 2020 most brands use the ASTM standard, so numbers are finally comparable.' ),
		array( 'p' => 'Like sleeping bag temperature ratings, R-values are lab numbers; frozen ground and wind still make a difference.' ),
	), '2024-04-18', null, 'Understand sleeping pad R-values and pick the right pad for every season.' ),
	'winter-camping-sleep-system'            => array( 'Building a Winter Camping Sleep System', 'sleep', 1300, array(
		array( 'p' => 'Winter nights reward a system, not a single piece of gear: two pads, a bag rated below the forecast low, a vapor barrier for long trips and a warm water bottle.' ),
		array( 'p' => 'Stack a foam pad under an insulated one to raise the total R-value; our [[sleeping-pads-r-value-guide|R-value guide]] explains how the numbers add up.' ),
	), '2022-12-02', '2024-01-15 10:00:00', 'Two pads, the right bag and a few tricks for warm winter nights.' ),
	'ultralight-cook-systems-compared'       => array( 'Ultralight Cook Systems Compared', 'kitchen', 1000, array(
		array( 'p' => 'We boiled 120 liters of water to compare integrated canister stoves, titanium pots with micro burners and alcohol setups. Boil time is only part of the story: fuel efficiency in wind decides real-world weight.' ),
	), '2024-07-09', null, 'Integrated canister, micro burner or alcohol: which ultralight cook system wins?' ),
	'camp-stove-buying-guide'                => array( 'Camp Stove Buying Guide: Canister, Liquid Fuel or Alcohol?', 'kitchen', 1400, array(
		array( 'p' => 'The best camp stove depends on group size, altitude and how much you cook versus boil. Canister stoves are easy, liquid fuel works in deep cold, and alcohol is the lightest option for solo hikers.' ),
		array( 'p' => 'If weight is your priority, an ultralight cook system with a small titanium pot can save half a kilo. Pair your stove with a few [[easy-backpacking-meals|easy backpacking meals]] that only need boiling water.' ),
	), '2024-05-27', null, 'Canister, liquid fuel or alcohol? Choose the right camp stove for your trips.' ),
	'backcountry-water-filters'              => array( 'Backcountry Water Filters and Purifiers Tested', 'kitchen', 1300, array(
		array( 'p' => 'We pumped, squeezed and gravity-fed murky water through eleven filters and purifiers. Flow rate after a week of silty water mattered more than the speed out of the box.' ),
	), '2024-08-14', null, 'Eleven backcountry water filters tested for flow, reliability and weight.' ),
	'easy-backpacking-meals'                 => array( 'Easy Backpacking Meals You Can Make in One Pot', 'kitchen', 1100, array(
		array( 'p' => 'These one-pot meals need nothing more than a small stove and a spoon. Every recipe works with any cook system that can boil half a liter of water.' ),
		array( 'p' => 'Cooking near camp means fire rules apply; read our [[campfire-safety-tips|campfire safety tips]] before you go. Plan your water stops too, and carry a reliable water filter.' ),
	), '2024-06-02', null, 'One-pot backpacking meals that are light, cheap and actually taste good.' ),
	'campfire-safety-tips'                   => array( 'Campfire Safety Tips', 'skills', 180, array(
		array( 'p' => 'Use existing fire rings, keep fires small and drown them until the ashes are cold to the touch.' ),
	), '2023-07-04', '2023-07-04 08:00:00', null ),
	'best-headlamps'                         => array( 'Best Headlamps for Camping', 'skills', 160, array(
		array( 'p' => 'A good headlamp has a red mode, a lockout switch and enough battery for three nights. Here are a few we like.' ),
	), '2023-09-30', '2023-09-30 08:00:00', null ),
	'beginner-hiking-checklist'              => array( 'The Beginner Hiking Checklist', 'hiking', 1500, array(
		array( 'p' => 'Your first hikes should be fun, not an endurance test. This checklist covers what to wear, what to pack and how to choose a trail that matches your fitness.' ),
		array( 'h' => 'Footwear', 'p' => 'Many beginners assume they need heavy boots, but trail running shoes are often more comfortable on well-maintained paths. Break in whatever you choose and learn [[how-to-prevent-blisters|how to prevent blisters]].' ),
		array( 'h' => 'Clothing', 'p' => 'Dress in layers you can add and remove as you warm up; our guide to [[layering-for-hiking|layering for hiking]] explains the system.' ),
		array( 'h' => 'On the trail', 'p' => 'Learn the basics of [[trail-etiquette|trail etiquette]] and pack a small [[first-aid-kit-for-hikers|first aid kit]] on every hike.' ),
	), '2024-03-02', null, 'What to wear, what to pack and how to pick your first trails.' ),
	'trail-running-shoes-vs-hiking-boots'    => array( 'Trail Running Shoes vs Hiking Boots', 'footwear', 1200, array(
		array( 'p' => 'More hikers than ever are swapping boots for trail running shoes. We compared stability, blister rates and durability over a summer of testing.' ),
	), '2024-05-12', null, 'Trail runners or boots? We compared comfort, stability and durability on the trail.' ),
	'how-to-prevent-blisters'                => array( 'How to Prevent Blisters on Long Hikes', 'footwear', 1000, array(
		array( 'p' => 'Blisters come from heat, moisture and friction. Fit, sock choice and early taping prevent most of them, whether you wear boots or trail running shoes.' ),
	), '2024-04-01', null, 'Fit, socks and taping: prevent blisters before they start.' ),
	'layering-for-hiking'                    => array( 'Layering for Hiking: Base, Mid and Shell', 'hiking', 1100, array(
		array( 'p' => 'A layering system lets you stay comfortable from a cold start to a sweaty climb. Base layers move moisture, mid layers insulate and shells block wind and rain.' ),
	), '2024-10-20', null, 'Base, mid and shell layers explained for hikers.' ),
	'leave-no-trace-principles'              => array( 'Leave No Trace Principles, Explained', 'skills', 900, array(
		array( 'p' => 'The seven Leave No Trace principles keep popular places wild. Plan ahead, stay on durable surfaces and pack out everything you bring.' ),
		array( 'p' => 'Good planning includes knowing where you are; our [[navigation-basics-compass-and-gps|navigation basics]] guide covers compass, GPS and phone apps. In bear country, follow the [[bear-safety-in-the-backcountry|bear safety rules]] for food storage.' ),
	), '2024-02-25', null, 'The seven Leave No Trace principles with practical examples.' ),
	'trail-etiquette'                        => array( 'Trail Etiquette Every Hiker Should Know', 'hiking', 700, array(
		array( 'p' => 'Uphill hikers have the right of way, bikes yield to hikers and everyone yields to horses. Keep music to yourself and step aside for faster groups.' ),
	), '2024-06-30', null, 'Right of way, noise and dogs: the unwritten rules of the trail.' ),
	'best-day-hikes-pacific-northwest'       => array( '12 Best Day Hikes in the Pacific Northwest', 'hiking', 1700, array(
		array( 'p' => 'From waterfall loops in the Columbia River Gorge to alpine lakes in the North Cascades, these twelve day hikes show the best of the region. Each one is doable in a day from Portland or Seattle.' ),
		array( 'p' => 'Trail conditions change every season; see our [[best-day-hikes-2023-update|2023 update]] and the [[trail-maps/old-loop-trail|old loop trail map]] for closures. Pack the [[beginner-hiking-checklist|beginner hiking checklist]] essentials on every one of these hikes.' ),
	), '2023-05-18', '2026-04-02 09:00:00', 'Twelve unforgettable day hikes in Oregon and Washington, with maps and tips.' ),
	'how-to-read-a-topographic-map'          => array( 'How to Read a Topographic Map', 'skills', 1200, array(
		array( 'p' => 'Contour lines turn a flat sheet into a 3D picture of the land. Learn to spot ridges, saddles and drainages, and you will rarely get lost.' ),
	), '2024-09-01', null, 'Contour lines, scale and symbols: read a topo map with confidence.' ),
	'navigation-basics-compass-and-gps'      => array( 'Navigation Basics: Compass, GPS and Phone Apps', 'skills', 1000, array(
		array( 'p' => 'Phones are great navigation tools until the battery dies. Carry a compass, know how to take a bearing and practice [[how-to-read-a-topographic-map|reading a topographic map]] before you rely on it.' ),
	), '2024-08-22', null, 'Compass, GPS or phone? Build navigation skills that work when tech fails.' ),
	'first-aid-kit-for-hikers'               => array( 'What to Pack in a Hiker\'s First Aid Kit', 'skills', 800, array(
		array( 'p' => 'A hiker first aid kit should handle blisters, cuts, sprains and allergic reactions. Here is our checklist and how to keep it light.' ),
	), '2024-07-15', null, null ),
	'bear-safety-in-the-backcountry'         => array( 'Bear Safety in the Backcountry', 'skills', 1100, array(
		array( 'p' => 'Most bear encounters end peacefully when hikers make noise, store food properly and know how to react. Here is what to do with black bears and grizzlies.' ),
	), '2024-05-05', null, 'Avoid and handle bear encounters: food storage, noise and what to do.' ),
	'how-to-hang-a-bear-bag'                 => array( 'How to Hang a Bear Bag', 'skills', 450, array(
		array( 'p' => 'Where canisters are not required, a well-hung food bag keeps bears and rodents out. Find a branch 15 feet up and use the PCT method.' ),
	), '2024-03-28', null, null ),
	'packing-list-for-weekend-camping'       => array( 'Weekend Camping Packing List', 'tents', 1300, array(
		array( 'p' => 'A weekend trip needs less than you think. Start with shelter, sleep and kitchen, then add clothing for the worst forecast.' ),
		array( 'h' => 'Shelter and sleep', 'p' => 'Bring a tent you have pitched at home (see [[complete-guide-backpacking-tents|our tent guide]]) and a bag suited to the season; [[how-to-choose-a-sleeping-bag|how to choose a sleeping bag]] explains ratings.' ),
		array( 'h' => 'Kitchen and water', 'p' => 'A simple stove from our [[camp-stove-buying-guide|camp stove buying guide]] and a filter from our [[backcountry-water-filters|water filter tests]] cover most trips. Review the [[campfire-safety-tips|campfire rules]] and pack a headlamp.' ),
		array( 'p' => 'Compare our [[best-headlamps|favorite headlamps]] before you buy one.' ),
	), '2024-04-25', null, 'Everything you need for a weekend of camping, and nothing you do not.' ),
	'glamping-vs-camping'                    => array( 'Glamping vs Camping: What\'s Right for You?', 'tents', 600, array(
		array( 'p' => 'Glamping trades a sleeping pad for a real bed and a camp stove for a café. It is a great way to introduce friends to the outdoors.' ),
	), '2022-08-11', '2023-02-10 12:00:00', null ),
	'old-gear-sale-2022'                     => array( 'Our 2022 Gear Sale Picks', 'tents', 250, array(
		array( 'p' => 'These deals ended long ago. See our [[spring-sale-2022|spring sale page]] for the list we published at the time.' ),
	), '2022-11-25', '2022-11-28 12:00:00', null ),
);

$pages = array(
	'home'                 => array( 'Home', 400, array(
		array( 'p' => 'Northwind Outdoor Journal publishes independent gear reviews and practical guides for campers and hikers. Start with our [[camping-gear-guide|camping gear guide]] or the [[hiking-guide|hiking guide]].' ),
		array( 'h' => 'Popular guides', 'p' => 'Readers love [[complete-guide-backpacking-tents|the complete guide to backpacking tents]], [[how-to-choose-a-sleeping-bag|how to choose a sleeping bag]] and [[beginner-hiking-checklist|the beginner hiking checklist]]. New to camping? Start with our [[packing-list-for-weekend-camping|weekend camping packing list]].' ),
	), 'Independent gear reviews and practical guides for campers and hikers.' ),
	'camping-gear-guide'   => array( 'Camping Gear Guide', 600, array(
		array( 'p' => 'Our camping gear guide collects every review and how-to in one place.' ),
		array( 'h' => 'Shelter and sleep', 'p' => 'Start with [[complete-guide-backpacking-tents|backpacking tents]], then [[how-to-choose-a-sleeping-bag|sleeping bags]] and [[sleeping-pads-r-value-guide|sleeping pads]].' ),
		array( 'h' => 'Kitchen and water', 'p' => 'Compare [[camp-stove-buying-guide|camp stoves]] and [[backcountry-water-filters|water filters]].' ),
	), 'All our camping gear reviews and guides in one place.' ),
	'hiking-guide'         => array( 'Hiking Guide', 600, array(
		array( 'p' => 'Everything we know about hiking, from first steps to multi-day trips.' ),
		array( 'h' => 'Getting started', 'p' => 'Read [[beginner-hiking-checklist|the beginner hiking checklist]] and [[layering-for-hiking|layering for hiking]].' ),
		array( 'h' => 'Skills and safety', 'p' => 'Learn [[leave-no-trace-principles|Leave No Trace]] and [[bear-safety-in-the-backcountry|bear safety]].' ),
		array( 'h' => 'Where to go', 'p' => 'Our favorite trails: [[best-day-hikes-pacific-northwest|the best day hikes in the Pacific Northwest]].' ),
	), 'Hiking skills, trails and gear advice for every level.' ),
	'about'                => array( 'About Northwind', 350, array(
		array( 'p' => 'We are a small team of hikers and gear testers. We buy most of the gear we review and never accept payment for placement. Questions? [[contact|Get in touch]].' ),
	), 'Who we are and how we review outdoor gear.' ),
	'contact'              => array( 'Contact', 60, array(
		array( 'p' => 'Email the editors at hello@northwind-outdoor.example.' ),
	), null ),
	'gear-testing-process' => array( 'How We Test Gear', 700, array(
		array( 'p' => 'Every product we review is used on real trips for at least 30 days. We measure weight ourselves, test in rain and cold, and revisit our picks every season.' ),
	), null ),
	'privacy-policy'       => array( 'Privacy Policy', 300, array(
		array( 'p' => 'This demo site does not collect personal data.' ),
	), null ),
);

// ---------------------------------------------------------------- create
$ids = array();
foreach ( $pages as $slug => $page ) {
	$ids[ $slug ] = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_name'    => $slug,
			'post_title'   => $page[0],
			'post_content' => northwind_body( $page[2], $page[1], $filler ),
			'post_date'    => '2023-01-10 09:00:00',
		)
	);
	if ( $page[3] ) {
		update_post_meta( $ids[ $slug ], '_sitegraph_meta_description', $page[3] );
	}
}
foreach ( $posts as $slug => $post ) {
	$ids[ $slug ] = wp_insert_post(
		array(
			'post_type'     => 'post',
			'post_status'   => 'publish',
			'post_name'     => $slug,
			'post_title'    => $post[0],
			'post_content'  => northwind_body( $post[3], $post[2], $filler ),
			'post_date'     => $post[4] . ' 08:00:00',
			'post_category' => array( $cat_ids[ $post[1] ] ),
		)
	);
	if ( $post[6] ) {
		update_post_meta( $ids[ $slug ], '_sitegraph_meta_description', $post[6] );
	}
}

// Turn [[slug|anchor]] into links now that every page has a URL.
global $wpdb;
foreach ( $ids as $slug => $id ) {
	$content = get_post_field( 'post_content', $id, 'raw' );
	$content = preg_replace_callback(
		'/\[\[([^|\]]+)\|([^\]]+)\]\]/',
		function ( $m ) use ( $ids ) {
			$url = isset( $ids[ $m[1] ] ) ? get_permalink( $ids[ $m[1] ] ) : home_url( '/' . $m[1] . '/' );
			return '<a href="' . esc_url( $url ) . '">' . $m[2] . '</a>';
		},
		$content
	);
	$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $id ) );
}

// Most posts were refreshed this year; a few are deliberately stale.
foreach ( $posts as $slug => $post ) {
	$modified = $post[5] ? $post[5] : sprintf( '2026-%02d-%02d 08:00:00', mt_rand( 3, 9 ), mt_rand( 1, 28 ) );
	$wpdb->update( $wpdb->posts, array( 'post_modified' => $modified, 'post_modified_gmt' => $modified ), array( 'ID' => $ids[ $slug ] ) );
}

update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $ids['home'] );
update_option( 'wp_page_for_privacy_policy', $ids['privacy-policy'] );

// Site navigation (block themes read wp_navigation posts).
$nav = '';
foreach ( array( 'home', 'camping-gear-guide', 'hiking-guide', 'about' ) as $slug ) {
	$nav .= '<!-- wp:navigation-link {"label":"' . esc_attr( get_the_title( $ids[ $slug ] ) ) . '","type":"page","id":' . (int) $ids[ $slug ] . ',"url":"' . esc_url( get_permalink( $ids[ $slug ] ) ) . '","kind":"post-type"} /-->';
}
wp_insert_post(
	array(
		'post_type'    => 'wp_navigation',
		'post_status'  => 'publish',
		'post_title'   => 'Main menu',
		'post_content' => $nav,
	)
);

clean_post_cache( 0 );
wp_cache_flush();
flush_rewrite_rules();

$plugin = 'sitegraph-seo-agent/sitegraph-seo-agent.php';
if ( file_exists( WP_PLUGIN_DIR . '/' . $plugin ) && ! is_plugin_active( $plugin ) ) {
	activate_plugin( $plugin );
}

// Start SiteGraph from a clean slate too.
foreach ( array( 'links', 'pages', 'search', 'changesets' ) as $table ) {
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'sitegraph_' . $table ) ) ) {
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}sitegraph_{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
foreach ( array( 'sitegraph_scan_state', 'sitegraph_last_scan', 'sitegraph_needs_refresh', 'sitegraph_search_meta' ) as $option ) {
	delete_option( $option );
}
delete_transient( 'sitegraph_path_map' );

printf( "Seeded %d posts and %d pages on %s\n", count( $posts ), count( $pages ), home_url() );
