/**
 * ExpirationField - "expire after N payments" toggle + total payments editor.
 *
 * Maps to the engine's billing_policy.max_cycles.
 */

import { CheckboxControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { NumberControl } from '../controls/number-control';
import { FormErrorMessage } from '../controls/form-error-message';

/**
 * Expiration editor (expires toggle + total payments).
 *
 * @param {Object}   props          Component props.
 * @param {Object}   props.data     Current form data.
 * @param {Function} props.onChange Change handler receiving a partial form-data patch.
 * @param {Object}   props.errors   Validation errors keyed by field id.
 * @return {Object} ExpirationEdit component.
 */
export function ExpirationEdit( { data, onChange, errors } ) {
	return (
		<div className="wc-subscriptions-lite-plans__field">
			<CheckboxControl
				__nextHasNoMarginBottom
				label={ __(
					'Expire after a set number of payments',
					'woocommerce-subscriptions-lite'
				) }
				checked={ Boolean( data.expires ) }
				onChange={ ( checked ) =>
					onChange( {
						expires: checked,
						maxCycles:
							checked && ! data.maxCycles ? 1 : data.maxCycles,
					} )
				}
			/>
			{ data.expires && (
				<div className="wc-subscriptions-lite-plans__total-payments">
					<NumberControl
						label={ __(
							'Total payments',
							'woocommerce-subscriptions-lite'
						) }
						value={ String( data.maxCycles ) }
						onChange={ ( value ) =>
							onChange( {
								maxCycles: parseInt( value, 10 ) || 0,
							} )
						}
						min={ 1 }
						step={ 1 }
						help={ __(
							'The number of payments, including the initial purchase, before the subscription automatically expires.',
							'woocommerce-subscriptions-lite'
						) }
					/>
					<FormErrorMessage message={ errors.maxCycles } />
				</div>
			) }
		</div>
	);
}
