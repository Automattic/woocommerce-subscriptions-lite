# WooCommerce Subscriptions Lite

Free subscriptions for WooCommerce: a base package plus a thin wrapper plugin, built on the WooCommerce subscriptions engine package (in development).

> **Status: early development scaffold.** Not functional, no releases. Names, APIs, and repository layout are provisional and will change without notice.

## What this repository will contain

- **The lite package** (`automattic/woocommerce-subscriptions-lite`) - the free subscriptions feature set, consumable as a composer dependency. Lives at the repository root (`composer.json` + `src/`).
- **The WordPress.org plugin** - the same repository root is also the plugin: `woocommerce-subscriptions-lite.php` carries the plugin header and bootstraps the package (the standard "package is also a plugin" layout).

## How renewals work

Lite renews its own subscriptions; the engine only tells it when one is due.

- **Due handler** (`src/Renewal/DueHandler.php`): registered with the engine's consumer registry. The engine's recurring due scan claims each due contract and calls it; only active subscriptions renew.
- **Processor** (`src/Renewal/RenewalProcessor.php`): appends the next billing cycle with the engine's cycle tools (claimed, so concurrent runs renew once), builds the renewal order (`RenewalOrderBuilder`: contract lines, addresses, the origin order's gateway data and tokens, BOGO bonus units) and asks the engine to charge it. A subscription several periods behind bills the one period containing now.
- **Settlement** (`src/Renewal/Settlement.php`): reads the renewal order's status. Paid: the cycle is billed and the next period armed (a subscription the customer put on hold stays on hold). Failed, or left pending by the charge: the subscription goes on hold and the customer pays the failed order from My Account to resume it. On hold: an asynchronous charge, left alone until it reports. Cancelled, trashed or deleted while unpaid: the renewal fails and the subscription stays on hold; Renew now builds a new order (customers cannot cancel renewal orders from My Account). A renewal order that cannot be built fails the same way.
- **Manual renewals**: a gateway that does not declare the engine's `subscriptions_engine` feature is never charged automatically; Lite leaves a pending renewal order for the customer to pay and holds the subscription until then.
- **Checkout** (`src/Checkout/ReusablePaymentMethod.php`): tells gateways, through the engine's reusable-payment-method query, when a checkout must leave a payment method that can be charged later, and keeps a free first order payable so the method is saved when the subscription renews for a charge.

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
npm run env:start        # once: boots the WordPress test environment
composer test            # the integration suite inside the tests environment
composer lint            # PHP coding standards
npm run lint:js          # JS
npm run lint:css         # SCSS
```

The suite bootstrap installs WooCommerce and the engine schema once, and
`WP_UnitTestCase` wraps each test in a rolled-back transaction. Tests seed
contracts through the production checkout path (a real order through
`ContractCreationHandler::create_contract()`) and assert observable behavior - rendered markup, facade
reads, hook effects - not implementation internals.

## License

GPLv3 or later. See [LICENSE](LICENSE).

## Contributing

The project is at the scaffolding stage; issues and pull requests may not receive prompt attention yet. Please check back once the first functional milestone lands.
