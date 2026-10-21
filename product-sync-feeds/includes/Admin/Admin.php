<?php
/**
 * Admin screens under WooCommerce > Product Sync Feeds.
 *
 * @package ProductSyncFeeds
 */

namespace ProductSyncFeeds\Admin;

use ProductSyncFeeds\Feed\Channels;
use ProductSyncFeeds\Feed\Feed;
use ProductSyncFeeds\Feed\FeedConfig;
use ProductSyncFeeds\Feed\FeedRepository;
use ProductSyncFeeds\Feed\RunRepository;
use ProductSyncFeeds\Plugin;
use ProductSyncFeeds\Support\Files;
use ProductSyncFeeds\Sync\Scheduler;
use ProductSyncFeeds\Transport\Uploader;

defined( 'ABSPATH' ) || exit;

/**
 * Admin controller.
 */
class Admin {

	const PAGE = 'psf-feeds';
	const CAP  = 'manage_woocommerce';

	/** @var FeedRepository */
	private $feeds;
	/** @var RunRepository */
	private $runs;
	/** @var Scheduler */
	private $scheduler;
	/** @var Files */
	private $files;
	/** @var Uploader */
	private $uploader;

	/**
	 * Constructor.
	 *
	 * @param FeedRepository $feeds     Feeds.
	 * @param RunRepository  $runs      Runs.
	 * @param Scheduler      $scheduler Scheduler.
	 * @param Files          $files     Files.
	 * @param Uploader       $uploader  Uploader.
	 */
	public function __construct( FeedRepository $feeds, RunRepository $runs, Scheduler $scheduler, Files $files, Uploader $uploader ) {
		$this->feeds     = $feeds;
		$this->runs      = $runs;
		$this->scheduler = $scheduler;
		$this->files     = $files;
		$this->uploader  = $uploader;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_psf_save_feed', array( $this, 'save' ) );
		add_action( 'admin_post_psf_feed_action', array( $this, 'action' ) );
		add_action( 'wp_ajax_psf_test_upload', array( $this, 'ajax_test_upload' ) );
		add_action( 'wp_ajax_psf_status', array( $this, 'ajax_status' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PSF_FILE ), array( $this, 'plugin_links' ) );
	}

	/**
	 * Menu entry.
	 */
	public function menu(): void {
		add_submenu_page( 'woocommerce', __( 'Product Sync Feeds', 'product-sync-feeds' ), __( 'Product Sync Feeds', 'product-sync-feeds' ), self::CAP, self::PAGE, array( $this, 'render' ) );
	}

	/**
	 * Settings link on the plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function plugin_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Feeds', 'product-sync-feeds' ) . '</a>' );
		return $links;
	}

	/**
	 * Admin page URL.
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Signed action URL.
	 *
	 * @param string $action  Action.
	 * @param int    $feed_id Feed id.
	 * @param array  $extra   Extra args.
	 * @return string
	 */
	public static function action_url( string $action, int $feed_id, array $extra = array() ): string {
		return wp_nonce_url(
			add_query_arg(
				array_merge(
					array(
						'action'   => 'psf_feed_action',
						'do'       => $action,
						'feed'     => $feed_id,
					),
					$extra
				),
				admin_url( 'admin-post.php' )
			),
			'psf_' . $action . '_' . $feed_id
		);
	}

	/**
	 * Enqueue CSS/JS on our page.
	 *
	 * @param string $hook Screen hook.
	 */
	public function assets( string $hook ): void {
		if ( false === strpos( $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'psf-admin', PSF_URL . 'assets/admin.css', array(), PSF_VERSION );
		wp_enqueue_script( 'psf-admin', PSF_URL . 'assets/admin.js', array(), PSF_VERSION, true );

		$defaults = array();
		foreach ( array_keys( Channels::all() ) as $channel ) {
			$defaults[ $channel ] = array(
				'mapping' => Channels::default_mapping( $channel ),
				'formats' => Channels::get( $channel )['formats'],
			);
		}
		wp_localize_script(
			'psf-admin',
			'psfAdmin',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'psf_admin' ),
				'channels' => $defaults,
				'i18n'     => array(
					'loadDefaults' => __( 'Replace the current mapping with the channel defaults?', 'product-sync-feeds' ),
					'testing'      => __( 'Connecting...', 'product-sync-feeds' ),
					'confirm'      => __( 'Are you sure?', 'product-sync-feeds' ),
				),
			)
		);
	}

	/**
	 * Route to a view.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to manage feeds.', 'product-sync-feeds' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing.
		$view    = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'list';
		$feed_id = isset( $_GET['feed'] ) ? absint( $_GET['feed'] ) : 0;
		$notice  = isset( $_GET['psf_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['psf_notice'] ) ) : '';
		$error   = isset( $_GET['psf_error'] ) ? sanitize_text_field( wp_unslash( $_GET['psf_error'] ) ) : '';
		// phpcs:enable

		echo '<div class="wrap psf-wrap">';
		if ( $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
		}
		if ( $error ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $error ) . '</p></div>';
		}

		if ( 'edit' === $view ) {
			$feed = $feed_id ? $this->feeds->find( $feed_id ) : null;
			if ( $feed_id && ! $feed ) {
				echo '<p>' . esc_html__( 'Feed not found.', 'product-sync-feeds' ) . '</p></div>';
				return;
			}
			$this->render_edit( $feed );
		} elseif ( 'runs' === $view && $feed_id ) {
			$feed = $this->feeds->find( $feed_id );
			if ( $feed ) {
				$runs  = $this->runs->latest( $feed->id, 100 );
				$files = $this->files;
				include __DIR__ . '/views/runs.php';
			}
		} else {
			$feeds     = $this->feeds->all();
			$runs      = $this->runs;
			$files     = $this->files;
			$scheduler = $this->scheduler;
			include __DIR__ . '/views/list.php';
		}
		echo '</div>';
	}

	/**
	 * Edit / create screen.
	 *
	 * @param Feed|null $feed Feed or null for new.
	 */
	private function render_edit( ?Feed $feed ): void {
		$is_new = null === $feed;
		if ( $is_new ) {
			$feed         = new Feed();
			$feed->name   = __( 'New feed', 'product-sync-feeds' );
			$feed->config = FeedConfig::normalize( array() );
		}
		$config     = $feed->config;
		$channels   = Channels::all();
		$categories = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 2000,
			)
		);
		$tags       = get_terms(
			array(
				'taxonomy'   => 'product_tag',
				'hide_empty' => false,
				'number'     => 2000,
			)
		);
		$categories = is_array( $categories ) ? $this->hierarchical( $categories ) : array();
		$tags       = is_array( $tags ) ? $tags : array();
		$files      = $this->files;
		include __DIR__ . '/views/edit.php';
	}

	/**
	 * Sort terms parent-first and add a depth property for indentation.
	 *
	 * @param \WP_Term[] $terms Terms.
	 * @return \WP_Term[]
	 */
	private function hierarchical( array $terms ): array {
		$children = array();
		foreach ( $terms as $term ) {
			$children[ (int) $term->parent ][] = $term;
		}
		$out  = array();
		$walk = static function ( int $parent, int $depth ) use ( &$walk, &$out, $children ) {
			foreach ( $children[ $parent ] ?? array() as $term ) {
				$term->psf_depth = $depth;
				$out[]           = $term;
				$walk( (int) $term->term_id, $depth + 1 );
			}
		};
		$walk( 0, 0 );
		return $out;
	}

	/**
	 * Save handler.
	 */
	public function save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to manage feeds.', 'product-sync-feeds' ) );
		}
		check_admin_referer( 'psf_save_feed' );

		$feed_id  = isset( $_POST['feed_id'] ) ? absint( $_POST['feed_id'] ) : 0;
		$existing = $feed_id ? $this->feeds->find( $feed_id ) : null;
		$name     = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$name     = '' !== $name ? $name : __( 'Untitled feed', 'product-sync-feeds' );
		$status   = empty( $_POST['status'] ) ? 0 : 1;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized field by field in FeedConfig::sanitize().
		$input  = isset( $_POST['config'] ) && is_array( $_POST['config'] ) ? wp_unslash( $_POST['config'] ) : array();
		$config = FeedConfig::sanitize( $input, $existing ? $existing->config : array() );

		if ( $existing ) {
			$existing->name   = $name;
			$existing->status = $status;
			$existing->config = $config;
			$this->feeds->save( $existing );
			$feed = $existing;
		} else {
			$feed_id = $this->feeds->create( $name, $config, $status );
			$feed    = $feed_id ? $this->feeds->find( $feed_id ) : null;
			if ( ! $feed ) {
				wp_safe_redirect( self::url( array( 'psf_error' => __( 'Could not create the feed.', 'product-sync-feeds' ) ) ) );
				exit;
			}
		}

		$this->scheduler->sync( $feed );

		$args = array(
			'view'       => 'edit',
			'feed'       => $feed->id,
			'psf_notice' => __( 'Feed saved.', 'product-sync-feeds' ),
		);
		if ( ! empty( $_POST['save_and_run'] ) ) {
			$run = $this->scheduler->run_now( $feed, 'full' );
			if ( is_wp_error( $run ) ) {
				$args['psf_error'] = $run->get_error_message();
			} else {
				$args['psf_notice'] = __( 'Feed saved. A full run was started.', 'product-sync-feeds' );
			}
		}
		$tab = isset( $_POST['psf_tab'] ) ? sanitize_key( wp_unslash( $_POST['psf_tab'] ) ) : '';
		wp_safe_redirect( self::url( $args ) . ( $tab ? '#' . $tab : '' ) );
		exit;
	}

	/**
	 * Row actions.
	 */
	public function action(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to manage feeds.', 'product-sync-feeds' ) );
		}
		$do      = isset( $_GET['do'] ) ? sanitize_key( $_GET['do'] ) : '';
		$feed_id = isset( $_GET['feed'] ) ? absint( $_GET['feed'] ) : 0;
		check_admin_referer( 'psf_' . $do . '_' . $feed_id );

		$feed = $this->feeds->find( $feed_id );
		if ( ! $feed ) {
			wp_safe_redirect( self::url( array( 'psf_error' => __( 'Feed not found.', 'product-sync-feeds' ) ) ) );
			exit;
		}

		$plugin = Plugin::instance();
		$args   = array();
		switch ( $do ) {
			case 'run_full':
			case 'run_delta':
				$result = $this->scheduler->run_now( $feed, 'run_full' === $do ? 'full' : 'delta' );
				if ( is_wp_error( $result ) ) {
					$args['psf_error'] = $result->get_error_message();
				} else {
					$args['psf_notice'] = __( 'Run started. It continues in the background.', 'product-sync-feeds' );
				}
				break;
			case 'cancel':
				$active = $this->runs->active_for( $feed->id );
				if ( $active ) {
					$plugin->generator()->cancel( $active->id );
				}
				$args['psf_notice'] = __( 'Run cancelled.', 'product-sync-feeds' );
				break;
			case 'toggle':
				$feed->status = $feed->status ? 0 : 1;
				$this->feeds->save( $feed );
				$this->scheduler->sync( $feed );
				$args['psf_notice'] = $feed->status ? __( 'Feed activated.', 'product-sync-feeds' ) : __( 'Feed paused.', 'product-sync-feeds' );
				break;
			case 'reset_state':
				if ( $this->runs->active_for( $feed->id ) ) {
					$args['psf_error'] = __( 'Wait for the current run to finish.', 'product-sync-feeds' );
					break;
				}
				$plugin->state()->reset( $feed->id );
				$this->feeds->update_fields( $feed->id, array( 'delta_cursor' => 0 ) );
				$args['psf_notice'] = __( 'Sync state reset. The next run is a new baseline.', 'product-sync-feeds' );
				break;
			case 'regenerate_key':
				$this->feeds->update_fields( $feed->id, array( 'api_key' => wp_generate_password( 32, false, false ) ) );
				$args = array(
					'view'       => 'edit',
					'feed'       => $feed->id,
					'psf_notice' => __( 'API key regenerated.', 'product-sync-feeds' ),
				);
				break;
			case 'duplicate':
				$copy_config                       = $feed->config;
				$copy_config['upload']['enabled']  = false;
				/* translators: %s: feed name */
				$new_id = $this->feeds->create( sprintf( __( '%s (copy)', 'product-sync-feeds' ), $feed->name ), $copy_config, 0 );
				$args   = $new_id ? array(
					'view'       => 'edit',
					'feed'       => $new_id,
					'psf_notice' => __( 'Feed duplicated (paused, upload off).', 'product-sync-feeds' ),
				) : array( 'psf_error' => __( 'Could not duplicate the feed.', 'product-sync-feeds' ) );
				break;
			case 'delete':
				$active = $this->runs->active_for( $feed->id );
				if ( $active ) {
					$plugin->generator()->cancel( $active->id );
				}
				$this->scheduler->unschedule( $feed->id );
				$plugin->state()->reset( $feed->id );
				$this->runs->delete_for( $feed->id );
				$this->files->delete_feed_files( $feed );
				$this->feeds->delete( $feed->id );
				$args['psf_notice'] = __( 'Feed deleted.', 'product-sync-feeds' );
				break;
		}

		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/**
	 * AJAX: test the upload settings currently in the form.
	 */
	public function ajax_test_upload(): void {
		check_ajax_referer( 'psf_admin', 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'product-sync-feeds' ) ), 403 );
		}
		$feed_id  = isset( $_POST['feed_id'] ) ? absint( $_POST['feed_id'] ) : 0;
		$existing = $feed_id ? $this->feeds->find( $feed_id ) : null;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in FeedConfig::sanitize().
		$upload = isset( $_POST['upload'] ) && is_array( $_POST['upload'] ) ? wp_unslash( $_POST['upload'] ) : array();
		$config = FeedConfig::sanitize( array( 'upload' => $upload ), $existing ? $existing->config : array() );
		$result = $this->uploader->test( $config['upload'] );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => __( 'Connected and listed the remote directory.', 'product-sync-feeds' ) ) );
	}

	/**
	 * AJAX: progress of running feeds for the list screen.
	 */
	public function ajax_status(): void {
		check_ajax_referer( 'psf_admin', 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( null, 403 );
		}
		$out = array();
		foreach ( $this->feeds->all() as $feed ) {
			$run               = $this->runs->active_for( $feed->id );
			$out[ $feed->id ] = $run ? array(
				'type'      => $run->type,
				'processed' => $run->processed,
				'total'     => $run->total,
			) : null;
		}
		wp_send_json_success( $out );
	}
}
