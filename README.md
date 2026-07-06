# WooCommerce Subscriptions Lite

Free subscriptions for WooCommerce: a base package plus a thin wrapper plugin, built on the WooCommerce subscriptions engine package (in development).

> **Status: early development scaffold.** Not functional, no releases. Names, APIs, and repository layout are provisional and will change without notice.

## What this repository will contain

- **The lite package** (`automattic/woocommerce-subscriptions-lite`) - the free subscriptions feature set, consumable as a composer dependency. Lives at the repository root (`composer.json` + `src/`).
- **The WordPress.org plugin** - the same repository root is also the plugin: `woocommerce-subscriptions-lite.php` carries the plugin header and bootstraps the package (the standard "package is also a plugin" layout).

## Development

The engine dependency resolves through a composer path repository expecting the
[WooCommerce monorepo](https://github.com/woocommerce/woocommerce) checked out
as a sibling directory (`../woocommerce`) - a sparse checkout of
`packages/php/woocommerce-subscriptions-engine` is enough:

```sh
git clone --depth 1 --filter=blob:none --sparse https://github.com/woocommerce/woocommerce.git ../woocommerce
git -C ../woocommerce sparse-checkout set packages/php/woocommerce-subscriptions-engine
composer install
npm install
```

## Testing

Tests run against a real WordPress + WooCommerce inside
[wp-env](https://www.npmjs.com/package/@wordpress/env) (Docker required):

```sh
composer env:start       # once: boots the WordPress test environment
composer test            # the integration suite inside the tests environment
composer lint            # PHP coding standards
npm run lint:js          # JS
npm run lint:css         # SCSS
```

The suite bootstrap installs WooCommerce and the engine schema once, and
`WP_UnitTestCase` wraps each test in a rolled-back transaction. Tests seed
contracts through the production checkout path (a real order through the
engine factory) and assert observable behavior - rendered markup, facade
reads, hook effects - not implementation internals.

## License

GPLv3 or later. See [LICENSE](LICENSE).

## Contributing

The project is at the scaffolding stage; issues and pull requests may not receive prompt attention yet. Please check back once the first functional milestone lands.
