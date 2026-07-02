/**
 * Duplicate-plan detection.
 *
 * Compares the engine-relevant shape of a candidate payload against existing
 * plans so a merchant is warned before creating a functionally identical plan.
 * Works on the engine payload/plan shape (billing_policy + pricing_policy), so
 * it also accounts for data contributed by injected fields.
 */

/**
 * Build a stable comparison signature from an engine plan or payload.
 *
 * @param {Object} planOrPayload Engine plan object or REST payload.
 * @return {string} Canonical JSON signature of the comparable fields.
 */
export function planSignature( planOrPayload = {} ) {
	const billing = planOrPayload.billing_policy || {};
	const pricing = planOrPayload.pricing_policy || {};

	const num = ( value ) =>
		value === undefined || value === null || value === ''
			? null
			: Number( value );

	const policies = (
		Array.isArray( pricing.policies ) ? pricing.policies : []
	).map( ( policy ) => ( {
		type: policy.type ?? null,
		value: num( policy.value ),
		starting_cycle: policy.starting_cycle ?? null,
		duration_cycles: policy.duration_cycles ?? null,
	} ) );

	const fees = (
		Array.isArray( pricing.one_time_fees ) ? pricing.one_time_fees : []
	).map( ( fee ) => ( {
		kind: fee.kind ?? null,
		amount: num( fee.amount ),
		taxable: Boolean( fee.taxable ),
		tax_class: fee.tax_class ?? null,
	} ) );

	return JSON.stringify( {
		period: billing.period ?? null,
		interval: num( billing.interval ),
		min_cycles: num( billing.min_cycles ),
		max_cycles: num( billing.max_cycles ),
		trial_duration: billing.trial_duration ?? null,
		policies,
		fees,
	} );
}

/**
 * Determine whether a candidate plan matches an existing one.
 *
 * @param {Object} candidate     Candidate engine payload.
 * @param {Array}  existingPlans Existing engine plan objects.
 * @param {number} [excludeId]   Plan id to skip (the plan being edited).
 * @return {{ isDuplicate: boolean, matchingPlan: (Object|null) }} Result.
 */
export function findDuplicatePlan(
	candidate,
	existingPlans = [],
	excludeId = null
) {
	const target = planSignature( candidate );

	const matchingPlan = existingPlans.find( ( plan ) => {
		if ( excludeId && plan.id === excludeId ) {
			return false;
		}
		return planSignature( plan ) === target;
	} );

	return {
		isDuplicate: Boolean( matchingPlan ),
		matchingPlan: matchingPlan || null,
	};
}
