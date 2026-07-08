# AGENTS.md

WooCommerce Subscriptions Lite: a free subscriptions package + thin wrapper plugin for WooCommerce, built on the WooCommerce subscriptions engine package (developed in the `woocommerce/woocommerce` monorepo under `packages/php/`).

Repository: `https://github.com/Automattic/woocommerce-subscriptions-lite`
Default branch: `trunk`
Status: scaffold - no functional code yet
License: GPL-3.0-or-later

## IMPORTANT: this is a PUBLIC repository

Everything here is published: source code, comments, docs, commit messages, branch names, issues, and PR titles/descriptions. Do not include references to Automattic-internal systems (Slack, P2, Linear, internal GitHub instances), internal documents or discussions, or non-public business information anywhere in this repository. Reference public sources only (developer.woocommerce.com, wordpress.org, public GitHub). When porting code or documentation from other codebases, strip internal references as part of the port.

## Repository layout

```
composer.json         # the lite package: automattic/woocommerce-subscriptions-lite
src/                  # package code, PSR-4: Automattic\WooCommerce\SubscriptionsLite\
templates/            # front-end and email templates
tests/                # PHPUnit suites (Unit; Integration to follow)
version-register.php  # highest-version-wins shim, required explicitly by consumers
woocommerce-subscriptions-lite.php  # plugin entry (header + bootstrap) - the wp.org plugin main file
package.json          # build tooling (@wordpress/scripts, webpack)
LICENSE               # GPL-3.0
```

The repository root serves as both the composer package (Packagist reads the root `composer.json`, so Premium consumes it directly) and the WordPress.org plugin (the root `woocommerce-subscriptions-lite.php` carries the plugin header and bootstraps the package). This is the standard "package is also a plugin" layout (cf. WooCommerce, Action Scheduler), and it keeps the wrapper next to the `vendor/`/`src/`/`version-register.php` it loads. The wp.org distribution is the repo with dev-only files excluded by the release build (CI pending). Layout is provisional until the release pipeline is built.

## Architecture ground rules

- **Consume the engine only through its public API.** No reliance on engine internals; if a feature cannot be built on the public surface, that is engine feedback, not a reason to reach inside.
- **This package owns no database schema.** Subscription data structures belong to the engine; Lite stores at most its own presentation preferences via standard WordPress options.
- **Render engine-provided definitions instead of hardcoding them** (fields, policies, statuses), so an older Lite renders newer engine data correctly.
- **The engine dependency is exact-pinned**, and the engine's version-register shim must be loaded with an explicit `require` from the plugin bootstrap - never via composer `files` autoload (composer deduplicates identical `files` entries across installed copies, which silently skips registration).
- **Runtime guard**: the plugin verifies at runtime that the resolved engine version satisfies its floor, and stands down with an admin notice instead of fataling when it does not.

## Conventions

- PHP namespace: `Automattic\WooCommerce\SubscriptionsLite\` (PSR-4 from `src/`).
- PHP floor: 7.4 (matches the engine package); no syntax newer than 7.4.
- WordPress coding standards for plugin-facing code; tooling configuration pending.
- Default branch `trunk`; work in feature branches, PRs into `trunk`.

### Naming: prefixes by role

One namespace, three prefixes, each with a fixed role. Pick the prefix by what the identifier *is*, not by taste:

- **`woocommerce_subscriptions_lite_` / `woocommerce-subscriptions-lite`** (the plugin slug) - anything WordPress/WooCommerce registers or that other code binds to: hooks, filters, actions, AJAX actions, nonce actions, option and transient keys, DOM element ids (underscore); text domain, plugin/extension slug, script-module namespace (hyphen).
- **`wc-subscriptions-lite-`** - front-end registration and presentation: script/style handles, admin page slugs, CSS class names.
- **`wcsl`** (`_wcsl_`, `data-wcsl-`) - terse private markers that are never a contract: post-meta keys, POST field names, `data-` attributes.

Do not add a `wc_subscriptions_lite_` (underscore) middle form - it duplicates the first prefix; use the full slug. Persisted names (post-meta keys, option names, the extension slug that the engine stores as the plan-ownership key) are a compatibility surface - settle them before release, not during hardening.

## Styling and UI

All UI work follows [`docs/styling.md`](docs/styling.md). In short: author SCSS, never raw `.css`; use `@wordpress/base-styles` design tokens (no hardcoded colours/spacing); reuse WordPress-admin and WooCommerce styles instead of re-deriving them; and match the surface - wp-admin screens render native to wp-admin (`WP_List_Table` / `form-table`, or `@wordpress/components` for rich editors), while storefront screens are theme-native (server-rendered + the Interactivity API, **not** `@wordpress/components`). The build (`@wordpress/scripts`) compiles SCSS and handles prefixing + RTL; `npm run lint:css` and `npm run lint:js` must pass.
