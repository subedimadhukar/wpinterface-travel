<?php
/**
 * Base for importers from other travel plugins.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Import;

use WP_Error;
use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Content\TripMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Copies trips from another plugin's data into WPInterface Travel trips.
 *
 * Importers only read the other plugin's data, which stays untouched, and
 * work whether or not that plugin is still active. Each copied trip
 * remembers its source (`_wpitravel_imported_from`), so running an import
 * again skips trips already copied. Bookings and customers are not
 * imported.
 */
abstract class Importer {

	public const SOURCE_META = '_wpitravel_imported_from';

	/**
	 * Trips per batch.
	 */
	public const BATCH = 10;

	/**
	 * Importer ID, e.g. wp-travel.
	 */
	abstract public function id(): string;

	/**
	 * Name of the source plugin.
	 */
	abstract public function label(): string;

	/**
	 * Source IDs of every trip to import, oldest first.
	 *
	 * @return list<int>
	 */
	abstract protected function source_ids(): array;

	/**
	 * Builds the trip from one source trip.
	 *
	 * @param int $source_id Source ID.
	 * @return array{post: array<string, mixed>, meta: array<string, mixed>, terms: array<string, list<string>>, thumbnail: int, notes: list<string>}|null
	 */
	abstract protected function read( int $source_id ): ?array;

	/**
	 * Number of trips found in the source.
	 */
	public function count(): int {
		return count( $this->source_ids() );
	}

	/**
	 * Summary for the import screen.
	 *
	 * @return array{id: string, label: string, count: int, imported: int}
	 */
	public function describe(): array {
		return array(
			'id'       => $this->id(),
			'label'    => $this->label(),
			'count'    => $this->count(),
			'imported' => count( $this->imported_map() ),
		);
	}

	/**
	 * Imports one batch.
	 *
	 * @param int $offset Position in the source list.
	 * @return array{created: list<int>, skipped: int, notes: list<string>, next: int|null, total: int}
	 */
	public function run( int $offset = 0 ): array {
		$ids     = $this->source_ids();
		$done    = $this->imported_map();
		$created = array();
		$skipped = 0;
		$notes   = array();

		foreach ( array_slice( $ids, $offset, self::BATCH ) as $source_id ) {
			if ( isset( $done[ $this->id() . ':' . $source_id ] ) ) {
				++$skipped;
				continue;
			}

			$trip = $this->read( $source_id );

			if ( ! $trip ) {
				++$skipped;
				continue;
			}

			$post_id = $this->write( $source_id, $trip );

			if ( is_wp_error( $post_id ) ) {
				/* translators: 1: trip name, 2: error. */
				$notes[] = sprintf( __( '%1$s was not imported: %2$s', 'wpinterface-travel' ), (string) $trip['post']['post_title'], $post_id->get_error_message() );
				continue;
			}

			$created[] = $post_id;

			foreach ( $trip['notes'] as $note ) {
				$notes[] = sprintf( '%s: %s', (string) $trip['post']['post_title'], $note );
			}
		}

		$next = $offset + self::BATCH;

		return array(
			'created' => $created,
			'skipped' => $skipped,
			'notes'   => $notes,
			'next'    => $next < count( $ids ) ? $next : null,
			'total'   => count( $ids ),
		);
	}

	/**
	 * Creates the trip.
	 *
	 * @param int                  $source_id Source ID.
	 * @param array<string, mixed> $trip      Trip from read().
	 * @return int|WP_Error
	 */
	protected function write( int $source_id, array $trip ) {
		$post = wp_parse_args(
			$trip['post'],
			array(
				'post_status'  => 'draft',
				'post_content' => '',
				'post_excerpt' => '',
			)
		);

		$post['post_type']   = PostTypes::TRIP;
		$post['post_status'] = in_array( $post['post_status'], array( 'publish', 'draft', 'pending', 'private', 'future' ), true ) ? $post['post_status'] : 'draft';

		$post_id = wp_insert_post( wp_slash( $post ), true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, self::SOURCE_META, $this->id() . ':' . $source_id );

		foreach ( $trip['meta'] as $key => $value ) {
			if ( null !== $value && array() !== $value && '' !== $value ) {
				update_post_meta( $post_id, $key, wp_slash( $value ) );
			}
		}

		foreach ( $trip['terms'] as $taxonomy => $names ) {
			$names = array_values( array_filter( array_map( 'trim', $names ) ) );

			if ( $names ) {
				wp_set_object_terms( $post_id, $names, $taxonomy );
			}
		}

		if ( $trip['thumbnail'] > 0 && wp_attachment_is_image( $trip['thumbnail'] ) ) {
			set_post_thumbnail( $post_id, $trip['thumbnail'] );
		}

		return (int) $post_id;
	}

	/**
	 * Already-imported trips: "importer:source_id" => trip ID.
	 *
	 * @return array<string, int>
	 */
	protected function imported_map(): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s", self::SOURCE_META, $wpdb->esc_like( $this->id() . ':' ) . '%' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One lookup per batch.
		$map  = array();

		foreach ( (array) $rows as $row ) {
			if ( get_post( (int) $row['post_id'] ) ) {
				$map[ (string) $row['meta_value'] ] = (int) $row['post_id'];
			}
		}

		return $map;
	}

	/**
	 * Post IDs of a post type in the source, read directly so it works
	 * while the source plugin is inactive (its post type unregistered).
	 *
	 * @param string $post_type Post type.
	 * @return list<int>
	 */
	protected static function post_ids( string $post_type ): array {
		global $wpdb;

		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('publish','draft','pending','private','future') ORDER BY ID", $post_type ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Source plugin may be inactive.
	}

	/**
	 * Term names of a post in a taxonomy, read directly (the taxonomy may
	 * not be registered while the source plugin is inactive).
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy.
	 * @return list<string>
	 */
	protected static function term_names( int $post_id, string $taxonomy ): array {
		global $wpdb;

		return array_map(
			static fn( $name ): string => html_entity_decode( (string) $name, ENT_QUOTES, 'UTF-8' ),
			(array) $wpdb->get_col( $wpdb->prepare( "SELECT t.name FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tr.object_id = %d AND tt.taxonomy = %s ORDER BY t.name", $post_id, $taxonomy ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Source plugin may be inactive.
		);
	}

	/**
	 * Name of a term by ID, read directly.
	 *
	 * @param int $term_id Term ID.
	 */
	protected static function term_name( int $term_id ): string {
		global $wpdb;

		return html_entity_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->terms} WHERE term_id = %d", $term_id ) ), ENT_QUOTES, 'UTF-8' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Source plugin may be inactive.
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Full table name.
	 */
	protected static function table_exists( string $table ): bool {
		global $wpdb;

		// A direct probe also finds temporary tables, which SHOW TABLES does not list.
		$suppress = $wpdb->suppress_errors( true );
		$found    = false !== $wpdb->query( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 0', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Schema check.
		$wpdb->suppress_errors( $suppress );

		return $found;
	}

	/**
	 * The post row of a source trip.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	protected static function post_fields( int $post_id ): array {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return array();
		}

		return array(
			'post_title'   => $post->post_title,
			'post_content' => $post->post_content,
			'post_excerpt' => $post->post_excerpt,
			'post_status'  => $post->post_status,
			'post_name'    => $post->post_name,
			'post_date'    => $post->post_date,
			'menu_order'   => $post->menu_order,
			'post_author'  => $post->post_author,
		);
	}

	/**
	 * Turns an HTML list or text with one item per line into a list.
	 *
	 * @param mixed $value HTML or text.
	 * @return list<string>
	 */
	protected static function to_list( $value ): array {
		$value = is_string( $value ) ? $value : '';

		if ( preg_match_all( '#<li[^>]*>(.*?)</li>#is', $value, $matches ) ) {
			$items = $matches[1];
		} else {
			$items = preg_split( '#(?:<br\s*/?>|</p>|\r\n|\n|\r)#i', $value );
		}

		$items = array_map( static fn( $item ): string => trim( html_entity_decode( wp_strip_all_tags( (string) $item ), ENT_QUOTES, 'UTF-8' ), " \t\n\r\0\x0B-•*" ), (array) $items );

		return array_values( array_filter( $items, static fn( string $item ): bool => '' !== $item ) );
	}

	/**
	 * Our difficulty key for a name such as "Moderate" ('' when unknown).
	 *
	 * @param string $name Name.
	 */
	protected static function difficulty( string $name ): string {
		$name = strtolower( trim( $name ) );

		foreach ( TripMeta::difficulties() as $key => $label ) {
			if ( $name === $key || strtolower( $label ) === $name ) {
				return $key;
			}
		}

		$synonyms = array(
			'beginner'     => 'easy',
			'light'        => 'easy',
			'medium'       => 'moderate',
			'intermediate' => 'moderate',
			'hard'         => 'challenging',
			'difficult'    => 'challenging',
			'demanding'    => 'challenging',
			'extreme'      => 'strenuous',
			'expert'       => 'strenuous',
		);

		return $synonyms[ $name ] ?? '';
	}

	/**
	 * A price option.
	 *
	 * @param string                                       $label      Option label.
	 * @param list<array{0: string, 1: string, 2: string}> $categories [label, regular price, sale price].
	 * @param int                                          $min_pax    Minimum travelers.
	 * @param int                                          $max_pax    Maximum travelers.
	 * @return array<string, mixed>|null
	 */
	protected static function price_option( string $label, array $categories, int $min_pax = 0, int $max_pax = 0 ): ?array {
		$rows = array();

		foreach ( $categories as $index => $category ) {
			list( $name, $regular, $sale ) = $category;

			if ( '' === trim( (string) $regular ) && '' === trim( (string) $sale ) ) {
				continue;
			}

			$name   = '' !== trim( $name ) ? $name : __( 'Adult', 'wpinterface-travel' );
			$rows[] = array(
				'key'        => sanitize_key( sanitize_title( $name ) ) ? sanitize_key( sanitize_title( $name ) ) : 'category-' . $index,
				'label'      => $name,
				'price'      => '' !== trim( (string) $regular ) ? (string) $regular : (string) $sale,
				'sale_price' => '' !== trim( (string) $regular ) && '' !== trim( (string) $sale ) && (float) $sale < (float) $regular ? (string) $sale : '',
				'min'        => 0 === $index ? 1 : 0,
			);
		}

		if ( ! $rows ) {
			return null;
		}

		$label = '' !== trim( $label ) ? $label : __( 'Standard', 'wpinterface-travel' );

		return array(
			'key'        => sanitize_title( $label ) ? sanitize_title( $label ) : 'standard',
			'label'      => $label,
			'min_pax'    => $min_pax,
			'max_pax'    => $max_pax,
			'categories' => $rows,
		);
	}

	/**
	 * Future dates (Y-m-d) from a list of date strings.
	 *
	 * @param string[] $dates Dates in any format strtotime() reads.
	 * @return list<array{date: string}>
	 */
	protected static function future_dates( array $dates ): array {
		$today = current_time( 'Y-m-d' );
		$out   = array();

		foreach ( $dates as $date ) {
			$time = strtotime( (string) $date );

			if ( $time ) {
				$date = gmdate( 'Y-m-d', $time );

				if ( $date >= $today ) {
					$out[ $date ] = array( 'date' => $date );
				}
			}
		}

		ksort( $out );

		return array_values( $out );
	}
}
