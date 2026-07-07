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
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
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
				// role="img" activates the aria-label without rendering any
				// text node - hidden text inside the row would render
				// unclipped in the browser's drag ghost. The keyboard path
				// stays the Move up / Move down row actions.
				render: () => (
					<span
						className="wc-subscriptions-lite-plans__grip-icon"
						role="img"
						aria-label={ gripLabel }
					>
						<Icon icon={ dragHandle } />
					</span>
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

	// Optimistic row order: dragover reorders this local copy for live
	// feedback, and the result is committed once on dragend.
	const [ localPlans, setLocalPlans ] = useState( plans ?? [] );

	// Refs let the delegated drag handlers read fresh state without
	// re-binding listeners mid-drag.
	const draggedIndexRef = useRef( null );
	const localPlansRef = useRef( localPlans );
	const plansRef = useRef( plans );
	const onReorderRef = useRef( onReorder );

	useEffect( () => {
		setLocalPlans( plans ?? [] );
		plansRef.current = plans ?? [];
	}, [ plans ] );
	useEffect( () => {
		localPlansRef.current = localPlans;
	}, [ localPlans ] );
	useEffect( () => {
		onReorderRef.current = onReorder;
	}, [ onReorder ] );

	const move = useCallback(
		( plan, direction ) => {
			const current = localPlansRef.current;
			const index = current.findIndex( ( item ) => item.id === plan.id );
			const nextIndex = direction === 'up' ? index - 1 : index + 1;
			if ( index < 0 || nextIndex < 0 || nextIndex >= current.length ) {
				return;
			}
			const next = [ ...current ];
			const [ moved ] = next.splice( index, 1 );
			next.splice( nextIndex, 0, moved );
			setLocalPlans( next );
			onReorder( next.map( ( item ) => item.id ) );
		},
		[ onReorder ]
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
				// Close over localPlans (not the ref) so the actions
				// reference changes when the order does - DataViews caches
				// eligibility per actions reference.
				isEligible: ( item ) =>
					localPlans.findIndex( ( plan ) => plan.id === item.id ) > 0,
				callback: ( items ) => move( items[ 0 ], 'up' ),
			},
			{
				id: 'move-down',
				label: __( 'Move down', 'woocommerce-subscriptions-lite' ),
				icon: <Icon icon={ chevronDown } />,
				isEligible: ( item ) => {
					const index = localPlans.findIndex(
						( plan ) => plan.id === item.id
					);
					return index !== -1 && index < localPlans.length - 1;
				},
				callback: ( items ) => move( items[ 0 ], 'down' ),
			},
		],
		[ move, onArchive, onEdit, onRestore, localPlans ]
	);

	// HTML5 drag-and-drop, wired outside JSX because DataViews owns its DOM.
	// DataViews re-creates the row elements after this component's effects
	// run (it defers data rendering internally), so per-row listeners would
	// silently disappear: instead the wrapper carries one set of delegated
	// listeners, and a MutationObserver re-stamps the draggable attribute and
	// row index whenever DataViews swaps the rows.
	useEffect( () => {
		const wrapper = wrapperRef.current;
		if ( ! wrapper ) {
			return undefined;
		}

		const stampRows = () => {
			const rows = wrapper.querySelectorAll(
				'.dataviews-view-table tbody tr'
			);
			rows.forEach( ( row, index ) => {
				row.setAttribute( 'draggable', 'true' );
				row.dataset.planIndex = String( index );
				row.classList.add( 'is-draggable' );
				row.classList.toggle(
					'is-dragging',
					draggedIndexRef.current !== null &&
						index === draggedIndexRef.current
				);
			} );
		};

		const rowFromEvent = ( event ) =>
			event.target.closest?.(
				'.dataviews-view-table tbody tr[draggable="true"]'
			);

		const handleDragStart = ( event ) => {
			const row = rowFromEvent( event );
			if ( ! row || ! wrapper.contains( row ) ) {
				return;
			}
			draggedIndexRef.current = Number( row.dataset.planIndex );
			row.classList.add( 'is-dragging' );
		};

		const handleDragOver = ( event ) => {
			const row = rowFromEvent( event );
			if ( ! row ) {
				return;
			}
			event.preventDefault();
			const from = draggedIndexRef.current;
			const to = Number( row.dataset.planIndex );
			if ( from === null || Number.isNaN( to ) || from === to ) {
				return;
			}
			const next = [ ...localPlansRef.current ];
			const [ moved ] = next.splice( from, 1 );
			next.splice( to, 0, moved );
			draggedIndexRef.current = to;
			setLocalPlans( next );
		};

		const handleDragEnd = () => {
			if ( draggedIndexRef.current === null ) {
				return;
			}
			draggedIndexRef.current = null;
			wrapper
				.querySelectorAll( '.is-dragging' )
				.forEach( ( el ) => el.classList.remove( 'is-dragging' ) );
			const current = localPlansRef.current;
			const original = plansRef.current;
			const changed = current.some(
				( plan, index ) => plan.id !== original[ index ]?.id
			);
			if ( changed ) {
				onReorderRef.current( current.map( ( plan ) => plan.id ) );
			}
		};

		stampRows();
		// childList only: re-stamping attributes must not retrigger it.
		const observer = new window.MutationObserver( stampRows );
		observer.observe( wrapper, { childList: true, subtree: true } );

		wrapper.addEventListener( 'dragstart', handleDragStart );
		wrapper.addEventListener( 'dragover', handleDragOver );
		wrapper.addEventListener( 'dragend', handleDragEnd );

		return () => {
			observer.disconnect();
			wrapper.removeEventListener( 'dragstart', handleDragStart );
			wrapper.removeEventListener( 'dragover', handleDragOver );
			wrapper.removeEventListener( 'dragend', handleDragEnd );
		};
	}, [] );

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

	const getItemId = useCallback( ( item ) => String( item.id ), [] );

	return (
		<div className="wc-subscriptions-lite-plans__table" ref={ wrapperRef }>
			<DataViews
				data={ localPlans }
				fields={ fields }
				view={ tableView }
				onChangeView={ onChangeView }
				actions={ actions }
				getItemId={ getItemId }
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
