<?php
/**
 * WordPress integration for the pricelist export.
 *
 * Wires {@see PricelistExporter} to the ways a store runs it:
 *   - WP-CLI:  `wp wc-pricebook export-pricelist [--file] [--email] [--roles] [--send] [--async]`
 *   - Cron:    a daily/weekly scheduled event that emails the configured recipient.
 *   - Admin:   the settings-page "Send now" button (admin-post handler).
 *
 * Cron and the "Send now" button run the export **in the background** via Action
 * Scheduler (bundled with WooCommerce): the product list is captured once, then users
 * are processed in bounded batches so no single request builds the whole file — this is
 * what stops a large store from timing out. The finished CSV is emailed after the last
 * batch. When Action Scheduler is unavailable the run falls back to synchronous mode.
 * The WP-CLI command stays synchronous by default (no request timeout).
 *
 * The cron schedule is (re)synced whenever the config option is saved. Nothing here
 * touches pricing logic — it only orchestrates when/where the CSV is produced and sent.
 *
 * @package WCPricebook
 */

namespace WCPricebook\Export;

use WCPricebook\Config;
use WCPricebook\PriceEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers cron, WP-CLI, and the admin "Send now" action for the pricelist export.
 */
class ExportModule {

	/**
	 * Cron event hook name.
	 */
	const CRON_HOOK = 'wc_pricebook_export_cron';

	/**
	 * admin-post action for the "Send now" button.
	 */
	const ACTION_NOW = 'wc_pricebook_export_now';

	/**
	 * Action Scheduler hook for a single background batch of users.
	 */
	const BATCH_HOOK = 'wc_pricebook_export_batch';

	/**
	 * Option holding the in-progress run's state ({ run, file, recipient, roles, page,
	 * per_page, rows }). Non-autoloaded.
	 */
	const STATE_OPTION = 'wc_pricebook_export_state';

	/**
	 * Option holding the run's captured product refs. Non-autoloaded.
	 */
	const PRODUCTS_OPTION = 'wc_pricebook_export_products';

	/**
	 * Config provider.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Exporter core.
	 *
	 * @var PricelistExporter
	 */
	private $exporter;

	/**
	 * Constructor.
	 *
	 * @param Config      $config Plugin config.
	 * @param PriceEngine $engine Price engine.
	 */
	public function __construct( Config $config, PriceEngine $engine ) {
		$this->config   = $config;
		$this->exporter = new PricelistExporter( $config, $engine );
	}

	/**
	 * Register hooks. Cron + admin-post are always registered (cron runs with no admin
	 * context); the WP-CLI command registers only under WP-CLI.
	 *
	 * @return void
	 */
	public function register() {
		add_action( self::CRON_HOOK, array( $this, 'run_cron' ) );
		add_action( self::BATCH_HOOK, array( $this, 'run_batch' ), 10, 1 );
		add_action( 'update_option_' . Config::OPTION, array( $this, 'reschedule' ), 10, 2 );
		add_action( 'add_option_' . Config::OPTION, array( $this, 'reschedule_added' ), 10, 2 );
		add_action( 'admin_post_' . self::ACTION_NOW, array( $this, 'handle_send_now' ) );
		add_action( 'admin_notices', array( $this, 'maybe_notice' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'wc-pricebook export-pricelist', array( $this, 'cli_export' ) );
		}
	}

	/**
	 * The scheduled cron callback: start a background export to the configured recipient
	 * (or the site admin). The heavy work runs in Action Scheduler batches, not in this
	 * (WP-Cron) request.
	 *
	 * @return void
	 */
	public function run_cron() {
		$this->start_background( $this->recipient(), $this->config->export()['roles'] );
	}

	/**
	 * Whether Action Scheduler (bundled with WooCommerce) is available to run the export
	 * in the background. When absent, callers fall back to a synchronous run.
	 *
	 * @return bool
	 */
	private function async_available() {
		return function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_has_scheduled_action' );
	}

	/**
	 * Whether a background export is currently in progress (state present and a batch
	 * still queued).
	 *
	 * @return bool
	 */
	private function is_running() {
		return false !== get_option( self::STATE_OPTION, false )
			&& $this->async_available()
			&& as_has_scheduled_action( self::BATCH_HOOK );
	}

	/**
	 * Start a background export: capture the product list, size the user batches to a
	 * target row count, write the CSV header, and queue the first batch. Each batch runs
	 * as an Action Scheduler job, so no single request builds the whole file.
	 *
	 * Falls back to a synchronous run + email when Action Scheduler is unavailable.
	 *
	 * @param string            $recipient Recipient email.
	 * @param array<int,string> $roles     Role filter (empty = all users).
	 * @return string Status: 'started', 'running', 'norecipient', 'sent', 'mailfail', or 'error'.
	 */
	public function start_background( $recipient, array $roles ) {
		if ( '' === (string) $recipient || ! is_email( $recipient ) ) {
			return 'norecipient';
		}

		// No Action Scheduler (e.g. WooCommerce inactive): run synchronously as before.
		if ( ! $this->async_available() ) {
			try {
				$result = $this->exporter->run_and_email( $recipient, array( 'roles' => $roles ) );
				$this->cleanup( $result['file'] );
				return $result['sent'] ? 'sent' : 'mailfail';
			} catch ( \RuntimeException $e ) {
				$this->log( 'Pricelist export failed: ' . $e->getMessage() );
				return 'error';
			}
		}

		if ( $this->is_running() ) {
			return 'running';
		}

		// A stale state with no queued batch (e.g. a previous run died) — reset it.
		$this->clear_run();

		$products = $this->exporter->product_refs();
		$file     = $this->run_file();
		try {
			$this->exporter->init_csv( $file );
		} catch ( \RuntimeException $e ) {
			$this->log( 'Pricelist export could not create its file: ' . $e->getMessage() );
			return 'error';
		}

		/**
		 * Filters the target number of CSV rows per background batch. Batches are sized
		 * so users-per-batch × products ≈ this, keeping each Action Scheduler run well
		 * inside a request's time/memory budget.
		 *
		 * @param int $rows Target rows per batch.
		 */
		$target   = (int) apply_filters( 'wc_pricebook_export_batch_rows', 5000 );
		$target   = $target > 0 ? $target : 5000;
		$per_page = max( 1, (int) floor( $target / max( 1, count( $products ) ) ) );
		$run      = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'run', true );

		add_option( self::PRODUCTS_OPTION, $products, '', 'no' );
		add_option(
			self::STATE_OPTION,
			array(
				'run'       => $run,
				'file'      => $file,
				'recipient' => $recipient,
				'roles'     => array_values( $roles ),
				'page'      => 1,
				'per_page'  => $per_page,
				'rows'      => 0,
			),
			'',
			'no'
		);

		as_enqueue_async_action( self::BATCH_HOOK, array( 'run' => $run ), 'wc-pricebook' );
		return 'started';
	}

	/**
	 * Process one batch of users (each priced against every product), then queue the
	 * next — or, when the users run out, email the finished CSV and clean up. Hooked to
	 * {@see self::BATCH_HOOK} via Action Scheduler.
	 *
	 * @param string $run Run token; a mismatch means the action is stale (superseded).
	 * @return void
	 */
	public function run_batch( $run = '' ) {
		$state = get_option( self::STATE_OPTION, false );
		if ( ! is_array( $state ) || ( '' !== (string) $run && ( $state['run'] ?? '' ) !== $run ) ) {
			return; // Stale or superseded action.
		}

		try {
			$products = get_option( self::PRODUCTS_OPTION, array() );
			$products = is_array( $products ) ? $products : array();

			$users = $this->exporter->user_refs_page( (array) $state['roles'], (int) $state['page'], (int) $state['per_page'] );

			if ( empty( $users ) ) {
				// Done — email the assembled file, then clean up.
				$sent = $this->exporter->email_file( $state['recipient'], $state['file'], (int) $state['rows'] );
				if ( ! $sent ) {
					$this->log( sprintf( 'Pricelist export assembled %d rows but wp_mail() failed to send to %s.', (int) $state['rows'], $state['recipient'] ) );
				}
				$this->cleanup( $state['file'] );
				$this->clear_run();
				return;
			}

			$state['rows'] += $this->exporter->append_rows( $state['file'], $users, $products );
			$state['page']  = (int) $state['page'] + 1;
			update_option( self::STATE_OPTION, $state, false );

			as_enqueue_async_action( self::BATCH_HOOK, array( 'run' => $state['run'] ), 'wc-pricebook' );
		} catch ( \Throwable $e ) {
			$this->log( 'Pricelist export batch failed: ' . $e->getMessage() );
			$this->cleanup( isset( $state['file'] ) ? $state['file'] : '' );
			$this->clear_run();
		}
	}

	/**
	 * Remove the run state + captured product list, and drop any queued batches.
	 *
	 * @return void
	 */
	private function clear_run() {
		delete_option( self::STATE_OPTION );
		delete_option( self::PRODUCTS_OPTION );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::BATCH_HOOK );
		}
	}

	/**
	 * A per-run CSV path under uploads/wc-pricebook (persists across batch requests).
	 *
	 * @return string
	 */
	private function run_file() {
		if ( function_exists( 'wp_upload_dir' ) ) {
			$uploads = wp_upload_dir();
			if ( empty( $uploads['error'] ) ) {
				$dir = rtrim( $uploads['basedir'], '/\\' ) . '/wc-pricebook';
				wp_mkdir_p( $dir );
				return $dir . '/pricelist-' . gmdate( 'Ymd-His' ) . '.csv';
			}
		}
		$tmp = function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir() . '/';
		return rtrim( $tmp, '/\\' ) . '/wc-pricebook-pricelist-' . gmdate( 'Ymd-His' ) . '.csv';
	}

	/**
	 * Log a message (WooCommerce logger when present, else the PHP error log).
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private function log( $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $message, array( 'source' => 'wc-pricebook-export' ) );
			return;
		}
		error_log( '[wc-pricebook] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Sync the cron schedule when the config option changes.
	 *
	 * @param mixed $old_value Previous option value.
	 * @param mixed $value     New option value.
	 * @return void
	 */
	public function reschedule( $old_value, $value ) {
		$schedule = 'off';
		if ( is_array( $value ) && isset( $value['export']['schedule'] ) ) {
			$schedule = (string) $value['export']['schedule'];
		}
		$this->apply_schedule( $schedule );
	}

	/**
	 * Sync the cron schedule when the option is first created.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Option value.
	 * @return void
	 */
	public function reschedule_added( $option, $value ) {
		$this->reschedule( null, $value );
	}

	/**
	 * Schedule, reschedule, or clear the cron event to match a cadence.
	 *
	 * @param string $schedule 'off', 'daily', or 'weekly'.
	 * @return void
	 */
	private function apply_schedule( $schedule ) {
		$schedule = in_array( $schedule, array( 'daily', 'weekly' ), true ) ? $schedule : 'off';
		$current  = wp_get_schedule( self::CRON_HOOK );

		if ( 'off' === $schedule ) {
			if ( false !== $current ) {
				wp_clear_scheduled_hook( self::CRON_HOOK );
			}
			return;
		}

		if ( $current === $schedule ) {
			return;
		}

		// Cadence changed (or nothing scheduled) — reset to the new recurrence.
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_schedule_event( time() + MINUTE_IN_SECONDS, $schedule, self::CRON_HOOK );
	}

	/**
	 * Handle the settings-page "Send now" button: generate the CSV and email it to the
	 * chosen recipient (defaults to the current admin user), then redirect back.
	 *
	 * @return void
	 */
	public function handle_send_now() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to run the pricelist export.', 'wc-pricebook' ) );
		}
		check_admin_referer( self::ACTION_NOW );

		$recipient = isset( $_POST['recipient'] ) ? sanitize_email( wp_unslash( $_POST['recipient'] ) ) : '';
		if ( '' === $recipient || ! is_email( $recipient ) ) {
			$recipient = $this->recipient();
		}

		// Queue the export in the background (synchronous fallback if Action Scheduler
		// is unavailable). The button returns immediately; the email follows.
		$status = $this->start_background( $recipient, $this->config->export()['roles'] );

		$redirect = add_query_arg(
			array(
				'page'                => 'wc-pricebook',
				'wc_pricebook_export' => $status,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Show the result of a "Send now" run as an admin notice on the settings page.
	 *
	 * @return void
	 */
	public function maybe_notice() {
		if ( ! isset( $_GET['wc_pricebook_export'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$status = sanitize_key( wp_unslash( $_GET['wc_pricebook_export'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$messages = array(
			'started'     => array( 'success', __( 'Pricelist export started. It runs in the background — you’ll get an email with the CSV when it finishes.', 'wc-pricebook' ) ),
			'running'     => array( 'warning', __( 'A pricelist export is already running. You’ll get the email when it finishes.', 'wc-pricebook' ) ),
			'sent'        => array( 'success', __( 'Pricelist export emailed.', 'wc-pricebook' ) ),
			'mailfail'    => array( 'error', __( 'The pricelist CSV was generated but the email could not be sent. Check the site’s mail configuration.', 'wc-pricebook' ) ),
			'norecipient' => array( 'error', __( 'No recipient email is set. Enter one in the Pricelist export settings.', 'wc-pricebook' ) ),
			'error'       => array( 'error', __( 'The pricelist export failed to generate. Check the server error log.', 'wc-pricebook' ) ),
		);
		if ( ! isset( $messages[ $status ] ) ) {
			return;
		}
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $status ][0] ),
			esc_html( $messages[ $status ][1] )
		);
	}

	/**
	 * WP-CLI: export the per-user pricelist to a CSV, optionally emailing it.
	 *
	 * ## OPTIONS
	 *
	 * [--file=<path>]
	 * : Write the CSV to this path. Defaults to a temp file whose path is printed.
	 *
	 * [--email=<address>]
	 * : Email the CSV to this address as an attachment. Implies generating it.
	 *
	 * [--roles=<slugs>]
	 * : Comma-separated WP role slugs to limit the export to. Default: every user.
	 *
	 * [--send]
	 * : Email the CSV to the configured recipient (or the site admin) instead of / in
	 *   addition to writing a file.
	 *
	 * [--async]
	 * : Queue the export via Action Scheduler (background batches) and email it when done,
	 *   instead of building it inline. Uses --email or the configured recipient.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wc-pricebook export-pricelist --file=/tmp/pricelist.csv
	 *     wp wc-pricebook export-pricelist --email=sales@example.com
	 *     wp wc-pricebook export-pricelist --roles=dealer,operator --send
	 *     wp wc-pricebook export-pricelist --async --email=sales@example.com
	 *
	 * @param array<int,string>    $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function cli_export( $args, $assoc_args ) {
		$roles = array();
		if ( ! empty( $assoc_args['roles'] ) ) {
			$roles = array_values( array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', (string) $assoc_args['roles'] ) ) ) ) );
		}
		$export_args = array( 'roles' => $roles );

		$email = isset( $assoc_args['email'] ) ? (string) $assoc_args['email'] : '';
		$send  = isset( $assoc_args['send'] );

		// Background mode: queue Action Scheduler batches and let them email when done.
		if ( isset( $assoc_args['async'] ) ) {
			$recipient = '' !== $email ? $email : $this->recipient();
			$status    = $this->start_background( $recipient, $roles );
			if ( in_array( $status, array( 'started', 'sent' ), true ) ) {
				\WP_CLI::success( sprintf( 'Queued the background pricelist export to %s. Run the Action Scheduler queue (e.g. `wp action-scheduler run`) to process it.', $recipient ) );
			} elseif ( 'running' === $status ) {
				\WP_CLI::warning( 'A background pricelist export is already running.' );
			} elseif ( 'norecipient' === $status ) {
				\WP_CLI::error( 'No valid recipient. Pass --email=<address> or configure a recipient in Pricebook settings.' );
			} else {
				\WP_CLI::error( 'Could not start the background export. Check the log.' );
			}
			return;
		}

		try {
			// Emailing: use the recipient flag, else the configured/admin recipient.
			if ( '' !== $email || $send ) {
				$recipient = '' !== $email ? $email : $this->recipient();
				if ( '' === $recipient || ! is_email( $recipient ) ) {
					\WP_CLI::error( 'No valid recipient. Pass --email=<address> or configure a recipient in Pricebook settings.' );
				}
				$result = $this->exporter->run_and_email( $recipient, $export_args );

				// Keep a copy at --file if requested; otherwise remove the temp file.
				if ( ! empty( $assoc_args['file'] ) ) {
					copy( $result['file'], (string) $assoc_args['file'] );
				}
				$this->cleanup( $result['file'] );

				if ( ! $result['sent'] ) {
					\WP_CLI::error( sprintf( 'Generated %d rows but wp_mail() failed to send to %s.', $result['count'], $recipient ) );
				}
				\WP_CLI::success( sprintf( 'Emailed %d rows to %s.', $result['count'], $recipient ) );
				return;
			}

			// File only.
			$file  = ! empty( $assoc_args['file'] ) ? (string) $assoc_args['file'] : $this->cli_default_file();
			$count = $this->exporter->write_csv( $file, $export_args );
			\WP_CLI::success( sprintf( 'Wrote %d rows to %s.', $count, $file ) );
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Default output path for the CLI file mode.
	 *
	 * @return string
	 */
	private function cli_default_file() {
		$dir = function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir() . '/';
		return rtrim( $dir, '/\\' ) . '/wc-pricebook-pricelist-' . gmdate( 'Ymd-His' ) . '.csv';
	}

	/**
	 * Resolve the configured recipient, falling back to the site admin email.
	 *
	 * @return string
	 */
	private function recipient() {
		$configured = $this->config->export()['recipient'];
		if ( '' !== $configured && is_email( $configured ) ) {
			return $configured;
		}
		return (string) get_option( 'admin_email' );
	}

	/**
	 * Remove a generated temp file.
	 *
	 * @param string $file File path.
	 * @return void
	 */
	private function cleanup( $file ) {
		if ( '' !== (string) $file && file_exists( $file ) ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
}
