<?php
/**
 * CSV output helpers shared by the pricelist export and the catalog CSV.
 *
 * @package WCPricebook
 */

namespace WCPricebook\Export;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes CSV rows safely for spreadsheet applications.
 */
class Csv {

	/**
	 * Neutralize a text cell so a spreadsheet does not evaluate it as a formula.
	 *
	 * Values such as a customer's display name are user-controlled. A cell that starts
	 * with =, +, -, @, a tab or a carriage return is treated as a formula by Excel and
	 * similar applications, so it is prefixed with a single quote to force plain text.
	 * Plain numbers (e.g. "-5.00") are left untouched.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	public static function cell( $value ) {
		$value = (string) $value;
		if ( '' === $value || is_numeric( $value ) ) {
			return $value;
		}
		if ( false !== strpos( "=+-@\t\r", $value[0] ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Write one row. The escape character is disabled so a backslash before a quote
	 * cannot break a value out of its cell (quotes are always doubled instead).
	 *
	 * @param resource          $handle Open file handle.
	 * @param array<int,string> $row    Row values (already neutralized where needed).
	 * @return void
	 */
	public static function put( $handle, array $row ) {
		fputcsv( $handle, $row, ',', '"', '' );
	}
}
