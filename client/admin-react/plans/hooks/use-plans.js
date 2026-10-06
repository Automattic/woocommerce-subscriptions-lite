/**
 * usePlans - plan list state + CRUD against the engine REST API, in the Lite
 * plan display order.
 *
 * Plan CRUD wraps the engine's plain WP-REST conventions (list with
 * X-WP-Total headers, POST create, PATCH update, archive-via-status). The
 * display order is Lite data: seeded from the inline config, applied to the
 * fetched plans client-side, and saved through Lite's own reorder route. It
 * intentionally does not assume Premium's { success, message, data } envelope.
 */

import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { createPlan, fetchPlans, reorderPlans, updatePlan } from '../api';
import { config } from '../config';
import { sortByPlanOrder } from '../order';
import { viewToQuery } from '../transforms';

/**
 * @param {Object} view DataViews view state driving the list query.
 * @return {Object} Plan list state and CRUD operations.
 */
export function usePlans( view ) {
	const [ fetchedPlans, setPlans ] = useState( [] );
	const [ planOrder, setPlanOrder ] = useState( config.planOrder );
	const plans = useMemo(
		() => sortByPlanOrder( fetchedPlans, planOrder ),
		[ fetchedPlans, planOrder ]
	);
	const [ paginationInfo, setPaginationInfo ] = useState( {
		totalItems: 0,
		totalPages: 0,
	} );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( '' );

	const load = useCallback( async () => {
		setIsLoading( true );
		setError( '' );
		try {
			const response = await fetchPlans( viewToQuery( view ) );
			setPlans( response.data );
			setPaginationInfo( {
				totalItems: response.totalItems,
				totalPages: response.totalPages,
			} );
		} catch ( fetchError ) {
			setError(
				fetchError?.message ||
					__(
						'Subscription plans could not be loaded.',
						'woocommerce-subscriptions-lite'
					)
			);
		} finally {
			setIsLoading( false );
		}
	}, [ view ] );

	useEffect( () => {
		load();
	}, [ load ] );

	const savePlan = useCallback( async ( payload, plan ) => {
		if ( plan?.id ) {
			return updatePlan( plan.id, payload );
		}
		return createPlan( payload );
	}, [] );

	const setStatus = useCallback(
		( plan, status ) => updatePlan( plan.id, { status } ),
		[]
	);

	const reorder = useCallback( async ( ids ) => {
		const response = await reorderPlans( ids );
		setPlanOrder( Array.isArray( response?.ids ) ? response.ids : ids );
	}, [] );

	return {
		plans,
		paginationInfo,
		isLoading,
		error,
		setError,
		reload: load,
		savePlan,
		setStatus,
		reorder,
	};
}
