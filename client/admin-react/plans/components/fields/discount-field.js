/**
 * DiscountField - pricing type + value + scope editor.
 *
 * Maps to the engine's pricing_policy.policies[0].
 */

import { SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { NumberControl } from '../controls/number-control';
import { FormErrorMessage } from '../controls/form-error-message';

/**
 * Discount editor (type + value + scope + duration).
 *
 * @param {Object}   props             Component props.
 * @param {Object}   props.data        Current form data.
 * @param {Function} props.onChange    Change handler receiving a partial form-data patch.
 * @param {Object}   props.errors      Validation errors keyed by field id.
 * @param {Object}   props.definitions Normalized engine definitions.
 * @return {Object} DiscountEdit component.
 */
export function DiscountEdit( { data, onChange, errors, definitions } ) {
	const typeOptions = definitions.pricingTypes.map( ( type ) => ( {
		label: type.label,
		value: type.value,
	} ) );
	const scopeOptions = definitions.pricingScopes.map( ( scope ) => ( {
		label: scope.label,
		value: scope.value,
	} ) );

	return (
		<div className="wc-subscriptions-lite-plans__field">
			<div className="wc-subscriptions-lite-plans__field-row">
				<NumberControl
					label={ __( 'Value', 'woocommerce-subscriptions-lite' ) }
					value={ String( data.pricingValue ) }
					onChange={ ( value ) =>
						onChange( { pricingValue: value } )
					}
					min={ 0 }
					max={ data.pricingType === 'percentage' ? 100 : Infinity }
					step={ 0.01 }
				/>
				<SelectControl
					__next40pxDefaultSize
					aria-label={ __(
						'Pricing type',
						'woocommerce-subscriptions-lite'
					) }
					value={ data.pricingType }
					options={ typeOptions }
					onChange={ ( value ) => onChange( { pricingType: value } ) }
				/>
			</div>
			<div className="wc-subscriptions-lite-plans__field-row">
				<SelectControl
					__next40pxDefaultSize
					label={ __(
						'Applies to',
						'woocommerce-subscriptions-lite'
					) }
					value={ data.pricingScope }
					options={ scopeOptions }
					onChange={ ( value ) =>
						onChange( { pricingScope: value } )
					}
				/>
				{ data.pricingScope === 'n_cycles' && (
					<NumberControl
						label={ __(
							'Cycle count',
							'woocommerce-subscriptions-lite'
						) }
						value={ String( data.durationCycles ) }
						onChange={ ( value ) =>
							onChange( {
								durationCycles: parseInt( value, 10 ) || 0,
							} )
						}
						min={ 2 }
						step={ 1 }
					/>
				) }
			</div>
			<FormErrorMessage message={ errors.pricingValue } />
			<FormErrorMessage message={ errors.durationCycles } />
		</div>
	);
}
