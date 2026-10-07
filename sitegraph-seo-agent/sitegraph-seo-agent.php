<?php
/**
 * Plugin Name:       SiteGraph SEO Agent
 * Plugin URI:        https://github.com/am333ni7y/wordpress-mcp
 * Description:       Finds your weak pages, turns your internal link graph and Search Console data into a prioritized action plan, and lets AI agents such as Claude fix issues as reviewable changesets with one-click undo and redo.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Amin Zahed (AMEEEN ZED) AMEEEN.IR
 * Author URI:        https://github.com/am333ni7y
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       sitegraph-seo-agent
 *
 * @package SiteGraph
 */

defined( 'ABSPATH' ) || exit;

define( 'SITEGRAPH_VERSION', '0.1.0' );
define( 'SITEGRAPH_DB_VERSION', '1' );
define( 'SITEGRAPH_FILE', __FILE__ );
define( 'SITEGRAPH_DIR', plugin_dir_path( __FILE__ ) );
define( 'SITEGRAPH_URL', plugin_dir_url( __FILE__ ) );

require_once SITEGRAPH_DIR . 'includes/class-installer.php';
require_once SITEGRAPH_DIR . 'includes/class-seo-meta.php';
require_once SITEGRAPH_DIR . 'includes/class-content-editor.php';
require_once SITEGRAPH_DIR . 'includes/class-link-index.php';
require_once SITEGRAPH_DIR . 'includes/class-search-data.php';
require_once SITEGRAPH_DIR . 'includes/class-page-analyzer.php';
require_once SITEGRAPH_DIR . 'includes/class-link-suggester.php';
require_once SITEGRAPH_DIR . 'includes/class-action-plan.php';
require_once SITEGRAPH_DIR . 'includes/class-changesets.php';
require_once SITEGRAPH_DIR . 'includes/class-abilities.php';
require_once SITEGRAPH_DIR . 'includes/class-admin.php';
require_once SITEGRAPH_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'SiteGraph\\Installer', 'activate' ) );
add_action( 'plugins_loaded', array( 'SiteGraph\\Plugin', 'boot' ) );
