/**
 * NameField - plan name editor.
 */

import { TextControl } from '@wordpress/components';
import { useId } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { FormErrorMessage } from '../controls/form-error-message';
import { formatFrequency } from '../../format';

/**
 * The suggested name for a new plan: its billing frequency label, the same
 * text as the Frequency list column (e.g. "1 month").
 *
 * @param {Object} data        Current form data.
 * @param {Object} definitions Normalized engine definitions.
 * @return {string} Suggested plan name.
 */
export function suggestedName( data, definitions ) {
	return formatFrequency(
		{
			billing_policy: {
				interval: data.interval,
				period: data.period,
			},
		},
		definitions
	);
}

/**
 * Plan name editor. A `null` name (a new plan the merchant has not named yet)
 * shows the suggested name, which follows the frequency until edited.
 *
 * @param {Object}   props             Component props.
 * @param {Object}   props.data        Current form data.
 * @param {Function} props.onChange    Change handler receiving a partial form-data patch.
 * @param {Function} props.onBlur      Blur handler; validates this field.
 * @param {Object}   props.errors      Validation errors keyed by field id.
 * @param {Object}   props.definitions Normalized engine definitions.
 * @return {Object} NameEdit component.
 */
export function NameEdit( { data, onChange, onBlur, errors, definitions } ) {
	const errorId = `${ useId() }-name-error`;

	return (
		<div className="wc-subscriptions-lite-plans__field">
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Name', 'woocommerce-subscriptions-lite' ) }
				value={ data.name ?? suggestedName( data, definitions ) }
				onChange={ ( value ) => onChange( { name: value ?? '' } ) }
				onBlur={ onBlur }
				aria-describedby={ errors.name ? errorId : undefined }
				aria-invalid={ errors.name ? 'true' : undefined }
			/>
			<FormErrorMessage id={ errorId } message={ errors.name } />
		</div>
	);
}
