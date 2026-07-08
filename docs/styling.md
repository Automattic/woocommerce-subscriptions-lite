# Styling and UI conventions

How to build UI in WooCommerce Subscriptions Lite so it looks native to WordPress and WooCommerce, stays theme-compatible on the storefront, and never ships hand-rolled CSS.

Public repo: reference only public sources (developer.woocommerce.com, wordpress.org, the `@wordpress/*` and `@woocommerce/*` packages).

## TL;DR

- **Author SCSS, never raw `.css`.** Pull colours, spacing, and typography from `@wordpress/base-styles` design tokens; never hardcode hex/px. The build compiles SCSS, adds vendor prefixes, and generates the RTL stylesheet.
- **Reuse, don't re-derive.** If WordPress admin or WooCommerce already styles something (status badges, list tables, buttons, `form-table`), use their markup/classes/components; don't hand-copy their colours.
- **Match the surface.** wp-admin screens look native to wp-admin; storefront screens look native to the merchant's *theme*. They use different tools (below).
- **Lint must pass:** `npm run lint:css` (Stylelint / `@wordpress/stylelint-config`) and `npm run lint:js` (ESLint).

## Build pipeline (already wired - use it, don't reinvent)

The repo builds with [`@wordpress/scripts`](https://www.npmjs.com/package/@wordpress/scripts) (wp-scripts):

- `npm run build` produces the production build; `npm run start` watches/rebuilds during development.
- **Admin PHP scripts:** `webpack.scripts.config.js`, entry `client/admin-php/index.js` -> `build/scripts/admin-php.js`. Import the entry's SCSS from its `index.js` (`import './style.scss';`) so wp-scripts compiles it, prefixes it, emits the RTL file, and writes the `admin-php.asset.php` dependency manifest.
- **Admin React scripts:** `webpack.scripts.config.js`, entry `client/admin-react/index.js` -> `build/scripts/admin-react.js`. Import the entry's SCSS from its `index.js` (`import './style.scss';`) so wp-scripts compiles it, prefixes it, emits the RTL file, and writes the `admin-react.asset.php` dependency manifest.
- **Interactivity API view modules (storefront):** `webpack.modules.config.js` (`npm run build:modules`, wp-scripts with `--experimental-modules`) builds ALL Interactivity API view modules - the PDP plan picker and the customer-portal store - into `build/modules/` as ESM script modules, with `@wordpress/interactivity` externalized as an import-mapped dependency and each entry's imported SCSS extracted to `style-<entry>.css` (+ RTL variant).
- **Blocks:** `npm run build:blocks` (`wp-scripts build --experimental-modules --blocks-manifest`) builds standard blocks from `src/blocks/`.
- `@woocommerce/dependency-extraction-webpack-plugin` maps `@wordpress/*` / `@woocommerce/*` imports to WordPress's already-bundled scripts, so `import { Button } from '@wordpress/components'` adds a dependency rather than re-bundling React. Never vendor your own copy of these.

There is no place for a hand-written `.css` file enqueued directly. Author `.scss` and let the build produce the stylesheet, then enqueue the compiled asset using its generated `*.asset.php` (handles dependencies + version).

## Design tokens - don't hardcode

Use `@wordpress/base-styles` variables via SCSS instead of literal values (import paths are resolved by wp-scripts):

```scss
@import "@wordpress/base-styles/colors";
@import "@wordpress/base-styles/variables";

.wc-subs-lite-foo {
	color: $gray-900;       // not #1e1e1e
	padding: $grid-unit-15; // 12px, via a token, not a literal
}
```

Colours, spacing (`$grid-unit-*`), radius, and breakpoints all have tokens. WooCommerce's status colours (the green / amber / grey order-status palette) come from WooCommerce's own admin styles: reuse those rather than redefining the hex values.

## Admin UI

Pick the pattern by how interactive the screen is:

1. **Server-rendered screens** (list tables, detail pages, simple settings) - the default for list/detail. Render with `WP_List_Table` and native wp-admin markup (`form-table`, `wp-list-table`, the `mark.order-status` badge pattern). This is what WooCommerce's own Orders list does; it reads as a native surface and inherits wp-admin's styles. Add only a thin SCSS layer (tokens + layout) for what wp-admin doesn't already give you.
2. **Rich, stateful editors** (e.g. a plan editor with live preview) - React + [`@wordpress/components`](https://developer.wordpress.org/block-editor/reference-guides/components/) mounted on an admin page. Use the components as-is; don't restyle them. Start with the lightest thing that works (a mounted React "island" on a screen), not a full single-page app, until a screen genuinely needs more.

Either way: SCSS + `@wordpress/base-styles` tokens, reuse wp-admin / WooCommerce styles, no hardcoded values.

## Storefront UI (PDP plan picker, My Account portal, checkout)

The storefront must look like the merchant's **theme**, not wp-admin:

- **Do not use `@wordpress/components` here** - it carries wp-admin styling and clashes with themes.
- **Render server-side** (overridable PHP templates under `templates/`, the WooCommerce way) and add interactivity with the **[Interactivity API](https://developer.wordpress.org/block-editor/reference-guides/interactivity-api/)** via view modules (the `build:modules` pipeline; blocks via `build:blocks`). Same approach as WooCommerce's cart / mini-cart blocks: PHP-rendered markup, light JS, framework-agnostic styling.
- **Inherit theme + WooCommerce styles.** Use WooCommerce's storefront classes and `theme.json` CSS custom properties; ship only minimal *structural* SCSS (layout, spacing) and let the theme own colour and typography.
- **Progressive enhancement:** the core flow should work from server-rendered markup; JS enhances rather than gating it, where feasible.

## File layout

- SCSS lives beside the JS / block entry that imports it (`client/admin-php/style.scss`, `client/admin-react/style.scss`, `src/blocks/<block>/style.scss`), not in a standalone `src/css/` folder.
- Blocks: one folder per block with `block.json` + `index.js` + `style.scss` (front-end) / `editor.scss` (editor) as needed.
- Compiled output goes to `build/`; enqueue it from PHP via the generated `*.asset.php`.

## i18n and accessibility

- Strings: `@wordpress/i18n` (`__`, `_x`, ...) in JS; `esc_html__()` etc. in PHP. Don't concatenate translated fragments.
- `@wordpress/components` are accessible by default; server-rendered and storefront markup must meet the same bar (labels, roles, focus states, keyboard operability).
