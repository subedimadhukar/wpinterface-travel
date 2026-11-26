<?php
/**
 * Importer registry.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Import;

defined( 'ABSPATH' ) || exit;

/**
 * The available importers, by ID.
 */
final class Importers {

	/**
	 * Every importer.
	 *
	 * @return array<string, Importer>
	 */
	public static function all(): array {
		$importers = array();

		/**
		 * Filters the importers. Add-ons can add their own (extend Importer).
		 *
		 * @param Importer[] $list Importers.
		 */
		$list = (array) apply_filters(
			'wpitravel_importers',
			array( new WpTravelImporter(), new WpTravelEngineImporter(), new YatraImporter() )
		);

		foreach ( $list as $importer ) {
			if ( $importer instanceof Importer ) {
				$importers[ $importer->id() ] = $importer;
			}
		}

		return $importers;
	}

	/**
	 * One importer.
	 *
	 * @param string $id ID.
	 */
	public static function get( string $id ): ?Importer {
		return self::all()[ $id ] ?? null;
	}

	/**
	 * Importers that found trips on this site.
	 *
	 * @return list<array{id: string, label: string, count: int, imported: int}>
	 */
	public static function detected(): array {
		$found = array();

		foreach ( self::all() as $importer ) {
			$summary = $importer->describe();

			if ( $summary['count'] > 0 ) {
				$found[] = $summary;
			}
		}

		return $found;
	}
}
