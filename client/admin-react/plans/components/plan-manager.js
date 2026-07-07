/**
 * PlanManager - orchestrates the plan list, editor modal, and CRUD.
 */

import { useCallback, useMemo, useState } from '@wordpress/element';
import { Button, Flex, FlexItem, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { plus } from '@wordpress/icons';
import { config } from '../config';
import { normalizeDefinitions } from '../definitions';
import { buildFieldRegistry } from '../fields';
import { usePlans } from '../hooks/use-plans';
import { useNotifications } from '../hooks/use-notifications';
import { useValidation } from '../hooks/use-validation';
import { PlansTable } from './plans-table';
import { PlanModal } from './plan-modal';

const DEFAULT_VIEW = {
	type: 'table',
	perPage: 100,
	page: 1,
	sort: { field: 'sort_order', direction: 'asc' },
	layout: {},
};

const CLOSED_EDITOR = {
	isOpen: false,
	plan: null,
	isSaving: false,
	apiError: '',
	duplicateWarning: false,
};

/**
 * @return {Object} PlanManager component.
 */
export function PlanManager() {
	const [ view, setView ] = useState( DEFAULT_VIEW );
	const [ editor, setEditor ] = useState( CLOSED_EDITOR );

	const definitions = useMemo(
		() => normalizeDefinitions( config.definitions ),
		[]
	);
	const registry = useMemo(
		() => buildFieldRegistry( { definitions, config } ),
		[ definitions ]
	);

	const {
		plans,
		paginationInfo,
		isLoading,
		error: loadError,
		setError: setLoadError,
		reload,
		savePlan,
		setStatus,
		reorder,
	} = usePlans( view );
	const { showSuccess, showError } = useNotifications();
	const { findDuplicate } = useValidation( registry );

	const openCreate = useCallback( () => {
		setEditor( { ...CLOSED_EDITOR, isOpen: true } );
	}, [] );

	const openEdit = useCallback( ( plan ) => {
		setEditor( { ...CLOSED_EDITOR, isOpen: true, plan } );
	}, [] );

	const closeEditor = useCallback( () => setEditor( CLOSED_EDITOR ), [] );

	const handleFieldChange = useCallback( () => {
		setEditor( ( current ) =>
			current.apiError || current.duplicateWarning
				? { ...current, apiError: '', duplicateWarning: false }
				: current
		);
	}, [] );

	const handleSave = useCallback(
		async ( payload ) => {
			const { isDuplicate } = findDuplicate(
				payload,
				plans,
				editor.plan?.id
			);
			if ( isDuplicate ) {
				setEditor( ( current ) => ( {
					...current,
					duplicateWarning: true,
				} ) );
				return;
			}

			setEditor( ( current ) => ( {
				...current,
				isSaving: true,
				apiError: '',
			} ) );

			try {
				await savePlan( payload, editor.plan );
				showSuccess(
					editor.plan
						? __(
								'Plan updated.',
								'woocommerce-subscriptions-lite'
						  )
						: __(
								'Plan created.',
								'woocommerce-subscriptions-lite'
						  )
				);
				setEditor( CLOSED_EDITOR );
				await reload();
			} catch ( saveError ) {
				setEditor( ( current ) => ( {
					...current,
					isSaving: false,
					apiError:
						saveError?.message ||
						__(
							'Subscription plan could not be saved.',
							'woocommerce-subscriptions-lite'
						),
				} ) );
			}
		},
		[ editor.plan, findDuplicate, plans, reload, savePlan, showSuccess ]
	);

	const changeStatus = useCallback(
		async ( plan, status ) => {
			try {
				await setStatus( plan, status );
				showSuccess(
					status === 'archived'
						? __(
								'Plan archived.',
								'woocommerce-subscriptions-lite'
						  )
						: __(
								'Plan restored.',
								'woocommerce-subscriptions-lite'
						  )
				);
				await reload();
			} catch ( statusError ) {
				showError(
					statusError?.message ||
						__(
							'Plan status could not be changed.',
							'woocommerce-subscriptions-lite'
						)
				);
			}
		},
		[ reload, setStatus, showError, showSuccess ]
	);

	const handleReorder = useCallback(
		async ( ids ) => {
			try {
				await reorder( ids );
				showSuccess(
					__(
						'Plans reordered successfully.',
						'woocommerce-subscriptions-lite'
					)
				);
				await reload();
			} catch ( reorderError ) {
				showError(
					reorderError?.message ||
						__(
							'Plan order could not be saved.',
							'woocommerce-subscriptions-lite'
						)
				);
			}
		},
		[ reload, reorder, showError, showSuccess ]
	);

	return (
		<div className="wc-subscriptions-lite-plans">
			{ loadError && (
				<Notice
					status="error"
					onRemove={ () => setLoadError( '' ) }
					className="wc-subscriptions-lite-plans__notice"
				>
					{ loadError }
				</Notice>
			) }

			<div className="wc-subscriptions-lite-plans__panel">
				<PlansTable
					plans={ plans }
					registry={ registry }
					definitions={ definitions }
					view={ view }
					onChangeView={ setView }
					paginationInfo={ paginationInfo }
					isLoading={ isLoading }
					onEdit={ openEdit }
					onArchive={ ( plan ) => changeStatus( plan, 'archived' ) }
					onRestore={ ( plan ) => changeStatus( plan, 'active' ) }
					onReorder={ handleReorder }
				/>

				<Flex className="wc-subscriptions-lite-plans__add-plan-button">
					<FlexItem>
						<Button
							variant="secondary"
							icon={ plus }
							onClick={ openCreate }
						>
							{ __(
								'Add subscription plan',
								'woocommerce-subscriptions-lite'
							) }
						</Button>
					</FlexItem>
				</Flex>
			</div>

			{ editor.isOpen && (
				<PlanModal
					registry={ registry }
					definitions={ definitions }
					plan={ editor.plan }
					isSaving={ editor.isSaving }
					apiError={ editor.apiError }
					duplicateWarning={ editor.duplicateWarning }
					onClose={ closeEditor }
					onSave={ handleSave }
					onFieldChange={ handleFieldChange }
				/>
			) }
		</div>
	);
}
