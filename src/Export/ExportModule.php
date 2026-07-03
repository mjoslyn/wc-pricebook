<?php
/**
 * WordPress integration for the pricelist export.
 *
 * Wires {@see PricelistExporter} to the three ways a store runs it:
 *   - WP-CLI:  `wp wc-pricebook export-pricelist [--file] [--email] [--roles] [--send]`
 *   - Cron:    a daily/weekly scheduled event that emails the configured recipient.
 *   - Admin:   the settings-page "Send now" button (admin-post handler).
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
		add_action( 'update_option_' . Config::OPTION, array( $this, 'reschedule' ), 10, 2 );
		add_action( 'add_option_' . Config::OPTION, array( $this, 'reschedule_added' ), 10, 2 );
		add_action( 'admin_post_' . self::ACTION_NOW, array( $this, 'handle_send_now' ) );
		add_action( 'admin_notices', array( $this, 'maybe_notice' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'wc-pricebook export-pricelist', array( $this, 'cli_export' ) );
		}
	}

	/**
	 * The scheduled cron callback: build the pricelist and email the configured
	 * recipient (or the site admin), then clean up the temp file.
	 *
	 * @return void
	 */
	public function run_cron() {
		$result = $this->exporter->run_and_email( $this->recipient(), array( 'roles' => $this->config->export()['roles'] ) );
		$this->cleanup( $result['file'] );
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

		$status = 'sent';
		if ( '' === $recipient ) {
			$status = 'norecipient';
		} else {
			try {
				$result = $this->exporter->run_and_email( $recipient, array( 'roles' => $this->config->export()['roles'] ) );
				$this->cleanup( $result['file'] );
				$status = $result['sent'] ? 'sent' : 'mailfail';
			} catch ( \RuntimeException $e ) {
				$status = 'error';
			}
		}

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
	 * ## EXAMPLES
	 *
	 *     wp wc-pricebook export-pricelist --file=/tmp/pricelist.csv
	 *     wp wc-pricebook export-pricelist --email=sales@example.com
	 *     wp wc-pricebook export-pricelist --roles=dealer,operator --send
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
