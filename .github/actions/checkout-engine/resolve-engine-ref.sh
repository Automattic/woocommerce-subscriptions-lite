#!/usr/bin/env bash
#
# Picks the WooCommerce monorepo branch to take the engine package from, and writes
# it to GITHUB_OUTPUT as `ref` (plus `source`, where it came from).
#
# In order: DISPATCH_REF (the workflow_dispatch input), the first line of PR_BODY
# that starts with `Engine-Ref:`, else `trunk`. Both inputs are untrusted (a fork
# can write any pull request description), so they arrive through the environment
# and must be a plain branch name that exists in the monorepo.

set -euo pipefail

# Test seam: point ENGINE_REPO at a local repository to try the script; CI leaves
# it unset. The clone step in action.yml reads the same variable and default.
ENGINE_REPO="${ENGINE_REPO:-https://github.com/woocommerce/woocommerce.git}"

fail() {
	echo "::error title=Engine ref::$1"
	exit 1
}

ref="${DISPATCH_REF:-}"
ref_source='the engine_ref input'

if [ -z "$ref" ]; then
	found=''
	while IFS= read -r line || [ -n "$line" ]; do
		line="${line%$'\r'}"
		if [[ "$line" =~ ^Engine-Ref:(.*)$ ]]; then
			ref="${BASH_REMATCH[1]}"
			found=1
			break
		fi
	done <<< "${PR_BODY:-}"

	if [ -n "$found" ]; then
		# Trim surrounding whitespace.
		ref="${ref#"${ref%%[![:space:]]*}"}"
		ref="${ref%"${ref##*[![:space:]]}"}"
		ref_source='the Engine-Ref line in the pull request description'
		[ -n "$ref" ] || fail "The Engine-Ref line in the pull request description has no branch name."
	else
		ref='trunk'
		ref_source='the default'
	fi
fi

# The invalid value is not echoed: it is untrusted text going to the workflow log.
if [ "${#ref}" -gt 200 ] ||
	! [[ "$ref" =~ ^[A-Za-z0-9._/-]+$ ]] ||
	[[ "$ref" == -* ]] ||
	[[ "$ref" == *..* ]] ||
	! git check-ref-format --branch "$ref" > /dev/null 2>&1; then
	fail "The engine ref from $ref_source is not a valid branch name. Use letters, digits and . _ / - only."
fi

if [ "$ref" != 'trunk' ]; then
	status=0
	git ls-remote --exit-code "$ENGINE_REPO" "refs/heads/$ref" > /dev/null || status=$?
	if [ "$status" -eq 2 ] && [[ "$ref" =~ ^[0-9a-f]{7,40}$ || "$ref" == refs/* ]]; then
		fail "The engine ref '$ref' (from $ref_source) is not a branch. Give a branch name, such as my-branch, not a commit or a refs/ path."
	elif [ "$status" -eq 2 ]; then
		fail "Branch '$ref' (from $ref_source) does not exist in woocommerce/woocommerce."
	elif [ "$status" -ne 0 ]; then
		fail "Could not look up branch '$ref' in woocommerce/woocommerce (git ls-remote exit $status)."
	fi
fi

{
	echo "ref=$ref"
	echo "source=$ref_source"
} >> "${GITHUB_OUTPUT:-/dev/stdout}"
