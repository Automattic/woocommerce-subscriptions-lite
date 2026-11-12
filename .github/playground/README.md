# WordPress Playground preview

Files for the WordPress Playground links in the "Test the build" comment that
[`test-the-build.yml`](../workflows/test-the-build.yml) posts on pull requests. Nothing here is part of the plugin.

## How a link works

The workflow builds the plugin zip and uploads it with [`setup.php`](setup.php) as one artifact. A link is
`https://playground.wordpress.net/#` followed by a Blueprint, which:

1. installs WooCommerce from WordPress.org;
2. skips its setup wizard, opens the store to visitors, switches on High-Performance Order Storage and "Cash on delivery";
3. downloads the artifact through Playground's artifact proxy and installs the plugin zip from it;
4. runs `setup.php`, which creates two plans, the "Coffee beans" product, a billing address for the admin and one
   subscription.

The Blueprint lives in the workflow step "Build the WordPress Playground links". The two links differ only in the
page they open.

## Limits

- Playground does not run scheduled actions, so nothing renews by itself. "Renew now" on a subscription works.
- No payment gateway in the preview can be charged automatically, so renewal orders wait for payment.
- No email is sent.
- The engine comes from the WooCommerce `trunk` at build time, as in CI.
- The artifact proxy picks the newest build for the pull request's branch name. Never put a secret in the Blueprint
  or in `setup.php`.
- `setup.php` calls plugin code. If a pull request changes that code, update `setup.php` in the same pull request;
  until then the preview opens without sample data.
