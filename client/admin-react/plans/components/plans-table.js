/**
 * PlansTable - DataViews list with drag-to-reorder and row actions.
 *
 * Renders a plain table (no search, filters, view configuration, or
 * pagination chrome) via the DataViews composition API, and layers HTML5
 * drag-reorder onto the rendered rows, plus keyboard-accessible move
 * up/down actions. Manual sort_order is the only ordering.
 */

import { DataViews } from '@wordpress/dataviews/wp';
import { VisuallyHidden } from '@wordpress/components';
import { useCallback, useEffect, useMemo, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Icon,
	chevronDown,
	chevronUp,
	dragHandle,
	pencil,
	trash,
	undo,
} from '@wordpress/icons';

/**
 * Build DataViews fields from the registry's list columns plus status,
 * prefixed with a synthetic drag-handle (grip) column.
 *
 * All columns are plain: no sorting, hiding, or filtering affordances -
 * manual sort_order is the only ordering.
 *
 * @param {Array<Object>} registry    Field descriptors.
 * @param {Object}        definitions Normalized engine definitions.
 * @return {{ fields: Array, columnIds: Array }} Field config.
 */
function useTableFields( registry, definitions ) {
	return useMemo( () => {
		const gripLabel = __( 'Reorder', 'woocommerce-subscriptions-lite' );
		const fields = [
			{
				id: 'grip',
				label: gripLabel,
				header: <VisuallyHidden>{ gripLabel }</VisuallyHidden>,
				// The drag icon is pointer-only, so it is hidden from
				// assistive technology; the hidden per-row hint points at
				// the keyboard-accessible path (row actions menu).
				render: () => (
					<>
						<Icon
							className="wc-subscriptions-lite-plans__grip-icon"
							icon={ dragHandle }
							aria-hidden="true"
						/>
						<VisuallyHidden>
							{ __(
								'Reorder with the Move up and Move down row actions.',
								'woocommerce-subscriptions-lite'
							) }
						</VisuallyHidden>
					</>
				),
				enableSorting: false,
				enableHiding: false,
				filterBy: false,
			},
		];

		registry
			.filter( ( descriptor ) => descriptor.listColumn )
			.forEach( ( descriptor ) => {
				const column = descriptor.listColumn;
				fields.push( {
					id: column.id,
					label: column.label,
					enableSorting: false,
					enableHiding: false,
					filterBy: false,
					getValue: ( { item } ) =>
						column.render( item, definitions ),
				} );
			} );

		fields.push( {
			id: 'status',
			label: __( 'Status', 'woocommerce-subscriptions-lite' ),
			getValue: ( { item } ) =>
				definitions.statuses.find(
					( status ) => status.value === item.status
				)?.label || item.status,
			enableSorting: false,
			enableHiding: false,
			filterBy: false,
		} );

		const columnIds = fields.map( ( field ) => field.id );

		return { fields, columnIds };
	}, [ registry, definitions ] );
}

/**
 * @param {Object}        props                Component props.
 * @param {Array}         props.plans          Loaded plans for the current page.
 * @param {Array<Object>} props.registry       Field descriptors.
 * @param {Object}        props.definitions    Normalized engine definitions.
 * @param {Object}        props.view           DataViews view state.
 * @param {Function}      props.onChangeView   View change handler.
 * @param {Object}        props.paginationInfo Pagination info.
 * @param {boolean}       props.isLoading      Whether the list is loading.
 * @param {Function}      props.onEdit         Edit callback.
 * @param {Function}      props.onArchive      Archive callback.
 * @param {Function}      props.onRestore      Restore callback.
 * @param {Function}      props.onReorder      Reorder callback, receives id array.
 * @return {Object} PlansTable component.
 */
export function PlansTable( {
	plans,
	registry,
	definitions,
	view,
	onChangeView,
	paginationInfo,
	isLoading,
	onEdit,
	onArchive,
	onRestore,
	onReorder,
} ) {
	const { fields, columnIds } = useTableFields( registry, definitions );

	const wrapperRef = useRef( null );
	const draggedIndexRef = useRef( null );
	const plansRef = useRef( plans );
	const onReorderRef = useRef( onReorder );

	useEffect( () => {
		plansRef.current = plans;
	}, [ plans ] );
	useEffect( () => {
		onReorderRef.current = onReorder;
	}, [ onReorder ] );

	const move = useCallback(
		( plan, direction ) => {
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
			onReorder( ids );
		},
		[ onReorder, plans ]
	);

	const actions = useMemo(
		() => [
			{
				id: 'edit',
				label: __( 'Edit', 'woocommerce-subscriptions-lite' ),
				icon: <Icon icon={ pencil } />,
				callback: ( items ) => onEdit( items[ 0 ] ),
			},
			{
				id: 'archive',
				label: __( 'Archive', 'woocommerce-subscriptions-lite' ),
				icon: <Icon icon={ trash } />,
				isEligible: ( item ) => item.status !== 'archived',
				callback: ( items ) => onArchive( items[ 0 ] ),
			},
			{
				id: 'restore',
				label: __( 'Restore', 'woocommerce-subscriptions-lite' ),
				icon: <Icon icon={ undo } />,
				isEligible: ( item ) => item.status === 'archived',
				callback: ( items ) => onRestore( items[ 0 ] ),
			},
			{
				id: 'move-up',
				label: __( 'Move up', 'woocommerce-subscriptions-lite' ),
				icon: <Icon icon={ chevronUp } />,
				isEligible: ( item ) =>
					plans.findIndex( ( plan ) => plan.id === item.id ) > 0,
				callback: ( items ) => move( items[ 0 ], 'up' ),
			},
			{
				id: 'move-down',
				label: __( 'Move down', 'woocommerce-subscriptions-lite' ),
				icon: <Icon icon={ chevronDown } />,
				isEligible: ( item ) =>
					plans.findIndex( ( plan ) => plan.id === item.id ) <
					plans.length - 1,
				callback: ( items ) => move( items[ 0 ], 'down' ),
			},
		],
		[ move, onArchive, onEdit, onRestore, plans ]
	);

	// Re-attach HTML5 drag handlers to the rendered rows after each render.
	// DataViews owns its DOM, so drag-and-drop is wired here rather than in JSX.
	// Reorder only persists when the drop actually changes the order.
	useEffect( () => {
		const wrapper = wrapperRef.current;
		if ( ! wrapper ) {
			return undefined;
		}

		const rows = wrapper.querySelectorAll(
			'.dataviews-view-table tbody tr'
		);

		const handleDragStart = ( event ) => {
			draggedIndexRef.current = Number(
				event.currentTarget.dataset.planIndex
			);
		};

		const handleDragOver = ( event ) => {
			event.preventDefault();
		};

		const handleDrop = ( event ) => {
			event.preventDefault();
			const from = draggedIndexRef.current;
			const to = Number( event.currentTarget.dataset.planIndex );
			draggedIndexRef.current = null;
			if ( from === null || Number.isNaN( to ) || from === to ) {
				return;
			}
			const ids = plansRef.current.map( ( item ) => item.id );
			const [ moved ] = ids.splice( from, 1 );
			ids.splice( to, 0, moved );
			onReorderRef.current( ids );
		};

		const cleanups = [];
		rows.forEach( ( row, index ) => {
			row.setAttribute( 'draggable', 'true' );
			row.dataset.planIndex = String( index );
			row.classList.add( 'is-draggable' );
			row.addEventListener( 'dragstart', handleDragStart );
			row.addEventListener( 'dragover', handleDragOver );
			row.addEventListener( 'drop', handleDrop );
			cleanups.push( () => {
				row.removeEventListener( 'dragstart', handleDragStart );
				row.removeEventListener( 'dragover', handleDragOver );
				row.removeEventListener( 'drop', handleDrop );
			} );
		} );

		return () => cleanups.forEach( ( fn ) => fn() );
	}, [ plans ] );

	const tableView = useMemo(
		() => ( {
			...view,
			fields: columnIds,
			layout: {
				...( view.layout || {} ),
				enableMoving: false,
				styles: { grip: { width: '48px' } },
			},
		} ),
		[ view, columnIds ]
	);

	return (
		<div className="wc-subscriptions-lite-plans__table" ref={ wrapperRef }>
			<DataViews
				data={ plans }
				fields={ fields }
				view={ tableView }
				onChangeView={ onChangeView }
				actions={ actions }
				paginationInfo={ paginationInfo }
				isLoading={ isLoading }
				defaultLayouts={ { table: {} } }
				empty={ __(
					'No subscription plans found.',
					'woocommerce-subscriptions-lite'
				) }
				type="table"
			>
				{ /* Layout only: replaces DataViews' default chrome (search,
				     filters, view config, pagination) with just the table. */ }
				<DataViews.Layout />
			</DataViews>
		</div>
	);
}
