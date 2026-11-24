/**
 * The discount marker: the key and value that mark a
 * `pricing_policy.policies` entry as the one the discount field edits. The
 * value is the discount descriptor's id.
 */

export const DISCOUNT_MARKER_KEY = 'field';
export const DISCOUNT_MARKER_VALUE = 'discount';

/**
 * Whether a pricing entry is the discount field's entry, by its marker.
 *
 * @param {Object} entry A `pricing_policy.policies` entry.
 * @return {boolean} True when the entry carries the discount marker.
 */
export function isDiscountEntry( entry ) {
	return (
		!! entry &&
		typeof entry === 'object' &&
		entry[ DISCOUNT_MARKER_KEY ] === DISCOUNT_MARKER_VALUE
	);
}
