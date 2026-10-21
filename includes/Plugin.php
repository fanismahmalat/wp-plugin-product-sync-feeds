<?php
/**
 * Plugin wiring: builds the services once and registers their hooks.
 *
 * @package ProductSyncFeeds
 */

namespace ProductSyncFeeds;

use ProductSyncFeeds\Admin\Admin;
use ProductSyncFeeds\Cli\Command;
use ProductSyncFeeds\Feed\FeedRepository;
use ProductSyncFeeds\Feed\RunRepository;
use ProductSyncFeeds\Product\AttributeResolver;
use ProductSyncFeeds\Product\ProductQuery;
use ProductSyncFeeds\Product\RowBuilder;
use ProductSyncFeeds\Rest\RestController;
use ProductSyncFeeds\Support\Files;
use ProductSyncFeeds\Support\Logger;
use ProductSyncFeeds\Sync\ChangeTracker;
use ProductSyncFeeds\Sync\Generator;
use ProductSyncFeeds\Sync\Scheduler;
use ProductSyncFeeds\Sync\StateStore;
use ProductSyncFeeds\Transport\Uploader;

defined( 'ABSPATH' ) || exit;

/**
 * Service locator and bootstrapper.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Built services keyed by id.
	 *
	 * @var array<string,object>
	 */
	private $services = array();

	/**
	 * Get the plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register everything with WordPress.
	 */
	public function boot(): void {
		Installer::maybe_upgrade();

		load_plugin_textdomain( 'product-sync-feeds', false, dirname( plugin_basename( PSF_FILE ) ) . '/languages' );

		$this->change_tracker()->register();
		$this->scheduler()->register();
		( new RestController( $this->feeds(), $this->runs(), $this->state(), $this->scheduler() ) )->register();

		if ( is_admin() ) {
			( new Admin( $this->feeds(), $this->runs(), $this->scheduler(), $this->files(), $this->uploader() ) )->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'psf', new Command( $this->feeds(), $this->runs(), $this->scheduler(), $this->generator() ) );
		}
	}

	/**
	 * Lazily build a service.
	 *
	 * @param string   $id      Service id.
	 * @param callable $factory Builds the service.
	 * @return object
	 */
	private function get( string $id, callable $factory ) {
		if ( ! isset( $this->services[ $id ] ) ) {
			$this->services[ $id ] = $factory();
		}
		return $this->services[ $id ];
	}

	/** @return FeedRepository */
	public function feeds(): FeedRepository {
		return $this->get( 'feeds', static fn() => new FeedRepository() );
	}

	/** @return RunRepository */
	public function runs(): RunRepository {
		return $this->get( 'runs', static fn() => new RunRepository() );
	}

	/** @return StateStore */
	public function state(): StateStore {
		return $this->get( 'state', static fn() => new StateStore() );
	}

	/** @return Files */
	public function files(): Files {
		return $this->get( 'files', static fn() => new Files() );
	}

	/** @return Logger */
	public function logger(): Logger {
		return $this->get( 'logger', static fn() => new Logger() );
	}

	/** @return Uploader */
	public function uploader(): Uploader {
		return $this->get( 'uploader', static fn() => new Uploader() );
	}

	/** @return ChangeTracker */
	public function change_tracker(): ChangeTracker {
		return $this->get( 'change_tracker', static fn() => new ChangeTracker() );
	}

	/** @return ProductQuery */
	public function product_query(): ProductQuery {
		return $this->get( 'product_query', fn() => new ProductQuery( $this->state() ) );
	}

	/** @return RowBuilder */
	public function row_builder(): RowBuilder {
		return $this->get( 'row_builder', static fn() => new RowBuilder( new AttributeResolver() ) );
	}

	/** @return Generator */
	public function generator(): Generator {
		return $this->get(
			'generator',
			fn() => new Generator(
				$this->feeds(),
				$this->runs(),
				$this->state(),
				$this->product_query(),
				$this->row_builder(),
				$this->files(),
				$this->uploader(),
				$this->logger()
			)
		);
	}

	/** @return Scheduler */
	public function scheduler(): Scheduler {
		return $this->get( 'scheduler', fn() => new Scheduler( $this->feeds(), $this->runs(), $this->state(), $this->generator(), $this->logger() ) );
	}
}
