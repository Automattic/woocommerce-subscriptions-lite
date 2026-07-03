/**
 * Name and description field editors.
 */

import {
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalInputControl as InputControl,
	TextareaControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { FormErrorMessage } from '../controls/form-error-message';

/**
 * Plan name editor.
 *
 * @param {Object}   props          Component props.
 * @param {Object}   props.data     Current form data.
 * @param {Function} props.onChange Change handler receiving a partial form-data patch.
 * @param {Object}   props.errors   Validation errors keyed by field id.
 * @return {Object} NameEdit component.
 */
export function NameEdit( { data, onChange, errors } ) {
	return (
		<div className="wc-subscriptions-lite-plans__field">
			<InputControl
				__next40pxDefaultSize
				label={ __( 'Name', 'woocommerce-subscriptions-lite' ) }
				value={ data.name }
				onChange={ ( value ) => onChange( { name: value ?? '' } ) }
			/>
			<FormErrorMessage message={ errors.name } />
		</div>
	);
}

/**
 * Plan description editor.
 *
 * @param {Object}   props          Component props.
 * @param {Object}   props.data     Current form data.
 * @param {Function} props.onChange Change handler receiving a partial form-data patch.
 * @return {Object} DescriptionEdit component.
 */
export function DescriptionEdit( { data, onChange } ) {
	return (
		<div className="wc-subscriptions-lite-plans__field">
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'Description', 'woocommerce-subscriptions-lite' ) }
				value={ data.description }
				onChange={ ( value ) => onChange( { description: value } ) }
			/>
		</div>
	);
}
