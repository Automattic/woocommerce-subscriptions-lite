# WooCommerce Subscriptions Lite

Free subscriptions for WooCommerce: a base package plus a thin wrapper plugin, built on the WooCommerce subscriptions engine package (in development).

> **Status: early development scaffold.** Not functional, no releases. Names, APIs, and repository layout are provisional and will change without notice.

## What this repository will contain

- **The lite package** (`automattic/woocommerce-subscriptions-lite`) - the free subscriptions feature set, consumable as a composer dependency. Lives at the repository root (`composer.json` + `src/`).
- **The WordPress.org plugin** - the same repository root is also the plugin: `woocommerce-subscriptions-lite.php` carries the plugin header and bootstraps the package (the standard "package is also a plugin" layout).

## How renewals work

Lite renews its own subscriptions; the engine only tells it when one is due.

- **Due handler** (`src/Lifecycle/ContractDueHandler.php`): registered with the engine's consumer registry. The engine's recurring due scan takes each due contract's lock and calls it with that lock, and it picks the lifecycle flow the contract needs; today only active subscriptions renew. A handler that fails leaves the lock in place until it expires, so the subscription is not retried while its state is unknown; its due date stays set, so the next scan after that picks it up again.
- **Processor** (`src/Lifecycle/Renewal/RenewalProcessor.php`): runs under the due scan's lock (or its own for Renew now) and holds it through the charge. It appends the next billing cycle, or resumes one an interrupted run left pending, builds the renewal order (`RenewalOrderBuilder`: contract lines, addresses, the origin order's gateway data and tokens, BOGO bonus units) and asks the engine to charge it; an order already paid is settled without a second charge. A subscription several periods behind bills the one period containing now.
- **Settlement** (`src/Lifecycle/Renewal/Settlement.php`): reads the renewal order's status under the subscription's lock: the lock already held when the gateway reports during the charge, else its own. When another operation holds the lock, the settlement is retried through Action Scheduler (`woocommerce_subscriptions_lite_settle_order`), so a paid order is never left unrecorded; a paid order whose renewal can no longer be billed gets a refund note. Paid: the cycle is billed and the next period armed (a subscription the customer put on hold stays on hold). Failed: the subscription goes on hold and the customer pays the failed order from My Account to resume it. Left pending by the gateway (awaiting the customer, a later report, or an error): the order stays pending and the subscription goes on hold until it is paid, as for a manual renewal order. On hold: an asynchronous charge, left alone until it reports. Cancelled, trashed or deleted while unpaid: the renewal fails and the subscription stays on hold; Renew now builds a new order (customers cannot cancel renewal orders from My Account). A renewal order that cannot be built fails the same way.
- **Manual renewals**: a gateway is charged automatically only when it declares both the engine's `subscriptions_engine` feature and WooCommerce's `subscriptions` feature. Any other gateway is never charged automatically; Lite leaves a pending renewal order for the customer to pay and holds the subscription until then.
- **Checkout** (`src/Checkout/PaymentRequirement.php`): tells gateways, through the engine's reusable-payment-method query, when a checkout must leave a payment method that can be charged later, and keeps a free first order payable so the method is saved when the subscription renews for a charge.

## Concurrency

Every flow that changes a subscription (hold, reactivate, cancel, checkout creation, renewal, settlement, the admin actions) runs under the engine's per-subscription lock (`src/Lifecycle/HeldLocks.php`), reads the subscription after taking it and writes with it. Code reached in the same request (settlement during a charge, the hold placed by a renewal) uses the lock already held. A second operation meanwhile is refused instead of overwriting the first: customers get a 409 "change in progress" answer in My Account, and admins an "operation in progress" notice naming the operation; both can try again shortly. Checkout creates each subscription locked from the start under its order's creation key, so a repeated paid signal never creates a second one.

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
