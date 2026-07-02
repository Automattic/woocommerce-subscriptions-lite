/**
 * NumberControl - number input with increment/decrement buttons.
 *
 * Provides a number input with +/- buttons, similar to the WooCommerce
 * product editor NumberControl.
 */

import { useState, useEffect, useRef } from '@wordpress/element';
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
export function NumberControl( {
	label,
	value,
	onChange,
	onBlur,
	min = 0,
	max = Infinity,
	step = 1,
	help,
} ) {
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

	return (
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
			help={ help }
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
	);
}
