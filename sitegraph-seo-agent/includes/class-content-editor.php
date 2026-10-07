<?php
/**
 * HTML-aware text operations on post content: find a phrase that can safely
 * become a link, wrap it in an anchor, and replace exact text.
 *
 * @package SiteGraph
 */

namespace SiteGraph;

use WP_Error;

defined( 'ABSPATH' ) || exit;

class Content_Editor {

	/**
	 * Text inside these elements is never turned into a link.
	 */
	const BLOCKED_TAGS = array( 'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'script', 'style', 'code', 'pre', 'button', 'textarea', 'select', 'option', 'title' );

	/**
	 * Splits HTML into tags/comments and text, marking which text is linkable.
	 *
	 * @return array<int, array{type:string, value:string, linkable:bool}>
	 */
	public static function tokenize( $html ) {
		$parts   = preg_split( '/(<!--.*?-->|<[^>]+>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		$tokens  = array();
		$blocked = 0;
		foreach ( $parts as $part ) {
			if ( '<' === $part[0] ) {
				if ( preg_match( '#^<(/?)([a-zA-Z0-9]+)#', $part, $m ) ) {
					$tag = strtolower( $m[2] );
					if ( in_array( $tag, self::BLOCKED_TAGS, true ) ) {
						if ( '/' === $m[1] ) {
							$blocked = max( 0, $blocked - 1 );
						} elseif ( '/' !== substr( rtrim( $part, '> ' ), -1 ) ) {
							$blocked++;
						}
					}
				}
				$tokens[] = array(
					'type'     => 'tag',
					'value'    => $part,
					'linkable' => false,
				);
			} else {
				$tokens[] = array(
					'type'     => 'text',
					'value'    => $part,
					'linkable' => 0 === $blocked,
				);
			}
		}
		return $tokens;
	}

	/**
	 * Regex for a whole-word, case-insensitive phrase match. Whitespace in the
	 * phrase matches any whitespace run in the content, and the last word matches
	 * its singular or plural form ("cook system" finds "cook systems").
	 */
	private static function phrase_pattern( $phrase ) {
		$words = preg_split( '/\s+/u', trim( $phrase ), -1, PREG_SPLIT_NO_EMPTY );
		$last  = array_pop( $words );
		$words = array_map(
			function ( $word ) {
				return preg_quote( $word, '/' );
			},
			$words
		);
		if ( preg_match( '/^\p{L}{3,}$/u', $last ) ) {
			$stem    = ( 's' === mb_strtolower( mb_substr( $last, -1 ) ) && 'ss' !== mb_strtolower( mb_substr( $last, -2 ) ) ) ? mb_substr( $last, 0, -1 ) : $last;
			$words[] = preg_quote( $stem, '/' ) . 's?';
		} else {
			$words[] = preg_quote( $last, '/' );
		}
		return '/(?<![\p{L}\p{N}])' . implode( '\s+', $words ) . '(?![\p{L}\p{N}])/iu';
	}

	/**
	 * Finds the first linkable occurrence of a phrase.
	 *
	 * @return array{token:int, offset:int, match:string, context:string}|null
	 */
	public static function find_linkable( $html, $phrase ) {
		if ( '' === trim( $phrase ) ) {
			return null;
		}
		$pattern = self::phrase_pattern( $phrase );
		$tokens  = self::tokenize( $html );
		foreach ( $tokens as $index => $token ) {
			if ( 'text' !== $token['type'] || ! $token['linkable'] ) {
				continue;
			}
			if ( ! preg_match_all( $pattern, $token['value'], $matches, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			foreach ( $matches[0] as $match ) {
				// Skip matches inside a shortcode tag, e.g. [gallery title="tent"].
				$prefix = substr( $token['value'], 0, $match[1] );
				if ( substr_count( $prefix, '[' ) > substr_count( $prefix, ']' ) ) {
					continue;
				}
				return array(
					'token'   => $index,
					'offset'  => $match[1],
					'match'   => $match[0],
					'context' => self::context( $token['value'], $match[1], strlen( $match[0] ) ),
				);
			}
		}
		return null;
	}

	/**
	 * Wraps the first linkable occurrence of $phrase in a link to $url.
	 *
	 * @return array{html:string, before:string, after:string}|WP_Error
	 */
	public static function insert_link( $html, $phrase, $url ) {
		$found = self::find_linkable( $html, $phrase );
		if ( null === $found ) {
			return new WP_Error(
				'sitegraph_phrase_not_found',
				sprintf( 'The phrase "%s" was not found in linkable text (it may be missing, inside a heading, or already linked).', $phrase )
			);
		}
		$tokens = self::tokenize( $html );
		$text   = $tokens[ $found['token'] ]['value'];
		$anchor = '<a href="' . esc_url( $url ) . '">' . $found['match'] . '</a>';
		$tokens[ $found['token'] ]['value'] = substr( $text, 0, $found['offset'] ) . $anchor . substr( $text, $found['offset'] + strlen( $found['match'] ) );

		$new_html = implode( '', wp_list_pluck( $tokens, 'value' ) );
		return array(
			'html'   => $new_html,
			'before' => self::context( $text, $found['offset'], strlen( $found['match'] ) ),
			'after'  => self::context( $tokens[ $found['token'] ]['value'], $found['offset'], strlen( $anchor ) ),
		);
	}

	/**
	 * Replaces exact text (case-sensitive) in content.
	 *
	 * @return array{html:string, before:string, after:string, count:int}|WP_Error
	 */
	public static function replace_text( $html, $find, $replace, $all = false ) {
		if ( '' === $find ) {
			return new WP_Error( 'sitegraph_empty_find', 'The text to find is empty.' );
		}
		$position = strpos( $html, $find );
		if ( false === $position ) {
			return new WP_Error( 'sitegraph_text_not_found', 'The exact text to replace was not found in the content.' );
		}
		$count = substr_count( $html, $find );
		if ( $all ) {
			$new_html = str_replace( $find, $replace, $html );
		} else {
			$new_html = substr( $html, 0, $position ) . $replace . substr( $html, $position + strlen( $find ) );
		}
		return array(
			'html'   => $new_html,
			'before' => self::context( $html, $position, strlen( $find ) ),
			'after'  => self::context( $new_html, $position, strlen( $replace ) ),
			'count'  => $all ? $count : 1,
		);
	}

	/**
	 * A window of text around a byte range, trimmed to UTF-8 character boundaries.
	 */
	public static function context( $text, $offset, $length, $radius = 90 ) {
		$start  = max( 0, $offset - $radius );
		$end    = min( strlen( $text ), $offset + $length + $radius );
		$window = substr( $text, $start, $end - $start );
		// Drop partial multibyte characters at either edge.
		$window = preg_replace( '/^[\x80-\xBF]+/', '', $window );
		$window = mb_convert_encoding( $window, 'UTF-8', 'UTF-8' );
		$window = preg_replace( '/\s+/u', ' ', $window );
		return ( $start > 0 ? '…' : '' ) . trim( $window ) . ( $end < strlen( $text ) ? '…' : '' );
	}

	/**
	 * Plain-text version of post content.
	 */
	public static function plain_text( $html ) {
		$html = preg_replace( '/<!--.*?-->/s', ' ', $html );
		$html = strip_shortcodes( $html );
		$text = wp_strip_all_tags( $html );
		return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * Unicode-aware word count.
	 */
	public static function word_count( $html ) {
		return (int) preg_match_all( "/[\p{L}\p{N}][\p{L}\p{N}'’-]*/u", self::plain_text( $html ) );
	}
}
