/**
 * DiscountField - pricing value + type + scope editor.
 *
 * Maps to the engine's pricing_policy.policies[0]. The value input carries a
 * type-driven affix: "%" for percentage discounts, the store currency symbol
 * (on its configured side) for monetary ones.
 */

import {
	SelectControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalInputControl as InputControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalInputControlPrefixWrapper as InputControlPrefixWrapper,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalInputControlSuffixWrapper as InputControlSuffixWrapper,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { config } from '../../config';
import { NumberControl } from '../controls/number-control';
import { FormErrorMessage } from '../controls/form-error-message';

/**
 * Build the value input's affix props for the selected pricing type.
 *
 * @param {string} pricingType Selected pricing type.
 * @param {Object} currency    Store currency settings.
 * @return {Object} prefix/suffix props for InputControl.
 */
function discountAffixes( pricingType, currency ) {
	if ( pricingType === 'percentage' ) {
		return {
			suffix: <InputControlSuffixWrapper>%</InputControlSuffixWrapper>,
		};
	}

	if (
		currency.position === 'right' ||
		currency.position === 'right_space'
	) {
		return {
			suffix: (
				<InputControlSuffixWrapper>
					{ currency.symbol }
				</InputControlSuffixWrapper>
			),
		};
	}

	return {
		prefix: (
			<InputControlPrefixWrapper>
				{ currency.symbol }
			</InputControlPrefixWrapper>
		),
	};
}

/**
 * Discount editor (value + type + scope + duration).
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
				<InputControl
					__next40pxDefaultSize
					type="number"
					label={ __( 'Discount', 'woocommerce-subscriptions-lite' ) }
					value={ String( data.pricingValue ) }
					onChange={ ( value ) =>
						onChange( { pricingValue: value ?? '' } )
					}
					min={ 0 }
					max={ data.pricingType === 'percentage' ? 100 : undefined }
					step={ 0.01 }
					{ ...discountAffixes( data.pricingType, config.currency ) }
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
			<FormErrorMessage message={ errors.pricingValue } />
			<div className="wc-subscriptions-lite-plans__applies-to">
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
					<div className="wc-subscriptions-lite-plans__cycle-count">
						<NumberControl
							label={ __(
								'Number of discounted payments',
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
							help={ __(
								'How many payments the discount applies to, starting with the first payment.',
								'woocommerce-subscriptions-lite'
							) }
						/>
						<FormErrorMessage message={ errors.durationCycles } />
					</div>
				) }
			</div>
		</div>
	);
}
