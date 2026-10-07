<?php
/**
 * Wires the plugin into WordPress.
 *
 * @package SiteGraph
 */

namespace SiteGraph;

defined( 'ABSPATH' ) || exit;

class Plugin {

	public static function boot() {
		Installer::maybe_upgrade();
		Seo_Meta::register_fallback_output();

		add_action( 'save_post', array( Link_Index::class, 'on_save_post' ), 20 );
		add_action( 'deleted_post', array( Link_Index::class, 'on_save_post' ), 20 );

		if ( function_exists( 'wp_register_ability' ) ) {
			add_action( 'wp_abilities_api_categories_init', array( Abilities::class, 'register_category' ) );
			add_action( 'wp_abilities_api_init', array( Abilities::class, 'register' ) );
		}
		add_action( 'mcp_adapter_init', array( Abilities::class, 'register_mcp_server' ) );

		if ( is_admin() ) {
			Admin::init();
		}
	}

	/**
	 * Environment checks shown on the Connect screen.
	 *
	 * @return array<int, array{label:string, ok:bool, detail:string}>
	 */
	public static function checks() {
		global $wp_version;
		return array(
			array(
				'label'  => 'WordPress 6.9 or newer (Abilities API)',
				'ok'     => function_exists( 'wp_register_ability' ),
				'detail' => 'WordPress ' . $wp_version,
			),
			array(
				'label'  => 'MCP Adapter plugin active',
				'ok'     => class_exists( '\\WP\\MCP\\Core\\McpAdapter' ),
				'detail' => class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ? 'Agents can connect over MCP.' : 'Install “MCP Adapter” from Plugins → Add New so agents can connect.',
			),
			array(
				'label'  => 'Application Passwords available',
				'ok'     => wp_is_application_passwords_available(),
				'detail' => wp_is_application_passwords_available() ? 'Used to authenticate the agent as your user.' : 'Application Passwords need HTTPS (or a local environment).',
			),
			array(
				'label'  => 'SEO title and description storage',
				'ok'     => true,
				'detail' => Seo_Meta::provider_label(),
			),
		);
	}
}
