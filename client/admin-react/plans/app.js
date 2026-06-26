import { DataForm, DataViews } from '@wordpress/dataviews/wp';
import {
	Button,
	Card,
	CardBody,
	Flex,
	FlexItem,
	Modal,
	Notice,
	Spinner,
} from '@wordpress/components';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Icon,
	chevronDown,
	chevronUp,
	pencil,
	plus,
	trash,
	undo,
} from '@wordpress/icons';
import { createPlan, fetchPlans, reorderPlans, updatePlan } from './api';
import {
	DEFAULT_FORM_DATA,
	formatDiscount,
	formatFrequency,
	formDataToPayload,
	formErrors,
	planToFormData,
	viewToQuery,
} from './transforms';
import { config } from './config';

const DEFAULT_VIEW = {
	type: 'table',
	perPage: 20,
	page: 1,
	search: '',
	filters: [],
	sort: {
		field: 'sort_order',
		direction: 'asc',
	},
	titleField: 'name',
	fields: [ 'frequency', 'discount', 'status' ],
	layout: {
		density: 'comfortable',
	},
};

const DEFAULT_DEFINITIONS = {
	statuses: [
		{
			value: 'active',
			label: __( 'Active', 'woocommerce-subscriptions-lite' ),
		},
		{
			value: 'archived',
			label: __( 'Archived', 'woocommerce-subscriptions-lite' ),
		},
	],
	billingUnits: [
		{
			value: 'day',
			label: __( 'Day', 'woocommerce-subscriptions-lite' ),
			singular: __( 'day', 'woocommerce-subscriptions-lite' ),
			plural: __( 'days', 'woocommerce-subscriptions-lite' ),
		},
		{
			value: 'week',
			label: __( 'Week', 'woocommerce-subscriptions-lite' ),
			singular: __( 'week', 'woocommerce-subscriptions-lite' ),
			plural: __( 'weeks', 'woocommerce-subscriptions-lite' ),
		},
		{
			value: 'month',
			label: __( 'Month', 'woocommerce-subscriptions-lite' ),
			singular: __( 'month', 'woocommerce-subscriptions-lite' ),
			plural: __( 'months', 'woocommerce-subscriptions-lite' ),
		},
		{
			value: 'year',
			label: __( 'Year', 'woocommerce-subscriptions-lite' ),
			singular: __( 'year', 'woocommerce-subscriptions-lite' ),
			plural: __( 'years', 'woocommerce-subscriptions-lite' ),
		},
	],
	pricingTypes: [
		{
			value: 'percentage',
			label: __( 'Percentage', 'woocommerce-subscriptions-lite' ),
		},
		{
			value: 'fixed_amount',
			label: __( 'Fixed amount', 'woocommerce-subscriptions-lite' ),
		},
		{
			value: 'price',
			label: __( 'Fixed price', 'woocommerce-subscriptions-lite' ),
		},
	],
	pricingScopes: [
		{
			value: 'all',
			label: __( 'All cycles', 'woocommerce-subscriptions-lite' ),
		},
		{
			value: 'first',
			label: __( 'First cycle', 'woocommerce-subscriptions-lite' ),
		},
		{
			value: 'n_cycles',
			label: __( 'First N cycles', 'woocommerce-subscriptions-lite' ),
		},
	],
};

function normalizeDefinitions( definitions ) {
	return {
		statuses: definitions?.statuses || DEFAULT_DEFINITIONS.statuses,
		billingUnits:
			definitions?.billing_units || DEFAULT_DEFINITIONS.billingUnits,
		pricingTypes:
			definitions?.pricing_types || DEFAULT_DEFINITIONS.pricingTypes,
		pricingScopes:
			definitions?.pricing_scopes || DEFAULT_DEFINITIONS.pricingScopes,
	};
}

function makeFormFields( definitions ) {
	return [
		{
			id: 'name',
			type: 'text',
			label: __( 'Name', 'woocommerce-subscriptions-lite' ),
			isValid: {
				required: true,
			},
		},
		{
			id: 'description',
			type: 'text',
			label: __( 'Description', 'woocommerce-subscriptions-lite' ),
		},
		{
			id: 'interval',
			type: 'integer',
			label: __( 'Interval', 'woocommerce-subscriptions-lite' ),
			isValid: {
				min: 1,
			},
		},
		{
			id: 'period',
			type: 'text',
			label: __( 'Billing period', 'woocommerce-subscriptions-lite' ),
			elements: definitions.billingUnits,
		},
		{
			id: 'expires',
			type: 'boolean',
			label: __(
				'Expire after a set number of payments',
				'woocommerce-subscriptions-lite'
			),
		},
		{
			id: 'maxCycles',
			type: 'integer',
			label: __( 'Total payments', 'woocommerce-subscriptions-lite' ),
			isVisible: ( item ) => Boolean( item.expires ),
			isValid: {
				min: 1,
			},
		},
		{
			id: 'pricingType',
			type: 'text',
			label: __( 'Pricing', 'woocommerce-subscriptions-lite' ),
			elements: definitions.pricingTypes,
		},
		{
			id: 'pricingValue',
			type: 'number',
			label: __( 'Value', 'woocommerce-subscriptions-lite' ),
			isValid: {
				min: 0,
			},
		},
		{
			id: 'pricingScope',
			type: 'text',
			label: __( 'Applies to', 'woocommerce-subscriptions-lite' ),
			elements: definitions.pricingScopes,
		},
		{
			id: 'durationCycles',
			type: 'integer',
			label: __( 'Cycle count', 'woocommerce-subscriptions-lite' ),
			isVisible: ( item ) => item.pricingScope === 'n_cycles',
			isValid: {
				min: 2,
			},
		},
	];
}

function makeListFields( definitions ) {
	return [
		{
			id: 'name',
			type: 'text',
			label: __( 'Name', 'woocommerce-subscriptions-lite' ),
			enableHiding: false,
			enableGlobalSearch: true,
		},
		{
			id: 'frequency',
			type: 'text',
			label: __( 'Frequency', 'woocommerce-subscriptions-lite' ),
			getValue: ( { item } ) => formatFrequency( item, definitions ),
			enableSorting: false,
			filterBy: {
				operators: [ 'isAny' ],
			},
		},
		{
			id: 'discount',
			type: 'text',
			label: __( 'Discount', 'woocommerce-subscriptions-lite' ),
			getValue: ( { item } ) => formatDiscount( item ),
			enableSorting: false,
			filterBy: false,
		},
		{
			id: 'status',
			type: 'text',
			label: __( 'Status', 'woocommerce-subscriptions-lite' ),
			getValue: ( { item } ) =>
				definitions.statuses.find(
					( status ) => status.value === item.status
				)?.label || item.status,
			elements: definitions.statuses,
			filterBy: {
				operators: [ 'isAny' ],
			},
			enableSorting: true,
		},
	];
}

function validityFromErrors( errors ) {
	return Object.fromEntries(
		Object.entries( errors ).map( ( [ field, message ] ) => [
			field,
			{
				custom: {
					type: 'invalid',
					message,
				},
			},
		] )
	);
}

export function PlansApp() {
	const [ view, setView ] = useState( DEFAULT_VIEW );
	const [ plans, setPlans ] = useState( [] );
	const [ definitions ] = useState(
		normalizeDefinitions( config.definitions )
	);
	const [ paginationInfo, setPaginationInfo ] = useState( {
		totalItems: 0,
		totalPages: 0,
	} );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ notice, setNotice ] = useState( '' );
	const [ editor, setEditor ] = useState( {
		isOpen: false,
		plan: null,
		data: DEFAULT_FORM_DATA,
		isSaving: false,
	} );

	const load = useCallback( async () => {
		setIsLoading( true );
		setError( '' );

		try {
			const plansResponse = await fetchPlans( viewToQuery( view ) );
			setPlans( plansResponse.data );
			setPaginationInfo( {
				totalItems: plansResponse.totalItems,
				totalPages: plansResponse.totalPages,
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

	const openCreate = useCallback( () => {
		setEditor( {
			isOpen: true,
			plan: null,
			data: DEFAULT_FORM_DATA,
			isSaving: false,
		} );
	}, [] );

	const openEdit = useCallback( ( plan ) => {
		setEditor( {
			isOpen: true,
			plan,
			data: planToFormData( plan ),
			isSaving: false,
		} );
	}, [] );

	const closeEditor = useCallback( () => {
		setEditor( ( current ) => ( { ...current, isOpen: false } ) );
	}, [] );

	const saveEditor = useCallback( async () => {
		const errors = formErrors( editor.data );
		if ( Object.keys( errors ).length > 0 ) {
			return;
		}

		setEditor( ( current ) => ( { ...current, isSaving: true } ) );
		setError( '' );

		try {
			const payload = formDataToPayload( editor.data, editor.plan );
			if ( editor.plan?.id ) {
				await updatePlan( editor.plan.id, payload );
				setNotice(
					__( 'Plan updated.', 'woocommerce-subscriptions-lite' )
				);
			} else {
				await createPlan( payload );
				setNotice(
					__( 'Plan created.', 'woocommerce-subscriptions-lite' )
				);
			}
			setEditor( ( current ) => ( { ...current, isOpen: false } ) );
			await load();
		} catch ( saveError ) {
			setError(
				saveError?.message ||
					__(
						'Subscription plan could not be saved.',
						'woocommerce-subscriptions-lite'
					)
			);
			setEditor( ( current ) => ( { ...current, isSaving: false } ) );
		}
	}, [ editor.data, editor.plan, load ] );

	const setPlanStatus = useCallback(
		async ( plan, status ) => {
			setError( '' );
			try {
				await updatePlan( plan.id, { status } );
				setNotice(
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
				await load();
			} catch ( statusError ) {
				setError(
					statusError?.message ||
						__(
							'Plan status could not be changed.',
							'woocommerce-subscriptions-lite'
						)
				);
			}
		},
		[ load ]
	);

	const movePlan = useCallback(
		async ( plan, direction ) => {
			const index = plans.findIndex( ( item ) => item.id === plan.id );
			const nextIndex = direction === 'up' ? index - 1 : index + 1;
			if ( index < 0 || nextIndex < 0 || nextIndex >= plans.length ) {
				return;
			}
			const ids = plans.map( ( item ) => item.id );
			[ ids[ index ], ids[ nextIndex ] ] = [
				ids[ nextIndex ],
				ids[ index ],
			];

			try {
				await reorderPlans( ids );
				await load();
			} catch ( reorderError ) {
				setError(
					reorderError?.message ||
						__(
							'Plan order could not be saved.',
							'woocommerce-subscriptions-lite'
						)
				);
			}
		},
		[ load, plans ]
	);

	const listFields = useMemo(
		() => makeListFields( definitions ),
		[ definitions ]
	);
	const formFields = useMemo(
		() => makeFormFields( definitions ),
		[ definitions ]
	);
	const errors = formErrors( editor.data );
	const validity = validityFromErrors( errors );
	const isFormValid = Object.keys( errors ).length === 0;

	const actions = useMemo(
		() => [
			{
				id: 'edit',
				label: __( 'Edit', 'woocommerce-subscriptions-lite' ),
				icon: <Icon icon={ pencil } />,
				isPrimary: true,
				callback: ( items ) => openEdit( items[ 0 ] ),
			},
			{
				id: 'archive',
				label: __( 'Archive', 'woocommerce-subscriptions-lite' ),
				icon: <Icon icon={ trash } />,
				isEligible: ( item ) => item.status !== 'archived',
				callback: ( items ) => setPlanStatus( items[ 0 ], 'archived' ),
			},
			{
				id: 'restore',
				label: __( 'Restore', 'woocommerce-subscriptions-lite' ),
				icon: <Icon icon={ undo } />,
				isEligible: ( item ) => item.status === 'archived',
				callback: ( items ) => setPlanStatus( items[ 0 ], 'active' ),
			},
			{
				id: 'move-up',
				label: __( 'Move up', 'woocommerce-subscriptions-lite' ),
				icon: <Icon icon={ chevronUp } />,
				isEligible: ( item ) =>
					plans.findIndex( ( plan ) => plan.id === item.id ) > 0,
				callback: ( items ) => movePlan( items[ 0 ], 'up' ),
			},
			{
				id: 'move-down',
				label: __( 'Move down', 'woocommerce-subscriptions-lite' ),
				icon: <Icon icon={ chevronDown } />,
				isEligible: ( item ) =>
					plans.findIndex( ( plan ) => plan.id === item.id ) <
					plans.length - 1,
				callback: ( items ) => movePlan( items[ 0 ], 'down' ),
			},
		],
		[ movePlan, openEdit, plans, setPlanStatus ]
	);

	return (
		<div className="wc-subscriptions-lite-plans">
			<Flex
				className="wc-subscriptions-lite-plans__header"
				justify="space-between"
				align="center"
			>
				<FlexItem>
					<h1>
						{ __(
							'Subscription Plans',
							'woocommerce-subscriptions-lite'
						) }
					</h1>
				</FlexItem>
				<FlexItem>
					<Button
						variant="primary"
						icon={ plus }
						onClick={ openCreate }
					>
						{ __( 'Add plan', 'woocommerce-subscriptions-lite' ) }
					</Button>
				</FlexItem>
			</Flex>

			{ notice && (
				<Notice
					status="success"
					onRemove={ () => setNotice( '' ) }
					className="wc-subscriptions-lite-plans__notice"
				>
					{ notice }
				</Notice>
			) }
			{ error && (
				<Notice
					status="error"
					onRemove={ () => setError( '' ) }
					className="wc-subscriptions-lite-plans__notice"
				>
					{ error }
				</Notice>
			) }

			<Card>
				<CardBody>
					{ isLoading && plans.length === 0 ? (
						<div className="wc-subscriptions-lite-plans__loading">
							<Spinner />
						</div>
					) : (
						<DataViews
							data={ plans }
							fields={ listFields }
							view={ view }
							onChangeView={ setView }
							actions={ actions }
							paginationInfo={ paginationInfo }
							isLoading={ isLoading }
							search
							searchLabel={ __(
								'Search plans',
								'woocommerce-subscriptions-lite'
							) }
							defaultLayouts={ {
								table: DEFAULT_VIEW.layout,
							} }
							empty={ __(
								'No subscription plans found.',
								'woocommerce-subscriptions-lite'
							) }
							type="table"
						/>
					) }
				</CardBody>
			</Card>

			{ editor.isOpen && (
				<Modal
					title={
						editor.plan
							? __(
									'Edit subscription plan',
									'woocommerce-subscriptions-lite'
							  )
							: __(
									'Add a subscription plan',
									'woocommerce-subscriptions-lite'
							  )
					}
					onRequestClose={ closeEditor }
					size="large"
					className="wc-subscriptions-lite-plans__modal"
				>
					<DataForm
						data={ editor.data }
						fields={ formFields }
						form={ {
							layout: {
								type: 'regular',
								labelPosition: 'top',
							},
							fields: [
								'name',
								'description',
								{
									id: 'billing',
									label: __(
										'Billing',
										'woocommerce-subscriptions-lite'
									),
									children: [
										'interval',
										'period',
										'expires',
										'maxCycles',
									],
								},
								{
									id: 'pricing',
									label: __(
										'Pricing',
										'woocommerce-subscriptions-lite'
									),
									children: [
										'pricingType',
										'pricingValue',
										'pricingScope',
										'durationCycles',
									],
								},
							],
						} }
						onChange={ ( edits ) =>
							setEditor( ( current ) => ( {
								...current,
								data: { ...current.data, ...edits },
							} ) )
						}
						validity={ validity }
					/>
					<div className="wc-subscriptions-lite-plans__modal-footer">
						<Button variant="tertiary" onClick={ closeEditor }>
							{ __( 'Cancel', 'woocommerce-subscriptions-lite' ) }
						</Button>
						<Button
							variant="primary"
							isBusy={ editor.isSaving }
							disabled={ editor.isSaving || ! isFormValid }
							onClick={ saveEditor }
						>
							{ __( 'Save', 'woocommerce-subscriptions-lite' ) }
						</Button>
					</div>
				</Modal>
			) }
		</div>
	);
}
