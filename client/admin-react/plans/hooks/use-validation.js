/**
 * useValidation - registry-driven form validation + duplicate detection.
 */

import { useCallback } from '@wordpress/element';
import { formErrors } from '../transforms';
import { findDuplicatePlan } from '../utils/validation';

/**
 * @param {Array<Object>} registry Field descriptors.
 * @return {Object} Validation helpers.
 */
export function useValidation( registry ) {
	const getErrors = useCallback(
		( formData ) => formErrors( registry, formData ),
		[ registry ]
	);

	const findDuplicate = useCallback(
		( payload, existingPlans, excludeId ) =>
			findDuplicatePlan( payload, existingPlans, excludeId ),
		[]
	);

	return { getErrors, findDuplicate };
}
