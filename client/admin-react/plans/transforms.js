import { __ } from '@wordpress/i18n';
import { config } from './config';

export const DEFAULT_FORM_DATA = {
	name: '',
	description: '',
	period: 'month',
	interval: 1,
	expires: false,
	maxCycles: '',
	pricingType: 'percentage',
	pricingValue: '',
	pricingScope: 'all',
	durationCycles: '',
};

export function planToFormData( plan = {} ) {
	const billing = plan.billing_policy || {};
	const pricing = plan.pricing_policy || {};
	const firstPolicy = Array.isArray( pricing.policies )
		? pricing.policies[ 0 ]
		: null;
	const maxCycles = billing.max_cycles || '';
	const pricingType = firstPolicy?.type || 'percentage';
	const durationCycles = firstPolicy?.duration_cycles || '';
	let pricingScope = 'all';
	if ( Number( durationCycles ) === 1 ) {
		pricingScope = 'first';
	} else if ( Number( durationCycles ) > 1 ) {
		pricingScope = 'n_cycles';
	}

	return {
		...DEFAULT_FORM_DATA,
		name: plan.name || '',
		description: plan.description || '',
		period: billing.period || 'month',
		interval: billing.interval || 1,
		expires: Number( maxCycles ) > 0,
		maxCycles,
		pricingType,
		pricingValue: pricingType === 'bogo' ? '' : firstPolicy?.value ?? '',
		pricingScope,
		durationCycles,
	};
}

export function formDataToPayload( formData, plan = null ) {
	const payload = {
		extension_slug: config.extensionSlug,
		name: formData.name.trim(),
		description: formData.description?.trim() || null,
		billing_policy: {
			period: formData.period,
			interval: Number( formData.interval ),
			max_cycles: formData.expires ? Number( formData.maxCycles ) : null,
		},
		pricing_policy: pricingPolicyFromFormData( formData, plan ),
	};

	if ( ! plan ) {
		payload.status = config.defaultStatus;
	}

	return payload;
}

export function pricingPolicyFromFormData( formData, plan = null ) {
	const existing = plan?.pricing_policy || {};
	const oneTimeFees = Array.isArray( existing.one_time_fees )
		? existing.one_time_fees
		: [];

	if ( formData.pricingType !== 'bogo' && formData.pricingValue === '' ) {
		return { policies: [], one_time_fees: oneTimeFees };
	}

	const entry = {
		type: formData.pricingType,
		value:
			formData.pricingType === 'bogo'
				? 1
				: Number( formData.pricingValue ),
	};

	if ( formData.pricingScope === 'first' ) {
		entry.duration_cycles = 1;
	} else if ( formData.pricingScope === 'n_cycles' ) {
		entry.duration_cycles = Number( formData.durationCycles );
	}

	return {
		policies: [ entry ],
		one_time_fees: oneTimeFees,
	};
}

export function formErrors( formData ) {
	const errors = {};

	if ( ! formData.name.trim() ) {
		errors.name = __(
			'Name is required.',
			'woocommerce-subscriptions-lite'
		);
	}
	if ( Number( formData.interval ) < 1 ) {
		errors.interval = __(
			'Interval must be at least 1.',
			'woocommerce-subscriptions-lite'
		);
	}
	if ( formData.expires && Number( formData.maxCycles ) < 1 ) {
		errors.maxCycles = __(
			'Total payments must be at least 1.',
			'woocommerce-subscriptions-lite'
		);
	}
	if (
		formData.pricingType !== 'bogo' &&
		formData.pricingValue !== '' &&
		Number( formData.pricingValue ) < 0
	) {
		errors.pricingValue = __(
			'Discount value cannot be negative.',
			'woocommerce-subscriptions-lite'
		);
	}
	if (
		formData.pricingType === 'percentage' &&
		formData.pricingValue !== '' &&
		Number( formData.pricingValue ) > 100
	) {
		errors.pricingValue = __(
			'Percentage cannot exceed 100.',
			'woocommerce-subscriptions-lite'
		);
	}
	if (
		formData.pricingScope === 'n_cycles' &&
		Number( formData.durationCycles ) < 2
	) {
		errors.durationCycles = __(
			'Cycle count must be at least 2.',
			'woocommerce-subscriptions-lite'
		);
	}

	return errors;
}

export function viewToQuery( view ) {
	const statusFilter = view.filters?.find(
		( filter ) => filter.field === 'status'
	);
	const statusValue = Array.isArray( statusFilter?.value )
		? statusFilter.value[ 0 ]
		: statusFilter?.value;

	return {
		page: view.page || 1,
		per_page: view.perPage || 20,
		search: view.search || '',
		status: statusValue || '',
		orderby: view.sort?.field || 'sort_order',
		order: view.sort?.direction || 'asc',
	};
}

export function formatFrequency( plan ) {
	const billing = plan.billing_policy || {};
	const interval = Number( billing.interval || 1 );
	const unit = billing.period || 'month';
	return interval === 1
		? unit
		: `${ interval } ${ unit }${ unit.endsWith( 's' ) ? '' : 's' }`;
}

export function formatDiscount( plan ) {
	const firstPolicy = plan.pricing_policy?.policies?.[ 0 ];
	if ( ! firstPolicy ) {
		return '-';
	}

	const value = Number( firstPolicy.value || 0 );
	let label = '-';
	if ( firstPolicy.type === 'percentage' ) {
		label = `${ value }% off`;
	} else if ( firstPolicy.type === 'fixed_amount' ) {
		label = `${ value } off`;
	} else if ( firstPolicy.type === 'price' ) {
		label = `${ value }`;
	} else if ( firstPolicy.type === 'bogo' ) {
		label = __( 'Buy one get one', 'woocommerce-subscriptions-lite' );
	}

	if ( Number( firstPolicy.duration_cycles ) === 1 ) {
		return `${ label } (${ __(
			'first cycle',
			'woocommerce-subscriptions-lite'
		) })`;
	}
	if ( Number( firstPolicy.duration_cycles ) > 1 ) {
		return `${ label } (${ firstPolicy.duration_cycles } ${ __(
			'cycles',
			'woocommerce-subscriptions-lite'
		) })`;
	}

	return label;
}
