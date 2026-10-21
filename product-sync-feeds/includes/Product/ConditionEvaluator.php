<?php
/**
 * Advanced filter conditions: "source operator value" rules on resolved values.
 *
 * @package ProductSyncFeeds
 */

namespace ProductSyncFeeds\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Evaluates one condition against a value.
 */
final class ConditionEvaluator {

	/**
	 * Operators for the admin picker.
	 *
	 * @return array<string,string>
	 */
	public static function operators(): array {
		return array(
			'equals'       => __( 'equals', 'product-sync-feeds' ),
			'not_equals'   => __( 'does not equal', 'product-sync-feeds' ),
			'contains'     => __( 'contains', 'product-sync-feeds' ),
			'not_contains' => __( 'does not contain', 'product-sync-feeds' ),
			'starts_with'  => __( 'starts with', 'product-sync-feeds' ),
			'ends_with'    => __( 'ends with', 'product-sync-feeds' ),
			'gt'           => __( 'greater than', 'product-sync-feeds' ),
			'gte'          => __( 'greater than or equal', 'product-sync-feeds' ),
			'lt'           => __( 'less than', 'product-sync-feeds' ),
			'lte'          => __( 'less than or equal', 'product-sync-feeds' ),
			'empty'        => __( 'is empty', 'product-sync-feeds' ),
			'not_empty'    => __( 'is not empty', 'product-sync-feeds' ),
			'regex'        => __( 'matches regex', 'product-sync-feeds' ),
		);
	}

	/**
	 * Evaluate.
	 *
	 * Text comparisons ignore case. A list value (categories, tags...) matches
	 * equals/contains/starts/ends when any element matches, and "not" operators
	 * when no element matches.
	 *
	 * @param string|string[] $actual   Resolved value.
	 * @param string          $operator Operator key.
	 * @param string          $expected Configured value.
	 * @return bool
	 */
	public static function matches( $actual, string $operator, string $expected ): bool {
		$values = is_array( $actual ) ? array_map( 'strval', $actual ) : array( (string) $actual );
		$values = array_values( array_filter( $values, static fn( $v ) => '' !== trim( $v ) ) );

		switch ( $operator ) {
			case 'empty':
				return ! $values;
			case 'not_empty':
				return (bool) $values;
			case 'not_equals':
				return ! self::any( $values, 'equals', $expected );
			case 'not_contains':
				return ! self::any( $values, 'contains', $expected );
			default:
				return self::any( $values, $operator, $expected );
		}
	}

	/**
	 * Whether any value satisfies a positive operator.
	 *
	 * @param string[] $values   Values.
	 * @param string   $operator Operator.
	 * @param string   $expected Expected.
	 * @return bool
	 */
	private static function any( array $values, string $operator, string $expected ): bool {
		foreach ( $values as $value ) {
			if ( self::one( $value, $operator, $expected ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Single value comparison.
	 *
	 * @param string $value    Value.
	 * @param string $operator Operator.
	 * @param string $expected Expected.
	 * @return bool
	 */
	private static function one( string $value, string $operator, string $expected ): bool {
		$a = mb_strtolower( trim( $value ) );
		$b = mb_strtolower( trim( $expected ) );

		switch ( $operator ) {
			case 'equals':
				return $a === $b;
			case 'contains':
				return '' !== $b && false !== mb_strpos( $a, $b );
			case 'starts_with':
				return '' !== $b && 0 === mb_strpos( $a, $b );
			case 'ends_with':
				return '' !== $b && mb_substr( $a, -mb_strlen( $b ) ) === $b;
			case 'gt':
			case 'gte':
			case 'lt':
			case 'lte':
				$x = self::to_number( $value );
				$y = self::to_number( $expected );
				if ( null === $x || null === $y ) {
					return false;
				}
				return ( 'gt' === $operator && $x > $y ) || ( 'gte' === $operator && $x >= $y ) || ( 'lt' === $operator && $x < $y ) || ( 'lte' === $operator && $x <= $y );
			case 'regex':
				$pattern = self::pattern( $expected );
				return '' !== $pattern && 1 === @preg_match( $pattern, $value ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- Invalid user patterns simply do not match.
		}
		return false;
	}

	/**
	 * Leading number of a value ("12.50 USD" → 12.5).
	 *
	 * @param string $value Value.
	 * @return float|null
	 */
	private static function to_number( string $value ): ?float {
		if ( preg_match( '/-?\d+(?:[.,]\d+)?/', $value, $m ) ) {
			return (float) str_replace( ',', '.', $m[0] );
		}
		return null;
	}

	/**
	 * Wrap a bare pattern in delimiters.
	 *
	 * @param string $pattern Pattern.
	 * @return string
	 */
	private static function pattern( string $pattern ): string {
		if ( '' === $pattern ) {
			return '';
		}
		if ( preg_match( '/^([\/#~@%]).+\1[imsxuU]*$/s', $pattern ) ) {
			return $pattern;
		}
		return '~' . str_replace( '~', '\~', $pattern ) . '~iu';
	}
}
