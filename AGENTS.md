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
composer.json    # the lite package: automattic/woocommerce-subscriptions-lite
src/             # package code, PSR-4: WooCommerce\SubscriptionsLite\
plugin/          # thin wrapper plugin - the WordPress.org distribution
LICENSE          # GPL-2.0
```

The package lives at the repository root so it is directly consumable from Packagist (which reads the root `composer.json`). The wrapper plugin under `plugin/` is assembled into the WordPress.org distribution by a build step (CI pending). Layout is provisional until the release pipeline is built.

## Architecture ground rules

- **Consume the engine only through its public API.** No reliance on engine internals; if a feature cannot be built on the public surface, that is engine feedback, not a reason to reach inside.
- **This package owns no database schema.** Subscription data structures belong to the engine; Lite stores at most its own presentation preferences via standard WordPress options.
- **Render engine-provided definitions instead of hardcoding them** (fields, policies, statuses), so an older Lite renders newer engine data correctly.
- **The engine dependency is exact-pinned**, and the engine's version-register shim must be loaded with an explicit `require` from the plugin bootstrap - never via composer `files` autoload (composer deduplicates identical `files` entries across installed copies, which silently skips registration).
- **Runtime guard**: the plugin verifies at runtime that the resolved engine version satisfies its floor, and stands down with an admin notice instead of fataling when it does not.

## Conventions

- PHP namespace: `WooCommerce\SubscriptionsLite\` (PSR-4 from `src/`).
- WordPress coding standards for plugin-facing code; tooling configuration pending.
- Default branch `trunk`; work in feature branches, PRs into `trunk`.
