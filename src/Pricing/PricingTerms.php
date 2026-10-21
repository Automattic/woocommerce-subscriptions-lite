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
use Automattic\WooCommerce\SubscriptionsEngine\Core\Support\ValidationErrorCode;
use WP_Error;

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
	 * Strict validation for a plan write. Null is valid (no terms). Each error carries an
	 * engine error code, a translated field-free message and `field` (dot path) / `params` data.
	 *
	 * @param mixed $data Raw pricing_policy payload.
	 * @return WP_Error Validation errors; none when valid.
	 */
	public static function validate( $data ): WP_Error {
		$errors = new WP_Error();
		if ( null === $data ) {
			return $errors;
		}
		if ( ! is_array( $data ) ) {
			ValidationErrors::add(
				$errors,
				ValidationErrorCode::INVALID_TYPE,
				'pricing_policy',
				__( 'Must be an object or null.', 'woocommerce-subscriptions-lite' ),
				[ 'type' => 'object' ]
			);
			return $errors;
		}

		// A malformed list hides only its own entries; the other list is still checked.
		foreach ( [ 'policies', 'one_time_fees' ] as $key ) {
			if ( isset( $data[ $key ] ) && ! ( is_array( $data[ $key ] ) && array_is_list( $data[ $key ] ) ) ) {
				ValidationErrors::add(
					$errors,
					ValidationErrorCode::INVALID_TYPE,
					'pricing_policy.' . $key,
					__( 'Must be a list.', 'woocommerce-subscriptions-lite' ),
					[ 'type' => 'array' ]
				);
				continue;
			}
			foreach ( self::entries( $data, $key ) as $index => $entry ) {
				$field = 'pricing_policy.' . $key . '.' . (int) $index;
				if ( 'policies' === $key ) {
					self::validate_policy( $errors, $field, $entry );
				} else {
					self::validate_fee( $errors, $field, $entry );
				}
			}
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
	 * Add the validation errors of one policy entry.
	 *
	 * @param WP_Error $errors Error collector.
	 * @param string   $path   The entry's field path, such as `pricing_policy.policies.0`.
	 * @param mixed    $entry  Raw entry.
	 */
	private static function validate_policy( WP_Error $errors, string $path, $entry ): void {
		if ( ! is_array( $entry ) ) {
			self::add_object_error( $errors, $path );
			return;
		}

		$type = $entry['type'] ?? null;
		if ( ! is_string( $type ) || ! in_array( $type, self::TYPES, true ) ) {
			ValidationErrors::add(
				$errors,
				ValidationErrorCode::NOT_IN_ENUM,
				$path . '.type',
				/* translators: %l: list of policy types, such as "percentage, fixed_amount, price, and bogo". */
				wp_sprintf( __( 'Must be one of %l.', 'woocommerce-subscriptions-lite' ), self::TYPES ),
				[ 'allowed' => self::TYPES ]
			);
			return;
		}

		$value = $entry['value'] ?? 0;
		$field = $path . '.value';
		if ( ! is_numeric( $value ) ) {
			self::add_number_error( $errors, $field );
		} else {
			$error = self::value_error( $type, (float) $value );
			if ( null !== $error ) {
				ValidationErrors::add( $errors, $error['code'], $field, $error['message'], $error['params'] );
			}
		}

		foreach ( self::CYCLE_GATES as $gate ) {
			if ( ! isset( $entry[ $gate ] ) ) {
				continue;
			}
			$field = $path . '.' . $gate;
			$cycle = self::cycle_gate( $entry[ $gate ] );
			if ( null === $cycle ) {
				ValidationErrors::add(
					$errors,
					ValidationErrorCode::INVALID_TYPE,
					$field,
					__( 'Must be an integer.', 'woocommerce-subscriptions-lite' ),
					[ 'type' => 'integer' ]
				);
			} elseif ( $cycle < 1 ) {
				ValidationErrors::add(
					$errors,
					ValidationErrorCode::OUT_OF_BOUNDS,
					$field,
					__( 'Must be 1 or greater.', 'woocommerce-subscriptions-lite' ),
					[ 'min' => 1 ]
				);
			}
		}
	}

	/**
	 * Range error for a policy value, shared by the write check and the tolerant read:
	 * the error code, its params and a translated, field-free message.
	 *
	 * @param string $type  Known policy type.
	 * @param float  $value Numeric value.
	 * @return array{code: string, message: string, params: array<string, int>}|null
	 */
	private static function value_error( string $type, float $value ): ?array {
		if ( ! is_finite( $value ) ) {
			return [
				'code'    => ValidationErrorCode::INVALID_VALUE,
				'message' => self::get_finite_message(),
				'params'  => [],
			];
		}
		if ( $value < 0 ) {
			return [
				'code'    => ValidationErrorCode::OUT_OF_BOUNDS,
				'message' => self::get_non_negative_message(),
				'params'  => [ 'min' => 0 ],
			];
		}
		if ( self::TYPE_PERCENTAGE === $type && $value > 100 ) {
			return [
				'code'    => ValidationErrorCode::OUT_OF_BOUNDS,
				'message' => __( 'Must not exceed 100 for a percentage policy.', 'woocommerce-subscriptions-lite' ),
				'params'  => [ 'max' => 100 ],
			];
		}
		if ( self::TYPE_BOGO === $type && 0.0 !== $value ) {
			return [
				'code'    => ValidationErrorCode::INVALID_VALUE,
				'message' => __( 'Must be 0 or omitted for a bogo policy.', 'woocommerce-subscriptions-lite' ),
				'params'  => [],
			];
		}

		return null;
	}

	/**
	 * Add the validation errors of one fee entry.
	 *
	 * @param WP_Error $errors Error collector.
	 * @param string   $path   The entry's field path, such as `pricing_policy.one_time_fees.0`.
	 * @param mixed    $entry  Raw entry.
	 */
	private static function validate_fee( WP_Error $errors, string $path, $entry ): void {
		if ( ! is_array( $entry ) ) {
			self::add_object_error( $errors, $path );
			return;
		}

		$amount = $entry['amount'] ?? 0;
		$field  = $path . '.amount';
		if ( ! is_numeric( $amount ) ) {
			self::add_number_error( $errors, $field );
		} elseif ( ! is_finite( (float) $amount ) ) {
			ValidationErrors::add( $errors, ValidationErrorCode::INVALID_VALUE, $field, self::get_finite_message() );
		} elseif ( (float) $amount < 0 ) {
			ValidationErrors::add( $errors, ValidationErrorCode::OUT_OF_BOUNDS, $field, self::get_non_negative_message(), [ 'min' => 0 ] );
		}

		$taxable = $entry['taxable'] ?? null;
		if ( null !== $taxable && null === filter_var( $taxable, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ) {
			ValidationErrors::add(
				$errors,
				ValidationErrorCode::INVALID_TYPE,
				$path . '.taxable',
				__( 'Must be true or false.', 'woocommerce-subscriptions-lite' ),
				[ 'type' => 'boolean' ]
			);
		}

		$tax_class = $entry['tax_class'] ?? null;
		if ( null !== $tax_class && ! is_string( $tax_class ) ) {
			ValidationErrors::add(
				$errors,
				ValidationErrorCode::INVALID_TYPE,
				$path . '.tax_class',
				__( 'Must be a string or null.', 'woocommerce-subscriptions-lite' ),
				[ 'type' => 'string' ]
			);
		}
	}

	/**
	 * Add an entry that is not an object.
	 *
	 * @param WP_Error $errors Error collector.
	 * @param string   $field  The entry's field path.
	 */
	private static function add_object_error( WP_Error $errors, string $field ): void {
		ValidationErrors::add(
			$errors,
			ValidationErrorCode::INVALID_TYPE,
			$field,
			__( 'Must be an object.', 'woocommerce-subscriptions-lite' ),
			[ 'type' => 'object' ]
		);
	}

	/**
	 * Add a value or amount that is not numeric.
	 *
	 * @param WP_Error $errors Error collector.
	 * @param string   $field  The value's field path.
	 */
	private static function add_number_error( WP_Error $errors, string $field ): void {
		ValidationErrors::add(
			$errors,
			ValidationErrorCode::INVALID_TYPE,
			$field,
			__( 'Must be a number.', 'woocommerce-subscriptions-lite' ),
			[ 'type' => 'number' ]
		);
	}

	/**
	 * Message for a number that is not finite, shared by policy values and fee amounts.
	 */
	private static function get_finite_message(): string {
		return __( 'Must be a finite number.', 'woocommerce-subscriptions-lite' );
	}

	/**
	 * Message for a negative number, shared by policy values and fee amounts.
	 */
	private static function get_non_negative_message(): string {
		return __( 'Must not be negative.', 'woocommerce-subscriptions-lite' );
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
