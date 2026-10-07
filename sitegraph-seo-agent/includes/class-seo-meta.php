<?php
/**
 * Reads and writes SEO title, meta description and focus keyword through
 * whichever SEO plugin is active, with a built-in fallback.
 *
 * @package SiteGraph
 */

namespace SiteGraph;

defined( 'ABSPATH' ) || exit;

class Seo_Meta {

	const KEYS = array(
		'yoast'    => array(
			'title'       => '_yoast_wpseo_title',
			'description' => '_yoast_wpseo_metadesc',
			'keyword'     => '_yoast_wpseo_focuskw',
		),
		'rankmath' => array(
			'title'       => 'rank_math_title',
			'description' => 'rank_math_description',
			'keyword'     => 'rank_math_focus_keyword',
		),
		'seopress' => array(
			'title'       => '_seopress_titles_title',
			'description' => '_seopress_titles_desc',
			'keyword'     => '_seopress_analysis_target_kw',
		),
		'sitegraph' => array(
			'title'       => '_sitegraph_seo_title',
			'description' => '_sitegraph_meta_description',
			'keyword'     => '_sitegraph_focus_keyword',
		),
	);

	/**
	 * The SEO plugin that owns title/description meta on this site.
	 */
	public static function provider() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			$provider = 'yoast';
		} elseif ( class_exists( '\\RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) {
			$provider = 'rankmath';
		} elseif ( defined( 'SEOPRESS_VERSION' ) ) {
			$provider = 'seopress';
		} else {
			$provider = 'sitegraph';
		}
		return apply_filters( 'sitegraph_seo_provider', $provider );
	}

	public static function provider_label() {
		$labels = array(
			'yoast'     => 'Yoast SEO',
			'rankmath'  => 'Rank Math',
			'seopress'  => 'SEOPress',
			'sitegraph' => 'SiteGraph (built-in output)',
		);
		$provider = self::provider();
		return isset( $labels[ $provider ] ) ? $labels[ $provider ] : $provider;
	}

	/**
	 * Meta key for a field ('title', 'description' or 'keyword') under the active provider.
	 */
	public static function key( $field ) {
		$provider = self::provider();
		$keys     = isset( self::KEYS[ $provider ] ) ? self::KEYS[ $provider ] : self::KEYS['sitegraph'];
		return $keys[ $field ];
	}

	public static function get( $post_id, $field ) {
		return trim( (string) get_post_meta( $post_id, self::key( $field ), true ) );
	}

	public static function get_description( $post_id ) {
		return self::get( $post_id, 'description' );
	}

	/**
	 * First focus keyword, if the SEO plugin stores several (Rank Math uses a comma list).
	 */
	public static function get_focus_keyword( $post_id ) {
		$keyword = self::get( $post_id, 'keyword' );
		$parts   = explode( ',', $keyword );
		return trim( $parts[0] );
	}

	/**
	 * Without an SEO plugin, print the values SiteGraph manages so edits have a visible effect.
	 */
	public static function register_fallback_output() {
		if ( 'sitegraph' !== self::provider() ) {
			return;
		}
		add_action(
			'wp_head',
			function () {
				if ( ! is_singular() ) {
					return;
				}
				$description = self::get_description( get_queried_object_id() );
				if ( '' !== $description ) {
					echo '<meta name="description" content="' . esc_attr( $description ) . '" />' . "\n";
				}
			},
			1
		);
		add_filter(
			'pre_get_document_title',
			function ( $title ) {
				if ( is_singular() ) {
					$custom = self::get( get_queried_object_id(), 'title' );
					if ( '' !== $custom ) {
						return $custom;
					}
				}
				return $title;
			}
		);
	}
}
