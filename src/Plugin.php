<?php
/**
 * Plugin bootstrap.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel;

use WPInterface\Travel\Admin\Admin;
use WPInterface\Travel\Announcements\AnnouncementBar;
use WPInterface\Travel\Announcements\AnnouncementsPage;
use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Content\TripIndex;
use WPInterface\Travel\Content\TripMeta;
use WPInterface\Travel\Emails\Triggers;
use WPInterface\Travel\Frontend\AccountPage;
use WPInterface\Travel\Frontend\BlockTemplates;
use WPInterface\Travel\Frontend\Blocks;
use WPInterface\Travel\Frontend\BookingWidget;
use WPInterface\Travel\Frontend\PaymentReturn;
use WPInterface\Travel\Frontend\ReviewsDisplay;
use WPInterface\Travel\Frontend\Schema;
use WPInterface\Travel\Frontend\Shortcodes;
use WPInterface\Travel\Frontend\Templates;
use WPInterface\Travel\Frontend\TripQuery;
use WPInterface\Travel\Frontend\Voucher;
use WPInterface\Travel\Install\Activator;
use WPInterface\Travel\Install\Cron;
use WPInterface\Travel\Install\Upgrader;
use WPInterface\Travel\Privacy\PersonalData;
use WPInterface\Travel\Rest\AvailabilityController;
use WPInterface\Travel\Rest\BookingController;
use WPInterface\Travel\Rest\BookingsAdminController;
use WPInterface\Travel\Rest\CustomerController;
use WPInterface\Travel\Rest\EmailsController;
use WPInterface\Travel\Rest\EnquiriesController;
use WPInterface\Travel\Rest\CouponsController;
use WPInterface\Travel\Rest\DeparturesController;
use WPInterface\Travel\Rest\PaymentsController;
use WPInterface\Travel\Rest\ReportsController;
use WPInterface\Travel\Rest\ReviewsController;
use WPInterface\Travel\Rest\SettingsController;
use WPInterface\Travel\Rest\SetupController;
use WPInterface\Travel\Rest\SystemController;
use WPInterface\Travel\Reviews\Reviews;
use WPInterface\Travel\Support\Capabilities;
use WPInterface\Travel\Support\Multilingual;

defined( 'ABSPATH' ) || exit;

/**
 * Wires services together and registers WordPress hooks.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Returns the shared instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Use instance().
	 */
	private function __construct() {
		$this->container = new Container();
		$this->register_services();
	}

	/**
	 * Registers hooks. Runs on `plugins_loaded`.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// On init, not here: migrations may create pages and use translations,
		// which need rewrite rules and the text domain to be ready.
		add_action( 'init', array( Upgrader::class, 'maybe_upgrade' ), 1 );

		( new Capabilities() )->register();
		( new PostTypes( $this->options() ) )->register();
		( new TripMeta() )->register();
		( new Multilingual() )->register();
		( new \WPInterface\Travel\Booking\Pickups() )->register();
		( new \WPInterface\Travel\Elementor\Elementor() )->register();
		( new Cron() )->register();
		( new Triggers() )->register();
		( new Reviews() )->register();
		( new PersonalData() )->register();
		( new TripIndex() )->register();
		( new \WPInterface\Travel\Abilities\Abilities() )->register();
		( new Blocks() )->register();
		( new BlockTemplates() )->register();

		if ( ! is_admin() ) {
			( new TripQuery() )->register();
			( new Templates() )->register();
			( new Shortcodes() )->register();
			( new Schema() )->register();
			( new BookingWidget() )->register();
			( new PaymentReturn() )->register();
			( new AccountPage() )->register();
			( new ReviewsDisplay() )->register();
			( new Voucher() )->register();
			( new AnnouncementBar() )->register();
		}

		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 999 );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'wp_initialize_site', array( Activator::class, 'on_new_site' ), 200 );
		add_action( 'wpitravel_settings_updated', array( $this, 'on_settings_updated' ), 10, 2 );

		if ( is_admin() ) {
			( new Admin() )->register();
			( new AnnouncementsPage() )->register();
		}

		/**
		 * Fires once the plugin has registered its hooks. Pro and add-ons boot here.
		 *
		 * @param Plugin $plugin Plugin instance.
		 */
		do_action( 'wpitravel_loaded', $this );
	}

	/**
	 * Registers core services.
	 */
	private function register_services(): void {
		$this->container->set( 'options', static fn() => new Options() );
	}

	/**
	 * Registers REST controllers.
	 */
	public function register_rest_routes(): void {
		( new SystemController( $this ) )->register_routes();
		( new SettingsController( $this ) )->register_routes();
		( new AvailabilityController( $this ) )->register_routes();
		( new DeparturesController( $this ) )->register_routes();
		( new BookingController( $this ) )->register_routes();
		( new BookingsAdminController( $this ) )->register_routes();
		( new CouponsController( $this ) )->register_routes();
		( new PaymentsController( $this ) )->register_routes();
		( new CustomerController( $this ) )->register_routes();
		( new EnquiriesController( $this ) )->register_routes();
		( new ReviewsController( $this ) )->register_routes();
		( new EmailsController( $this ) )->register_routes();
		( new ReportsController( $this ) )->register_routes();
		( new SetupController( $this ) )->register_routes();

		/**
		 * Fires after core REST routes are registered, for Pro and add-ons.
		 *
		 * @param Plugin $plugin Plugin instance.
		 */
		do_action( 'wpitravel_register_rest_routes', $this );
	}

	/**
	 * Flushes rewrite rules once after activation, an upgrade or a permalink
	 * change, after the post type has registered its rules on `init`.
	 */
	public function maybe_flush_rewrite_rules(): void {
		if ( get_option( 'wpitravel_flush_rewrite_rules' ) ) {
			delete_option( 'wpitravel_flush_rewrite_rules' );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Schedules a rewrite flush when permalink bases change.
	 *
	 * The new rules only exist after the post type re-registers on the next
	 * request, so flushing now would store the old slugs.
	 *
	 * @param array<string, mixed> $settings New settings.
	 * @param array<string, mixed> $old      Previous settings.
	 */
	public function on_settings_updated( $settings, $old ): void {
		if ( ( $settings['permalinks'] ?? null ) !== ( $old['permalinks'] ?? null ) ) {
			update_option( 'wpitravel_flush_rewrite_rules', 1, true );
		}
	}

	/**
	 * Service container, for Pro and add-ons to register or replace services.
	 */
	public function container(): Container {
		return $this->container;
	}

	/**
	 * Settings service.
	 */
	public function options(): Options {
		return $this->container->get( 'options' );
	}
}
