/**
 * PlanForm - registry-driven create/edit form.
 *
 * Iterates the field registry, grouping descriptors into sections. Each field
 * renders via its own Edit component (built-in or injected). Validation runs on
 * submit; the Save button is disabled while errors are present.
 */

import { useMemo, useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import {
	formDataToPayload,
	formErrors,
	makeDefaultFormData,
	planToFormData,
} from '../transforms';
import { GROUP_BILLING, GROUP_PRICING } from '../fields/builtins';

const GROUP_LABELS = {
	[ GROUP_BILLING ]: __( 'Billing', 'woocommerce-subscriptions-lite' ),
	[ GROUP_PRICING ]: __( 'Pricing', 'woocommerce-subscriptions-lite' ),
};

/**
 * Group descriptors into ordered sections, preserving first-seen order.
 *
 * @param {Array<Object>} registry Field descriptors.
 * @return {Array<{ id: (string|null), label: (string|null), fields: Array }>} Sections.
 */
function groupFields( registry ) {
	const sections = [];
	const index = new Map();

	registry.forEach( ( field ) => {
		const groupId = field.group || null;
		if ( ! index.has( groupId ) ) {
			const section = {
				id: groupId,
				label: groupId
					? field.groupLabel || GROUP_LABELS[ groupId ] || groupId
					: null,
				fields: [],
			};
			index.set( groupId, section );
			sections.push( section );
		}
		index.get( groupId ).fields.push( field );
	} );

	return sections;
}

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
	const [ showErrors, setShowErrors ] = useState( false );

	const errors = useMemo(
		() => formErrors( registry, formData ),
		[ registry, formData ]
	);
	const hasErrors = Object.keys( errors ).length > 0;
	const displayedErrors = showErrors ? errors : {};

	const sections = useMemo( () => groupFields( registry ), [ registry ] );

	const handleChange = ( patch ) => {
		setFormData( ( current ) => ( { ...current, ...patch } ) );
		if ( onFieldChange ) {
			onFieldChange();
		}
	};

	const handleSubmit = ( event ) => {
		event.preventDefault();
		setShowErrors( true );
		if ( hasErrors ) {
			return;
		}
		onSave( formDataToPayload( registry, formData, plan ), formData );
	};

	return (
		<form
			className="wc-subscriptions-lite-plans__form"
			onSubmit={ handleSubmit }
		>
			{ sections.map( ( section ) => (
				<div
					key={ section.id || 'general' }
					className="wc-subscriptions-lite-plans__form-section"
				>
					{ section.label && (
						<h3 className="wc-subscriptions-lite-plans__form-section-title">
							{ section.label }
						</h3>
					) }
					{ section.fields.map( ( field ) => {
						const Edit = field.Edit;
						if ( ! Edit ) {
							return null;
						}
						return (
							<Edit
								key={ field.id }
								data={ formData }
								onChange={ handleChange }
								errors={ displayedErrors }
								definitions={ definitions }
							/>
						);
					} ) }
				</div>
			) ) }

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
					disabled={ isDisabled || ( showErrors && hasErrors ) }
				>
					{ __( 'Save', 'woocommerce-subscriptions-lite' ) }
				</Button>
			</div>
		</form>
	);
}
