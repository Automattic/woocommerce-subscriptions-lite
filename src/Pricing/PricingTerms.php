<?php
/**
 * PricingTerms - Lite's pricing vocabulary for a plan's `pricing_policy` payload.
 *
 * The engine stores `pricing_policy` as an opaque extension payload; Lite owns
 * its meaning. Shape:
 *   {
 *     policies: [ { type: percentage|fixed_amount|price|bogo, value: float, starting_cycle?: int, duration_cycles?: int }, ... ],
 *     one_time_fees: [ { kind: string, amount: float, taxable: bool, tax_class: string|null }, ... ]
 *   }
 *
 * Reads are tolerant and never throw ({@see self::from_array()}): entries that
 * break the type or range rules are dropped, but structure is coerced rather
 * than rejected (a non-numeric fee amount reads as 0, an unreadable `taxable` as
 * false). Writes are strict on both ({@see self::validate()}). A `bogo` entry is
 * value-less: writes accept only 0 or an omitted value, the payload is stored as
 * sent, and reads treat any value as 0. Fee `tax_class` keeps `''` (the store's
 * Standard class) distinct from `null` (untaxed).
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Pricing
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Pricing;

use Automattic\WooCommerce\SubscriptionsEngine\Api\View\PlanView;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable, normalized pricing terms.
 */
final class PricingTerms {

	public const TYPE_PERCENTAGE   = 'percentage';
	public const TYPE_FIXED_AMOUNT = 'fixed_amount';
	public const TYPE_PRICE        = 'price';
	public const TYPE_BOGO         = 'bogo';

	public const TYPES = [ self::TYPE_PERCENTAGE, self::TYPE_FIXED_AMOUNT, self::TYPE_PRICE, self::TYPE_BOGO ];

	private const CYCLE_GATES = [ 'starting_cycle', 'duration_cycles' ];

	/**
	 * Recurring price adjustments, applied in order.
	 *
	 * @var array<int, array{type: string, value: float, starting_cycle?: int, duration_cycles?: int}>
	 */
	private $policies;

	/**
	 * One-time fees.
	 *
	 * @var array<int, array{kind: string, amount: float, taxable: bool, tax_class: string|null}>
	 */
	private $one_time_fees;

	/**
	 * Build from normalized entries; use the named constructors.
	 *
	 * @param array<int, array{type: string, value: float, starting_cycle?: int, duration_cycles?: int}> $policies      Policies.
	 * @param array<int, array{kind: string, amount: float, taxable: bool, tax_class: string|null}>      $one_time_fees Fees.
	 */
	private function __construct( array $policies, array $one_time_fees ) {
		$this->policies      = $policies;
		$this->one_time_fees = $one_time_fees;
	}

	/**
	 * Tolerant parse of a stored payload. Drops non-array entries, unknown types,
	 * non-numeric or out-of-range values, invalid cycle gates and negative fees;
	 * keeps valid entries in order. A BOGO value reads as 0.
	 *
	 * @param array<array-key, mixed> $data Decoded pricing_policy payload.
	 */
	public static function from_array( array $data ): self {
		$policies = [];
		foreach ( self::entries( $data, 'policies' ) as $entry ) {
			$policy = self::parse_policy( $entry );
			if ( null !== $policy ) {
				$policies[] = $policy;
			}
		}

		$fees = [];
		foreach ( self::entries( $data, 'one_time_fees' ) as $entry ) {
			$fee = is_array( $entry ) ? self::parse_fee( $entry ) : null;
			if ( null !== $fee ) {
				$fees[] = $fee;
			}
		}

		return new self( $policies, $fees );
	}

	/**
	 * Terms of a plan; empty terms when there is no plan or it carries no payload.
	 *
	 * @param PlanView|null $plan Plan.
	 */
	public static function from_plan( ?PlanView $plan ): self {
		return self::from_array( null !== $plan ? $plan->get_pricing_policy() ?? [] : [] );
	}

	/**
	 * Strict validation for a plan write. Null is valid (no terms).
	 *
	 * @param mixed $data Raw pricing_policy payload.
	 * @return array<int, string> Error messages; empty when valid.
	 */
	public static function validate( $data ): array {
		if ( null === $data ) {
			return [];
		}
		if ( ! is_array( $data ) ) {
			return [ 'pricing_policy must be an object or null.' ];
		}

		$errors = [];

		foreach ( [ 'policies', 'one_time_fees' ] as $key ) {
			if ( isset( $data[ $key ] ) && ! ( is_array( $data[ $key ] ) && array_is_list( $data[ $key ] ) ) ) {
				$errors[] = sprintf( 'pricing_policy.%s: must be a list.', $key );
			}
		}
		if ( ! empty( $errors ) ) {
			return $errors;
		}

		foreach ( self::entries( $data, 'policies' ) as $index => $entry ) {
			$errors = array_merge( $errors, self::validate_policy( (int) $index, $entry ) );
		}
		foreach ( self::entries( $data, 'one_time_fees' ) as $index => $entry ) {
			$errors = array_merge( $errors, self::validate_fee( (int) $index, $entry ) );
		}

		return $errors;
	}

	/**
	 * Recurring price adjustments, in application order.
	 *
	 * @return array<int, array{type: string, value: float, starting_cycle?: int, duration_cycles?: int}>
	 */
	public function get_policies(): array {
		return $this->policies;
	}

	/**
	 * One-time fees.
	 *
	 * @return array<int, array{kind: string, amount: float, taxable: bool, tax_class: string|null}>
	 */
	public function get_one_time_fees(): array {
		return $this->one_time_fees;
	}

	/**
	 * Whether any policy entry has the given type.
	 *
	 * @param string $type One of {@see self::TYPES}.
	 */
	public function has_type( string $type ): bool {
		foreach ( $this->policies as $policy ) {
			if ( $type === $policy['type'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The entries under a top-level key, or none when it is absent or not an array.
	 *
	 * @param array<array-key, mixed> $data Payload.
	 * @param string                  $key  Top-level key.
	 * @return array<array-key, mixed>
	 */
	private static function entries( array $data, string $key ): array {
		return isset( $data[ $key ] ) && is_array( $data[ $key ] ) ? $data[ $key ] : [];
	}

	/**
	 * Parse one policy entry, or null when it is malformed.
	 *
	 * @param mixed $entry Raw entry.
	 * @return array{type: string, value: float, starting_cycle?: int, duration_cycles?: int}|null
	 */
	private static function parse_policy( $entry ): ?array {
		if ( ! is_array( $entry ) || ! isset( $entry['type'] ) || ! in_array( $entry['type'], self::TYPES, true ) ) {
			return null;
		}

		// A BOGO value has no effect, so a stray one reads as 0 instead of dropping the bonus.
		$type  = (string) $entry['type'];
		$value = self::TYPE_BOGO === $type ? 0 : ( $entry['value'] ?? 0 );
		if ( ! is_numeric( $value ) || null !== self::value_error( $type, (float) $value ) ) {
			return null;
		}

		$policy = [
			'type'  => $type,
			'value' => (float) $value,
		];

		foreach ( self::CYCLE_GATES as $gate ) {
			if ( ! isset( $entry[ $gate ] ) ) {
				continue;
			}
			$cycle = self::cycle_gate( $entry[ $gate ] );
			if ( null === $cycle || $cycle < 1 ) {
				return null;
			}
			$policy[ $gate ] = $cycle;
		}

		return $policy;
	}

	/**
	 * Normalize one fee entry (float amount, real boolean taxable, scalar
	 * tax_class), or null when its amount is negative or not finite.
	 *
	 * @param array<array-key, mixed> $entry Raw entry.
	 * @return array{kind: string, amount: float, taxable: bool, tax_class: string|null}|null
	 */
	private static function parse_fee( array $entry ): ?array {
		$amount = isset( $entry['amount'] ) && is_numeric( $entry['amount'] ) ? (float) $entry['amount'] : 0.0;
		if ( ! is_finite( $amount ) || $amount < 0 ) {
			return null;
		}

		// A stored 'false' string must not read as taxable.
		$taxable = false;
		if ( array_key_exists( 'taxable', $entry ) ) {
			$taxable = (bool) filter_var( $entry['taxable'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		}

		return [
			'kind'      => isset( $entry['kind'] ) && is_scalar( $entry['kind'] ) ? (string) $entry['kind'] : '',
			'amount'    => $amount,
			'taxable'   => $taxable,
			'tax_class' => array_key_exists( 'tax_class', $entry ) && is_scalar( $entry['tax_class'] ) ? (string) $entry['tax_class'] : null,
		];
	}

	/**
	 * Validation errors for one policy entry.
	 *
	 * @param int   $index Entry index.
	 * @param mixed $entry Raw entry.
	 * @return array<int, string>
	 */
	private static function validate_policy( int $index, $entry ): array {
		if ( ! is_array( $entry ) ) {
			return [ sprintf( 'pricing_policy.policies[%d]: must be an object, got %s', $index, gettype( $entry ) ) ];
		}

		$type = $entry['type'] ?? null;
		if ( ! is_string( $type ) || ! in_array( $type, self::TYPES, true ) ) {
			$shown = is_scalar( $type ) ? (string) $type : gettype( $type );
			return [ sprintf( 'pricing_policy.policies[%d]: invalid type %s', $index, $shown ) ];
		}

		$errors = [];
		$value  = $entry['value'] ?? 0;
		if ( ! is_numeric( $value ) ) {
			$errors[] = sprintf( 'pricing_policy.policies[%d]: value must be numeric, got %s', $index, gettype( $value ) );
		} else {
			$error = self::value_error( $type, (float) $value );
			if ( null !== $error ) {
				$errors[] = sprintf( 'pricing_policy.policies[%d]: %s', $index, $error );
			}
		}

		foreach ( self::CYCLE_GATES as $gate ) {
			if ( ! isset( $entry[ $gate ] ) ) {
				continue;
			}
			$cycle = self::cycle_gate( $entry[ $gate ] );
			if ( null === $cycle ) {
				$errors[] = sprintf( 'pricing_policy.policies[%d]: %s must be an integer, got %s', $index, $gate, gettype( $entry[ $gate ] ) );
			} elseif ( $cycle < 1 ) {
				$errors[] = sprintf( 'pricing_policy.policies[%d]: %s must be at least 1, got %d', $index, $gate, $cycle );
			}
		}

		return $errors;
	}

	/**
	 * Range error for a policy value, shared by the write check and the tolerant read.
	 *
	 * @param string $type  Known policy type.
	 * @param float  $value Numeric value.
	 */
	private static function value_error( string $type, float $value ): ?string {
		if ( ! is_finite( $value ) ) {
			return sprintf( '%s value must be finite', $type );
		}
		if ( $value < 0 ) {
			return sprintf( '%s value must be non-negative, got %s', $type, $value );
		}
		if ( self::TYPE_PERCENTAGE === $type && $value > 100 ) {
			return sprintf( 'percentage must not exceed 100, got %s', $value );
		}
		if ( self::TYPE_BOGO === $type && 0.0 !== $value ) {
			return sprintf( 'bogo is value-less; value must be 0 or omitted, got %s', $value );
		}

		return null;
	}

	/**
	 * Validation errors for one fee entry.
	 *
	 * @param int   $index Entry index.
	 * @param mixed $entry Raw entry.
	 * @return array<int, string>
	 */
	private static function validate_fee( int $index, $entry ): array {
		if ( ! is_array( $entry ) ) {
			return [ sprintf( 'pricing_policy.one_time_fees[%d]: must be an object, got %s', $index, gettype( $entry ) ) ];
		}

		$errors = [];
		$amount = $entry['amount'] ?? 0;
		if ( ! is_numeric( $amount ) ) {
			$errors[] = sprintf( 'pricing_policy.one_time_fees[%d]: amount must be numeric, got %s', $index, gettype( $amount ) );
		} elseif ( ! is_finite( (float) $amount ) ) {
			$errors[] = sprintf( 'pricing_policy.one_time_fees[%d]: amount must be finite', $index );
		} elseif ( (float) $amount < 0 ) {
			$errors[] = sprintf( 'pricing_policy.one_time_fees[%d]: amount must be non-negative, got %s', $index, $amount );
		}

		$taxable = $entry['taxable'] ?? null;
		if ( null !== $taxable && null === filter_var( $taxable, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ) {
			$errors[] = sprintf( 'pricing_policy.one_time_fees[%d]: taxable must be a bool, got %s', $index, gettype( $taxable ) );
		}

		$tax_class = $entry['tax_class'] ?? null;
		if ( null !== $tax_class && ! is_string( $tax_class ) ) {
			$errors[] = sprintf( 'pricing_policy.one_time_fees[%d]: tax_class must be string or null, got %s', $index, gettype( $tax_class ) );
		}

		return $errors;
	}

	/**
	 * An integer-like cycle gate as int (int, whole float, integer string), or null.
	 *
	 * @param mixed $value Raw gate value.
	 */
	private static function cycle_gate( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_float( $value ) && is_finite( $value ) && floor( $value ) === $value ) {
			return (int) $value;
		}
		if ( is_string( $value ) ) {
			$validated = filter_var( $value, FILTER_VALIDATE_INT );
			return false === $validated ? null : $validated;
		}

		return null;
	}
}
