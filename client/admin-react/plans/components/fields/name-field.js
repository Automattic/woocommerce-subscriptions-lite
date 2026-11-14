/**
 * NameField - plan name editor.
 */

import { TextControl } from '@wordpress/components';
import { useId } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { FormErrorMessage } from '../controls/form-error-message';

/**
 * Plan name editor. Required, so the input carries `aria-required`; native
 * `required` would show the browser's own validation instead of the field
 * error.
 *
 * @param {Object}   props          Component props.
 * @param {Object}   props.data     Current form data.
 * @param {Function} props.onChange Change handler receiving a partial form-data patch.
 * @param {Function} props.onBlur   Blur handler; validates this field.
 * @param {Object}   props.errors   Validation errors keyed by field id.
 * @return {Object} NameEdit component.
 */
export function NameEdit( { data, onChange, onBlur, errors } ) {
	const errorId = `${ useId() }-name-error`;

	return (
		<div className="wc-subscriptions-lite-plans__field">
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Name', 'woocommerce-subscriptions-lite' ) }
				value={ data.name }
				onChange={ ( value ) => onChange( { name: value } ) }
				onBlur={ onBlur }
				aria-required="true"
				aria-describedby={ errors.name ? errorId : undefined }
				aria-invalid={ errors.name ? 'true' : undefined }
			/>
			<FormErrorMessage id={ errorId } message={ errors.name } />
		</div>
	);
}
