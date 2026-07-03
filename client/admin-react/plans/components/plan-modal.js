/**
 * PlanModal - modal dialog wrapping PlanForm, with save-UX states.
 */

import { Modal, Notice, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { PlanForm } from './plan-form';

/**
 * @param {Object}        props                  Component props.
 * @param {Array<Object>} props.registry         Field descriptors.
 * @param {Object}        props.definitions      Normalized engine definitions.
 * @param {Object|null}   props.plan             Plan being edited, or null.
 * @param {boolean}       props.isSaving         Whether a save is in progress.
 * @param {string}        props.apiError         API error message, if any.
 * @param {boolean}       props.duplicateWarning Whether a duplicate was detected.
 * @param {Function}      props.onClose          Close callback.
 * @param {Function}      props.onSave           Save callback (payload, formData).
 * @param {Function}      props.onFieldChange    Per-change callback.
 * @return {Object} PlanModal component.
 */
export function PlanModal( {
	registry,
	definitions,
	plan,
	isSaving = false,
	apiError = '',
	duplicateWarning = false,
	onClose,
	onSave,
	onFieldChange,
} ) {
	return (
		<Modal
			title={
				plan
					? __(
							'Edit subscription plan',
							'woocommerce-subscriptions-lite'
					  )
					: __(
							'Add a subscription plan',
							'woocommerce-subscriptions-lite'
					  )
			}
			onRequestClose={ onClose }
			className="wc-subscriptions-lite-plans__modal"
			size="medium"
			shouldCloseOnClickOutside={ ! isSaving }
			shouldCloseOnEsc={ ! isSaving }
		>
			{ apiError && (
				<Notice status="error" isDismissible={ false }>
					<strong>
						{ __(
							'Error saving plan:',
							'woocommerce-subscriptions-lite'
						) }
					</strong>{ ' ' }
					{ apiError }
				</Notice>
			) }
			{ duplicateWarning && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'This plan matches an existing plan. Change its settings to make it unique, then save again.',
						'woocommerce-subscriptions-lite'
					) }
				</Notice>
			) }
			{ isSaving && (
				<div className="wc-subscriptions-lite-plans__saving">
					<Spinner />
					<span>
						{ __(
							'Saving plan…',
							'woocommerce-subscriptions-lite'
						) }
					</span>
				</div>
			) }

			<PlanForm
				registry={ registry }
				definitions={ definitions }
				plan={ plan }
				isDisabled={ isSaving || duplicateWarning }
				onCancel={ onClose }
				onSave={ onSave }
				onFieldChange={ onFieldChange }
			/>
		</Modal>
	);
}
