<?php

namespace WeeConnectPay\WordPress\Plugin\includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides static methods as helpers.
 *
 * @since 1.0.0
 */
class WeeConnectPayHelper {

	/**
	 * Takes a raw amount and turns it to cents ( IE: 39.84999999 > 3985.99999 )
	 * Meant to be used if there are any other mathematical calculations to be done afterwards before rounding.
	 *
	 * @param float $amount
	 *
	 * @since 1.2.0
	 *
	 * @return float
	 */
	public static function amount_to_cents( float $amount ) {
		return $amount * 100.00;
	}

	/**
	 * Takes a raw amount and turns it to cents then rounds it to the nearest integer ( IE: 39.84999999 > 3985 )
	 * Meant to be used if there aren't any mathematical calculations to be done afterwards. Usually just before sending to the API after all calculations are done.
	 * @param float $amount
	 *
	 * @since 1.2.0
	 *
	 * @return float
	 */
	public static function amount_to_cents_rounded( float $amount ) {
		return round( $amount * 100.00 );
	}

	/**
	 * Formats the description on the clover receipt. Also used in refunds.
	 * @param $name
	 * @param $qty
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public static function name_and_qty_as_clover_line_desc( $name, $qty ) {
		return $name . ' x ' . $qty;
	}

	/**
	 * Safely formats a string amount value to an int representation of its value in cents.
	 * @param string $string_amount
	 * @since 1.3.3
	 *
	 * @return int
	 */
	public static function safe_amount_to_cents_int( string $string_amount ) {
		return (int) number_format( $string_amount, 2, '', '' );
	}
}
