/**
 * PlanForm - registry-driven create/edit form.
 *
 * Iterates the field registry in order; each field renders via its own Edit
 * component (built-in or injected). Field errors surface on blur, clear as
 * the value changes, and the whole form re-validates on submit.
 */

import { useMemo, useRef, useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import {
	formDataToPayload,
	makeDefaultFormData,
	planToFormData,
} from '../transforms';

/**
 * @param {Object}        props               Component props.
 * @param {Array<Object>} props.registry      Field descriptors.
 * @param {Object}        props.definitions   Normalized engine definitions.
 * @param {Object|null}   props.plan          Plan being edited, or null for create.
 * @param {boolean}       props.isDisabled    Whether the form is disabled.
 * @param {Function}      props.onCancel      Cancel callback.
 * @param {Function}      props.onSave        Save callback, receives (payload, formData).
 * @param {Function}      props.onFieldChange Optional per-change callback.
 * @return {Object} PlanForm component.
 */
export function PlanForm( {
	registry,
	definitions,
	plan,
	isDisabled = false,
	onCancel,
	onSave,
	onFieldChange,
} ) {
	const [ formData, setFormData ] = useState( () =>
		plan
			? planToFormData( registry, plan )
			: makeDefaultFormData( registry )
	);

	// Validation errors, bucketed per field descriptor so a blur re-validation
	// replaces exactly the keys that descriptor owns.
	const [ errorsByField, setErrorsByField ] = useState( {} );

	// Blur handlers validate against the latest data even when the triggering
	// commit lands in the same tick as the blur.
	const formDataRef = useRef( formData );
	formDataRef.current = formData;

	const errors = useMemo(
		() => Object.assign( {}, ...Object.values( errorsByField ) ),
		[ errorsByField ]
	);
	const hasErrors = Object.keys( errors ).length > 0;

	const handleChange = ( patch ) => {
		setFormData( ( current ) => {
			const next = { ...current, ...patch };
			formDataRef.current = next;
			return next;
		} );

		// A changed value clears its own error until the next blur or submit.
		const patchKeys = Object.keys( patch );
		setErrorsByField( ( current ) => {
			const next = {};
			Object.keys( current ).forEach( ( fieldId ) => {
				const kept = { ...current[ fieldId ] };
				patchKeys.forEach( ( key ) => delete kept[ key ] );
				next[ fieldId ] = kept;
			} );
			return next;
		} );

		if ( onFieldChange ) {
			onFieldChange();
		}
	};

	const validateField = ( field ) => {
		if ( ! field.validate ) {
			return;
		}
		const fieldErrors = field.validate( formDataRef.current ) || {};
		setErrorsByField( ( current ) => ( {
			...current,
			[ field.id ]: fieldErrors,
		} ) );
	};

	const handleSubmit = ( event ) => {
		event.preventDefault();

		const allErrors = {};
		registry.forEach( ( field ) => {
			if ( field.validate ) {
				allErrors[ field.id ] =
					field.validate( formDataRef.current ) || {};
			}
		} );
		setErrorsByField( allErrors );

		const failing = Object.values( allErrors ).some(
			( bucket ) => Object.keys( bucket ).length > 0
		);
		if ( failing ) {
			return;
		}
		onSave( formDataToPayload( registry, formData, plan ), formData );
	};

	return (
		<form
			className="wc-subscriptions-lite-plans__form"
			onSubmit={ handleSubmit }
		>
			{ registry.map( ( field ) => {
				const Edit = field.Edit;
				if ( ! Edit ) {
					return null;
				}
				return (
					<Edit
						key={ field.id }
						data={ formData }
						onChange={ handleChange }
						onBlur={ () => validateField( field ) }
						errors={ errors }
						definitions={ definitions }
					/>
				);
			} ) }

			<div className="wc-subscriptions-lite-plans__form-actions">
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ onCancel }
				>
					{ __( 'Cancel', 'woocommerce-subscriptions-lite' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="primary"
					type="submit"
					isBusy={ isDisabled }
					disabled={ isDisabled || hasErrors }
				>
					{ __( 'Save', 'woocommerce-subscriptions-lite' ) }
				</Button>
			</div>
		</form>
	);
}
