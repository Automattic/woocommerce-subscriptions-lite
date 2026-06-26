<?php
/**
 * Plan definition adapter for the admin UI.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Admin
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Admin;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Rest\PlansController;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Plan;
use Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject\BillingPolicy;
use Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject\PricingPolicy;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Converts engine plan schema values into option definitions for DataViews.
 */
final class PlanDefinitions {

	private const FALLBACK_BILLING_UNITS = [ 'day', 'week', 'month', 'year' ];

	private const FALLBACK_PRICING_SCOPES = [ 'all', 'first', 'n_cycles' ];

	/**
	 * Return plan definitions for the admin React app.
	 *
	 * @return array<string, array<int, array<string, string>>>
	 */
	public function to_array(): array {
		$schema = $this->get_plan_schema();

		return [
			'statuses'       => $this->options_from_values(
				$this->schema_enum(
					$schema,
					[ 'properties', 'status', 'enum' ],
					$this->engine_constant_values( Plan::class, 'ALLOWED_STATUSES', [ Plan::STATUS_ACTIVE, Plan::STATUS_ARCHIVED ] )
				),
				$this->status_labels()
			),
			'billing_units'  => $this->options_from_values(
				$this->schema_enum(
					$schema,
					[ 'properties', 'billing_policy', 'properties', 'period', 'enum' ],
					$this->engine_constant_values( BillingPolicy::class, 'ALLOWED_PERIODS', self::FALLBACK_BILLING_UNITS )
				),
				$this->billing_unit_labels(),
				$this->billing_unit_metadata()
			),
			'pricing_types'  => $this->options_from_values(
				$this->schema_enum(
					$schema,
					[ 'properties', 'pricing_policy', 'properties', 'policies', 'items', 'properties', 'type', 'enum' ],
					$this->engine_constant_values( Plan::class, 'ALLOWED_POLICY_TYPES', [ 'percentage', 'fixed_amount', 'price' ] )
				),
				$this->pricing_type_labels()
			),
			'pricing_scopes' => $this->options_from_values(
				$this->engine_constant_values( PricingPolicy::class, 'ALLOWED_SCOPES', self::FALLBACK_PRICING_SCOPES ),
				$this->pricing_scope_labels()
			),
		];
	}

	/**
	 * Get the engine REST schema when it is available.
	 *
	 * @return array<string, mixed>
	 */
	private function get_plan_schema(): array {
		if ( ! class_exists( PlansController::class ) ) {
			return [];
		}

		try {
			$schema = ( new PlansController() )->get_item_schema();
		} catch ( Throwable $exception ) {
			return [];
		}

		return is_array( $schema ) ? $schema : [];
	}

	/**
	 * Read an enum from the engine REST schema.
	 *
	 * @param array<string, mixed> $schema   Schema.
	 * @param array<int, string>   $path     Nested path to the enum.
	 * @param array<int, string>   $fallback Fallback values.
	 * @return array<int, string>
	 */
	private function schema_enum( array $schema, array $path, array $fallback ): array {
		return $this->normalize_values( $this->value_at_path( $schema, $path ), $fallback );
	}

	/**
	 * Read a public engine constant as a list of scalar values.
	 *
	 * @param string             $class_name    Class name.
	 * @param string             $constant_name Constant name.
	 * @param array<int, string> $fallback      Fallback values.
	 * @return array<int, string>
	 */
	private function engine_constant_values( string $class_name, string $constant_name, array $fallback ): array {
		$constant = $class_name . '::' . $constant_name;
		if ( ! defined( $constant ) ) {
			return $fallback;
		}

		return $this->normalize_values( constant( $constant ), $fallback );
	}

	/**
	 * Get a nested value from an array.
	 *
	 * @param array<string, mixed> $source Source array.
	 * @param array<int, string>   $path   Nested path.
	 * @return mixed|null
	 */
	private function value_at_path( array $source, array $path ) {
		$value = $source;
		foreach ( $path as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return null;
			}
			$value = $value[ $segment ];
		}

		return $value;
	}

	/**
	 * Normalize arbitrary values into a unique string list.
	 *
	 * @param mixed              $values   Values.
	 * @param array<int, string> $fallback Fallback values.
	 * @return array<int, string>
	 */
	private function normalize_values( $values, array $fallback ): array {
		if ( ! is_array( $values ) ) {
			return $fallback;
		}

		$normalized = [];
		foreach ( $values as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$normalized[] = (string) $value;
		}

		$normalized = array_values( array_unique( $normalized ) );

		return [] === $normalized ? $fallback : $normalized;
	}

	/**
	 * Convert values into DataViews option definitions.
	 *
	 * @param array<int, string>                  $values   Values.
	 * @param array<string, string>               $labels   Labels keyed by value.
	 * @param array<string, array<string,string>> $metadata Extra metadata keyed by value.
	 * @return array<int, array<string, string>>
	 */
	private function options_from_values( array $values, array $labels, array $metadata = [] ): array {
		$options = [];
		foreach ( $values as $value ) {
			$option = [
				'value' => $value,
				'label' => $labels[ $value ] ?? $this->label_from_value( $value ),
			];

			if ( isset( $metadata[ $value ] ) ) {
				$option = array_merge( $option, $metadata[ $value ] );
			}

			$options[] = $option;
		}

		return $options;
	}

	/**
	 * Build a readable fallback label for unknown engine values.
	 *
	 * @param string $value Raw value.
	 */
	private function label_from_value( string $value ): string {
		return ucwords( str_replace( [ '_', '-' ], ' ', $value ) );
	}

	/**
	 * Labels for known plan statuses.
	 *
	 * @return array<string, string>
	 */
	private function status_labels(): array {
		return [
			Plan::STATUS_ACTIVE   => __( 'Active', 'woocommerce-subscriptions-lite' ),
			Plan::STATUS_ARCHIVED => __( 'Archived', 'woocommerce-subscriptions-lite' ),
		];
	}

	/**
	 * Labels for known billing units.
	 *
	 * @return array<string, string>
	 */
	private function billing_unit_labels(): array {
		return [
			'day'   => __( 'Day', 'woocommerce-subscriptions-lite' ),
			'week'  => __( 'Week', 'woocommerce-subscriptions-lite' ),
			'month' => __( 'Month', 'woocommerce-subscriptions-lite' ),
			'year'  => __( 'Year', 'woocommerce-subscriptions-lite' ),
		];
	}

	/**
	 * Frequency labels for known billing units.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function billing_unit_metadata(): array {
		return [
			'day'   => [
				'singular' => __( 'day', 'woocommerce-subscriptions-lite' ),
				'plural'   => __( 'days', 'woocommerce-subscriptions-lite' ),
			],
			'week'  => [
				'singular' => __( 'week', 'woocommerce-subscriptions-lite' ),
				'plural'   => __( 'weeks', 'woocommerce-subscriptions-lite' ),
			],
			'month' => [
				'singular' => __( 'month', 'woocommerce-subscriptions-lite' ),
				'plural'   => __( 'months', 'woocommerce-subscriptions-lite' ),
			],
			'year'  => [
				'singular' => __( 'year', 'woocommerce-subscriptions-lite' ),
				'plural'   => __( 'years', 'woocommerce-subscriptions-lite' ),
			],
		];
	}

	/**
	 * Labels for known pricing policy types.
	 *
	 * @return array<string, string>
	 */
	private function pricing_type_labels(): array {
		return [
			'percentage'   => __( 'Percentage', 'woocommerce-subscriptions-lite' ),
			'fixed_amount' => __( 'Fixed amount', 'woocommerce-subscriptions-lite' ),
			'price'        => __( 'Fixed price', 'woocommerce-subscriptions-lite' ),
		];
	}

	/**
	 * Labels for known pricing policy scopes.
	 *
	 * @return array<string, string>
	 */
	private function pricing_scope_labels(): array {
		return [
			'all'      => __( 'All cycles', 'woocommerce-subscriptions-lite' ),
			'first'    => __( 'First cycle', 'woocommerce-subscriptions-lite' ),
			'n_cycles' => __( 'First N cycles', 'woocommerce-subscriptions-lite' ),
		];
	}
}
