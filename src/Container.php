<?php
/**
 * Minimal service container.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Lazily builds shared services from registered factories.
 */
final class Container {

	/**
	 * Service factories keyed by ID.
	 *
	 * @var array<string, callable>
	 */
	private array $factories = array();

	/**
	 * Built services keyed by ID.
	 *
	 * @var array<string, mixed>
	 */
	private array $instances = array();

	/**
	 * Registers a factory. The factory receives the container.
	 *
	 * @param string   $id      Service ID.
	 * @param callable $factory Factory callback.
	 */
	public function set( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->instances[ $id ] );
	}

	/**
	 * Whether a service is registered.
	 *
	 * @param string $id Service ID.
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] ) || isset( $this->instances[ $id ] );
	}

	/**
	 * Returns a shared service instance.
	 *
	 * @param string $id Service ID.
	 * @return mixed
	 * @throws InvalidArgumentException When the service is unknown.
	 */
	public function get( string $id ) {
		if ( array_key_exists( $id, $this->instances ) ) {
			return $this->instances[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new InvalidArgumentException( sprintf( 'Unknown service "%s".', esc_html( $id ) ) );
		}

		$this->instances[ $id ] = ( $this->factories[ $id ] )( $this );

		return $this->instances[ $id ];
	}
}
