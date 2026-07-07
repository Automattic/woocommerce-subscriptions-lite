/**
 * FrequencyField - billing interval + period editor.
 */

import { SelectControl } from '@wordpress/components';
import { useId } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { NumberControl } from '../controls/number-control';
import { FormErrorMessage } from '../controls/form-error-message';

/**
 * Frequency editor (interval + period).
 *
 * @param {Object}   props             Component props.
 * @param {Object}   props.data        Current form data.
 * @param {Function} props.onChange    Change handler receiving a partial form-data patch.
 * @param {Object}   props.errors      Validation errors keyed by field id.
 * @param {Object}   props.definitions Normalized engine definitions.
 * @return {Object} FrequencyEdit component.
 */
export function FrequencyEdit( { data, onChange, errors, definitions } ) {
	const intervalErrorId = `${ useId() }-interval-error`;
	const periodOptions = definitions.billingUnits.map( ( unit ) => ( {
		label: unit.label,
		value: unit.value,
	} ) );

	return (
		<div className="wc-subscriptions-lite-plans__field">
			<div className="wc-subscriptions-lite-plans__field-row">
				<NumberControl
					label={ __(
						'Frequency',
						'woocommerce-subscriptions-lite'
					) }
					value={ String( data.interval ) }
					onChange={ ( value ) =>
						onChange( { interval: parseInt( value, 10 ) || 1 } )
					}
					min={ 1 }
					max={ 365 }
					aria-describedby={
						errors.interval ? intervalErrorId : undefined
					}
					aria-invalid={ errors.interval ? 'true' : undefined }
				/>
				<SelectControl
					__next40pxDefaultSize
					aria-label={ __(
						'Billing period',
						'woocommerce-subscriptions-lite'
					) }
					value={ data.period }
					options={ periodOptions }
					onChange={ ( value ) => onChange( { period: value } ) }
				/>
			</div>
			<FormErrorMessage
				id={ intervalErrorId }
				message={ errors.interval }
			/>
		</div>
	);
}
