/**
 * FormErrorMessage - inline validation error display.
 *
 * Renders a styled paragraph with aria-live so screen readers announce
 * validation errors without interrupting the current task. Pass an `id` and
 * reference it from the invalid input's aria-describedby so the error is
 * announced with the input.
 *
 * @param {Object} props         Component props.
 * @param {string} props.message Error message to display.
 * @param {string} [props.id]    Element id, for aria-describedby references.
 * @return {Object|null} FormErrorMessage element, or null when no message.
 */
export function FormErrorMessage( { message, id } ) {
	if ( ! message ) {
		return null;
	}

	return (
		<p
			id={ id }
			className="wc-subscriptions-lite-plans__error"
			aria-live="polite"
			role="status"
		>
			{ message }
		</p>
	);
}
