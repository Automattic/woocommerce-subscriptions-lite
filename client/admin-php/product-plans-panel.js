/**
 * Product-edit "Subscriptions" panel behavior.
 *
 * Pure visibility toggling: the server-rendered markup carries the saved
 * state, and the PHP save handler is the source of truth. The purchase-mode
 * select shows or hides the plans section (help text follows the selected
 * option), and the scope radios flip the per-plan checkboxes between the
 * "everything applies" cue (checked + disabled) and an editable selection.
 *
 * Self-gates on the panel's DOM marker so other admin-php screens do no work.
 */

/**
 * Wire the toggle behavior for one rendered panel.
 *
 * @param {Element} panel The `[data-wcsl-product-plans-panel]` wrapper.
 */
function initPanel( panel ) {
	const modeSelect = panel.querySelector( '[data-wcsl-mode-select]' );
	const helpText = panel.querySelector( '[data-wcsl-mode-help]' );
	const plansSection = panel.querySelector( '[data-wcsl-plans-section]' );
	const scopeRadios = Array.from(
		panel.querySelectorAll( '[data-wcsl-scope-radio]' )
	);
	const checkboxes = Array.from(
		panel.querySelectorAll( '[data-wcsl-plan-checkbox]' )
	);

	// Remember each checkbox's selection so an all -> select round trip
	// restores what the merchant had picked instead of leaving everything on.
	const selection = new Map();
	checkboxes.forEach( ( checkbox ) => {
		selection.set( checkbox, checkbox.checked );
		checkbox.addEventListener( 'change', () =>
			selection.set( checkbox, checkbox.checked )
		);
	} );

	const applyScope = () => {
		const isAll = ! scopeRadios.some(
			( radio ) => 'select' === radio.value && radio.checked
		);
		checkboxes.forEach( ( checkbox ) => {
			checkbox.disabled = isAll;
			checkbox.checked = isAll ? true : selection.get( checkbox );
		} );
	};

	const applyMode = () => {
		if ( plansSection ) {
			plansSection.hidden = 'plans' !== modeSelect.value;
		}
		if ( helpText ) {
			const option = modeSelect.options[ modeSelect.selectedIndex ];
			helpText.textContent = option ? option.dataset.wcslHelp || '' : '';
		}
	};

	if ( modeSelect ) {
		modeSelect.addEventListener( 'change', applyMode );
	}
	scopeRadios.forEach( ( radio ) =>
		radio.addEventListener( 'change', applyScope )
	);
}

function init() {
	const panel = document.querySelector( '[data-wcsl-product-plans-panel]' );
	if ( panel ) {
		initPanel( panel );
	}
}

if ( 'loading' === document.readyState ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
