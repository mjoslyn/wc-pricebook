<?php
/**
 * Pricelist exporter tests: the CSV row builder resolves each user's price through the
 * engine and renders the display-name / roles / product / SKU / price columns.
 *
 * The WP-query gathering (get_users / wc_get_products) and cron/CLI wiring are WordPress
 * integration and out of scope for the standalone suite; build_row() is the pure core.
 *
 * @package WCPricebook\Tests
 */

declare( strict_types=1 );

namespace WCPricebook\Tests;

use WCPricebook\Export\PricelistExporter;

/**
 * @covers \WCPricebook\Export\PricelistExporter
 */
class PricelistExporterTest extends TestCase {

	/**
	 * Build an exporter over the test config/engine.
	 *
	 * @return PricelistExporter
	 */
	private function exporter() {
		return new PricelistExporter( $this->config, $this->engine );
	}

	public function test_row_uses_tier_price_for_a_dealer() {
		Store::add_user( 5, array( 'dealer' ), array( 'dealer' ) );
		$this->set_meta( 10, array( '_regular_price' => '100', 'dealer_price' => '70' ) );

		$row = $this->exporter()->build_row(
			array( 'id' => 5, 'display_name' => 'Jane Doe', 'roles' => array( 'dealer' ) ),
			array( 'id' => 10, 'name' => 'Widget A', 'sku' => 'WID-A' )
		);

		$this->assertSame( array( 'Jane Doe', 'dealer', 'Widget A', 'WID-A', '70.00' ), $row );
	}

	public function test_row_falls_back_to_msrp_for_untiered_user() {
		Store::add_user( 6, array( 'customer' ), array() );
		$this->set_meta( 11, array( '_regular_price' => '120' ) );

		$row = $this->exporter()->build_row(
			array( 'id' => 6, 'display_name' => 'Bob Smith', 'roles' => array( 'customer' ) ),
			array( 'id' => 11, 'name' => 'Widget B', 'sku' => 'WID-B' )
		);

		$this->assertSame( '120.00', $row[4] );
		$this->assertSame( 'customer', $row[1] );
	}

	public function test_row_joins_multiple_roles() {
		Store::add_user( 7, array( 'customer', 'subscriber' ), array() );
		$this->set_meta( 12, array( '_regular_price' => '50' ) );

		$row = $this->exporter()->build_row(
			array( 'id' => 7, 'display_name' => 'Multi', 'roles' => array( 'customer', 'subscriber' ) ),
			array( 'id' => 12, 'name' => 'Widget C', 'sku' => '' )
		);

		$this->assertSame( 'customer, subscriber', $row[1] );
		$this->assertSame( '', $row[3] );
	}

	public function test_init_csv_then_append_rows_builds_the_file() {
		// The background (batched) path: init_csv writes the header once, then append_rows
		// adds a chunk of users — the same shape write_csv produces in one pass.
		Store::add_user( 50, array( 'dealer' ), array( 'dealer' ) );
		Store::add_user( 51, array( 'customer' ), array() );
		$this->set_meta( 60, array( '_regular_price' => '100', 'dealer_price' => '70' ) );

		$exporter = $this->exporter();
		$file     = tempnam( sys_get_temp_dir(), 'pbtest' );

		$exporter->init_csv( $file );
		$written = $exporter->append_rows(
			$file,
			array(
				array( 'id' => 50, 'display_name' => 'Dealer Dan', 'roles' => array( 'dealer' ) ),
				array( 'id' => 51, 'display_name' => 'Retail Rita', 'roles' => array( 'customer' ) ),
			),
			array( array( 'id' => 60, 'name' => 'Widget', 'sku' => 'W-1' ) )
		);

		$lines = array_values( array_filter( explode( "\n", (string) file_get_contents( $file ) ), 'strlen' ) );
		@unlink( $file );

		$this->assertSame( 2, $written );
		$this->assertSame( array( 'display_name', 'roles', 'product', 'sku', 'resolved_price' ), str_getcsv( $lines[0], ',', '"', '\\' ) );
		$this->assertSame( array( 'Dealer Dan', 'dealer', 'Widget', 'W-1', '70.00' ), str_getcsv( $lines[1], ',', '"', '\\' ) );
		$this->assertSame( array( 'Retail Rita', 'customer', 'Widget', 'W-1', '100.00' ), str_getcsv( $lines[2], ',', '"', '\\' ) );
	}

	public function test_row_is_empty_when_price_is_hidden() {
		// The 'gate' visibility role hides pricing (Call for Price) for MSRP customers
		// on category 999 — the resolved price is empty.
		Store::add_user( 8, array( 'customer' ), array() );
		$this->set_meta( 13, array( '_regular_price' => '100' ) );
		$this->set_terms( 13, 'product_cat', array( 999 ) );

		$row = $this->exporter()->build_row(
			array( 'id' => 8, 'display_name' => 'Hidden', 'roles' => array( 'customer' ) ),
			array( 'id' => 13, 'name' => 'Gated', 'sku' => 'GATE-1' )
		);

		$this->assertSame( '', $row[4] );
	}
}
