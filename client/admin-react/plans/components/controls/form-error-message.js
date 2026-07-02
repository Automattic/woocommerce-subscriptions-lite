/**
 * FormErrorMessage - inline validation error display.
 *
 * Renders a styled paragraph with aria-live so screen readers announce
 * validation errors without interrupting the current task.
 *
 * @param {Object} props         Component props.
 * @param {string} props.message Error message to display.
 * @return {Object|null} FormErrorMessage element, or null when no message.
 */
export function FormErrorMessage( { message } ) {
	if ( ! message ) {
		return null;
	}

	return (
		<p
			className="wc-subscriptions-lite-plans__error"
			aria-live="polite"
			role="status"
		>
			{ message }
		</p>
	);
}
