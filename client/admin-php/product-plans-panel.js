/**
 * Product-edit "Subscriptions" panel behavior.
 *
 * The server-rendered markup carries the saved state, and the PHP save
 * handler is the source of truth. The purchase-mode select shows or hides
 * the plans section (help text follows the selected option), the scope
 * radios flip the plans table between all-scope (the checkbox column hidden
 * - every plan applies) and an editable selection, and in select-scope the
 * header checkbox toggles the whole selection (indeterminate on a partial
 * one) while the empty-selection warning tracks a zero-checked table.
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
	const plansTable = panel.querySelector( '[data-wcsl-plans-table]' );
	const selectAll = panel.querySelector( '[data-wcsl-select-all]' );
	const emptyWarning = panel.querySelector( '[data-wcsl-empty-warning]' );
	const scopeRadios = Array.from(
		panel.querySelectorAll( '[data-wcsl-scope-radio]' )
	);
	const checkboxes = Array.from(
		panel.querySelectorAll( '[data-wcsl-plan-checkbox]' )
	);

	// Remember each checkbox's selection so an all -> select round trip
	// restores what the merchant had picked instead of leaving everything on.
	const selection = new Map();

	const isSelectScope = () =>
		scopeRadios.some(
			( radio ) => 'select' === radio.value && radio.checked
		);

	const syncSelectAll = () => {
		if ( ! selectAll ) {
			return;
		}
		const checkedCount = checkboxes.filter(
			( checkbox ) => checkbox.checked
		).length;
		selectAll.checked =
			checkboxes.length > 0 && checkedCount === checkboxes.length;
		selectAll.indeterminate =
			checkedCount > 0 && checkedCount < checkboxes.length;
	};

	const syncEmptyWarning = () => {
		if ( ! emptyWarning ) {
			return;
		}
		emptyWarning.hidden = ! (
			isSelectScope() &&
			checkboxes.every( ( checkbox ) => ! checkbox.checked )
		);
	};

	checkboxes.forEach( ( checkbox ) => {
		selection.set( checkbox, checkbox.checked );
		checkbox.addEventListener( 'change', () => {
			selection.set( checkbox, checkbox.checked );
			syncSelectAll();
			syncEmptyWarning();
		} );
	} );

	const applyScope = () => {
		const isAll = ! isSelectScope();
		if ( plansTable ) {
			plansTable.classList.toggle( 'is-scope-all', isAll );
		}
		checkboxes.forEach( ( checkbox ) => {
			checkbox.disabled = isAll;
			checkbox.checked = isAll ? true : selection.get( checkbox );
		} );
		if ( selectAll ) {
			selectAll.disabled = isAll;
		}
		syncSelectAll();
		syncEmptyWarning();
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
	if ( selectAll ) {
		selectAll.addEventListener( 'change', () => {
			selectAll.indeterminate = false;
			checkboxes.forEach( ( checkbox ) => {
				checkbox.checked = selectAll.checked;
				selection.set( checkbox, selectAll.checked );
			} );
			syncEmptyWarning();
		} );
	}

	// The server renders checked/hidden but indeterminate is DOM-only state;
	// converge once so a partial saved selection shows it from the start.
	syncSelectAll();
	syncEmptyWarning();
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
