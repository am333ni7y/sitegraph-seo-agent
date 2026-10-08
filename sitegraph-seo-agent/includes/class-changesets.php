<?php
/**
 * Changesets: every content change an agent makes is proposed first, reviewed
 * as a diff, applied as one unit, and can be undone and redone at any time.
 *
 * Lifecycle: proposed → applied ⇄ undone, or proposed → discarded.
 *
 * Each changeset stores the exact before/after value of every field it touches.
 * Undo and redo refuse to run when a field was edited in the meantime (by a
 * person or another changeset), unless forced, so they never overwrite work.
 *
 * @package SiteGraph
 */

namespace SiteGraph;

use WP_Error;

defined( 'ABSPATH' ) || exit;

// SiteGraph keeps its link index, page scores, search data and changesets in its own
// tables. They change on every scan or edit, so they are queried directly, not cached.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class Changesets {

	const OPERATION_TYPES = array( 'add_internal_link', 'replace_text', 'set_post_title', 'set_seo_title', 'set_meta_description' );
	const MAX_OPERATIONS  = 50;

	/**
	 * Validates operations against current content and stores them as a proposal.
	 * Nothing on the site changes until apply() is called.
	 *
	 * @param string $title      Short summary of the change.
	 * @param string $rationale  Why the change is being made.
	 * @param array  $operations List of operations (see OPERATION_TYPES).
	 * @return array|WP_Error
	 */
	public static function propose( $title, $rationale, array $operations ) {
		if ( empty( $operations ) ) {
			return new WP_Error( 'sitegraph_no_operations', 'A changeset needs at least one operation.' );
		}
		if ( count( $operations ) > self::MAX_OPERATIONS ) {
			return new WP_Error( 'sitegraph_too_many_operations', sprintf( 'A changeset can contain at most %d operations. Split the work into several changesets.', self::MAX_OPERATIONS ) );
		}

		$working   = array();
		$originals = array();
		$summaries = array();
		$errors    = array();

		foreach ( array_values( $operations ) as $index => $op ) {
			$result = self::prepare_operation( (array) $op, $working, $originals );
			if ( is_wp_error( $result ) ) {
				$errors[] = sprintf( 'Operation %d (%s): %s', $index + 1, isset( $op['type'] ) ? $op['type'] : 'unknown', $result->get_error_message() );
				continue;
			}
			$summaries[] = $result;
		}

		if ( $errors ) {
			return new WP_Error( 'sitegraph_invalid_operations', implode( "\n", $errors ), array( 'errors' => $errors ) );
		}

		$fields = array();
		foreach ( $working as $key => $value ) {
			if ( $originals[ $key ] === $value ) {
				continue;
			}
			list( $post_id, $field ) = explode( '|', $key, 2 );
			$fields[]                = array(
				'post_id' => (int) $post_id,
				'field'   => $field,
				'before'  => $originals[ $key ],
				'after'   => $value,
			);
		}
		if ( empty( $fields ) ) {
			return new WP_Error( 'sitegraph_no_change', 'These operations would not change anything.' );
		}

		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			Installer::table( 'changesets' ),
			array(
				'title'      => mb_substr( sanitize_text_field( $title ), 0, 250 ),
				'rationale'  => sanitize_textarea_field( $rationale ),
				'status'     => 'proposed',
				'operations' => wp_json_encode( $summaries ),
				'fields'     => wp_json_encode( $fields ),
				'baseline'   => '[]',
				'history'    => wp_json_encode( array( self::event( 'proposed' ) ) ),
				'created_by' => get_current_user_id(),
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		return self::get( (int) $wpdb->insert_id );
	}

	/**
	 * Applies one operation to the in-memory working copy.
	 *
	 * @return array|WP_Error Operation summary with a before/after preview.
	 */
	private static function prepare_operation( array $op, array &$working, array &$originals ) {
		$type    = isset( $op['type'] ) ? (string) $op['type'] : '';
		$post_id = isset( $op['post_id'] ) ? (int) $op['post_id'] : 0;
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! in_array( $type, self::OPERATION_TYPES, true ) ) {
			return new WP_Error( 'sitegraph_bad_type', 'Unknown operation type. Use one of: ' . implode( ', ', self::OPERATION_TYPES ) . '.' );
		}
		if ( ! $post || ! in_array( $post->post_type, Link_Index::post_types(), true ) ) {
			return new WP_Error( 'sitegraph_bad_post', sprintf( 'Post %d does not exist or is not a content page.', $post_id ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'sitegraph_forbidden', sprintf( 'You are not allowed to edit post %d.', $post_id ) );
		}

		$post_title = wp_strip_all_tags( $post->post_title );
		$params     = array_diff_key( $op, array_flip( array( 'type', 'post_id' ) ) );

		switch ( $type ) {
			case 'add_internal_link':
				$target_id = isset( $op['target_post_id'] ) ? (int) $op['target_post_id'] : 0;
				$anchor    = isset( $op['anchor_text'] ) ? trim( (string) $op['anchor_text'] ) : '';
				if ( ! $target_id || 'publish' !== get_post_status( $target_id ) ) {
					return new WP_Error( 'sitegraph_bad_target', 'target_post_id must be a published post.' );
				}
				if ( $target_id === $post_id ) {
					return new WP_Error( 'sitegraph_self_link', 'A page cannot link to itself.' );
				}
				$field   = 'post_content';
				$current = self::working_value( $working, $originals, $post_id, $field );
				$url     = get_permalink( $target_id );
				foreach ( Link_Index::extract_links( $current ) as $link ) {
					$resolved = Link_Index::resolve( $link['href'], get_permalink( $post_id ) );
					if ( 'ok' === $resolved['status'] && $resolved['target_id'] === $target_id ) {
						return new WP_Error( 'sitegraph_already_linked', sprintf( '“%s” already links to “%s”.', $post_title, get_the_title( $target_id ) ) );
					}
				}
				$result = Content_Editor::insert_link( $current, $anchor, $url );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$new     = $result['html'];
				$preview = array(
					'before' => $result['before'],
					'after'  => $result['after'],
				);
				$summary = sprintf( 'Link “%s” on “%s” to “%s”', wp_strip_all_tags( $anchor ), $post_title, wp_strip_all_tags( get_the_title( $target_id ) ) );
				break;

			case 'replace_text':
				$field   = 'post_content';
				$current = self::working_value( $working, $originals, $post_id, $field );
				$result  = Content_Editor::replace_text(
					$current,
					isset( $op['find'] ) ? (string) $op['find'] : '',
					isset( $op['replace'] ) ? (string) $op['replace'] : '',
					! empty( $op['all'] )
				);
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$new     = $result['html'];
				$preview = array(
					'before' => $result['before'],
					'after'  => $result['after'],
				);
				$summary = sprintf( 'Edit text on “%s” (%d occurrence%s)', $post_title, $result['count'], 1 === $result['count'] ? '' : 's' );
				break;

			case 'set_post_title':
			case 'set_seo_title':
			case 'set_meta_description':
				$value = isset( $op['value'] ) ? (string) $op['value'] : '';
				if ( 'set_post_title' === $type ) {
					$field = 'post_title';
					$new   = sanitize_text_field( $value );
					if ( '' === $new ) {
						return new WP_Error( 'sitegraph_empty_title', 'The post title cannot be empty.' );
					}
					$label = 'post title';
				} elseif ( 'set_seo_title' === $type ) {
					$field = 'meta:' . Seo_Meta::key( 'title' );
					$new   = sanitize_text_field( $value );
					$label = 'SEO title';
				} else {
					$field = 'meta:' . Seo_Meta::key( 'description' );
					$new   = sanitize_textarea_field( $value );
					$label = 'meta description';
				}
				$current = self::working_value( $working, $originals, $post_id, $field );
				$preview = array(
					'before' => $current,
					'after'  => $new,
				);
				$summary = sprintf( 'Set the %s of “%s” (%d characters)', $label, $post_title, mb_strlen( $new ) );
				break;
		}

		$working[ $post_id . '|' . $field ] = $new;

		return array(
			'type'       => $type,
			'post_id'    => $post_id,
			'post_title' => $post_title,
			'field'      => $field,
			'params'     => $params,
			'summary'    => $summary,
			'preview'    => $preview,
		);
	}

	private static function working_value( array &$working, array &$originals, $post_id, $field ) {
		$key = $post_id . '|' . $field;
		if ( ! array_key_exists( $key, $working ) ) {
			$originals[ $key ] = self::read_field( $post_id, $field );
			$working[ $key ]   = $originals[ $key ];
		}
		return $working[ $key ];
	}

	/**
	 * @return array|WP_Error
	 */
	public static function apply( $id, $force = false ) {
		return self::transition( $id, 'proposed', 'before', 'after', 'applied', 'applied', $force );
	}

	/**
	 * @return array|WP_Error
	 */
	public static function undo( $id, $force = false ) {
		return self::transition( $id, 'applied', 'after', 'before', 'undone', 'undone', $force );
	}

	/**
	 * @return array|WP_Error
	 */
	public static function redo( $id, $force = false ) {
		return self::transition( $id, 'undone', 'before', 'after', 'applied', 'redone', $force );
	}

	/**
	 * @return array|WP_Error
	 */
	public static function discard( $id ) {
		$changeset = self::load( $id );
		if ( ! $changeset ) {
			return new WP_Error( 'sitegraph_not_found', sprintf( 'Changeset %d does not exist.', $id ) );
		}
		if ( 'proposed' !== $changeset['status'] ) {
			return new WP_Error( 'sitegraph_bad_status', sprintf( 'Only proposed changesets can be discarded; changeset %d is %s.', $id, $changeset['status'] ) );
		}
		$changeset['status']    = 'discarded';
		$changeset['history'][] = self::event( 'discarded' );
		self::save( $changeset );
		return self::get( $id );
	}

	/**
	 * Moves a changeset between states, writing one side of every field.
	 *
	 * @param int    $id       Changeset ID.
	 * @param string $from     Required current status.
	 * @param string $expect   Side ('before'/'after') the site must currently match.
	 * @param string $write    Side to write.
	 * @param string $to       New status.
	 * @param string $action   History label.
	 * @param bool   $force    Overwrite fields that changed since.
	 * @return array|WP_Error
	 */
	private static function transition( $id, $from, $expect, $write, $to, $action, $force ) {
		$changeset = self::load( $id );
		if ( ! $changeset ) {
			return new WP_Error( 'sitegraph_not_found', sprintf( 'Changeset %d does not exist.', $id ) );
		}
		if ( $from !== $changeset['status'] ) {
			$hints = array(
				'proposed'  => 'apply-changeset',
				'applied'   => 'undo-changeset',
				'undone'    => 'redo-changeset',
				'discarded' => 'nothing (it was discarded)',
			);
			$hint = isset( $hints[ $changeset['status'] ] ) ? $hints[ $changeset['status'] ] : 'get-changeset';
			return new WP_Error( 'sitegraph_bad_status', sprintf( 'Changeset %d is %s, so this action is not available. Next possible action: %s.', $id, $changeset['status'], $hint ) );
		}

		foreach ( $changeset['fields'] as $field ) {
			if ( ! current_user_can( 'edit_post', $field['post_id'] ) ) {
				return new WP_Error( 'sitegraph_forbidden', sprintf( 'You are not allowed to edit post %d.', $field['post_id'] ) );
			}
		}

		$conflicts = self::conflicts( $changeset, $expect );
		if ( $conflicts && ! $force ) {
			return new WP_Error(
				'sitegraph_conflict',
				sprintf( "Changeset %d was not %s because content changed since:\n- %s\nUndo the newer change first, or pass force=true to overwrite it.", $id, $action, implode( "\n- ", wp_list_pluck( $conflicts, 'message' ) ) ),
				array( 'conflicts' => $conflicts )
			);
		}

		$written = self::write_fields( $changeset['fields'], $write );
		if ( is_wp_error( $written ) ) {
			$changeset['history'][] = self::event( 'failed', array( 'error' => $written->get_error_message() ) );
			self::save( $changeset );
			return $written;
		}
		$changeset['fields'] = $written;

		if ( 'applied' === $action ) {
			$changeset['baseline'] = self::baseline( $changeset );
		}
		$changeset['status']    = $to;
		$changeset['history'][] = self::event( $action, $force && $conflicts ? array( 'forced' => true ) : array() );
		self::save( $changeset );

		self::reindex( $changeset );
		return self::get( $id );
	}

	/**
	 * Fields whose current value no longer matches the given side.
	 */
	private static function conflicts( array $changeset, $side ) {
		$conflicts = array();
		foreach ( $changeset['fields'] as $field ) {
			$current = self::read_field( $field['post_id'], $field['field'] );
			if ( self::normalize( $current ) === self::normalize( $field[ $side ] ) ) {
				continue;
			}
			$newer = self::newer_changesets( $changeset['id'], $field['post_id'] );
			$conflicts[] = array(
				'post_id'           => $field['post_id'],
				'field'             => $field['field'],
				'newer_changesets'  => $newer,
				'message'           => sprintf(
					'%s of “%s” (#%d) was edited%s',
					self::field_label( $field['field'] ),
					wp_strip_all_tags( get_the_title( $field['post_id'] ) ),
					$field['post_id'],
					$newer ? ' by changeset ' . implode( ', ', array_map( function ( $n ) { return '#' . $n; }, $newer ) ) : ' outside SiteGraph'
				),
			);
		}
		return $conflicts;
	}

	/**
	 * Writes one side of every field. On failure, restores what was already written.
	 *
	 * @return array|WP_Error Fields with the written side replaced by the value read back.
	 */
	private static function write_fields( array $fields, $side ) {
		$other = 'after' === $side ? 'before' : 'after';
		$done  = array();
		Link_Index::suspend( true );
		foreach ( $fields as $index => $field ) {
			$result = self::write_field( $field['post_id'], $field['field'], $field[ $side ] );
			if ( is_wp_error( $result ) ) {
				foreach ( array_reverse( $done ) as $undo_index ) {
					self::write_field( $fields[ $undo_index ]['post_id'], $fields[ $undo_index ]['field'], $fields[ $undo_index ][ $other ] );
				}
				Link_Index::suspend( false );
				return $result;
			}
			// WordPress may normalize what it stores (e.g. kses); remember the stored value.
			$fields[ $index ][ $side ] = self::read_field( $field['post_id'], $field['field'] );
			$done[]                    = $index;
		}
		Link_Index::suspend( false );
		return $fields;
	}

	private static function allowed_meta_keys() {
		$keys = array();
		foreach ( Seo_Meta::KEYS as $provider_keys ) {
			$keys[] = $provider_keys['title'];
			$keys[] = $provider_keys['description'];
		}
		return $keys;
	}

	private static function read_field( $post_id, $field ) {
		if ( 0 === strpos( $field, 'meta:' ) ) {
			return (string) get_post_meta( $post_id, substr( $field, 5 ), true );
		}
		$post = get_post( $post_id );
		return $post && in_array( $field, array( 'post_content', 'post_title' ), true ) ? (string) $post->$field : '';
	}

	/**
	 * @return true|WP_Error
	 */
	private static function write_field( $post_id, $field, $value ) {
		if ( 0 === strpos( $field, 'meta:' ) ) {
			$key = substr( $field, 5 );
			if ( ! in_array( $key, self::allowed_meta_keys(), true ) ) {
				return new WP_Error( 'sitegraph_bad_field', sprintf( 'Refusing to write meta key %s.', $key ) );
			}
			if ( '' === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, wp_slash( $value ) );
			}
			return true;
		}
		if ( ! in_array( $field, array( 'post_content', 'post_title' ), true ) ) {
			return new WP_Error( 'sitegraph_bad_field', sprintf( 'Refusing to write field %s.', $field ) );
		}
		$result = wp_update_post(
			wp_slash(
				array(
					'ID'   => $post_id,
					$field => $value,
				)
			),
			true
		);
		return is_wp_error( $result ) ? $result : true;
	}

	private static function normalize( $value ) {
		return str_replace( "\r\n", "\n", (string) $value );
	}

	private static function field_label( $field ) {
		if ( 'post_content' === $field ) {
			return 'Content';
		}
		if ( 'post_title' === $field ) {
			return 'Title';
		}
		if ( in_array( substr( $field, 5 ), array_column( Seo_Meta::KEYS, 'description' ), true ) ) {
			return 'Meta description';
		}
		return 'SEO title';
	}

	/**
	 * IDs of applied changesets created after $id that touch the same post.
	 */
	private static function newer_changesets( $id, $post_id ) {
		global $wpdb;
		$table = Installer::table( 'changesets' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, fields FROM %i WHERE id > %d AND status = 'applied'", $table, $id ) );
		$ids   = array();
		foreach ( $rows as $row ) {
			foreach ( (array) json_decode( $row->fields, true ) as $field ) {
				if ( (int) $field['post_id'] === (int) $post_id ) {
					$ids[] = (int) $row->id;
					break;
				}
			}
		}
		return $ids;
	}

	/**
	 * Post IDs whose performance this changeset is meant to improve: link targets
	 * for link operations, the edited post for everything else.
	 */
	private static function measured_posts( array $changeset ) {
		$ids = array();
		foreach ( $changeset['operations'] as $op ) {
			if ( 'add_internal_link' === $op['type'] && ! empty( $op['params']['target_post_id'] ) ) {
				$ids[] = (int) $op['params']['target_post_id'];
			} else {
				$ids[] = (int) $op['post_id'];
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Search metrics and score of the measured pages at the moment of applying.
	 */
	private static function baseline( array $changeset ) {
		$baseline = array();
		foreach ( self::measured_posts( $changeset ) as $post_id ) {
			$page                 = Page_Analyzer::get_page( $post_id );
			$baseline[ $post_id ] = array(
				'score'  => $page ? $page['score'] : null,
				'search' => Search_Data::get( $post_id ),
			);
		}
		return $baseline;
	}

	private static function reindex( array $changeset ) {
		if ( ! Link_Index::has_scanned() ) {
			return;
		}
		$ids = array_unique( wp_list_pluck( $changeset['fields'], 'post_id' ) );
		foreach ( $ids as $post_id ) {
			Link_Index::index_post( (int) $post_id );
		}
		Link_Index::refresh();
	}

	private static function event( $action, array $extra = array() ) {
		$user = wp_get_current_user();
		return array_merge(
			array(
				'action' => $action,
				'user'   => $user && $user->exists() ? $user->user_login : 'system',
				'at'     => gmdate( 'c' ),
			),
			$extra
		);
	}

	private static function load( $id ) {
		global $wpdb;
		$table = Installer::table( 'changesets' );
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		foreach ( array( 'operations', 'fields', 'baseline', 'history' ) as $key ) {
			$decoded     = json_decode( (string) $row[ $key ], true );
			$row[ $key ] = is_array( $decoded ) ? $decoded : array();
		}
		$row['id'] = (int) $row['id'];
		return $row;
	}

	private static function save( array $changeset ) {
		global $wpdb;
		$wpdb->update(
			Installer::table( 'changesets' ),
			array(
				'status'     => $changeset['status'],
				'fields'     => wp_json_encode( $changeset['fields'] ),
				'baseline'   => wp_json_encode( $changeset['baseline'] ),
				'history'    => wp_json_encode( $changeset['history'] ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $changeset['id'] )
		);
	}

	private static function next_actions( $status ) {
		$map = array(
			'proposed'  => array( 'apply', 'discard' ),
			'applied'   => array( 'undo' ),
			'undone'    => array( 'redo' ),
			'discarded' => array(),
		);
		return isset( $map[ $status ] ) ? $map[ $status ] : array();
	}

	/**
	 * Full changeset for review: operations with previews, affected pages,
	 * history, available actions and measured impact.
	 *
	 * @return array|WP_Error
	 */
	public static function get( $id ) {
		$changeset = self::load( $id );
		if ( ! $changeset ) {
			return new WP_Error( 'sitegraph_not_found', sprintf( 'Changeset %d does not exist.', $id ) );
		}

		$posts = array();
		foreach ( array_unique( wp_list_pluck( $changeset['fields'], 'post_id' ) ) as $post_id ) {
			$posts[] = array(
				'post_id' => (int) $post_id,
				'title'   => wp_strip_all_tags( get_the_title( $post_id ) ),
				'url'     => get_permalink( $post_id ),
			);
		}

		$impact = array();
		foreach ( $changeset['baseline'] as $post_id => $before ) {
			$page     = Page_Analyzer::get_page( (int) $post_id );
			$impact[] = array(
				'post_id' => (int) $post_id,
				'title'   => wp_strip_all_tags( get_the_title( $post_id ) ),
				'before'  => $before,
				'now'     => array(
					'score'  => $page ? $page['score'] : null,
					'search' => Search_Data::get( (int) $post_id ),
				),
			);
		}

		$creator = get_userdata( (int) $changeset['created_by'] );
		return array(
			'id'                => $changeset['id'],
			'title'             => $changeset['title'],
			'rationale'         => $changeset['rationale'],
			'status'            => $changeset['status'],
			'available_actions' => self::next_actions( $changeset['status'] ),
			'created_by'        => $creator ? $creator->user_login : null,
			'created_at'        => $changeset['created_at'],
			'updated_at'        => $changeset['updated_at'],
			'operations'        => $changeset['operations'],
			'affected_posts'    => $posts,
			'history'           => $changeset['history'],
			'impact'            => $impact,
			'impact_note'       => $impact ? 'Compare "before" (at apply time) with "now". Search numbers only change after you import fresh Search Console data, usually 2–4 weeks later.' : null,
			'admin_url'         => admin_url( 'admin.php?page=sitegraph&tab=changes&changeset=' . $changeset['id'] ),
		);
	}

	public static function list_changesets( $status = '', $limit = 20 ) {
		global $wpdb;
		$table = Installer::table( 'changesets' );
		$limit = max( 1, min( 100, (int) $limit ) );
		if ( '' !== $status ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, title, status, operations, fields, created_by, created_at, updated_at FROM %i WHERE status = %s ORDER BY id DESC LIMIT %d', $table, $status, $limit ) );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, title, status, operations, fields, created_by, created_at, updated_at FROM %i ORDER BY id DESC LIMIT %d', $table, $limit ) );
		}
		$out = array();
		foreach ( $rows as $row ) {
			$operations = (array) json_decode( $row->operations, true );
			$fields     = (array) json_decode( $row->fields, true );
			$creator    = get_userdata( (int) $row->created_by );
			$out[]      = array(
				'id'                => (int) $row->id,
				'title'             => $row->title,
				'status'            => $row->status,
				'available_actions' => self::next_actions( $row->status ),
				'operations'        => count( $operations ),
				'posts'             => count( array_unique( wp_list_pluck( $fields, 'post_id' ) ) ),
				'created_by'        => $creator ? $creator->user_login : null,
				'created_at'        => $row->created_at,
				'updated_at'        => $row->updated_at,
			);
		}
		return $out;
	}
}
