<?php
/**
 * Eraser - removes the person's personal data from their subscriptions on an erasure request.
 *
 * Only when the merchant has turned on {@see ErasureSetting}; otherwise every subscription is
 * reported as retained. A running subscription is cancelled through {@see Cancellation} first,
 * then erased like an ended one: its history replaced by one `personal-data-removed` entry,
 * addresses anonymised in place, payment references cleared, and the customer link cleared
 * last. Each subscription is erased under its contract lock.
 *
 * WordPress calls an eraser with only the email and a page, and erased subscriptions drop out
 * of the lookup, so every call reads the set afresh and erases the next eligible ones. Any
 * failure is reported for its subscription and ends the request; a rerun picks up where it
 * stopped.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Privacy
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Privacy;

use Automattic\WooCommerce\SubscriptionsEngine\Api\ContractLock;
use Automattic\WooCommerce\SubscriptionsEngine\Api\ContractLockedException;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Cancellation;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\History;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\HeldLocks;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use RuntimeException;
use Throwable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Personal data eraser for Lite contracts.
 */
final class Eraser {

	/**
	 * Operation label of the eraser's contract lock.
	 */
	public const OPERATION = 'privacy-erase';

	/**
	 * `source` of the `personal-data-removed` history entry the eraser writes.
	 */
	private const HISTORY_SOURCE = 'erasure-request';

	/**
	 * Contracts erased per call.
	 */
	private const BATCH_SIZE = 10;

	/**
	 * Statuses of a contract that has ended: erased as they are, with nothing to cancel.
	 */
	private const ENDED_STATUSES = [ ContractStatus::CANCELLED, ContractStatus::EXPIRED ];

	/**
	 * How {@see ContractView} address fields are anonymised, as WooCommerce anonymises order
	 * addresses with `wp_privacy_anonymize_data()`.
	 */
	private const ADDRESS_DATA_TYPES = [
		'first_name' => 'text',
		'last_name'  => 'text',
		'company'    => 'text',
		'address_1'  => 'text',
		'address_2'  => 'text',
		'city'       => 'text',
		'postcode'   => 'text',
		'state'      => 'address_state',
		'country'    => 'address_country',
		'email'      => 'email',
		'phone'      => 'phone',
	];

	/**
	 * Register WordPress hooks.
	 */
	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_erasers', [ self::class, 'add_eraser' ] );
	}

	/**
	 * Filter callback: the erasers with Lite's added.
	 *
	 * @param mixed $erasers Registered erasers.
	 * @return mixed
	 */
	public static function add_eraser( $erasers ) {
		if ( ! is_array( $erasers ) ) {
			return $erasers;
		}

		$erasers[ Package::EXTENSION_SLUG ] = [
			'eraser_friendly_name' => __( 'Subscriptions', 'woocommerce-subscriptions-lite' ),
			'callback'             => [ new self(), 'erase' ],
		];

		return $erasers;
	}

	/**
	 * Erase the next batch of the person's subscriptions. Retained subscriptions are reported
	 * on page 1 only. `done` is true when no eligible subscription is left after this batch, or
	 * when one of the batch failed. A failed read of the set is logged (without personal data)
	 * and returned as an error, which stops the erasure.
	 *
	 * @param string $email_address The request's email address.
	 * @param int    $page          Page, from 1.
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}|WP_Error
	 */
	public function erase( string $email_address, int $page = 1 ) {
		try {
			$contracts = RequesterContracts::get_for_email( $email_address );
		} catch ( Throwable $e ) {
			wc_get_logger()->error(
				sprintf( 'Privacy erasure: subscriptions could not be read (%s).', get_class( $e ) ),
				[ 'source' => Package::EXTENSION_SLUG ]
			);

			return new WP_Error(
				'woocommerce_subscriptions_lite_privacy_read_failed',
				__( 'Subscriptions could not be read for this request. Check the WooCommerce logs and try again.', 'woocommerce-subscriptions-lite' )
			);
		}

		$response = [
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => [],
			'done'           => true,
		];

		$erasure_enabled = ErasureSetting::is_enabled();
		$eligible        = [];
		foreach ( $contracts as $contract ) {
			if ( $erasure_enabled && self::is_erasable( $contract->get_status() ) ) {
				$eligible[] = $contract->get_id();
			} elseif ( 1 === $page ) {
				$response['items_retained'] = true;
				$response['messages'][]     = self::get_retained_message( $contract->get_id(), ! $erasure_enabled && Cancellation::can_cancel( $contract->get_status() ) );
			}
		}

		$failed = false;
		foreach ( array_slice( $eligible, 0, self::BATCH_SIZE ) as $contract_id ) {
			$outcome = $this->erase_contract( $contract_id );
			if ( null === $outcome ) {
				continue;
			}

			$response['messages'][] = $outcome['message'];
			if ( $outcome['removed'] ) {
				$response['items_removed'] = true;
			} else {
				$response['items_retained'] = true;
			}
			if ( $outcome['failed'] ) {
				$failed = true;
			}
		}

		$response['done'] = $failed || count( $eligible ) <= self::BATCH_SIZE;

		return $response;
	}

	/**
	 * Whether the eraser removes personal data from a contract in `$status`: an ended one, or
	 * a running one it can cancel first. Any other status is retained.
	 *
	 * @param string $status Contract status slug.
	 */
	private static function is_erasable( string $status ): bool {
		return in_array( $status, self::ENDED_STATUSES, true ) || Cancellation::can_cancel( $status );
	}

	/**
	 * Cancel a running contract, then replace its history and anonymise it, under its lock. Exceptions are reported as
	 * a failure for the contract, never thrown; each failure is logged with the step it hit.
	 *
	 * @param int $contract_id Contract id.
	 * @return array{message: string, removed: bool, failed: bool}|null The outcome; null when the
	 *                                                                  contract is gone or not Lite's.
	 */
	private function erase_contract( int $contract_id ): ?array {
		$lock = null;
		$step = 'lock';
		try {
			$lock = HeldLocks::acquire( $contract_id, self::OPERATION );
			if ( null === $lock ) {
				$conflict = HeldLocks::get_conflict( $contract_id );
				if ( null === $conflict ) {
					return null;
				}

				$data = $conflict->get_error_data();
				self::log_failure( $contract_id, $step, sprintf( 'held by %s', (string) ( $data['operation'] ?? 'unknown' ) ) );

				return self::get_failure( self::get_locked_message( $conflict ) );
			}

			$step     = 'read';
			$contract = Contracts::get( $contract_id );
			if ( null === $contract ) {
				return null;
			}

			$status = $contract->get_status();
			if ( ! self::is_erasable( $status ) ) {
				return [
					'message' => self::get_retained_message( $contract_id, false ),
					'removed' => false,
					'failed'  => false,
				];
			}

			$was_running = Cancellation::can_cancel( $status );
			if ( $was_running ) {
				$step      = 'cancel';
				$cancelled = ( new Cancellation() )->cancel( $contract_id );
				if ( ! $cancelled instanceof ContractView ) {
					self::log_failure( $contract_id, $step, $cancelled instanceof WP_Error ? $cancelled->get_error_code() : 'not found' );

					return self::get_failure( self::get_failed_message( $contract_id ) );
				}
			}

			$step = 'history';
			$this->replace_history( $contract, $lock );
			$step = 'anonymise';
			$this->anonymise( $contract, $lock );
			$step = 'unlink';
			$this->unlink( $contract, $lock );

			return [
				'message' => $was_running
					/* translators: %d: subscription number. */
					? sprintf( __( 'Cancelled subscription #%d and removed its personal data.', 'woocommerce-subscriptions-lite' ), $contract_id )
					/* translators: %d: subscription number. */
					: sprintf( __( 'Removed personal data from subscription #%d.', 'woocommerce-subscriptions-lite' ), $contract_id ),
				'removed' => true,
				'failed'  => false,
			];
		} catch ( ContractLockedException $e ) {
			$conflict = HeldLocks::get_refused_conflict( $e );
			self::log_failure( $contract_id, $step, sprintf( 'held by %s', $e->get_operation() ?? 'unknown' ) );

			return self::get_failure( self::get_locked_message( $conflict ) );
		} catch ( Throwable $e ) {
			self::log_failure( $contract_id, $step, sprintf( '%s, code %s', get_class( $e ), (string) $e->getCode() ) );

			return self::get_failure( self::get_failed_message( $contract_id ) );
		} finally {
			if ( null !== $lock ) {
				HeldLocks::release( $lock );
			}
		}
	}

	/**
	 * Delete every history entry, then record one `personal-data-removed` entry by the current
	 * user. The deletion is logged with its count. A failed marker entry is logged by
	 * {@see History} and does not stop the erasure; the contract stays findable until it is unlinked.
	 *
	 * @param ContractView $contract The contract as read under the lock.
	 * @param ContractLock $lock     The held lock.
	 */
	private function replace_history( ContractView $contract, ContractLock $lock ): void {
		$deleted = Contracts::delete_history( $contract->get_id(), [ 'extension_slug' => Package::EXTENSION_SLUG ], $lock );
		wc_get_logger()->info(
			sprintf( 'Privacy erasure: deleted %d history entries of contract %d.', $deleted, $contract->get_id() ),
			[
				'source'      => Package::EXTENSION_SLUG,
				'contract_id' => $contract->get_id(),
			]
		);

		History::add( $contract, History::TYPE_PERSONAL_DATA_REMOVED, [ 'payload' => [ 'source' => self::HISTORY_SOURCE ] ] );
	}

	/**
	 * Anonymise the addresses and clear the payment references. The customer link stays until
	 * {@see self::unlink()}, so the account lookup still finds the contract if a later step fails.
	 *
	 * @param ContractView $contract The contract as read under the lock, with its addresses.
	 * @param ContractLock $lock     The held lock.
	 * @throws RuntimeException If the write finds the contract missing.
	 */
	private function anonymise( ContractView $contract, ContractLock $lock ): void {
		$anonymised = Contracts::update(
			$contract->get_id(),
			[
				'extension_slug'       => Package::EXTENSION_SLUG,
				'addresses'            => self::anonymise_addresses( $contract->get_addresses() ?? [] ),
				'payment_token_id'     => null,
				'payment_method_title' => null,
			],
			$lock
		);
		if ( null === $anonymised ) {
			throw new RuntimeException( 'The contract was not found for the anonymising write.' );
		}
	}

	/**
	 * Clear the customer link: the final write, after which the account lookup no longer finds
	 * the contract.
	 *
	 * @param ContractView $contract The contract as read under the lock.
	 * @param ContractLock $lock     The held lock.
	 * @throws RuntimeException If the write finds the contract missing.
	 */
	private function unlink( ContractView $contract, ContractLock $lock ): void {
		if ( null === $contract->get_customer_id() ) {
			return;
		}

		$unlinked = Contracts::update(
			$contract->get_id(),
			[
				'extension_slug' => Package::EXTENSION_SLUG,
				'customer_id'    => null,
			],
			$lock
		);
		if ( null === $unlinked ) {
			throw new RuntimeException( 'The contract was not found for the unlinking write.' );
		}
	}

	/**
	 * Every address with each non-empty field anonymised; empty fields stay as they are.
	 *
	 * @param array<string, array<string, mixed>> $addresses Addresses by type.
	 * @return array<string, array<string, mixed>>
	 */
	private static function anonymise_addresses( array $addresses ): array {
		foreach ( $addresses as $type => $address ) {
			foreach ( $address as $field => $value ) {
				if ( null === $value || '' === $value ) {
					continue;
				}
				$addresses[ $type ][ $field ] = wp_privacy_anonymize_data( self::ADDRESS_DATA_TYPES[ $field ] ?? 'text', (string) $value );
			}
		}

		return $addresses;
	}

	/**
	 * The message for a retained contract; for a running one while erasure is off, with the
	 * warning that the request may remove its saved payment methods.
	 *
	 * @param int  $contract_id          Contract id.
	 * @param bool $warn_payment_methods Whether to add the saved payment method warning.
	 */
	private static function get_retained_message( int $contract_id, bool $warn_payment_methods ): string {
		if ( $warn_payment_methods ) {
			return sprintf(
				/* translators: %d: subscription number. */
				__( 'Personal data within subscription #%d has been retained. This erasure may remove the customer\'s saved payment methods, so the subscription\'s automatic renewals may fail.', 'woocommerce-subscriptions-lite' ),
				$contract_id
			);
		}

		/* translators: %d: subscription number. */
		return sprintf( __( 'Personal data within subscription #%d has been retained.', 'woocommerce-subscriptions-lite' ), $contract_id );
	}

	/**
	 * The message for a contract whose lock another operation holds: the shared lock-conflict
	 * wording, plus the erasure's retry advice.
	 *
	 * @param WP_Error $conflict The lock conflict from {@see HeldLocks}.
	 */
	private static function get_locked_message( WP_Error $conflict ): string {
		return $conflict->get_error_message() . ' ' . __( 'Its personal data was not removed. Run the erasure again in a few minutes.', 'woocommerce-subscriptions-lite' );
	}

	/**
	 * The message for a contract whose erasure failed for any other reason.
	 *
	 * @param int $contract_id Contract id.
	 */
	private static function get_failed_message( int $contract_id ): string {
		return sprintf(
			/* translators: %d: subscription number. */
			__( 'Personal data could not be removed from subscription #%d. Check the WooCommerce logs and run the erasure again.', 'woocommerce-subscriptions-lite' ),
			$contract_id
		);
	}

	/**
	 * A failed outcome with `$message`.
	 *
	 * @param string $message Message for the request.
	 * @return array{message: string, removed: bool, failed: bool}
	 */
	private static function get_failure( string $message ): array {
		return [
			'message' => $message,
			'removed' => false,
			'failed'  => true,
		];
	}

	/**
	 * Log a failed erasure as an error, since the request still completes: the contract id,
	 * the step that failed and the reason only, no personal data.
	 *
	 * @param int    $contract_id Contract id.
	 * @param string $step        The failing step: lock, read, cancel, history, anonymise or unlink.
	 * @param string $reason      Reason: the lock holder, an error code or the exception class and code.
	 */
	private static function log_failure( int $contract_id, string $step, string $reason ): void {
		wc_get_logger()->error(
			sprintf( 'Privacy erasure: personal data was not removed from contract %d at the %s step (%s).', $contract_id, $step, $reason ),
			[
				'source'      => Package::EXTENSION_SLUG,
				'contract_id' => $contract_id,
				'step'        => $step,
			]
		);
	}
}
