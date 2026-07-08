/**
 * NumberControl - number input with increment/decrement buttons.
 *
 * Provides a number input with +/- buttons, similar to the WooCommerce
 * product editor NumberControl.
 */

import { useId, useState, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Button,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalInputControl as InputControl,
} from '@wordpress/components';
import { plus, reset } from '@wordpress/icons';

/**
 * NumberControl component with increment/decrement buttons.
 *
 * The help text is rendered here (not via InputControl's help prop) so that
 * an external aria-describedby (e.g. a validation error id) can be merged
 * with the help text id; InputControl replaces any passed aria-describedby
 * with its own help id whenever help is set. When help is present it renders
 * as a sibling paragraph, so place the control in a block container.
 *
 * @param {Object}   props          Component props.
 * @param {string}   props.label    Field label.
 * @param {string}   props.value    Current value.
 * @param {Function} props.onChange Change handler.
 * @param {Function} props.onBlur   Blur handler.
 * @param {number}   props.min      Minimum value.
 * @param {number}   props.max      Maximum value.
 * @param {number}   props.step     Step increment.
 * @param {string}   props.help     Optional help text displayed below the input.
 * @return {Object} NumberControl component.
 */
export function NumberControl( props ) {
	const {
		label,
		value,
		onChange,
		onBlur,
		min = 0,
		max = Infinity,
		step = 1,
		help,
		'aria-describedby': describedBy,
		'aria-invalid': ariaInvalid,
	} = props;
	const helpId = useId();
	const [ increment, setIncrement ] = useState( 0 );
	const timeoutRef = useRef( null );
	const isInitialClick = useRef( false );

	useEffect( () => {
		function incrementValue() {
			const currentValue = parseFloat( value || '0' );
			let newValue = currentValue + increment;

			if ( newValue > max ) {
				newValue = max;
			} else if ( newValue < min ) {
				newValue = min;
			}

			if ( newValue !== currentValue ) {
				onChange( String( newValue ) );
			}
		}

		if ( increment !== 0 ) {
			timeoutRef.current = setTimeout(
				incrementValue,
				isInitialClick.current ? 500 : 100
			);
			isInitialClick.current = false;
		} else if ( timeoutRef.current ) {
			clearTimeout( timeoutRef.current );
		}
		return () => {
			if ( timeoutRef.current ) {
				clearTimeout( timeoutRef.current );
			}
		};
	}, [ increment, value, min, max, onChange ] );

	function resetIncrement() {
		setIncrement( 0 );
	}

	function handleIncrement( thisStep ) {
		const currentValue = parseFloat( value || '0' );
		let newValue = currentValue + thisStep;

		if ( newValue > max ) {
			newValue = max;
		} else if ( newValue < min ) {
			newValue = min;
		}

		if ( newValue !== currentValue ) {
			onChange( String( newValue ) );
			setIncrement( thisStep );
			isInitialClick.current = true;
		}
	}

	const ariaDescribedBy =
		[ describedBy, help ? helpId : null ].filter( Boolean ).join( ' ' ) ||
		undefined;

	return (
		<>
			<InputControl
				__next40pxDefaultSize
				className="wc-subscriptions-lite-plans__number-control"
				type="number"
				label={ label }
				value={ value }
				onChange={ onChange }
				onBlur={ onBlur }
				min={ min }
				max={ max }
				step={ step }
				aria-describedby={ ariaDescribedBy }
				aria-invalid={ ariaInvalid }
				suffix={
					<>
						<Button
							icon={ plus }
							disabled={ parseFloat( value || '0' ) >= max }
							onMouseDown={ () => handleIncrement( step ) }
							onMouseLeave={ resetIncrement }
							onMouseUp={ resetIncrement }
							onBlur={ onBlur }
							size="small"
							aria-hidden="true"
							aria-label={ __(
								'Increment',
								'woocommerce-subscriptions-lite'
							) }
							tabIndex={ -1 }
						/>
						<Button
							icon={ reset }
							disabled={ parseFloat( value || '0' ) <= min }
							onMouseDown={ () => handleIncrement( -step ) }
							onMouseLeave={ resetIncrement }
							onMouseUp={ resetIncrement }
							onBlur={ onBlur }
							size="small"
							aria-hidden="true"
							aria-label={ __(
								'Decrement',
								'woocommerce-subscriptions-lite'
							) }
							tabIndex={ -1 }
						/>
					</>
				}
			/>
			{ help && (
				<p id={ helpId } className="wc-subscriptions-lite-plans__help">
					{ help }
				</p>
			) }
		</>
	);
}
